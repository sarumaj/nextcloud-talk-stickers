# Changelog

## Unreleased

### Changed

- The README follows the order of the other apps; settings and troubleshooting moved to
  `docs/admin.md`, the details of sending to `docs/user.md`, layout, checks and releasing to
  `docs/developer.md`. `AGENTS.md`, `SECURITY.md` and `.editorconfig` are new.
- `info.xml` asks for PHP 8.2, as `composer.json` does.
- CI checks the PHP with Nextcloud's coding standard (php-cs-fixer) and Psalm, and the Markdown
  with markdownlint; it runs on every `renovate/` branch.

### Fixed

- The sizes of the packs, measured: about 550 MB to download and 420 MB in the app data
  (`info.xml` said 300 MB, the README 600 MB).
- A sticker sent as a picture is named in German and Polish too ("Sticker", "Naklejka"): the
  translations lacked the word. A test checks that every translation has every string.

## 1.6.3 - 2026-10-15

### Fixed

- The stickers page has its own background: the themed background (the user's colour or image)
  showed through behind the gray hints.

## 1.6.2 - 2026-10-14

### Fixed

- Stickers and GIFs are sent as pictures also in a conversation without files yet ("Note to
  self", or a chat where nothing was attached): they went as links, which the phone apps show as
  links. The app now makes the sender's folder of the conversation and its share with Talk's own
  ConversationFolderService, as for a first attachment, and keeps Talk from posting "shared a
  folder". Links remain for no quota, an older Talk, or a failure.

## 1.6.1 - 2026-10-14

### Fixed

- Two pack builds at once (the background job after an update and `occ talk_stickers:packs`)
  removed each other's folder and left the picker without packs. A build now takes Nextcloud's
  lock: a second `occ` build stops with a message, the background job skips.

### Changed

- Issues go to the GitHub copy of the repository, where the app's users can open them: the
  `bugs` link in `info.xml` and the README say so.

## 1.6.0 - 2026-10-14

### Changed

- **The app builds its packs itself**, into its app data, so that an update from the app store
  no longer removes them: a background job queued on installation (and after updates that change
  the sources), or `occ talk_stickers:packs`. The builder is PHP with what Nextcloud has, and
  replaces the packs only once a build is complete. The images are served from the app data by a
  public, cached route.

### Added

- Releases for the app store: CI checks `info.xml` against the app store's schema and runs the
  PHPUnit tests; a release is built, signed with the app store key and published.
- The README is written for anyone installing the app.

### Removed

- The Python pack builder (`packs/build.py`), which ran on the server outside Nextcloud.

## 1.5.0 - 2026-10-14

### Added

- **`/gif <words>`** (also `/gifs`, `/giphy`) sends the first GIF GIPHY finds, in the sender's
  language and at the GIPHY integration's rating, as an image file like a sticker. A message that
  is only a GIPHY link, as the GIPHY picker inserts, becomes that GIF the same way. The key is the
  GIPHY integration's own. While the conversation's folder is not shared yet, the GIF goes as its
  GIPHY link.

## 1.4.0 - 2026-10-14

### Changed

- **Stickers arrive as images:** `/sticker` puts the sticker into the sender's folder of the
  conversation and sends it as Talk's own message of that file, so every client shows the image
  alone instead of a link with a preview or a link card. Animated stickers become GIFs, which
  Talk animates, SVG and still WebP become PNGs; conversions are kept in the app data. In Talk
  the picker inserts `/sticker <pack>/<id>`, which takes the same way.
- Until the folder is shared with the conversation (Talk shares it once a file was sent there),
  or when anything fails, the sticker goes as its link.

## 1.3.0 - 2026-10-14

### Added

- **`/sticker <words or emoji>` in every Talk client** (also `/stickers` and `/naklejka`):
  before Talk saves the message, it is replaced by the link of the sticker that fits best (an
  emoji's own sticker, else names and keywords; a first word naming a pack keeps to it). Nobody
  sees the typed text, and the phone apps, which have no Smart Picker, can send stickers too.
- A bare `/sticker` becomes the link of the stickers' page; words that fit no sticker are sent
  as typed.

## 1.2.0 - 2026-10-14

### Changed

- On phones (up to 600 px wide) the grid fits 5 stickers to a row instead of 4, the categories
  read apart from the packs, buttons are large enough for a finger, and rows that scroll sideways
  fade out at their edge.
- A sticker's sheet puts its buttons across the width, and on a low screen (a phone held
  sideways) the sticker beside them. Touch screens keep no hover after a tap.

## 1.1.0 - 2026-10-14

### Added

- **A page for phones** (`/apps/talk_stickers/`, *Stickers* in the app menu, for the home
  screen) with the same picker, since Talk's apps have no Smart Picker. A tapped sticker offers
  *Share*, which hands the image to the phone's share sheet (an SVG drawn into a PNG first), and
  *Copy link*.

## 1.0.0 - 2026-10-14

### Added

- **Stickers in the Smart Picker** of Talk and of every app that offers the picker: Fluent Emoji
  (3D), Noto Emoji (animated and still), OpenMoji and Twemoji, with their licences shown under
  each pack.
- Your own stickers: images in subfolders of a shared folder `Stickers`, one pack per subfolder,
  shown only to those who can read it.
- The stickers last sent from this browser come first.
- A sticker is sent as a link that Talk shows as the image.
- `packs/build.py` builds the shipped packs on the server from pinned sources.
