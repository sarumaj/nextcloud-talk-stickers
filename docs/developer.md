# Talk stickers: developer's guide

## Layout

- `app/talk_stickers/`: the app as servers install it, PHP and plain JavaScript, no build step.
  - `appinfo/info.xml`, `lib/` (PHP), `js/talk_stickers.js` (the picker and the phone page),
    `css/`, `templates/`, `l10n/` (German and Polish, `.js` and `.json` alike).
  - `lib/Packs/manifest.json`: the pinned sources of the packs, with their SHA-256.
- `tests/`: PHPUnit tests (no Nextcloud, no network), `tests/l10n.js` (every translation has every
  string), `tests/stubs/` (for Psalm).
- `composer.json`: development tools only (PHPUnit, php-cs-fixer, Psalm); the app needs none.
- `screenshots/`: the README's and the app store's pictures.

## Checks

CI (`.forgejo/workflows/ci.yml`) runs these; run them for what you changed and look at each exit
code. Without PHP or Composer, run them in `docker.io/library/composer:2`.

```bash
composer install
find app -name '*.php' -print0 | xargs -0 -n1 php -l
vendor/bin/phpunit
composer cs:check        # composer cs:fix formats
composer psalm           # accepted findings are in psalm-baseline.xml
node tests/l10n.js
npx --yes markdownlint-cli2 "*.md" "docs/**/*.md"
```

`appinfo/info.xml` is checked against `https://apps.nextcloud.com/schema/apps/info.xsd` with
`xmllint --noout --schema`.

## Releasing

The release bot (`release-bot` on git.ciepiela.org) does steps 1 to 3 weekly when a release is
due, from the Conventional Commits since the last tag and the `## Unreleased` entries of
`CHANGELOG.md` (`.cz.toml`). By hand, at once:

1. Move the `## Unreleased` entries of `CHANGELOG.md` under `## X.Y.Z - YYYY-MM-DD` and set
   `<version>` in `appinfo/info.xml` to `X.Y.Z`.
2. Commit as `chore(release): X.Y.Z`.
3. Tag it, annotated and signed as the earlier tags: `git tag -s vX.Y.Z -m "Talk stickers X.Y.Z"`,
   and push the tag. The release workflow (`.forgejo/workflows/release.yml`, the shared action
   `nextcloud-app-release` of `dawid/ci-actions`) then:

   1. builds `talk_stickers-X.Y.Z.tar.gz`;
   2. signs it with the app store key (secret `NC_APP_SIGNING_KEY`);
   3. attaches it to the release, whose text is the version's section of `CHANGELOG.md` (a tag
      without one fails before anything is released);
   4. publishes it on the app store (secret `NC_APPSTORE_TOKEN`; not when the repository's variable
      `NC_APPSTORE` is `false`).
