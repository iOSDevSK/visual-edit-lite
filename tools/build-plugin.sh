#!/usr/bin/env bash
# Build the distributable Visual Edit Lite ZIP, reproducibly — and refuse to
# build one that is not actually Lite, or that WordPress.org would bounce.
#
#   tools/build-plugin.sh [output.zip]
#   VE_CHANNEL=github tools/build-plugin.sh [output.zip]
#
# Two channels, one source. The default build is the WordPress.org package and
# is exactly what the directory's reviewers see. VE_CHANNEL=github packs the
# same files plus three Git Updater headers in the PACKAGED main file, so an
# install from a GitHub release can update itself (through the Git Updater
# plugin) while the plugin waits for, or lives beside, its directory listing.
# The headers are never in the source tree: nothing a reviewer reads changes.
# They carry no `Update URI`, so once the directory serves this slug WordPress
# offers its build too, and installing it moves the site to that channel.
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
# The "Try it live" link in the README never changes; what it installs is named
# in this file. A version bump that forgets it leaves the demo on the previous
# release, and nothing else would notice. (Dot-directory: never packed.)
BLUEPRINT="$SRC/.github/playground/blueprint.json"
if [ -f "$BLUEPRINT" ]; then
  grep -q "/releases/download/$VERSION/$SLUG-$VERSION.zip\"" "$BLUEPRINT" \
    || fail "header $VERSION vs the Playground blueprint — update the ZIP URL in .github/playground/blueprint.json"
fi

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

# node --check proves a file PARSES. It says nothing about whether the
# functions it calls still EXIST — and the derivation deletes whole sections
# of editor.js. Three calls outlived their definitions once; one of them threw
# on every click of the Search-appearance button.
php "$SRC/tools/check-js-symbols.php" "$SRC"/assets/*.js >/dev/null \
  || { php "$SRC/tools/check-js-symbols.php" "$SRC"/assets/*.js; fail "a called function has no definition"; }

# ------------------------------------------------------- Lite purity gates ---
# Each of these must match NOTHING outside comments that deliberately explain
# the absence. A hit means the derivation missed something.
#
# tools/, docs/ and tests/ are scanned by none of them: none of the three is
# packed (see the rsync excludes below), and all three have to be able to NAME
# what Lite does not have in order to document or assert it.
purity() {
  local label="$1" pattern="$2" allow="${3:-}"
  local hits
  hits="$(grep -rniE "$pattern" "$SRC" \
            --include='*.php' --include='*.js' --include='*.css' \
            --exclude-dir=.git --exclude-dir=tools --exclude-dir=docs --exclude-dir=tests \
            --exclude=readme.txt --exclude=README.md || true)"
  # visual-edit-lite.php's own "what Lite does not contain" note is allowed to
  # name the removed features; nothing else is.
  hits="$(printf '%s\n' "$hits" | grep -v 'visual-edit-lite\.php:[0-9]*: \* ' || true)"
  # A named, deliberate exception: the STAND-DOWN for a feature Lite does not
  # have. Naming it is not having it, and a converted theme asks this plugin
  # about it by name — see the Turnstile gate below.
  [ -n "$allow" ] && hits="$(printf '%s\n' "$hits" | grep -vE "$allow" || true)"
  [ -z "$hits" ] || { echo "$hits" >&2; fail "$label"; }
}
purity "licence gate survived"      'clara_ve_is_licensed|UNLICENSED_ENTRIES|licenseKey|licenseSignature'
# The half of the licence gate the line above never saw. Pro's history keeps
# 300 rows and lists ten of them to an unregistered install; Lite inherited the
# listing, the server-side refusal and the message that went with it, under
# names that carry no "licensed" in them -- and WordPress.org's pre-review
# found it (guideline 5) when nothing here did. Lite's history is ten deep in
# STORAGE instead: nothing is withheld, so there is nothing to refuse.
purity "history restore gate survived" 'may_restore|visible_entries|VISIBLE_ENTRIES|license_required|unlicensed|activated licen[cs]e'
purity "updater survived"           'updatepulse|UpdatePulse|plugin-update-checker|Puc_v'
purity "AI code survived"           'Clara_VE_AI_|clara-ve-ai|clara_ve_ai_|ai-chat|ai-image|ai-video|ai-job|openrouter|OpenRouter'
# Turnstile, in two halves.
#
# Lite must not IMPLEMENT it — no secret, no verify call, no widget, no posted
# response read. But it must still ANSWER for it: a theme converted from static
# HTML carries its own form runtime that calls
# Clara_VE_Form_Settings::turnstile_enabled() the moment the class exists, and
# in 1.27.0 the missing method was a fatal on every public page holding a form.
# So the two stand-down methods in class-form-settings.php are allowed by name,
# and an implementation is refused wherever it appears.
#
# The Get Pro screen is the second allowed file, for the same reason in a
# different shape: it SELLS what Lite does not have, and a feature list that
# will not say the name of the feature is no use to the person reading it. Only
# the naming gate makes the exception — the implementation gate right below it
# covers this file like every other, so a secret, a verifier or a widget landing
# here still refuses the build.
purity "Turnstile survived"         'turnstile' 'includes/class-form-settings\.php:|includes/class-get-pro\.php:'
purity "Turnstile implementation survived" \
  'turnstile_secret|turnstile_ok|cf-turnstile-response|challenges\.cloudflare\.com|OPT_TURNSTILE'
purity "theme export survived"      'Clara_VE_Export_Page|clara_ve_export_theme'
# The export SCREEN going was not the export going. The engine behind it — the
# branch of the bundle writer that copies a theme, stamps its version and checks
# its screenshot — stayed in Lite for as long as only the class names above
# were asked about, with a greyed "Export Theme" item in the menu in front of
# it: a paid feature present in the package and switched off. Lite's bundle
# writer packages content only.
purity "theme export engine survived" "function stamp_version|::stamp_version\(|make-screenshot\.mjs|copy_tree\(|'package' *=>"
# What a person READS. The readme says Lite has no AI writing or image tools,
# so no string shown to anybody may promise one.
purity "AI feature named in a user-visible string" 'AI-generated|AI-edited|turned into video'

# The gate that runs the OTHER way: something that must still be HERE.
#
# Deriving Lite cuts the AI chat and the credits chip out of editor.css, and
# those blocks are not adjacent to each other — so the cut is made by reading
# the file, and one made by span instead takes whatever sits between them. That
# happened: thirty non-AI rules went with it, including .cve-panel, .cve-field,
# .cve-grid and every control inside them, and Lite shipped for two releases
# with the editor panel unstyled. Nothing caught it, because every gate above
# asks what is still present that should be gone, and verify.sh asks whether
# the editor RENDERS — not whether it is styled.
#
# These nine are the panel's skeleton, not a feature list: they are what
# el( 'div', 'cve-field' ) and its neighbours put in the markup on every
# render, and they do not come and go the way features do. A tenth going
# missing would be caught by the same cut taking one of these with it.
#
# Cut CSS by SELECTOR, never by span. A combined rule keeps its non-AI half.
for CLASS in cve-panel cve-field cve-field-label cve-grid cve-num cve-step cve-unit cve-color cve-swatch; do
  grep -qF ".$CLASS" "$SRC/assets/editor.css" \
    || fail "editor.css has no rule for .$CLASS — the CSS derivation over-cut (see the note above)"
done

# The product name itself. Pro hardcodes "Visual Edit Pro" into user-visible
# strings — the admin-bar node and a wp_die() title — and neither carries a
# licence gate, an AI class or any other marker the greps above look for, so
# both shipped in Lite reading "Visual Edit Pro" to anyone using it. Matched as
# a QUOTED literal so the prose that legitimately names Pro (the coexistence
# notice, and comments explaining what Lite was derived from) still passes.
NAMEHITS="$(grep -rn "['\"]Visual Edit Pro['\"]" "$SRC" \
  --include='*.php' --include='*.js' --include='*.css' \
  --exclude-dir=.git --exclude-dir=tools || true)"
# A named, deliberate exception, in the shape of the Turnstile stand-down
# above: the ONE place Lite names Pro on purpose is the screen that sells it,
# plus the test that holds that screen to its word. Filtered by PATH after the
# fact, never by weakening the pattern — the string stays written out in full
# in the source so this grep can still see it, and any other file that starts
# saying "Visual Edit Pro" to a user still fails the build.
NAMEHITS="$(printf '%s\n' "$NAMEHITS" \
  | grep -vE 'includes/class-get-pro\.php:|tests/regression-get-pro\.php:' || true)"
[ -z "$NAMEHITS" ] || { echo "$NAMEHITS" >&2; fail "the Pro product name is still in a user-visible string"; }
grep -rn "Require License\|Update URI" "$MAIN" >/dev/null 2>&1 && fail "forbidden plugin header present"

# ------------------------------------------------- reviewed findings, again ---
# Every finding the directory's reviewers raised against this plugin, re-run
# before every build. The checks live in the submit-checker skill so they grow
# with each review of any plugin; the build refuses if one has come back.
SWEEP="$HOME/.claude/skills/wp-plugin-submit-checker/scripts/regression-sweep.sh"
if [ -x "$SWEEP" ]; then
  "$SWEEP" "$SRC" --prefix=clara_ve >/dev/null 2>&1 || { "$SWEEP" "$SRC" --prefix=clara_ve; fail "a finding a reviewer already raised is back"; }
else
  echo "  (regression sweep not found at $SWEEP — skipped)" >&2
fi

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
  --exclude 'assets-source/' \
  --exclude '*.zip' \
  --exclude 'docs/' \
  --exclude '*.md' \
  "$SRC/" "$STAGE/$SLUG/"

# Plugin Check's file_type rule forbids archives inside a plugin, and the
# obvious way to trip it is to write the output ZIP into the source tree and
# then build again — the second build packs the first one. The rsync exclude
# above is the fix; this is the proof it worked.
if find "$STAGE/$SLUG" \( -name '*.zip' -o -name '*.gz' -o -name '*.rar' -o -name '*.phar' -o -name '*.exe' \) -print -quit | grep -q .; then
  fail "an archive or binary ended up inside the package"
fi
# The package is the plugin, not the repository: readme.txt is the only prose
# that ships. The developer docs, README.md and any planning notes stay on
# GitHub, so a directory reviewer never has to read past what WordPress shows.
if find "$STAGE/$SLUG" -name '*.md' -print -quit | grep -q .; then
  fail "a Markdown file ended up inside the package — only readme.txt ships"
fi

# ---------------------------------------------------------------- channel ---
CHANNEL="${VE_CHANNEL:-wporg}"
case "$CHANNEL" in
  wporg) ;;
  github)
    STAGED_MAIN="$STAGE/$SLUG/$SLUG.php"
    grep -q '^ \* Domain Path: /languages$' "$STAGED_MAIN" || fail "cannot place the Git Updater headers: no Domain Path line"
    awk '{ print } /^ \* Domain Path: \/languages$/ {
           print " * GitHub Plugin URI: iOSDevSK/visual-edit-lite"
           print " * Primary Branch: main"
           print " * Release Asset: true" }' "$STAGED_MAIN" > "$STAGED_MAIN.tmp" && mv "$STAGED_MAIN.tmp" "$STAGED_MAIN"
    [ "$(grep -c '^ \* GitHub Plugin URI: ' "$STAGED_MAIN")" = "1" ] || fail "Git Updater headers were not placed exactly once"
    grep -q 'Update URI' "$STAGED_MAIN" && fail "the github channel must not carry an Update URI either"
    php -l "$STAGED_MAIN" >/dev/null || fail "the packaged main file no longer parses"
    ;;
  *) fail "unknown VE_CHANNEL '$CHANNEL' (wporg or github)";;
esac

mkdir -p "$(dirname "$OUT")"
rm -f "$OUT"
# -X strips extended attributes / resource forks (the __MACOSX source).
( cd "$STAGE" && zip -q -r -X "$OUT" "$SLUG" )

echo "built $OUT (v$VERSION, $CHANNEL channel, $(du -h "$OUT" | cut -f1 | tr -d ' '))"
unzip -l "$OUT" | grep -cE '\.php$|\.js$|\.css$' | xargs echo "  files (php/js/css):"
if unzip -l "$OUT" | grep -qE '__MACOSX|\.DS_Store'; then
  fail "junk in the archive — build is dirty"
fi
echo "  clean: no __MACOSX, no .DS_Store"
echo "  gates: licence, updater, AI, Turnstile, export, wp.org headers, panel CSS — all clear"
