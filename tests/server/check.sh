#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# talk_stickers on a real Nextcloud, after the shared action nextcloud-app-server of
# dawid/ci-actions installed or upgraded it there (CI's job server): a pack built
# from its real sources (three stickers of Noto, not the 300 MB of every pack, which
# is why the action skips the job BuildPacks), then what people get: the catalogue
# for the picker, a sticker's image for anyone (it is shown in Talk without a
# sign-in), a sent sticker's link and the page for phones. The action passes OCC,
# NEXTCLOUD_URL, NEXTCLOUD_USER and NEXTCLOUD_PASSWORD.
#   tests/server/check.sh
set -euo pipefail
: "${OCC:?}" "${NEXTCLOUD_URL:?}" "${NEXTCLOUD_USER:?}" "${NEXTCLOUD_PASSWORD:?}"
admin=(-u "$NEXTCLOUD_USER:$NEXTCLOUD_PASSWORD")
app="$NEXTCLOUD_URL/index.php/apps/talk_stickers"
body="$(mktemp)"
trap 'rm -f "$body"' EXIT

# Expects the status $1 for curl's other arguments; the answer is left in $body, its
# type is printed.
expect() {
  local want="$1" got
  shift
  got="$(curl -s -o "$body" -w '%{http_code} %{content_type}' "$@")"
  if [[ ${got%% *} != "$want" ]]; then
    echo "::error::${*: -1} answered ${got%% *}, not $want: $(head -c 500 "$body")" >&2
    exit 1
  fi
  echo "OK: ${*: -1} answers $want" >&2
  echo "${got#* }"
}

"$OCC" talk_stickers:packs --only noto --limit 3

# The catalogue: without a session cookie, the OCS header passes the CSRF check.
expect 200 "${admin[@]}" -H 'OCS-APIRequest: true' "$app/api/packs" >/dev/null
count="$(jq '[.packs[] | select(.id == "noto") | .stickers[]] | length' "$body")"
[[ $count == 3 ]] || { echo "::error::The catalogue has $count stickers of noto, not 3: $(head -c 500 "$body")"; exit 1; }
id="$(jq -r '.packs[] | select(.id == "noto") | .stickers[0].i' "$body")"
file="$(jq -r '.packs[] | select(.id == "noto") | .stickers[0].f' "$body")"
echo "OK: the catalogue has the 3 stickers of noto"

type="$(expect 200 "$app/img/noto/$file")"
[[ $type == image/* ]] || { echo "::error::The sticker's image is $type"; exit 1; }
echo "OK: the sticker's image is served without a sign-in ($type)"

# A sent shipped sticker's link leads to its image.
location="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "${admin[@]}" "$app/s/noto/$id")"
[[ $location == 303\ */apps/talk_stickers/img/noto/"$file" ]] \
  || { echo "::error::The link of a sent sticker answered $location"; exit 1; }
echo "OK: the link of a sent sticker leads to its image"
expect 404 "${admin[@]}" "$app/s/noto/doesnotexist" >/dev/null

expect 200 "${admin[@]}" "$app/" >/dev/null
grep -qF 'id="talk-stickers-page"' "$body" || { echo "::error::The page for phones lacks its markup"; exit 1; }
echo "OK: the page for phones renders"
