# Stickers for Nextcloud Talk

A Nextcloud app, `talk_stickers`, that adds **Stickers** to the Smart Picker of Talk (and of every
other app that offers the picker, such as Text): open-licensed emoji packs and your own stickers,
uploaded as images to a shared folder. A sticker is sent as a link, which Talk shows as the image.

- **Shipped packs:** Fluent Emoji (3D), Noto Emoji (animated and still), OpenMoji and Twemoji,
  about 7,500 stickers. Their licences and attributions are listed in [NOTICE.md](NOTICE.md) and
  shown under each pack in the picker.
- **Your own:** images (PNG, JPEG, GIF, WebP) in subfolders of the folder `Stickers`, one pack per
  subfolder, for example `Stickers/Dog/Happy.png`. The folder is meant to be a team folder shared
  with everyone who should see the stickers; they are shown only to those who can read it.
- **Recent:** the stickers last sent from this browser come first.
- **On phones:** the Talk apps have no Smart Picker, so the app has a page of its own,
  `/apps/talk_stickers/` (*Stickers* in the app menu), to add to the home screen. Tapping a sticker
  shows it with **Share**, which hands the image to the phone's share sheet (choose Talk and the
  conversation), and **Copy link**, for pasting into Talk. SVG stickers are shared as PNG.

## How it works

- `app/talk_stickers/` is the app (PHP and plain JavaScript, no build step).
    - The Smart Picker's provider and the preview of a sent sticker are a reference provider
      (`lib/Reference/StickerReferenceProvider.php`): a link to
      `/apps/talk_stickers/s/<pack>/<id>` is shown as the sticker (rich object
      `talk_stickers_sticker`, rendered by `js/talk_stickers.js`). Clients without the widget,
      such as the mobile apps, show the link's preview image.
    - The picker itself is a custom element of the Smart Picker (`js/talk_stickers.js`). Picker and
      widget register through the global registries of `@nextcloud/vue`, so they need no bundler.
    - `/apps/talk_stickers/api/packs` returns the catalogue: the shipped packs' index and the
      user's own packs.
    - `/apps/talk_stickers/` is the page for phones (`templates/main.php`, the same script); the
      manifest for the home screen is Nextcloud's own for each app.
- `packs/` builds the shipped packs into `app/talk_stickers/packs/` (not committed):
    - `manifest.json` pins each source: the archives with their SHA-256, Unicode's emoji list for
      names and groups. Noto's images come from Google's emoji CDN, which offers no pinned version.
    - `build.py` (Python 3, standard library only) downloads, checks and writes one directory per
      pack and `index.json`; skin-tone variants are left out. It replaces the output only once the
      new one is complete.

## Installing

The app is not in the app store. On the server:

```bash
git clone https://git.ciepiela.org/dawid/nextcloud-talk-stickers.git
rsync -a --delete --exclude /packs/ nextcloud-talk-stickers/app/talk_stickers/ <nextcloud>/custom_apps/talk_stickers/
python3 nextcloud-talk-stickers/packs/build.py --out <nextcloud>/custom_apps/talk_stickers/packs
chown -R www-data: <nextcloud>/custom_apps/talk_stickers
occ app:enable talk_stickers
```

The build downloads about 300 MB and writes about 600 MB, most of it Noto's animated stickers.
For your own stickers, create a team folder `Stickers` (app *Team folders*) shared with the people
who should see them. Another folder name is set with
`occ config:app:set talk_stickers folder --value <name>`.

The HomeLab repository's `nextcloud` role does all of this (`nextcloud_talk_stickers`).

## Developing

```bash
cd packs && python3 -m unittest -v test_build              # the builder, without network
python3 packs/build.py --out /tmp/packs/packs --limit 25   # a small build of every pack
```

CI (`.forgejo/workflows/ci.yml`) lints the PHP and JavaScript and runs these tests. A release is a
tag `vX.Y.Z` with the same version in `appinfo/info.xml`.

## Licence

The app is licensed under the [AGPL-3.0-or-later](LICENSE). The packs keep their own licences
([NOTICE.md](NOTICE.md)).
