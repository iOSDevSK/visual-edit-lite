<?php
/**
 * Report functions that are CALLED but never DEFINED in a JavaScript file.
 *
 *   php tools/check-js-symbols.php assets/editor.js assets/bridge.js
 *
 * `node --check` proves a file PARSES; it says nothing about whether the
 * functions it calls still exist. Deriving Lite from Pro deletes whole
 * sections of editor.js, and three calls to functions that went with them
 * survived the first attempt — one of which (`setAiOpen`) threw on every
 * click of the Search-appearance button, leaving the panel stuck on
 * "Loading…". A hand-written list of symbols to grep for is what missed
 * them; this asks the file itself.
 *
 * Deliberately conservative: string literals, comments, member calls
 * (`a.b()`), parameter names and every browser/WordPress global in the list
 * below are excluded, so a hit means a genuinely missing definition rather
 * than a style this parser does not understand. It is a regex pass, not a
 * JS engine — new syntax may need teaching.
 *
 * Exit code 1 if anything is missing.
 */

/**
 * Blank out comments, string/template literals and regex literals, preserving
 * newlines so reported line numbers stay true.
 *
 * @param string $src
 * @return string
 */
function clara_ve_strip_js( $src ) {
	$out  = '';
	$len  = strlen( $src );
	$prev = ''; // last significant character emitted, for the regex/divide test
	for ( $i = 0; $i < $len; $i++ ) {
		$c    = $src[ $i ];
		$next = $i + 1 < $len ? $src[ $i + 1 ] : '';

		if ( '/' === $c && '/' === $next ) {
			while ( $i < $len && "\n" !== $src[ $i ] ) {
				$i++;
			}
			$out .= "\n";
			continue;
		}
		if ( '/' === $c && '*' === $next ) {
			$i += 2;
			while ( $i < $len && ! ( '*' === $src[ $i ] && $i + 1 < $len && '/' === $src[ $i + 1 ] ) ) {
				if ( "\n" === $src[ $i ] ) {
					$out .= "\n";
				}
				$i++;
			}
			$i++; // land on the closing slash; the loop's $i++ passes it
			continue;
		}
		if ( "'" === $c || '"' === $c || '`' === $c ) {
			$quote = $c;
			$i++;
			while ( $i < $len && $src[ $i ] !== $quote ) {
				if ( '\\' === $src[ $i ] ) {
					$i++;
				} elseif ( "\n" === $src[ $i ] ) {
					$out .= "\n"; // template literals span lines
				}
				$i++;
			}
			$out .= '""';
			$prev = '"';
			continue;
		}
		// A slash starts a REGEX unless it follows something a value can end
		// with, in which case it is division.
		if ( '/' === $c && ! preg_match( '/[\w$)\]]/', $prev ) ) {
			$i++;
			$in_class = false;
			while ( $i < $len ) {
				$d = $src[ $i ];
				if ( '\\' === $d ) {
					$i += 2;
					continue;
				}
				if ( '[' === $d ) {
					$in_class = true;
				} elseif ( ']' === $d ) {
					$in_class = false;
				} elseif ( '/' === $d && ! $in_class ) {
					break;
				} elseif ( "\n" === $d ) {
					break; // unterminated — treat as division after all
				}
				$i++;
			}
			$out .= '0';
			$prev = '0';
			continue;
		}

		$out .= $c;
		if ( '' !== trim( $c ) ) {
			$prev = $c;
		}
	}
	return $out;
}

$files = array_slice( $argv, 1 );
if ( ! $files ) {
	fwrite( STDERR, "usage: php tools/check-js-symbols.php <file.js> [...]\n" );
	exit( 2 );
}

// Provided by the browser, WordPress, or the language itself.
$globals = array(
	'if', 'for', 'while', 'switch', 'catch', 'return', 'typeof', 'function', 'var', 'new',
	'delete', 'void', 'in', 'instanceof', 'do', 'else', 'try', 'throw', 'let', 'const',
	'Array', 'Object', 'String', 'Number', 'Boolean', 'Math', 'JSON', 'Date', 'RegExp',
	'Promise', 'Error', 'Map', 'Set', 'WeakMap', 'Symbol', 'parseInt', 'parseFloat',
	'isNaN', 'isFinite', 'encodeURIComponent', 'decodeURIComponent', 'encodeURI',
	'decodeURI', 'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval',
	'requestAnimationFrame', 'cancelAnimationFrame', 'alert', 'confirm', 'prompt',
	'fetch', 'window', 'document', 'console', 'navigator', 'location', 'getComputedStyle',
	'MutationObserver', 'IntersectionObserver', 'ResizeObserver', 'CustomEvent', 'Event',
	'FormData', 'URL', 'URLSearchParams', 'DOMParser', 'XMLHttpRequest', 'Image', 'Blob',
	'FileReader', 'structuredClone', 'queueMicrotask', 'wp', 'jQuery', 'lodash',
);

$failed = false;

foreach ( $files as $file ) {
	if ( ! is_readable( $file ) ) {
		fwrite( STDERR, "cannot read $file\n" );
		exit( 2 );
	}
	$code = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	// Comments and literals have to come out in ONE left-to-right pass, not as
	// a series of independent regexes: strip single quotes first and the
	// apostrophe in a double-quoted "it's" opens a string that swallows code
	// until the next apostrophe — which is how the first version of this file
	// reported two dozen defined functions as missing. So: a small scanner
	// that tracks what it is inside.
	$code = clara_ve_strip_js( $code );

	$defined = array();
	// function foo(…)
	preg_match_all( '/function\s+([A-Za-z_$][\w$]*)/', $code, $m );
	$defined = array_merge( $defined, $m[1] );
	// var foo = …  /  foo = function  /  let|const foo =
	preg_match_all( '/(?:var|let|const)\s+([A-Za-z_$][\w$]*)/', $code, $m );
	$defined = array_merge( $defined, $m[1] );
	preg_match_all( '/([A-Za-z_$][\w$]*)\s*=\s*function/', $code, $m );
	$defined = array_merge( $defined, $m[1] );
	// Parameters — a callback invoked by name inside its own scope is defined
	// for our purposes.
	preg_match_all( '/function\s*[A-Za-z_$\w$]*\s*\(([^)]*)\)/', $code, $m );
	foreach ( $m[1] as $params ) {
		foreach ( explode( ',', $params ) as $param ) {
			$param = trim( $param );
			if ( '' !== $param && preg_match( '/^[A-Za-z_$][\w$]*$/', $param ) ) {
				$defined[] = $param;
			}
		}
	}
	$defined = array_flip( array_merge( $defined, $globals ) );

	// Calls: `name(` not preceded by a dot (member call) or a word character.
	preg_match_all( '/(?<![.\w$])([A-Za-z_$][\w$]*)\s*\(/', $code, $m );
	$missing = array();
	foreach ( array_unique( $m[1] ) as $name ) {
		// Constructors and namespaced globals are someone else's problem.
		if ( ctype_upper( $name[0] ) ) {
			continue;
		}
		if ( ! isset( $defined[ $name ] ) ) {
			$missing[] = $name;
		}
	}

	if ( $missing ) {
		$failed = true;
		sort( $missing );
		fwrite( STDERR, "$file calls functions that are not defined anywhere in it:\n" );
		foreach ( $missing as $name ) {
			preg_match_all( '/(?<![.\w$])' . preg_quote( $name, '/' ) . '\s*\(/', $code, $x, PREG_OFFSET_CAPTURE );
			$lines = array();
			foreach ( $x[0] as $hit ) {
				$lines[] = substr_count( substr( $code, 0, $hit[1] ), "\n" ) + 1;
			}
			fwrite( STDERR, sprintf( "  %-24s line(s) %s\n", $name, implode( ', ', $lines ) ) );
		}
	} else {
		echo basename( $file ), ": every called function is defined\n";
	}
}

exit( $failed ? 1 : 0 );
