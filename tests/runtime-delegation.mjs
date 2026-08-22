import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const source = await readFile(new URL('../visual-edit.php', import.meta.url), 'utf8');

assert.match(source, /define\( 'CLARA_VE_THEME_RUNTIME_DELEGATION', 1 \)/);
assert.match(source, /function clara_ve_theme_owns_public_runtime\(\)/);
assert.match(source, /isset\( \$entry\['schema'\] \).*\(int\) \$entry\['schema'\] >= 1/);
assert.match(source, /function clara_ve_delegate_public_runtime_to_theme\(\)/);
assert.match(source, /remove_filter\( 'render_block_core\/html', array\( 'Clara_VE_Tokens'/);
assert.match(source, /remove_action\( 'rest_api_init', array\( 'Clara_VE_Forms', 'register_routes' \)/);
assert.match(source, /add_action\( 'after_setup_theme', 'clara_ve_delegate_public_runtime_to_theme', 12 \)/);
assert.match(source, /function clara_ve_enhance_theme_form\( \$handled, \$context \)/);
assert.match(source, /add_filter\( 'html2wp_theme_form_handle', 'clara_ve_enhance_theme_form', 10, 2 \)/);

console.log('PASS: plugin standalone runtime delegation regression');
