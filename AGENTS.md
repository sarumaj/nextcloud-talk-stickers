# Talk stickers

The Nextcloud app `talk_stickers`: stickers and GIFs in Nextcloud Talk, from the Smart Picker,
from `/sticker` and `/gif` in any Talk client, and from a sticker page for phones. The app builds
its sticker packs itself, into its app data, from pinned open-licensed sources.

## Layout

- `app/talk_stickers/`: the app as servers install it (PHP and plain JavaScript, no build).
  - `appinfo/info.xml`, `lib/` (PHP), `js/talk_stickers.js`, `css/`, `templates/`, `l10n/`.
  - `lib/Packs/manifest.json`: the packs' sources, pinned with their SHA-256.
- `tests/`: PHPUnit tests (no Nextcloud, no network), `tests/l10n.js` (translations complete),
  `tests/stubs/` (Psalm).
- `docs/user.md`, `docs/admin.md`, `docs/developer.md`: the details the README links to.
- `screenshots/`: the README's and the app store's pictures.
- `composer.json`: development tools only (PHPUnit, php-cs-fixer, Psalm); the app needs none.

## Checks

Run what CI runs for what you changed, and look at each exit code:

```bash
composer install                                          # composer:2 in Docker if missing
find app -name '*.php' -print0 | xargs -0 -n1 php -l
vendor/bin/phpunit
composer cs:check && composer psalm
node tests/l10n.js
npx --yes markdownlint-cli2 "*.md" "docs/**/*.md"
```

`appinfo/info.xml` is checked against `https://apps.nextcloud.com/schema/apps/info.xsd` with
`xmllint --noout --schema`.

## Conventions

- Commit messages: one line, no trailers. A change comes with its README or `docs/` text and an
  entry under `## Unreleased` in `CHANGELOG.md` (Keep a Changelog: `### Added`, `### Changed`,
  `### Fixed`).
- Markdown: lines of at most 100 characters (tables excepted), tables aligned.
- PHP, JavaScript and CSS indented with tabs (`.editorconfig`); PHP formatted by `composer cs:fix`.
  A reformatting is a commit of its own.
- Every source file starts with `SPDX-License-Identifier: AGPL-3.0-or-later`.
- A new translatable string goes into every file of `l10n/` (`.js` and `.json` alike).
- A pack source changes only with its new SHA-256 in `lib/Packs/manifest.json`.

## Releasing

1. Move the `## Unreleased` entries under `## X.Y.Z - YYYY-MM-DD` and set `<version>` in
   `appinfo/info.xml` to `X.Y.Z`.
2. Commit as `release X.Y.Z`.
3. Tag it, annotated and signed as the earlier tags:
   `git tag -s vX.Y.Z -m "Talk stickers X.Y.Z"`, and push the tag.
   `.forgejo/workflows/release.yml` builds, signs and publishes the release.

## Screenshots

`screenshots/1-…png` to `4-…png` were recorded against a throwaway Nextcloud in Docker, with Talk
and this app from the working tree, demo people (Anna and Ben Example) and demo content only,
with Playwright (Chromium, 1280×800 at device scale 2), then scaled down to 1600 pixels wide
(`vips thumbnail in.png out.png[compression=9,strip] 1600`). `appinfo/info.xml` links them by
their raw URLs on `main`. Never show real accounts, addresses or host names.
