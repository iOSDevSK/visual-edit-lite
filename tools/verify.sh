#!/usr/bin/env bash
# Full verification of a Visual Edit Lite build, end to end, against a real
# WordPress — the gate that decides whether this package may be released or
# submitted to WordPress.org.
#
#   tools/verify.sh [--keep]
#
# What it does, in order:
#   1. builds the ZIP through every static gate in build-plugin.sh
#   2. boots a throwaway WordPress + MariaDB in Docker
#   3. installs THE EXTRACTED PACKAGE (not the working tree) as
#      wp-content/plugins/visual-edit-lite — Plugin Check refuses any other
#      location, and a differently named directory produces a flood of bogus
#      textdomain_mismatch errors
#   4. installs Plugin Check if it is not already there, and fails loudly if
#      it cannot be obtained rather than quietly skipping the check
#   5. runs Plugin Check across EVERY category — any ERROR fails the run
#   6. asserts the things Plugin Check cannot know: that this is actually the
#      Lite edition, that history behaves as specified, and that WordPress
#      logged no notice
#
# --keep leaves the containers running for poking at (http://localhost:8897).
#
# Offline: set PLUGIN_CHECK_ZIP=/path/to/plugin-check.zip and step 4 installs
# from that file instead of wordpress.org.
set -uo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="visual-edit-lite"
NET=velite-verify-net
DB=velite-verify-db
WP=velite-verify-wp
PORT=8897
KEEP=0
[ "${1:-}" = "--keep" ] && KEEP=1

FAILED=0
pass() { printf '  \033[32m✓\033[0m %s\n' "$*"; }
bad()  { printf '  \033[31m✗\033[0m %s\n' "$*"; FAILED=1; }
step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
die()  { printf '\n\033[31mVERIFY ABORTED: %s\033[0m\n' "$*" >&2; exit 2; }

cleanup() {
  if [ "$KEEP" = "1" ]; then
    echo; echo "containers left running: $WP ($PORT), $DB — remove with:"
    echo "  docker rm -f $WP $DB && docker network rm $NET"
  else
    docker rm -f "$WP" "$DB" >/dev/null 2>&1
    docker network rm "$NET" >/dev/null 2>&1
  fi
}
trap cleanup EXIT

# Cheap checks first: no point booting WordPress to learn that a function is
# missing. build-plugin.sh runs this too; here it fails fast and by name.
php "$SRC/tools/check-js-symbols.php" "$SRC"/assets/*.js || die "a called function has no definition"

command -v docker >/dev/null || die "docker is required"
docker info >/dev/null 2>&1 || die "docker is installed but not running"

# ---------------------------------------------------------------- 1. build ---
step "Build (static gates)"
STAGE_ZIP="$(mktemp -d)/$SLUG.zip"
"$SRC/tools/build-plugin.sh" "$STAGE_ZIP" || die "build refused — fix that first"
PKG="$(mktemp -d)"
( cd "$PKG" && unzip -q "$STAGE_ZIP" ) || die "could not unpack the build"
[ -d "$PKG/$SLUG" ] || die "the archive root is not $SLUG/"
pass "package built and unpacked"

# ------------------------------------------------------------ 2. WordPress ---
step "WordPress"
docker rm -f "$WP" "$DB" >/dev/null 2>&1
docker network rm "$NET" >/dev/null 2>&1
docker network create "$NET" >/dev/null || die "could not create the docker network"
docker run -d --name "$DB" --network "$NET" \
  -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp mariadb:11 >/dev/null \
  || die "could not start MariaDB"
docker run -d --name "$WP" --network "$NET" -p "$PORT:80" \
  -e WORDPRESS_DB_HOST="$DB" -e WORDPRESS_DB_USER=root \
  -e WORDPRESS_DB_PASSWORD=root -e WORDPRESS_DB_NAME=wp -e WORDPRESS_DEBUG=1 \
  -e WORDPRESS_CONFIG_EXTRA="define('WP_DEBUG_LOG', true); define('WP_DEBUG_DISPLAY', false); define('FS_METHOD','direct');" \
  wordpress:latest >/dev/null || die "could not start WordPress"

for _ in $(seq 1 60); do
  docker exec "$DB" mariadb -uroot -proot -e 'select 1' wp >/dev/null 2>&1 && break
  sleep 3
done
for _ in $(seq 1 40); do
  docker exec "$WP" test -f /var/www/html/wp-load.php && break
  sleep 3
done
sleep 5

wpcli() {
  docker run --rm --network "$NET" --volumes-from "$WP" -u 33:33 \
    -e WORDPRESS_DB_HOST="$DB" -e WORDPRESS_DB_USER=root \
    -e WORDPRESS_DB_PASSWORD=root -e WORDPRESS_DB_NAME=wp \
    wordpress:cli wp "$@" 2>&1
}

wpcli core install --url="http://localhost:$PORT" --title=VerifyLite \
  --admin_user=admin --admin_password=admin --admin_email=a@example.com \
  --skip-email >/dev/null || die "WordPress would not install"
pass "WordPress $(wpcli core version) up"

# ----------------------------------------------------------- 3. the plugin ---
# The extracted package, under its real slug — see the header comment.
docker cp "$PKG/$SLUG" "$WP:/var/www/html/wp-content/plugins/$SLUG" >/dev/null \
  || die "could not copy the package into the container"
docker exec "$WP" chown -R www-data:www-data "/var/www/html/wp-content/plugins/$SLUG"
docker exec "$WP" rm -f /var/www/html/wp-content/debug.log
wpcli plugin activate "$SLUG" >/dev/null || die "the plugin would not activate"
pass "activated $SLUG $(wpcli plugin get "$SLUG" --field=version)"

# --------------------------------------------------------- 4. Plugin Check ---
step "Plugin Check"
if [ "$(wpcli plugin is-installed plugin-check >/dev/null 2>&1; echo $?)" != "0" ]; then
  if [ -n "${PLUGIN_CHECK_ZIP:-}" ]; then
    [ -f "$PLUGIN_CHECK_ZIP" ] || die "PLUGIN_CHECK_ZIP is set but $PLUGIN_CHECK_ZIP does not exist"
    docker cp "$PLUGIN_CHECK_ZIP" "$WP:/tmp/plugin-check.zip" >/dev/null
    wpcli plugin install /tmp/plugin-check.zip --activate >/dev/null \
      || die "could not install Plugin Check from $PLUGIN_CHECK_ZIP"
    pass "installed Plugin Check from the local ZIP"
  else
    wpcli plugin install plugin-check --activate >/dev/null \
      || die "could not install Plugin Check from wordpress.org — retry online, or download the ZIP from https://wordpress.org/plugins/plugin-check/ and re-run with PLUGIN_CHECK_ZIP=/path/to/plugin-check.zip"
    pass "installed Plugin Check from wordpress.org"
  fi
else
  wpcli plugin activate plugin-check >/dev/null
  pass "Plugin Check already present"
fi
wpcli plugin is-active plugin-check >/dev/null 2>&1 || die "Plugin Check did not activate — the check below would be a lie"
echo "  version $(wpcli plugin get plugin-check --field=version)"

REPORT="$(mktemp -d)"
for CAT in plugin_repo security general performance accessibility; do
  wpcli plugin check "$SLUG" --categories="$CAT" --format=csv \
    --fields=type,code,file,line,message > "$REPORT/$CAT.csv"
  E=$(grep -c '^ERROR' "$REPORT/$CAT.csv"); W=$(grep -c '^WARNING' "$REPORT/$CAT.csv")
  if [ "$E" = "0" ]; then pass "$(printf '%-14s 0 errors, %s warnings' "$CAT" "$W")"
  else bad "$(printf '%-14s %s ERRORS, %s warnings' "$CAT" "$E" "$W")"; fi
done
if [ "$FAILED" = "1" ]; then
  echo; echo "  --- every error ---"
  grep -h '^ERROR' "$REPORT"/*.csv | sort -u | sed 's/^/  /'
fi

# ------------------------------------------------------- 5. Lite assertions ---
# What Plugin Check cannot know: that this is the Lite edition at all, and that
# history behaves the way the product promises.
step "Lite behaviour"
ASSERT=$(wpcli eval '
$out = array();
$out[] = array( "no licence gate", ! function_exists( "clara_ve_is_licensed" ) );
$out[] = array( "no Pro classes", ! ( class_exists( "Clara_VE_AI_Settings" ) || class_exists( "Clara_VE_AI_Chat" ) || class_exists( "Clara_VE_AI_Jobs" ) || class_exists( "Clara_VE_AI_Image" ) || class_exists( "Clara_VE_AI_Video" ) || class_exists( "Clara_VE_Export_Page" ) ) );
$out[] = array( "no Turnstile", ! method_exists( "Clara_VE_Form_Settings", "turnstile_enabled" ) );
$routes = array_keys( rest_get_server()->get_routes() );
$out[] = array( "no ai-* REST routes", 0 === count( preg_grep( "#/ai-#", $routes ) ) );
$out[] = array( "import-image survives", 0 < count( preg_grep( "#import-image#", $routes ) ) );

$key = "verify-" . wp_generate_password( 6, false );
$ids = array();
for ( $i = 1; $i <= 15; $i++ ) {
    $ids[ $i ] = Clara_VE_History::record( "<p>v{$i}</p>", array(), "save", ( 1 === $i ? "Original" : "Save {$i}" ), null, $key );
}
$visible = Clara_VE_History::visible_entries( $key );
$vids    = wp_list_pluck( $visible, "id" );
$out[] = array( "history lists 11 (10 + Original)", 11 === count( $visible ) );
$out[] = array( "Original is the last row", end( $vids ) === $ids[1] );
$out[] = array( "Original stays restorable", true === Clara_VE_History::may_restore( $ids[1], $key ) );
$out[] = array( "an unlisted save is refused", false === Clara_VE_History::may_restore( $ids[5], $key ) );
$out[] = array( "nothing pruned below 300", 15 === count( Clara_VE_History::list_entries( 300, $key ) ) );

wp_set_current_user( 1 );
require_once ABSPATH . "wp-admin/includes/plugin.php";
do_action( "admin_menu" );
global $submenu;
$items = implode( "|", wp_list_pluck( (array) ( isset( $submenu["visual-edit"] ) ? $submenu["visual-edit"] : array() ), 0 ) );
$out[] = array( "no AI Settings menu", false === stripos( $items, "AI Settings" ) );
$out[] = array( "no Export Theme menu", false === stripos( $items, "Export Theme" ) );

ob_start(); Clara_VE_Editor_Page::render(); $html = ob_get_clean();
$out[] = array( "editor renders", 500 < strlen( $html ) );
// The toolbar heading is one of the six user-visible product names, and the
// admin-bar and sidebar checks below do not reach it -- it shipped reading
// "Visual Editor" because nothing looked here.
$out[] = array( "editor toolbar says Visual Edit Lite", false === strpos( $html, "Visual Editor" ) );
$out[] = array( "no AI chat panel in the DOM", false === strpos( $html, "clara-ve-ai-chat" ) );

// Block mode is the reason Lite is worth installing on a Gutenberg theme:
// whole sections can be added, copied, moved and removed there. None of it is
// licence-gated in Pro, so all of it belongs here -- and nothing else in this
// script would notice if a future derivation dropped the classes.
$out[] = array( "block mode ships", class_exists( "Clara_VE_Block_Gate" ) && class_exists( "Clara_VE_Block_Supports" ) );
$out[] = array( "block editing helpers ship", class_exists( "Clara_VE_Block_Stamp" ) && class_exists( "Clara_VE_Block_Patch" ) );
$out[] = array( "motion, patterns and responsive ship", class_exists( "Clara_VE_Motion" ) && class_exists( "Clara_VE_Patterns" ) && class_exists( "Clara_VE_Responsive" ) );
$out[] = array( "the active block theme is recognised as a block theme", function_exists( "wp_is_block_theme" ) && wp_is_block_theme() );

// The product name as a USER sees it. Pro hardcodes "Visual Edit Pro" into
// the admin-bar node, and it shipped that way in Lite because no gate looked
// at a product name and nobody re-rendered the bar after the derivation.
require_once ABSPATH . "wp-includes/class-wp-admin-bar.php";
$bar = new WP_Admin_Bar();
clara_ve_admin_bar_link( $bar );
$node  = $bar->get_node( "clara-visual-edit" );
$title = $node ? trim( wp_strip_all_tags( $node->title ) ) : "";
$out[] = array( "admin bar says \"Visual Edit Lite\" (got: " . $title . ")", "Visual Edit Lite" === $title );

// The sidebar menu, for the same reason: Pro labels it after the screen, so
// the derivation has to rename it and nothing static would notice if it did
// not. $menu rows are [ title, cap, slug, page_title, ... ].
// NOTE: this whole block is inside a single-quoted shell string. An
// apostrophe anywhere in it silently ends that string and the assertions
// stop running -- which is exactly how this comment lost its quotes.
$sidebar = "";
global $menu;
foreach ( (array) $menu as $row ) {
    if ( isset( $row[2] ) && "visual-edit" === $row[2] ) { $sidebar = trim( wp_strip_all_tags( $row[0] ) ); }
}
$out[] = array( "sidebar menu says \"Visual Edit Lite\" (got: " . $sidebar . ")", "Visual Edit Lite" === $sidebar );

// The theme in this container declares no contract, so the incompatibility
// notice must reach the plugin screen. It is gated on the ADMIN PAGE SLUG,
// which is not the text domain -- renaming one into the other made the check
// match nothing and the notice vanished, silently.
set_current_screen( "toplevel_page_visual-edit" );
wp_set_current_user( 1 );
ob_start(); clara_ve_contract_notice(); $notice = ob_get_clean();
// Which notice depends on the theme: a block theme that keeps menus in
// navigation blocks gets the informational one, a non-contract theme of
// ours gets the warning. What is being protected here is neither -- it is
// that the SCREEN GATE still matches, so any notice at all proves it.
$out[] = array( "no-contract notice reaches the plugin screen", false !== strpos( $notice, "notice-" ) );

$out[] = array( "assertion block ran to completion", true );
foreach ( $out as $row ) { echo ( $row[1] ? "OK|" : "FAIL|" ), $row[0], "\n"; }
')
# A fatal inside the eval prints a stack trace, not verdicts. Skipping lines
# that carry no verdict is how a crash once counted as zero failures and the
# script announced VERIFIED over assertions that never ran. Anything that is
# not a verdict is now a failure, and the sentinel proves the block finished.
SAW_SENTINEL=0
while IFS='|' read -r verdict label; do
  [ -z "$verdict" ] && [ -z "$label" ] && continue
  case "$verdict" in
    OK)
      [ "$label" = "assertion block ran to completion" ] && { SAW_SENTINEL=1; continue; }
      pass "$label" ;;
    FAIL) bad "$label" ;;
    *) bad "unexpected output from the assertion block: ${verdict}${label:+|$label}" ;;
  esac
done <<< "$ASSERT"
[ "$SAW_SENTINEL" = "1" ] || bad "the assertion block did not run to completion"


# ------------------------------------------------------------- 6. no noise ---
step "Runtime"
curl -s -o /dev/null -w '' "http://localhost:$PORT/" || true
FRONT=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$PORT/")
[ "$FRONT" = "200" ] && pass "front page 200" || bad "front page $FRONT"
LOG=$(docker exec "$WP" sh -c 'cat /var/www/html/wp-content/debug.log 2>/dev/null')
if [ -z "$LOG" ]; then pass "debug.log empty — no PHP notices"
else bad "WordPress logged something:"; echo "$LOG" | head -20 | sed 's/^/      /'; fi

# ------------------------------------------------------------------ verdict ---
echo
if [ "$FAILED" = "0" ]; then
  printf '\033[32m✓ VERIFIED\033[0m — %s is clean and ready to release/submit.\n' "$SLUG"
  echo "  package: $STAGE_ZIP"
  # Stamp the verified commit so .githooks/pre-push can skip an unchanged
  # re-push. Only a clean tree earns a stamp: a dirty one verified something
  # that is not what a push would send.
  if [ -z "$(git -C "$SRC" status --porcelain 2>/dev/null)" ]; then
    git -C "$SRC" rev-parse HEAD 2>/dev/null | tr -d '\n' > "$SRC/.verified"
  else
    rm -f "$SRC/.verified"
  fi
  exit 0
fi
rm -f "$SRC/.verified"
printf '\033[31m✗ FAILED\033[0m — do not release or submit this build.\n'
exit 1
