import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

// The parked set must be derived from the CONTENT as well as the registry
// flag. Park writes statuses first and the flag second, and the lifecycle is
// handed back and forth with a converted theme every time this plugin is
// activated or deactivated — so a handover mid-park leaves the flag saying
// "live" for content that is away. Every filter that hides a put-away theme's
// menus, terms and attachments reads that set: on a site with three converted
// themes it showed as every theme's menus appearing in every other theme.
const source = await readFile(new URL('../includes/class-theme-park.php', import.meta.url), 'utf8');

const parkedThemes = source.slice(
  source.indexOf('public static function parked_themes()'),
  source.indexOf('private static $parked_memo')
);
assert.notEqual(parkedThemes.length, 0, 'parked_themes() not found');

// The registry flag is still read...
assert.match(parkedThemes, /\$record\['parked'\]/, 'the registry flag is no longer consulted');
// ...and so is the content itself.
assert.match(
  parkedThemes,
  /SELECT DISTINCT pm\.meta_value[\s\S]*post_status = %s/,
  'the parked set no longer reads the content, only the flag'
);
assert.match(
  parkedThemes,
  /CLARA_VE_PAGE_THEME_META[\s\S]*CLARA_VE_PARKED_STATUS/,
  'the derived query must be bound to the ownership meta and the parked status'
);
// The active theme is never in the set: during its own restore its content is
// briefly still parked, and hiding a theme's menus from itself is the one
// answer always wrong.
assert.match(
  parkedThemes,
  /array_diff\( \$parked, array\( sanitize_key\( get_stylesheet\(\) \) \) \)/,
  'the active theme must be excluded from the parked set'
);

// park() must keep asking the registry directly — deriving there would make a
// theme whose content is already away un-parkable.
assert.match(
  source,
  /public static function is_parked\( \$theme \) \{\s*\$record = Clara_VE_Theme_Registry::get\( \$theme \);/,
  'is_parked() must read the registry, not the derived set'
);

console.log('PASS: parked set derived from content, not the flag alone');
