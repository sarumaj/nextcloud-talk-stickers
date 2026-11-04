# Talk stickers: administrator's guide

Requirements and installing are in the [README](../README.md#requirements).

## Settings

**Where its settings are:** *Administration settings → Additional settings → Talk stickers*:
the team folder, the packs to offer and the pack `/sticker` prefers. Each change is saved as it
is made (there is no Save button); a change of the packs is built by a background job within
minutes, or at once with `occ talk_stickers:packs`.

All settings are optional, and the same keys work with
`occ config:app:set talk_stickers <key> --value <value>`:

| Key            | Default         | Meaning                                                                                                                                        |
| -------------- | --------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| `folder`       | `Stickers`      | The shared folder with your own stickers                                                                                                       |
| `packs`        | all             | The packs to build, comma-separated: `fluent`, `noto-animated`, `noto`, `openmoji`, `twemoji`. Run `occ talk_stickers:packs` after changing it |
| `command_pack` | `noto-animated` | The pack `/sticker` prefers when several stickers fit equally well                                                                             |

The rating and language of GIFs come from the GIPHY integration's own settings.

## Troubleshooting

- **The picker shows no packs:** the packs aren't built yet. Check that background jobs run by
  cron, or run `occ talk_stickers:packs`, which shows each pack as it's done.
- **Stickers or GIFs arrive as links:** the sender has no free storage quota, Talk is older than
  25, or Imagick is missing (`php -m | grep imagick`). The server log says which.
- **`/gif` is sent as typed:** the GIPHY integration is missing or has no API key.
