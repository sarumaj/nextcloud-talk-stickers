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

- Commit messages: one line, no trailers, as Conventional Commits with the area as scope
  (`feat(vault): …`, `fix(inbox): …`, `docs: …`, `chore: …`), which the release bot reads. A change
  comes with its README or `docs/` text and an entry under `## Unreleased` in `CHANGELOG.md` (Keep a
  Changelog: `### Added`, `### Changed`, `### Fixed`).
- Markdown: lines of at most 100 characters (tables excepted), tables aligned.
- PHP, JavaScript and CSS indented with tabs (`.editorconfig`); PHP formatted by `composer cs:fix`.
  A reformatting is a commit of its own.
- Settings save as they change, as Nextcloud's own do (its declarative forms, or a page of our
  own doing the same): no Save button; what happened is shown (saved, or why not, at the field),
  never silently. The README and `docs/` name where the settings are, by Nextcloud's menu names.
- Every source file starts with `SPDX-License-Identifier: AGPL-3.0-or-later`.
- A new translatable string goes into every file of `l10n/` (`.js` and `.json` alike).
- A pack source changes only with its new SHA-256 in `lib/Packs/manifest.json`.

## Releasing

The release bot (`release-bot` on git.ciepiela.org, weekly) releases what is due, from the
Conventional Commits since the last tag (`feat` a minor, `fix`, `perf`, `refactor` and `fix(deps)` a
patch, `!` a major) and the `## Unreleased` entries (an `### Added` makes it at least a minor). It
moves the entries under `## X.Y.Z - YYYY-MM-DD` (adding Renovate's updates as `### Dependencies`),
sets `<version>` in `appinfo/info.xml` (`.cz.toml`), commits `chore(release): X.Y.Z`, tags `vX.Y.Z`
signed and pushes; `.forgejo/workflows/release.yml` then releases it.

To release at once by hand: move the entries, set the version, commit `chore(release): X.Y.Z`, tag
it annotated and signed as the earlier tags (`git tag -s vX.Y.Z -m "Talk stickers X.Y.Z"`) and push
the tag.

## Screenshots

`screenshots/1-…png` to `4-…png` were recorded against a throwaway Nextcloud in Docker, with Talk
and this app from the working tree, demo people (Anna and Ben Example) and demo content only,
with Playwright (Chromium, 1280×800 at device scale 2), then scaled down to 1600 pixels wide
(`vips thumbnail in.png out.png[compression=9,strip] 1600`). `appinfo/info.xml` links them by
their raw URLs on `main`. Never show real accounts, addresses or host names.
