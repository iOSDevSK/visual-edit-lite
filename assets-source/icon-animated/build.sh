#!/usr/bin/env bash
# Builds icon-256x256.gif and icon-128x128.gif from make_icon.py.
# Needs: python3, rsvg-convert (librsvg), magick (ImageMagick), ffmpeg, gifsicle.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
COLORS="${COLORS:-64}"

python3 "$DIR/make_icon.py" frames "$WORK/svg" >/dev/null
mkdir -p "$WORK/256" "$WORK/128"
for f in "$WORK"/svg/*.svg; do
  b="$(basename "${f%.svg}")"
  rsvg-convert -w 512 -h 512 "$f" -o "$WORK/$b.png"
  for sz in 256 128; do
    # Edge pixels are blended onto white; GIF only has on/off transparency.
    magick "$WORK/$b.png" -filter Lanczos -resize "${sz}x${sz}" \
      \( +clone -alpha extract -threshold 50% \) \
      \( -clone 0 -background white -alpha remove -alpha off \) \
      -delete 0 +swap -compose CopyOpacity -composite "PNG32:$WORK/$sz/$b.png"
  done
done

for sz in 256 128; do
  ffmpeg -loglevel error -y -framerate 33.333 -i "$WORK/$sz/f%03d.png" \
    -vf "palettegen=max_colors=$COLORS:reserve_transparent=1:stats_mode=full" "$WORK/pal$sz.png"
  ffmpeg -loglevel error -y -framerate 33.333 -i "$WORK/$sz/f%03d.png" -i "$WORK/pal$sz.png" \
    -lavfi "[0:v][1:v]paletteuse=dither=none:alpha_threshold=128" -loop 0 "$WORK/raw$sz.gif"
  gifsicle -O3 --careful "$WORK/raw$sz.gif" -o "${OUT_DIR:-$DIR}/icon-${sz}x${sz}.gif"
  echo "icon-${sz}x${sz}.gif  $(stat -f %z "${OUT_DIR:-$DIR}/icon-${sz}x${sz}.gif") bytes"
done
