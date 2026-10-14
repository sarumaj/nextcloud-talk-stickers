# Stickers for Nextcloud Talk

Send stickers and GIFs in [Nextcloud Talk](https://apps.nextcloud.com/apps/spreed), from the
browser, the desktop app and the Talk apps for Android and iOS.

- **About 8,400 stickers** in five open-licensed packs: Fluent Emoji (3D), Noto Emoji (animated
  and still), OpenMoji and Twemoji. Your server downloads them after installation.
- **Your own stickers:** upload images to a shared folder, and everyone who can read the folder
  can send them.
- **Stickers arrive as pictures:** animated ones keep moving, and there's no link text around them.
- **`/sticker party`** or **`/sticker 🎉`** sends a sticker from any Talk client, phones included.
- **`/gif happy dance`** sends a GIF from GIPHY, if the
  [GIPHY integration](https://apps.nextcloud.com/apps/integration_giphy) is set up.
- **A sticker page for phones:** you can add it to your home screen and share a sticker to Talk
  through the phone's share sheet.

## Requirements

- Nextcloud 32 to 36 with Talk. Talk 25 or later sends stickers and GIFs as pictures; older
  versions send them as links.
- PHP with the `zip` extension. Imagick (with SVG and WebP support) converts animated stickers to
  GIF, which Talk animates; without it, those stickers are sent as links.
- Background jobs run by cron, as Nextcloud recommends. The sticker packs are built by a
  background job.
- About 600 MB of disk space in the app data for the packs. The server needs to reach GitHub,
  unicode.org and fonts.gstatic.com once, to download them.

## Installing

1. Install **Talk stickers** from the app store (*Apps*, *Social & communication*). Or unpack a
   release's `talk_stickers-X.Y.Z.tar.gz` into `custom_apps/` and run
   `occ app:enable talk_stickers`.
2. Wait for the packs. A background job downloads and builds them; this takes a few minutes.
   To build them at once instead, run:

   ```bash
   occ talk_stickers:packs
   ```

3. Optional, for your own stickers: install **Team folders**, create a team folder named
   `Stickers` and share it with the people who should see them.
4. Optional, for GIFs: install the **GIPHY integration** and enter its API key in the admin
   settings.

## Using it

**In the browser and the desktop app:** in a Talk conversation, type `/sticker` and press Enter,
or choose the paperclip, *Smart picker*, *Stickers*. Pick a sticker, then press Enter to send it.
The picker has a tab per pack, categories, search, and a *Recent* tab for the stickers you sent
last. The picker also works in other apps with the Smart Picker, such as Text, where it inserts
the sticker's link.

**In any client, phones included,** send a message that starts with `/sticker`:

| Message                   | Sends                                                         |
|---------------------------|---------------------------------------------------------------|
| `/sticker 🎉`             | that emoji's sticker                                          |
| `/sticker party`          | the sticker whose name or keywords fit best (English words)   |
| `/sticker twemoji cat`    | a sticker from the pack named first (`fluent`, `noto`, `openmoji`, `twemoji`, or `ours` for your own) |
| `/sticker`                | a link to the sticker page                                    |
| `/gif happy dance`        | the first GIF GIPHY finds (words in your language)            |

The message is replaced before it's saved, so nobody sees what you typed. Words that fit no
sticker are sent as you typed them. `/stickers` and `/gifs` work too, and so do `/giphy` and
`/naklejka` (Polish).

**On a phone,** you can also open the sticker page, `https://<your cloud>/apps/talk_stickers/`
(*Stickers* in the app menu), and add it to your home screen. Tap a sticker, then **Share** and
choose Talk and the conversation, or **Copy link** and paste it into Talk.

**Your own stickers:** put PNG, JPEG, GIF or WebP images in a subfolder of the `Stickers` folder.
Each subfolder is a pack, for example `Stickers/Dog/Happy.png`.

### Where sent stickers are kept

A sticker or GIF sent as a picture is saved in your folder for that conversation,
`Talk/<conversation>/<you>/`, the folder Talk uses for your attachments there; a sticker you send
again reuses its file. If the folder doesn't exist yet, the app creates it and shares it with the
conversation, as Talk does for your first attachment, but without Talk's "shared a folder" message.
Stickers count against your storage quota, a few hundred kilobytes each at most.

## Settings

All settings are optional, set with `occ config:app:set talk_stickers <key> --value <value>`:

| Key            | Default         | Meaning                                                              |
|----------------|-----------------|----------------------------------------------------------------------|
| `folder`       | `Stickers`      | The shared folder with your own stickers                             |
| `packs`        | all             | The packs to build, comma-separated: `fluent`, `noto-animated`, `noto`, `openmoji`, `twemoji`. Run `occ talk_stickers:packs` after changing it |
| `command_pack` | `noto-animated` | The pack `/sticker` prefers when several stickers fit equally well   |

The rating and language of GIFs come from the GIPHY integration's own settings.

## Troubleshooting

- **The picker shows no packs:** the packs aren't built yet. Check that background jobs run by
  cron, or run `occ talk_stickers:packs`, which shows each pack as it's done.
- **Stickers or GIFs arrive as links:** the sender has no free storage quota, Talk is older than
  25, or Imagick is missing (`php -m | grep imagick`). The server log says which.
- **`/gif` is sent as typed:** the GIPHY integration is missing or has no API key.

## How it works

The app (`app/talk_stickers/`) is PHP and plain JavaScript, with no build step.

- **Picker:** a custom element of the Smart Picker, `js/talk_stickers.js`. A reference provider,
  `lib/Reference/StickerReferenceProvider.php`, shows a sticker link
  (`/apps/talk_stickers/s/<pack>/<id>`) as the sticker.
- **`/sticker` and `/gif`:** `lib/Listener/StickerCommandListener.php` listens to Talk's
  `BeforeChatMessageSentEvent`. It finds the sticker (`StickerService::search()`) or the GIF
  (`GifService`), saves it in the sender's conversation folder (made and shared with Talk's own
  `ConversationFolderService` when missing; `QuietFolderShareListener` keeps Talk from announcing
  that share), and rewrites the message into
  Talk's own message of that file, which refers to it by file id, as Talk 25's attachments do.
  WebP and SVG stickers are converted with Imagick; the results are kept in the app data.
- **Packs:** `lib/Packs/Builder.php` builds them into the app data from the sources pinned in
  `lib/Packs/manifest.json`. Archives are checked against their SHA-256, and Unicode's emoji list
  supplies names and categories. Noto's images come from Google's emoji CDN, which has no pinned
  versions. Skin-tone variants are left out. A build goes into a new folder, which is used only
  once it's complete.
- **Images:** `/apps/talk_stickers/img/<pack>/<file>` serves the packs' images, public and
  cached, because the phone apps load previews without a login.

## Reporting problems

Please open an issue on GitHub:
[sarumaj/nextcloud-talk-stickers](https://github.com/sarumaj/nextcloud-talk-stickers/issues). Say
which versions of Nextcloud, Talk and this app you use, and which Talk client.

## Developing

```bash
composer install && vendor/bin/phpunit
```

The tests need neither Nextcloud nor network. CI (`.forgejo/workflows/ci.yml`) lints the PHP and
JavaScript, checks `appinfo/info.xml` against the app store's schema and runs the tests. The code
lives at [git.ciepiela.org](https://git.ciepiela.org/dawid/nextcloud-talk-stickers); GitHub has a
copy, where the issues are.

To release, tag `vX.Y.Z` with the same version as in `appinfo/info.xml`. The release workflow
(`.forgejo/workflows/release.yml`) then:

1. builds `talk_stickers-X.Y.Z.tar.gz`;
2. signs it with the app store key (secret `APP_SIGNING_KEY`);
3. attaches it to the release;
4. publishes it on the app store (secret `APPSTORE_TOKEN`).

## Licence

The app is licensed under the [AGPL-3.0-or-later](LICENSE). The sticker packs keep their own
licences; see [NOTICE.md](NOTICE.md). The picker shows each pack's attribution.
