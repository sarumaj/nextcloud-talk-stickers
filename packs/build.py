#!/usr/bin/env python3
"""Build the shipped sticker packs from their upstream sources.

Reads packs/manifest.json, downloads each source (archives are checked against
their pinned SHA-256), and writes one directory per pack plus index.json, the
catalogue the app serves to the picker:

    <out>/index.json
    <out>/<pack>/<sticker id>.<ext>

A sticker's id is its emoji's code points in lower-case hex joined by "-",
without the variation selector FE0F, so the same emoji has the same id in every
pack. Skin-tone variants are left out: the picker shows each emoji once.

Only the standard library is used, so the script runs on the server itself
(python3 build.py --out DIR). The output is written to DIR.new and then swapped
in, so the app never sees a half-built directory.
"""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import os
import re
import shutil
import sys
import tarfile
import time
import urllib.error
import urllib.request
import zipfile
from collections.abc import Callable, Iterable, Iterator
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass, field
from pathlib import Path

SKIN_TONES = {0x1F3FB, 0x1F3FC, 0x1F3FD, 0x1F3FE, 0x1F3FF}
USER_AGENT = "nextcloud-talk-stickers-build/1"
PNG_MAGIC = b"\x89PNG\r\n\x1a\n"
# OpenMoji's own groups, named for the picker.
OPENMOJI_GROUPS = {"extras-openmoji": "OpenMoji extras", "extras-unicode": "More symbols"}


@dataclass
class Emoji:
    """An emoji of Unicode's emoji-test.txt."""

    codepoints: list[int]
    name: str
    group: str
    order: int


@dataclass
class Sticker:
    id: str
    file: str
    name: str
    category: str
    keywords: list[str] = field(default_factory=list)


def sticker_id(codepoints: Iterable[int]) -> str:
    """The id shared by all packs: hex code points without FE0F, joined by "-"."""
    return "-".join(f"{cp:x}" for cp in codepoints if cp != 0xFE0F)


def parse_codepoints(text: str) -> list[int]:
    """Code points from "1F600", "1f44d 1f3fb", "1f468_200d_1f469" or "1F469-200D-1F9B2"."""
    return [int(part, 16) for part in re.split(r"[\s_\-]+", text.strip()) if part]


def has_skin_tone(codepoints: Iterable[int]) -> bool:
    return any(cp in SKIN_TONES for cp in codepoints)


def parse_emoji_test(text: str) -> dict[str, Emoji]:
    """The fully-qualified emoji of emoji-test.txt by sticker id, in Unicode's order."""
    emoji: dict[str, Emoji] = {}
    group = ""
    for line in text.splitlines():
        if line.startswith("# group:"):
            group = line.split(":", 1)[1].strip()
            continue
        if not line or line.startswith("#"):
            continue
        data, _, comment = line.partition("#")
        points, _, status = data.partition(";")
        if status.strip() != "fully-qualified":
            continue
        codepoints = parse_codepoints(points)
        # "😀 E1.0 grinning face": the name follows the emoji and its version.
        name = re.sub(r"^\S+\s+E\d+\.\d+\s+", "", comment.strip())
        emoji.setdefault(sticker_id(codepoints), Emoji(codepoints, name, group, len(emoji)))
    return emoji


def fetch(url: str, attempts: int = 3) -> bytes:
    """The body of url, with a few retries for the CDN's occasional hiccups."""
    for attempt in range(attempts):
        try:
            request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
            with urllib.request.urlopen(request, timeout=60) as response:
                return response.read()
        except urllib.error.HTTPError as error:
            if error.code == 404 or attempt == attempts - 1:
                raise
        except (urllib.error.URLError, TimeoutError):
            if attempt == attempts - 1:
                raise
        time.sleep(2 ** attempt)
    raise AssertionError("unreachable")


def fetch_optional(url: str) -> bytes | None:
    """The body of url, or None where the CDN has no such file (404)."""
    try:
        return fetch(url)
    except urllib.error.HTTPError as error:
        if error.code == 404:
            return None
        raise


def fetch_pinned(source: dict, cache: Path) -> bytes:
    """A pinned download (url, sha256), kept in cache under its checksum."""
    cached = cache / source["sha256"]
    if cached.exists():
        data = cached.read_bytes()
    else:
        data = fetch(source["url"])
    digest = hashlib.sha256(data).hexdigest()
    if digest != source["sha256"]:
        raise SystemExit(f"{source['url']}: SHA-256 {digest}, expected {source['sha256']}")
    if not cached.exists():
        cache.mkdir(parents=True, exist_ok=True)
        cached.write_bytes(data)
    return data


def looks_like_image(data: bytes, ext: str) -> bool:
    """Whether data is the image its extension claims (a CDN error page is not)."""
    if ext == "png":
        return data.startswith(PNG_MAGIC)
    if ext == "webp":
        return data[:4] == b"RIFF" and data[8:12] == b"WEBP"
    if ext == "svg":
        head = data[:512].lstrip().lower()
        return head.startswith(b"<svg") or head.startswith(b"<?xml")
    return False


def svg_is_safe(data: bytes) -> bool:
    """No scripts, event handlers or external references in an SVG."""
    text = data.decode("utf-8", "replace").lower()
    return not re.search(r"<script|\son\w+\s*=|javascript:|<foreignobject|xlink:href\s*=\s*[\"'](?!#)", text)


class Pack:
    """Collects a pack's stickers and writes their files."""

    def __init__(self, spec: dict, out: Path) -> None:
        self.spec = spec
        self.dir = out / spec["id"]
        self.dir.mkdir(parents=True)
        self.stickers: dict[str, Sticker] = {}

    def add(self, sid: str, ext: str, data: bytes, name: str, category: str, keywords: Iterable[str] = ()) -> None:
        if sid in self.stickers or not looks_like_image(data, ext):
            return
        if ext == "svg" and not svg_is_safe(data):
            return
        filename = f"{sid}.{ext}"
        (self.dir / filename).write_bytes(data)
        words = sorted({w.strip().lower() for w in keywords if w and w.strip()} - {name.lower()})
        self.stickers[sid] = Sticker(sid, filename, name, category, words)

    def index(self, emoji: dict[str, Emoji]) -> dict:
        """The pack's entry of index.json: stickers in Unicode's order, extras last."""
        def order(sticker: Sticker) -> tuple[int, str]:
            known = emoji.get(sticker.id)
            return (known.order if known else 1_000_000, sticker.name)

        stickers = sorted(self.stickers.values(), key=order)
        categories: list[str] = []
        for sticker in stickers:
            if sticker.category not in categories:
                categories.append(sticker.category)
        return {
            "id": self.spec["id"],
            "title": self.spec["title"],
            "license": self.spec["license"],
            "licenseUrl": self.spec["licenseUrl"],
            "source": self.spec["source"],
            "attribution": self.spec["attribution"],
            "categories": categories,
            # Short keys: the catalogue is loaded by every picker.
            "stickers": [
                {"i": s.id, "f": s.file, "n": s.name, "c": categories.index(s.category), "k": " ".join(s.keywords)}
                for s in stickers
            ],
        }


def category_of(sid: str, emoji: dict[str, Emoji], fallback: str) -> str:
    known = emoji.get(sid)
    return known.group if known else (fallback or "Other")


def emoji_without_skin_tones(emoji: dict[str, Emoji]) -> Iterator[tuple[str, Emoji]]:
    for sid, entry in emoji.items():
        if not has_skin_tone(entry.codepoints):
            yield sid, entry


def download_all(urls: dict[str, list[str]], workers: int) -> dict[str, bytes | None]:
    """The first URL of each list that exists, by key, downloaded in parallel."""
    def first(candidates: list[str]) -> bytes | None:
        for url in candidates:
            data = fetch_optional(url)
            if data is not None:
                return data
        return None

    with ThreadPoolExecutor(max_workers=workers) as pool:
        return dict(zip(urls, pool.map(first, urls.values())))


def read_tar(data: bytes, wanted: Callable[[str], bool]) -> dict[str, bytes]:
    """The wanted files of a .tar.gz, read in one pass (seeking in gzip would
    decompress it again from the start for every file)."""
    files: dict[str, bytes] = {}
    with tarfile.open(fileobj=io.BytesIO(data), mode="r|gz") as archive:
        for member in archive:
            if member.isfile() and wanted(member.name):
                files[member.name] = archive.extractfile(member).read()
    return files


def build_fluent(pack: Pack, spec: dict, emoji: dict[str, Emoji], cache: Path, limit: int | None) -> None:
    """Microsoft's Fluent Emoji: the 3D images (Default for those with skin tones)."""
    files = read_tar(fetch_pinned(spec["archive"], cache),
                     lambda name: name.endswith("/metadata.json") or "/3D/" in name and name.endswith("_3d.png"))
    # The 3D images by emoji folder; skin-tone emoji keep their neutral ones under
    # Default/ (the other tones' folders have no metadata.json, so none is used).
    images: dict[str, list[str]] = {}
    for name in files:
        for marker in ("/Default/3D/", "/3D/"):
            if marker in name:
                images.setdefault(name.split(marker, 1)[0], []).append(name)
                break
    for name in sorted(files):
        if not name.endswith("/metadata.json") or name.count("/assets/") != 1:
            continue
        if limit is not None and len(pack.stickers) >= limit:
            break
        folder = name.rsplit("/", 1)[0]
        meta = json.loads(files[name])
        found = images.get(folder)
        if not found or "unicode" not in meta:
            continue
        sid = sticker_id(parse_codepoints(meta["unicode"]))
        pack.add(sid, "png", files[sorted(found)[0]],
                 meta.get("cldr", sid), category_of(sid, emoji, meta.get("group", "")), meta.get("keywords", []))


def build_noto_animated(pack: Pack, spec: dict, emoji: dict[str, Emoji], workers: int, limit: int | None) -> None:
    """Google's animated Noto Emoji, from its CDN (the index lists the animated ones)."""
    icons = json.loads(fetch(spec["index"]))["icons"]
    if limit is not None:
        icons = icons[:limit]
    urls = {icon["codepoint"]: [spec["url"].format(codepoint=icon["codepoint"])] for icon in icons}
    files = download_all(urls, workers)
    for icon in icons:
        data = files[icon["codepoint"]]
        if data is None:
            continue
        sid = sticker_id(parse_codepoints(icon["codepoint"]))
        known = emoji.get(sid)
        fallback = (icon.get("categories") or [""])[0]
        keywords = [tag.strip(":").replace("-", " ") for tag in icon.get("tags", [])]
        pack.add(sid, "webp", data, known.name if known else keywords[0] if keywords else sid,
                 category_of(sid, emoji, fallback), keywords)


def build_by_codepoint(pack: Pack, emoji: dict[str, Emoji], ext: str, candidates: Callable[[Emoji], list[str]],
                       load: Callable[[list[str]], bytes | None] | None, workers: int, limit: int | None) -> None:
    """A pack with one image per emoji of emoji-test.txt, found by code points."""
    entries = list(emoji_without_skin_tones(emoji))
    if limit is not None:
        entries = entries[:limit]
    if load is None:
        files = download_all({sid: candidates(entry) for sid, entry in entries}, workers)
    else:
        files = {sid: load(candidates(entry)) for sid, entry in entries}
    for sid, entry in entries:
        data = files[sid]
        if data is not None:
            pack.add(sid, ext, data, entry.name, entry.group)


def build_noto(pack: Pack, spec: dict, emoji: dict[str, Emoji], workers: int, limit: int | None) -> None:
    """Google's Noto Emoji, the still images from its CDN."""
    def candidates(entry: Emoji) -> list[str]:
        codepoint = "_".join(f"{cp:x}" for cp in entry.codepoints if cp != 0xFE0F)
        return [spec["url"].format(codepoint=codepoint)]

    build_by_codepoint(pack, emoji, "png", candidates, None, workers, limit)


def build_twemoji(pack: Pack, spec: dict, emoji: dict[str, Emoji], cache: Path, limit: int | None) -> None:
    """Twemoji (jdecked's continuation of Twitter's): its SVG images."""
    files = read_tar(fetch_pinned(spec["archive"], cache), lambda name: "/assets/svg/" in name and name.endswith(".svg"))
    svgs = {name.rsplit("/", 1)[1]: data for name, data in files.items()}

    def candidates(entry: Emoji) -> list[str]:
        # Twemoji keeps FE0F in some file names and drops it in others.
        return [sticker_id(entry.codepoints) + ".svg", "-".join(f"{cp:x}" for cp in entry.codepoints) + ".svg"]

    def load(names: list[str]) -> bytes | None:
        for name in names:
            if name in svgs:
                return svgs[name]
        return None

    build_by_codepoint(pack, emoji, "svg", candidates, load, 1, limit)


def build_openmoji(pack: Pack, spec: dict, emoji: dict[str, Emoji], cache: Path, limit: int | None) -> None:
    """OpenMoji: the coloured 618×618 images, with OpenMoji's own extras."""
    meta = json.loads(fetch_pinned(spec["metadata"], cache))
    archive = zipfile.ZipFile(io.BytesIO(fetch_pinned(spec["archive"], cache)))
    names = set(archive.namelist())
    for entry in meta:
        if limit is not None and len(pack.stickers) >= limit:
            break
        # Components (hair styles, skin tones) are parts of emoji, not stickers.
        if entry.get("skintone") or entry.get("group") == "component" or f"{entry['hexcode']}.png" not in names:
            continue
        sid = sticker_id(parse_codepoints(entry["hexcode"]))
        group = OPENMOJI_GROUPS.get(entry.get("group", ""), entry.get("group", "").replace("-", " ").title())
        keywords = [t for t in (entry.get("tags", "") + "," + entry.get("openmoji_tags", "")).split(",")]
        pack.add(sid, "png", archive.read(f"{entry['hexcode']}.png"), entry.get("annotation") or sid,
                 category_of(sid, emoji, group), keywords)


def build(manifest: dict, out: Path, cache: Path, workers: int, limit: int | None, only: set[str] | None) -> dict:
    """Every pack of the manifest into out; returns index.json's content."""
    emoji = parse_emoji_test(fetch_pinned(manifest["unicode"], cache).decode("utf-8"))
    packs = []
    for spec in manifest["packs"]:
        if only and spec["id"] not in only:
            continue
        pack = Pack(spec, out)
        kind = spec["kind"]
        if kind == "fluent":
            build_fluent(pack, spec, emoji, cache, limit)
        elif kind == "noto-animated":
            build_noto_animated(pack, spec, emoji, workers, limit)
        elif kind == "noto":
            build_noto(pack, spec, emoji, workers, limit)
        elif kind == "twemoji":
            build_twemoji(pack, spec, emoji, cache, limit)
        elif kind == "openmoji":
            build_openmoji(pack, spec, emoji, cache, limit)
        else:
            raise SystemExit(f"Unknown kind of pack: {kind}")
        if not pack.stickers:
            raise SystemExit(f"{spec['id']}: no stickers found; has the source changed?")
        print(f"{spec['id']}: {len(pack.stickers)} stickers", file=sys.stderr)
        packs.append(pack.index(emoji))
    return {"version": manifest_digest(manifest), "packs": packs}


def manifest_digest(manifest: dict) -> str:
    return hashlib.sha256(json.dumps(manifest, sort_keys=True).encode()).hexdigest()[:16]


def main(argv: list[str] | None = None) -> int:
    here = Path(__file__).resolve().parent
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--manifest", type=Path, default=here / "manifest.json")
    parser.add_argument("--out", type=Path, required=True, help="directory of the packs (replaced)")
    parser.add_argument("--cache", type=Path, help="downloads kept between runs (default: <out>.cache)")
    parser.add_argument("--workers", type=int, default=8, help="parallel downloads from the CDN")
    parser.add_argument("--limit", type=int, help="at most this many stickers per pack (for tests)")
    parser.add_argument("--only", action="append", help="build only this pack (repeatable)")
    args = parser.parse_args(argv)

    manifest = json.loads(args.manifest.read_text())
    out = args.out.resolve()
    staging = out.with_name(out.name + ".new")
    shutil.rmtree(staging, ignore_errors=True)
    staging.mkdir(parents=True)
    index = build(manifest, staging, args.cache or out.with_name(out.name + ".cache"), args.workers,
                  args.limit, set(args.only) if args.only else None)
    (staging / "index.json").write_text(json.dumps(index, ensure_ascii=False, separators=(",", ":")))
    for path in staging.rglob("*"):
        path.chmod(0o755 if path.is_dir() else 0o644)
    staging.chmod(0o755)
    # Swap: the old directory goes only once the new one is complete.
    old = out.with_name(out.name + ".old")
    shutil.rmtree(old, ignore_errors=True)
    if out.exists():
        os.rename(out, old)
    os.rename(staging, out)
    shutil.rmtree(old, ignore_errors=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
