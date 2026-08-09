<?php
// Minimal, tokenizer-based POT generator for this plugin's i18n calls.
$domain = 'visual-edit-lite';
$forms  = array(
    '__' => array('s'=>0,'n'=>null,'c'=>null,'d'=>1),
    '_e' => array('s'=>0,'n'=>null,'c'=>null,'d'=>1),
    'esc_html__' => array('s'=>0,'n'=>null,'c'=>null,'d'=>1),
    'esc_html_e' => array('s'=>0,'n'=>null,'c'=>null,'d'=>1),
    'esc_attr__' => array('s'=>0,'n'=>null,'c'=>null,'d'=>1),
    'esc_attr_e' => array('s'=>0,'n'=>null,'c'=>null,'d'=>1),
    '_x' => array('s'=>0,'n'=>null,'c'=>1,'d'=>2),
    'esc_html_x' => array('s'=>0,'n'=>null,'c'=>1,'d'=>2),
    '_n' => array('s'=>0,'n'=>1,'c'=>null,'d'=>3),
);
$entries = array();
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.', FilesystemIterator::SKIP_DOTS));
$files = array();
foreach ($rii as $f) {
    $p = $f->getPathname();
    if (substr($p, -4) !== '.php') continue;
    if (strpos($p, '/.git/') !== false || strpos($p, '/tests/') !== false || strpos($p, '/tools/') !== false) continue;
    $files[] = $p;
}
sort($files);
foreach ($files as $file) {
    $rel = ltrim(str_replace('\\', '/', $file), './');
    $t = token_get_all(file_get_contents($file));
    $n = count($t);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($t[$i]) || $t[$i][0] !== T_STRING || !isset($forms[$t[$i][1]])) continue;
        // must be a call, and not a method/property access
        $prev = $i - 1;
        while ($prev >= 0 && is_array($t[$prev]) && in_array($t[$prev][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) $prev--;
        if ($prev >= 0 && is_array($t[$prev]) && in_array($t[$prev][0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION), true)) continue;
        $j = $i + 1;
        while ($j < $n && is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) $j++;
        if ($j >= $n || $t[$j] !== '(') continue;
        // collect top-level string arguments
        $depth = 0; $args = array(); $cur = null; $simple = true;
        for ($k = $j; $k < $n; $k++) {
            $tok = $t[$k];
            if ($tok === '(') { $depth++; if ($depth === 1) { $cur = array(); continue; } }
            if ($tok === ')') { $depth--; if ($depth === 0) { $args[] = $cur; break; } }
            if ($depth === 1 && $tok === ',') { $args[] = $cur; $cur = array(); continue; }
            if ($depth >= 1) $cur[] = $tok;
        }
        $line = $t[$i][2];
        $spec = $forms[$t[$i][1]];
        $lit = function ($arg) use (&$simple) {
            if ($arg === null) return null;
            $out = ''; $seen = false;
            foreach ($arg as $tk) {
                if (is_array($tk) && $tk[0] === T_WHITESPACE) continue;
                if (is_array($tk) && $tk[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $s = $tk[1]; $q = $s[0]; $s = substr($s, 1, -1);
                    $s = $q === "'" ? str_replace(array("\\'", '\\\\'), array("'", '\\'), $s) : stripcslashes($s);
                    $out .= $s; $seen = true; continue;
                }
                if ($tk === '.') continue;
                return null; // not a plain literal
            }
            return $seen ? $out : null;
        };
        $dom = isset($args[$spec['d']]) ? $lit($args[$spec['d']]) : null;
        if ($dom !== $domain) continue;
        $single = isset($args[$spec['s']]) ? $lit($args[$spec['s']]) : null;
        if ($single === null) continue;
        $ctx = ($spec['c'] !== null && isset($args[$spec['c']])) ? $lit($args[$spec['c']]) : null;
        $plural = ($spec['n'] !== null && isset($args[$spec['n']])) ? $lit($args[$spec['n']]) : null;
        $key = ($ctx === null ? '' : $ctx . "\4") . $single . ($plural === null ? '' : "\0" . $plural);
        if (!isset($entries[$key])) $entries[$key] = array('ctx'=>$ctx,'single'=>$single,'plural'=>$plural,'refs'=>array());
        $entries[$key]['refs'][] = $rel . ':' . $line;
    }
}
$esc = function ($s) {
    $s = str_replace(array('\\', '"', "\t"), array('\\\\', '\\"', '\\t'), $s);
    return str_replace("\n", '\\n', $s);
};
$out  = "# Copyright (C) 2026 Filip Dvoran\n";
$out .= "# This file is distributed under the GPL-2.0-or-later.\n";
$out .= "msgid \"\"\nmsgstr \"\"\n";
$out .= "\"Project-Id-Version: Visual Edit Lite 1.19.6\\n\"\n";
$out .= "\"Report-Msgid-Bugs-To: https://github.com/iOSDevSK/visual-edit-lite/issues\\n\"\n";
$out .= "\"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n\"\n";
$out .= "\"Language-Team: LANGUAGE <LL@li.org>\\n\"\n";
$out .= "\"MIME-Version: 1.0\\n\"\n";
$out .= "\"Content-Type: text/plain; charset=UTF-8\\n\"\n";
$out .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
$out .= "\"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n\"\n";
$out .= "\"Plural-Forms: nplurals=2; plural=(n != 1);\\n\"\n";
$out .= "\"X-Domain: $domain\\n\"\n";
foreach ($entries as $e) {
    $out .= "\n";
    foreach ($e['refs'] as $r) $out .= "#: $r\n";
    if ($e['ctx'] !== null) $out .= 'msgctxt "' . $esc($e['ctx']) . "\"\n";
    $out .= 'msgid "' . $esc($e['single']) . "\"\n";
    if ($e['plural'] !== null) {
        $out .= 'msgid_plural "' . $esc($e['plural']) . "\"\n";
        $out .= "msgstr[0] \"\"\nmsgstr[1] \"\"\n";
    } else {
        $out .= "msgstr \"\"\n";
    }
}
file_put_contents('languages/visual-edit-lite.pot', $out);
echo count($entries), " strings\n";
