import assert from 'node:assert';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

/**
 * The import folder is the one internal name a site's VISITORS read.
 *
 * It sits in the address of every photograph on a converted site, and these
 * themes are sold to people with no connection to the site the pipeline was
 * first written for. So new media goes to `ve-import/`.
 *
 * The old name cannot simply be dropped: every site already running has years
 * of media under it, and `visual-edit.php` decides what is a plugin attachment
 * by that prefix. Reads accept both, writes take the constant — this test is
 * what stops a later cleanup from turning that into a one-way rename and
 * quietly orphaning those libraries.
 *
 * Checked as source text rather than by running PHP: this repo stands up no
 * WordPress, and the property that matters — no literal old name left on a
 * write path — is a property of the source.
 */

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const read = (p) => readFileSync(join(root, p), 'utf8');
const main = read('visual-edit.php');

assert.match(main, /define\(\s*'CLARA_VE_IMPORT_DIR',\s*'ve-import'\s*\)/,
  'new media must be written under ve-import');
assert.match(main, /define\(\s*'CLARA_VE_IMPORT_DIR_LEGACY',\s*'clara-ve-import'\s*\)/,
  'the old folder must still be named, because live sites hold media under it');

// The three helpers every caller goes through, so a new call site cannot
// reintroduce a one-name assumption by accident.
for (const fn of ['clara_ve_import_dirs', 'clara_ve_is_import_path', 'clara_ve_strip_import_dir']) {
  assert.ok(main.includes(`function ${fn}(`), `${fn}() must exist`);
}

// Reading: the scan that decides which attachments belong to the plugin must
// go through the helper. A `strpos( $relative, 'clara-ve-import/' )` here is
// the exact line that used to make the old name load-bearing.
assert.ok(main.includes('clara_ve_is_import_path( $relative )'),
  'the attachment scan must accept either folder name');

const files = [
  'visual-edit.php',
  'includes/class-import-plan.php',
  'includes/class-import-legacy.php',
  'includes/class-theme-purge.php',
  'includes/class-import-page.php',
];
for (const f of files) {
  const stray = read(f).split('\n')
    .map((line, i) => [i + 1, line])
    // The one line allowed to name it is the constant that keeps live sites
    // readable; everything else naming it is a write path that would orphan them.
    .filter(([, line]) => line.includes("'clara-ve-import") && !line.includes('IMPORT_DIR_LEGACY'))
    .map(([n, line]) => `${f}:${n} ${line.trim().slice(0, 70)}`);
  assert.deepEqual(stray, [], `no write path may name the old folder literally:\n  ${stray.join('\n  ')}`);
}

// The purge has to sweep BOTH, or a site converted before the rename keeps
// orphan files after its theme is removed.
assert.match(read('includes/class-theme-purge.php'), /foreach \(\s*clara_ve_import_dirs\(\)/,
  'purge must cover every import folder name, not only the current one');

console.log('PASS: plugin import folder naming regression');
