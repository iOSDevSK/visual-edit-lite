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
php "$SRC/tests/native-history.php" || die "native history adapter/storage regression"
if command -v node >/dev/null; then
  node "$SRC/tests/popup-values.mjs" || die "shared popup values regression"
  node "$SRC/tests/workspace-model.mjs" || die "workspace model regression"
  node "$SRC/tests/form-css-congruence.mjs" || die "form CSS editor/site congruence regression"
  node "$SRC/tests/runtime-delegation.mjs" || die "theme runtime delegation regression"
  # The rest of the dependency-free node suite. Left out of the gate for a
  # while, which is how tests/import-dir-names.mjs sat broken from the day the
  # plugin file was renamed: nothing ran it. A test not in the gate is a test
  # that will rot.
  node "$SRC/tests/collection-congruence.mjs" || die "collection congruence regression"
  node "$SRC/tests/collection-editor.mjs" || die "collection editor regression"
  node "$SRC/tests/import-dir-names.mjs" || die "import folder naming regression"
  node "$SRC/tests/parked-heal.mjs" || die "parked-set derivation regression"
  # The dark sweep needs a real renderer, so it runs only when a node_modules
  # with playwright is pointed at (NM=…). Skipped rather than silently passed.
  if [ -n "${NM:-}" ] && [ -d "$NM/playwright" ]; then
    node "$SRC/tests/workspace-dark-sweep.cjs" "$NM" || die "dark sweep regression — a control in the popup is unreadable"
  else
    echo "  (skipped: dark sweep needs NM=/path/to/node_modules with playwright)"
  fi
  # form-convert.cjs, workspace-popup.cjs, ve-api.cjs and workspace-history.cjs
  # need jsdom: run them by hand with a node_modules path.
fi

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
// Not "no Turnstile" — that assertion was true and wrong. A converted theme
// calls turnstile_enabled() by name whenever this class is loaded, so the
// method absent is a fatal on its public pages, which is what 1.27.0 shipped
// for an hour. What Lite must not have is an IMPLEMENTATION: the method is
// here, it stands down, and there is no secret and no verifier behind it.
$out[] = array( "Turnstile stands down, not missing", method_exists( "Clara_VE_Form_Settings", "turnstile_enabled" ) && false === Clara_VE_Form_Settings::turnstile_enabled() && "" === Clara_VE_Form_Settings::turnstile_site_key() );
$out[] = array( "no Turnstile implementation", ! method_exists( "Clara_VE_Form_Settings", "turnstile_secret" ) && ! method_exists( "Clara_VE_Forms", "turnstile_ok" ) );
$routes = array_keys( rest_get_server()->get_routes() );
$out[] = array( "no ai-* REST routes", 0 === count( preg_grep( "#/ai-#", $routes ) ) );
$out[] = array( "import-image survives", 0 < count( preg_grep( "#import-image#", $routes ) ) );

$key = "verify-" . wp_generate_password( 6, false );
$ids = array();
for ( $i = 1; $i <= 15; $i++ ) {
    $ids[ $i ] = Clara_VE_History::record( "<p>v{$i}</p>", array(), "save", ( 1 === $i ? "Original" : "Save {$i}" ), null, $key );
}
$listed = Clara_VE_History::list_entries( 300, $key );
$lids   = wp_list_pluck( $listed, "id" );
$out[] = array( "history keeps 11 (10 + Original)", 11 === count( $listed ) );
$out[] = array( "Original is the last row", end( $lids ) === $ids[1] );
$out[] = array( "Original stays restorable", is_array( Clara_VE_History::get( $ids[1], $key ) ) );
// Guideline 5: what is stored is what is listed, and what is listed can be
// restored. A row kept in the table but withheld from the panel is a limit a
// payment lifts, whatever it is called -- so there must be no such row and no
// method whose job is to refuse one.
$restorable = array_filter( $lids, function ( $id ) use ( $key ) { return is_array( Clara_VE_History::get( $id, $key ) ); } );
$out[] = array( "every listed save can be restored", count( $restorable ) === count( $lids ) );
$out[] = array( "no restore gate", ! method_exists( "Clara_VE_History", "may_restore" ) && ! method_exists( "Clara_VE_History", "visible_entries" ) );

wp_set_current_user( 1 );
require_once ABSPATH . "wp-admin/includes/plugin.php";
do_action( "admin_menu" );
global $submenu;
$slugs = wp_list_pluck( (array) ( isset( $submenu["visual-edit"] ) ? $submenu["visual-edit"] : array() ), 2 );
// Checked by SLUG: what must be absent is the real screen, and a screen is
// its slug.
$out[] = array( "no real AI Settings screen", ! in_array( "visual-edit-ai", $slugs, true ) );
$out[] = array( "no real Export Theme screen", ! in_array( "visual-edit-export", $slugs, true ) );
$out[] = array( "the upsell is one plain item, and the last one", "visual-edit-lite-pro" === end( $slugs ) && 1 === count( preg_grep( "#^visual-edit-lite-|html2wp#", $slugs ) ) );

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
$out[] = array( "native Gutenberg integration ships", class_exists( "Clara_VE_Native_Gutenberg" ) );
$out[] = array( "block themes use native Gutenberg", Clara_VE_Native_Gutenberg::is_native_mode() );
$out[] = array( "native Gutenberg sidebar is registered", false !== has_action( "enqueue_block_editor_assets", array( "Clara_VE_Native_Gutenberg", "enqueue" ) ) );
$out[] = array( "native SEO route ships", in_array( "/clara-ve/v1/native/seo/(?P<post>\\d+)", $routes, true ) );
$responsive_meta = get_registered_meta_keys( "post" );
$out[] = array( "responsive data is available to Gutenberg", ! empty( $responsive_meta[Clara_VE_Responsive::META]["show_in_rest"] ) );
$out[] = array( "responsive data participates in core revisions", ! empty( $responsive_meta[Clara_VE_Responsive::META]["revisions_enabled"] ) );
$responsive_probe = Clara_VE_Responsive::sanitize_meta( wp_json_encode( array(
    "cve-r-abcd1234" => array( "mobile" => array(
        "spacing.padding.top" => "12px",
        "typography.fontSize" => "18px;body{display:none}",
    ) ),
) ) );
$responsive_probe = json_decode( $responsive_probe, true );
$out[] = array( "native responsive meta keeps valid values", isset( $responsive_probe["cve-r-abcd1234"]["mobile"]["spacing.padding.top"] ) );
$out[] = array( "native responsive meta rejects CSS injection", ! isset( $responsive_probe["cve-r-abcd1234"]["mobile"]["typography.fontSize"] ) );

// The product name as a USER sees it. Pro hardcodes "Visual Edit Pro" into
// the admin-bar node, and it shipped that way in Lite because no gate looked
// at a product name and nobody re-rendered the bar after the derivation.
require_once ABSPATH . "wp-includes/class-wp-admin-bar.php";
$bar = new WP_Admin_Bar();
clara_ve_admin_bar_link( $bar );
$node  = $bar->get_node( "clara-visual-edit" );
$title = $node ? trim( wp_strip_all_tags( $node->title ) ) : "";
$out[] = array( "admin bar says \"Visual Edit Lite\" (got: " . $title . ")", "Visual Edit Lite" === $title );
$out[] = array( "admin bar opens the VE workspace on a block theme", $node && false !== strpos( $node->href, "page=visual-edit" ) );
$out[] = array( "block themes render the runtime host", false !== strpos( $html, "cve-workspace-frame" ) );
$out[] = array( "portable block extras ship", class_exists( "Clara_VE_Block_Extras" ) );
$paragraph_type = WP_Block_Type_Registry::get_instance()->get_registered( "core/paragraph" );
$out[] = array( "block extras schema reaches registered core blocks", isset( $paragraph_type->attributes["claraVe"] ) );

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


# ----------------------------------------------------------- 5b. form blocks ---
step "Form blocks"
docker cp "$SRC/tests/form-blocks-wp.php" "$WP:/tmp/form-blocks-wp.php" >/dev/null
if docker exec "$WP" php /tmp/form-blocks-wp.php /var/www/html; then pass "form blocks render, submit safely and survive the plugin going away"
else bad "form blocks regression"; fi

# ------------------------------------------------ 5b2. other plugins' forms ---
step "Other plugins' forms"
docker cp "$SRC/tests/form-connect-wp.php" "$WP:/tmp/form-connect-wp.php" >/dev/null
if docker exec -e AJAX_URL=http://localhost/wp-admin/admin-ajax.php "$WP" php /tmp/form-connect-wp.php /var/www/html; then pass "a connected Kadence form is stored, emailed to the chosen address and author-gated"
else bad "form connect regression"; fi

# ------------------------------------------ 5b3. Custom HTML to native blocks ---
# What a theme ships as one Custom HTML block becomes the blocks it stands for,
# written as each block's save() writes them; what has no block stays byte for byte.
step "Custom HTML to native blocks"
CONVERT_OUT=$(mktemp)
docker cp "$SRC/tests/regression-block-convert.php" "$WP:/tmp/regression-block-convert.php" >/dev/null
if docker exec -u www-data "$WP" php -r 'define("WP_USE_THEMES", false); $_SERVER["HTTP_HOST"] = "localhost"; require "/var/www/html/wp-load.php"; wp_set_current_user( 1 ); require "/tmp/regression-block-convert.php";' > "$CONVERT_OUT" 2>&1; then pass "sections convert to valid native blocks, embeds stay, sections added on the server convert"
else sed -n '/FAIL/p' "$CONVERT_OUT" | head -5; bad "Custom HTML conversion regression"; fi

# ------------------------------------------------------------- 5b4. sections ---
# What a page may be built out of: the theme's own sections and the ones saved
# from this site, one list with one set of rules. The regression was in no gate
# until now, and it is the one that decides whether a wp_block post nobody can
# edit ends up in front of somebody assembling a page.
step "Sections"
PATTERNS_OUT=$(mktemp)
docker cp "$SRC/tests/regression-patterns.php" "$WP:/tmp/regression-patterns.php" >/dev/null
if docker exec -u www-data "$WP" php -r 'define("WP_USE_THEMES", false); $_SERVER["HTTP_HOST"] = "localhost"; require "/var/www/html/wp-load.php"; wp_set_current_user( 1 ); require "/tmp/regression-patterns.php";' > "$PATTERNS_OUT" 2>&1; then pass "$(tail -1 "$PATTERNS_OUT")"
else sed -n '/FAIL/p' "$PATTERNS_OUT" | head -5; bad "saved sections regression"; fi

# ---------------------------------------------------------------- 5b5. Get Pro ---
# The upsell: one plain item at the end of the menu, one page behind it, nothing
# on any other admin screen, and none of it on a site that already has Pro.
# The last part boots a second WordPress from inside the test, because the
# stand-down is a file-scope return that an already-booted process is past.
step "Get Pro"
GETPRO_OUT=$(mktemp)
docker cp "$SRC/tests/regression-get-pro.php" "$WP:/tmp/regression-get-pro.php" >/dev/null
if docker exec -u www-data "$WP" php -r 'define("WP_USE_THEMES", false); $_SERVER["HTTP_HOST"] = "localhost"; require "/var/www/html/wp-load.php"; wp_set_current_user( 1 ); require "/tmp/regression-get-pro.php";' > "$GETPRO_OUT" 2>&1; then pass "$(tail -1 "$GETPRO_OUT")"
else sed -n '/FAIL/p' "$GETPRO_OUT" | head -8; bad "Get Pro regression"; fi

# --------------------------------------------------- 5c. the theme contract ---
# A converted theme's own runtime delegates to this plugin whenever the class
# is loaded. A method it calls and this edition lacks is a fatal on the PUBLIC
# page, not a missing feature — and nothing else in this gate can see it,
# because the gate renders no converted theme.
step "Theme contract"
docker cp "$SRC/tests/theme-contract-api.php" "$WP:/tmp/theme-contract-api.php" >/dev/null
if docker exec "$WP" php /tmp/theme-contract-api.php /var/www/html; then pass "every method a converted theme's runtime delegates to exists"
else bad "a converted theme would fatal the public page"; fi

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
