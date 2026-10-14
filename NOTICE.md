# Licences of the sticker packs

The packs are not part of this app: the server downloads them from their sources after
installation (`app/talk_stickers/lib/Packs/manifest.json`). They keep the licences below, and the
picker shows each pack's attribution with a link to its licence.

| Pack                  | Source                                                                      | Licence                                                             | Attribution                                                                          |
|-----------------------|-----------------------------------------------------------------------------|---------------------------------------------------------------------|--------------------------------------------------------------------------------------|
| Fluent Emoji          | [microsoft/fluentui-emoji](https://github.com/microsoft/fluentui-emoji)     | [MIT](https://github.com/microsoft/fluentui-emoji/blob/main/LICENSE) | Fluent Emoji © Microsoft Corporation                                                 |
| Noto Emoji (animated) | [Noto Emoji Animation](https://googlefonts.github.io/noto-emoji-animation/) | [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)           | Animated Noto Emoji © Google LLC                                                     |
| Noto Emoji            | [googlefonts/noto-emoji](https://github.com/googlefonts/noto-emoji)         | [Apache-2.0](https://www.apache.org/licenses/LICENSE-2.0)           | Noto Emoji © Google LLC                                                              |
| OpenMoji              | [openmoji.org](https://openmoji.org/)                                       | [CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/)     | All emojis designed by OpenMoji – the open-source emoji and icon project             |
| Twemoji               | [jdecked/twemoji](https://github.com/jdecked/twemoji)                       | [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)           | Twemoji © Twitter, Inc and other contributors                                        |

The images are selected (skin-tone variants are left out) and renamed after their emoji's code
points. When a sticker is sent in Talk, WebP and SVG images are converted to GIF or PNG and scaled
to at most 256 pixels.
