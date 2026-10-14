"""Tests of packs/build.py that need no network (python3 -m unittest discover packs)."""

import json
import tempfile
import unittest
from pathlib import Path

import build

EMOJI_TEST = """\
# group: Smileys & Emotion
# subgroup: face-smiling
1F600                                                  ; fully-qualified     # 😀 E1.0 grinning face
# group: People & Body
1F44D                                                  ; fully-qualified     # 👍 E0.6 thumbs up
1F44D 1F3FB                                            ; fully-qualified     # 👍🏻 E1.0 thumbs up: light skin tone
2764 FE0F                                              ; fully-qualified     # ❤️ E0.6 red heart
2764                                                   ; unqualified         # ❤ E0.6 red heart
"""

PNG = build.PNG_MAGIC + b"rest"
WEBP = b"RIFF\x00\x00\x00\x00WEBPVP8 "


class Ids(unittest.TestCase):
    def test_same_id_in_every_notation(self):
        for text in ["2764 FE0F", "2764-fe0f", "2764_fe0f", "2764"]:
            self.assertEqual(build.sticker_id(build.parse_codepoints(text)), "2764")

    def test_sequence(self):
        self.assertEqual(build.sticker_id(build.parse_codepoints("1F469-200D-1F9B2")), "1f469-200d-1f9b2")

    def test_skin_tone(self):
        self.assertTrue(build.has_skin_tone(build.parse_codepoints("1f44d 1f3fb")))
        self.assertFalse(build.has_skin_tone(build.parse_codepoints("1f44d")))


class EmojiTest(unittest.TestCase):
    def test_fully_qualified_only_with_names_groups_order(self):
        emoji = build.parse_emoji_test(EMOJI_TEST)
        self.assertEqual(list(emoji), ["1f600", "1f44d", "1f44d-1f3fb", "2764"])
        self.assertEqual(emoji["1f600"].name, "grinning face")
        self.assertEqual(emoji["1f44d"].group, "People & Body")
        self.assertEqual(emoji["2764"].name, "red heart")

    def test_without_skin_tones(self):
        emoji = build.parse_emoji_test(EMOJI_TEST)
        self.assertNotIn("1f44d-1f3fb", dict(build.emoji_without_skin_tones(emoji)))


class Images(unittest.TestCase):
    def test_magic(self):
        self.assertTrue(build.looks_like_image(PNG, "png"))
        self.assertTrue(build.looks_like_image(WEBP, "webp"))
        self.assertFalse(build.looks_like_image(b"<html>error</html>", "png"))
        self.assertTrue(build.looks_like_image(b'<svg xmlns="http://www.w3.org/2000/svg"/>', "svg"))

    def test_unsafe_svg(self):
        self.assertTrue(build.svg_is_safe(b'<svg><path d="M0 0"/><use xlink:href="#a"/></svg>'))
        for bad in [b"<svg><script>x</script></svg>", b'<svg onload="x()"/>',
                    b'<svg><a href="javascript:x"/></svg>', b'<svg><use xlink:href="https://x/y.svg#a"/></svg>',
                    b"<svg><foreignObject/></svg>"]:
            self.assertFalse(build.svg_is_safe(bad), bad)


class PackIndex(unittest.TestCase):
    def test_order_categories_and_skipped_files(self):
        emoji = build.parse_emoji_test(EMOJI_TEST)
        spec = {"id": "test", "title": "Test", "license": "MIT", "licenseUrl": "u", "source": "s", "attribution": "a"}
        with tempfile.TemporaryDirectory() as tmp:
            pack = build.Pack(spec, Path(tmp))
            pack.add("1f44d", "png", PNG, "thumbs up", "People & Body", ["Hand", "thumbs up", ""])
            pack.add("1f600", "png", PNG, "grinning face", "Smileys & Emotion")
            pack.add("e000", "png", PNG, "an extra", "Extras")
            pack.add("broken", "png", b"<html>", "error page", "Other")
            pack.add("1f600", "png", PNG, "duplicate", "Other")
            index = pack.index(emoji)
            self.assertEqual([s["i"] for s in index["stickers"]], ["1f600", "1f44d", "e000"])
            self.assertEqual(index["categories"], ["Smileys & Emotion", "People & Body", "Extras"])
            self.assertEqual(index["stickers"][1]["k"], "hand")
            self.assertEqual(sorted(p.name for p in Path(tmp, "test").iterdir()), ["1f44d.png", "1f600.png", "e000.png"])


class Manifest(unittest.TestCase):
    def test_every_pack_is_complete(self):
        manifest = json.loads((Path(build.__file__).parent / "manifest.json").read_text())
        kinds = {"fluent", "noto-animated", "noto", "openmoji", "twemoji"}
        for pack in manifest["packs"]:
            for key in ["id", "kind", "title", "license", "licenseUrl", "source", "attribution"]:
                self.assertTrue(pack.get(key), f"{pack.get('id')}: {key}")
            self.assertIn(pack["kind"], kinds)
            for pinned in [pack.get("archive"), pack.get("metadata")]:
                if pinned:
                    self.assertRegex(pinned["sha256"], r"^[0-9a-f]{64}$")


if __name__ == "__main__":
    unittest.main()
