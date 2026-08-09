#!/usr/bin/env bash
# Build the distributable Visual Edit Lite ZIP, reproducibly — and refuse to
# build one that is not actually Lite, or that WordPress.org would bounce.
#
#   tools/build-plugin.sh [output.zip]
#
# The gates below are the whole point. Lite is DERIVED from Visual Edit Pro by
# deleting the licence-gated half, and a derivation is only trustworthy if
# something checks it: every gate here is a leak that would otherwise ship —
# a stray `clara_ve_is_licensed()`, an AI class that survived a re-derivation
# after an upstream update, a `Update URI` header that gets a wp.org
# submission rejected on sight.
#
# The archive root is visual-edit-lite/ — WordPress derives the plugin's
# directory name (and thus its identity for upgrades) from it, and
# WordPress.org requires it to equal the plugin slug.
set -euo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)"
MAIN="$SRC/visual-edit-lite.php"
SLUG="visual-edit-lite"
VERSION="$(grep -m1 '^ \* Version:' "$MAIN" | sed 's/.*: //' | tr -d '[:space:]')"
OUT="${1:-$SRC/$SLUG-$VERSION.zip}"
fail() { echo "BUILD REFUSED: $*" >&2; exit 1; }

[ -f "$MAIN" ] || fail "plugin source not found at $MAIN"

# ---------------------------------------------------------------- version ---
DEFINED="$(grep -m1 "define( 'CLARA_VE_VERSION'" "$MAIN" | sed "s/.*'\([0-9.]*\)'.*/\1/")"
[ "$DEFINED" = "$VERSION" ] || fail "header $VERSION vs CLARA_VE_VERSION $DEFINED"
STABLE="$(grep -m1 '^Stable tag:' "$SRC/readme.txt" | sed 's/.*: //' | tr -d '[:space:]')"
[ "$STABLE" = "$VERSION" ] || fail "header $VERSION vs readme Stable tag $STABLE"

# ------------------------------------------------------------ completeness ---
while IFS= read -r rel; do
  [ -f "$SRC/$rel" ] || fail "MISSING required file: $rel"
done < <(grep -o "includes/[a-z0-9-]*\.php" "$MAIN" | sort -u)

while IFS= read -r -d '' f; do
  php -l "$f" >/dev/null || fail "PHP syntax error in $f"
done < <(find "$SRC" -name '*.php' -not -path '*/.git/*' -print0)

command -v node >/dev/null && for j in "$SRC"/assets/*.js; do
  node --check "$j" >/dev/null || fail "JS syntax error in $j"
done

# ------------------------------------------------------- Lite purity gates ---
# Each of these must match NOTHING outside comments that deliberately explain
# the absence. A hit means the derivation missed something.
purity() {
  local label="$1" pattern="$2"
  local hits
  hits="$(grep -rniE "$pattern" "$SRC" \
            --include='*.php' --include='*.js' --include='*.css' \
            --exclude-dir=.git --exclude-dir=tools --exclude-dir=docs \
            --exclude=readme.txt --exclude=README.md || true)"
  # visual-edit-lite.php's own "what Lite does not contain" note is allowed to
  # name the removed features; nothing else is.
  hits="$(printf '%s\n' "$hits" | grep -v 'visual-edit-lite\.php:[0-9]*: \* ' || true)"
  [ -z "$hits" ] || { echo "$hits" >&2; fail "$label"; }
}
purity "licence gate survived"      'clara_ve_is_licensed|UNLICENSED_ENTRIES|licenseKey|licenseSignature'
purity "updater survived"           'updatepulse|UpdatePulse|plugin-update-checker|Puc_v'
purity "AI code survived"           'Clara_VE_AI_|clara-ve-ai|clara_ve_ai_|ai-chat|ai-image|ai-video|ai-job|openrouter|OpenRouter'
purity "Turnstile survived"         'turnstile'
purity "theme export survived"      'Clara_VE_Export_Page|clara_ve_export_theme'
grep -rn "Require License\|Update URI" "$MAIN" >/dev/null 2>&1 && fail "forbidden plugin header present"

# ------------------------------------------------ WordPress.org submission ---
# Plugin Check blocks a submission on any ERROR in its "Plugin repo" category.
# These are the ones a build can decide statically; run the real Plugin Check
# on the finished ZIP as well (see the make-ve-lite skill).
grep -q "^ \* Text Domain: $SLUG$" "$MAIN" || fail "Text Domain header must equal the slug ($SLUG)"
grep -q "^=== " "$SRC/readme.txt"          || fail "readme.txt has no === Plugin Name === header"
grep -q "^Tested up to:" "$SRC/readme.txt" || fail "readme.txt has no 'Tested up to'"
grep -q "^License URI:" "$SRC/readme.txt"  || fail "readme.txt has no 'License URI'"
TAGS="$(grep -m1 '^Tags:' "$SRC/readme.txt" | sed 's/^Tags: *//')"
TAGCOUNT="$(printf '%s' "$TAGS" | awk -F, '{print NF}')"
[ "$TAGCOUNT" -le 5 ] || fail "readme.txt lists $TAGCOUNT tags; WordPress.org allows 5"

# Every PHP file that ships must refuse a direct request.
while IFS= read -r -d '' f; do
  grep -q "defined( 'ABSPATH' )\|WP_UNINSTALL_PLUGIN" "$f" \
    || fail "no direct-access guard in ${f#"$SRC"/}"
done < <(find "$SRC" -name '*.php' -not -path '*/.git/*' -not -path '*/tools/*' -not -path '*/tests/*' -print0)

# Minified assets have to ship their source; simplest is not to minify at all.
while IFS= read -r -d '' f; do
  case "$f" in *.min.js|*.min.css) fail "minified asset without source: ${f#"$SRC"/}";; esac
done < <(find "$SRC/assets" -type f -print0)

# ------------------------------------------------------------------- pack ---
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$SLUG"
rsync -a \
  --exclude '.DS_Store' \
  --exclude '.*' \
  --exclude '__MACOSX' \
  --exclude 'tests/' \
  --exclude 'tools/' \
  "$SRC/" "$STAGE/$SLUG/"

mkdir -p "$(dirname "$OUT")"
rm -f "$OUT"
# -X strips extended attributes / resource forks (the __MACOSX source).
( cd "$STAGE" && zip -q -r -X "$OUT" "$SLUG" )

echo "built $OUT (v$VERSION, $(du -h "$OUT" | cut -f1 | tr -d ' '))"
unzip -l "$OUT" | grep -cE '\.php$|\.js$|\.css$' | xargs echo "  files (php/js/css):"
if unzip -l "$OUT" | grep -qE '__MACOSX|\.DS_Store'; then
  fail "junk in the archive — build is dirty"
fi
echo "  clean: no __MACOSX, no .DS_Store"
echo "  gates: licence, updater, AI, Turnstile, export, wp.org headers — all clear"
