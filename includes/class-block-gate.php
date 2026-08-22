<?php
/**
 * The validation gate every write of BLOCK markup passes through.
 *
 * Raw-HTML saves are not gated here and never will be: they have their own
 * check in Clara_VE_Source_Store::validate_shape(), and adding rejection paths
 * to the themes this plugin has always edited is the one thing block mode may
 * not do. This file exists because block markup fails in ways HTML does not —
 * silently, at parse time, with balanced comments and balanced tags — and the
 * failure is only visible once WordPress has already stored it.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Four checks, applied per write path rather than uniformly, because they are
 * not all meaningful everywhere. See check() for the matrix.
 */
class Clara_VE_Block_Gate {

	const CODE = 'clara_ve_block_gate';

	/**
	 * Anything that LOOKS like it was meant to be a block delimiter — cast
	 * wide on purpose, so a malformed one is caught rather than skipped for
	 * not matching the real grammar.
	 */
	const CANDIDATE = '~<!--\s*/?\s*wp:[^>]*?-->~s';

	/**
	 * What WordPress will actually accept, transcribed from WP_Block_Parser.
	 *
	 * The whitespace in it is not decoration. `<!-- wp:paragraph {"a":1}-->`
	 * — no space before the arrow — is not a delimiter at all: WordPress reads
	 * the paragraph as freeform HTML, the matching `<!-- /wp:paragraph -->`
	 * then closes the wrong thing, and every block after it nests one level
	 * too deep. Converting the reference theme produced nineteen of these
	 * across fifteen pages, and one folded an entire page into a sticky panel
	 * 4,794px tall. Nothing else catches it: the comments balance, the HTML
	 * balances, and the fault appears only once WordPress parses the result.
	 */
	const GRAMMAR = '~^<!--\s+(?P<closer>/)?wp:(?P<namespace>[a-z][a-z0-9_-]*/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>\{(?:(?!\}\s+/?-->).)*?\}\s+)?(?P<void>/)?-->$~s';

	/**
	 * Judge a write.
	 *
	 * |                        | patch | AI edit      | AI create | restore |
	 * | delimiter grammar      |  yes  | new ones     | whole doc |  yes    |
	 * | output idempotency     |  yes  |  —           |  —        |  yes    |
	 * | structural invariance  |  yes  | conditional  |  —        |  —      |
	 * | per-block schema       |  yes  | new ones     | whole doc |  yes    |
	 *
	 * Idempotency is only meaningful where $out came out of serialize_blocks();
	 * an AI edit is a text substitution on markup a human or an importer may
	 * have written, whose delimiter JSON spacing PHP would re-encode
	 * differently, so requiring it there would reject correct edits. Creation
	 * is canonicalized instead — see canonicalize().
	 *
	 * Edits are judged on the DELTA, not the document. A page may already
	 * carry a malformed delimiter or a dirty image from before this plugin
	 * ever saw it; failing a one-word edit for a violation it did not
	 * introduce is a gate nobody can get past.
	 *
	 * @param string      $out  The markup about to be written.
	 * @param string|null $in   What is stored now, for delta and structure
	 *                          comparison. Null when there is no before.
	 * @param array       $args {
	 *     @type string $context                'patch'|'edit'|'create'|'restore'.
	 *     @type bool   $allow_structure_change Waive the invariance check —
	 *                                          the caller knows the write is
	 *                                          deliberately restructuring.
	 * }
	 * @return true|WP_Error
	 */
	public static function check( $out, $in = null, $args = array() ) {
		$out     = (string) $out;
		$in      = is_string( $in ) ? $in : null;
		$context = isset( $args['context'] ) ? (string) $args['context'] : 'edit';
		$whole   = ( 'create' === $context || null === $in );

		if ( '' === trim( $out ) ) {
			return self::reject( 'empty', __( 'Refusing to write an empty document.', 'visual-edit-lite' ) );
		}

		// --- 1: delimiter grammar -------------------------------------------
		$bad = self::added(
			self::malformed_delimiters( $out ),
			$whole ? array() : self::malformed_delimiters( $in )
		);
		if ( $bad ) {
			return self::reject(
				'delimiters',
				sprintf(
					/* translators: 1: number of delimiters, 2: the first malformed delimiter and why. */
					_n(
						'%1$d block delimiter is malformed and WordPress would not read it as a block: %2$s',
						'%1$d block delimiters are malformed and WordPress would not read them as blocks: %2$s',
						count( $bad ),
						'visual-edit-lite'
					),
					count( $bad ),
					reset( $bad )
				),
				array( 'delimiters' => array_values( $bad ) )
			);
		}

		// --- 2: the output survives its own serializer -----------------------
		if ( in_array( $context, array( 'patch', 'restore' ), true ) && ! self::is_idempotent( $out ) ) {
			return self::reject(
				'idempotency',
				__( 'The composed block markup does not survive a parse/serialize round trip — refusing to write it.', 'visual-edit-lite' )
			);
		}

		// --- 3: the document's delimiters still balance -----------------------
		// Applies in every context, waiver or none. Restructuring is a thing a
		// caller may declare; leaving a block unclosed is not. An unclosed
		// opener does not remove a block — the parser closes it at the end of
		// the document, so everything after it becomes its CHILD, which
		// renders almost right and reads as a page that folded into itself.
		// Judged as a delta like the rest: a document that arrived unbalanced
		// is not this write's fault.
		if ( ! self::delimiters_balanced( $out ) && ( $whole || self::delimiters_balanced( $in ) ) ) {
			return self::reject(
				'structure',
				__( 'A block delimiter is left unclosed, or closes something that was never opened — WordPress would fold the rest of the page inside it. Refusing to write it.', 'visual-edit-lite' )
			);
		}

		// --- 3b: structural invariance ---------------------------------------
		$waived = ! empty( $args['allow_structure_change'] ) || in_array( $context, array( 'create', 'restore' ), true );
		if ( null !== $in && ! $waived ) {
			$before = self::block_shape( $in );
			$after  = self::block_shape( $out );
			if ( $before !== $after ) {
				return self::reject(
					'structure',
					sprintf(
						/* translators: 1: block count before, 2: block count after. */
						__( 'This write changes the page structure (%1$d blocks before, %2$d after) when it should only have changed content — refusing it. Quote a passage that sits inside one block.', 'visual-edit-lite' ),
						count( $before ),
						count( $after )
					),
					array(
						'before' => $before,
						'after'  => $after,
					)
				);
			}
		}

		// --- 4: per-block schema ---------------------------------------------
		$violations = self::added(
			self::schema_violations( $out ),
			$whole ? array() : self::schema_violations( $in )
		);
		if ( $violations ) {
			return self::reject(
				'schema',
				sprintf(
					/* translators: %s: a list of block-level problems. */
					__( 'The block editor would flag this content as invalid: %s', 'visual-edit-lite' ),
					implode( '; ', array_slice( array_values( $violations ), 0, 4 ) )
				),
				array( 'violations' => array_values( $violations ) )
			);
		}

		return true;
	}

	/**
	 * Normalize markup into what serialize_blocks() would emit, so anything
	 * stored from here is idempotent from birth.
	 *
	 * Used instead of the idempotency CHECK on creation: a model writes
	 * `{ "level": 2 }` where PHP writes `{"level":2}`, and rejecting that
	 * teaches it nothing except that the tool is unreliable. parse/serialize
	 * re-encodes the delimiters and re-emits innerHTML byte for byte, so the
	 * content itself is untouched.
	 *
	 * Grammar-check BEFORE calling this. Canonicalizing markup whose
	 * delimiters do not parse would launder the fault into a shape that no
	 * longer looks broken.
	 *
	 * @param string $markup
	 * @return string
	 */
	public static function canonicalize( $markup ) {
		return serialize_blocks( parse_blocks( (string) $markup ) );
	}

	/**
	 * @param string $markup
	 * @return bool Whether the markup is already what its own serializer emits.
	 */
	public static function is_idempotent( $markup ) {
		$markup = (string) $markup;
		return self::canonicalize( $markup ) === $markup;
	}

	/**
	 * Every delimiter-shaped token WordPress would NOT accept, each mapped to
	 * a description a person — or a model retrying — can act on.
	 *
	 * @param string $markup
	 * @return string[] Descriptions, one per malformed token.
	 */
	public static function malformed_delimiters( $markup ) {
		$out = array();
		if ( ! preg_match_all( self::CANDIDATE, (string) $markup, $matches ) ) {
			return $out;
		}
		foreach ( $matches[0] as $token ) {
			if ( preg_match( self::GRAMMAR, $token ) ) {
				continue;
			}
			$snippet = substr( implode( ' ', preg_split( '/\s+/', trim( $token ) ) ), 0, 88 );
			$out[]   = $snippet . ' (' . self::explain( $token ) . ')';
		}
		return $out;
	}

	/**
	 * Why one token is not a block delimiter, in the terms of what to change.
	 *
	 * @param string $token
	 * @return string
	 */
	public static function explain( $token ) {
		if ( 0 !== strpos( $token, '<!-- ' ) ) {
			return __( 'no space after <!--', 'visual-edit-lite' );
		}
		if ( preg_match( '~\}/?-->$~', $token ) ) {
			return __( 'no space between the attributes and -->', 'visual-edit-lite' );
		}
		if ( preg_match( '~wp:[a-z0-9_/-]+\{~', $token ) ) {
			return __( 'no space between the block name and its attributes', 'visual-edit-lite' );
		}
		if ( preg_match( '~[^\s]/-->$~', $token ) ) {
			return __( 'no space before /-->', 'visual-edit-lite' );
		}
		if ( preg_match( '~[^\s]-->$~', $token ) ) {
			return __( 'no space before -->', 'visual-edit-lite' );
		}
		if ( preg_match( '~wp:[A-Z]~', $token ) ) {
			return __( 'block name is not lowercase', 'visual-edit-lite' );
		}
		return __( 'does not match the block grammar', 'visual-edit-lite' );
	}

	/**
	 * The document's shape: every block's name prefixed by its depth, read
	 * depth first.
	 *
	 * The depth is the point. A find/replace that eats a closing delimiter
	 * does not remove a block — the parser auto-closes at the end of the
	 * document, so the blocks that followed become CHILDREN of the one that
	 * lost its closer. Same names, same count, same order; a flat list of
	 * names calls that unchanged. It is also the exact fault that folded a
	 * whole page into a sticky form panel 4,794px tall.
	 *
	 * Whitespace-only freeform blocks are dropped: parse_blocks() emits one
	 * for every newline BETWEEN blocks, so keeping them would compare
	 * indentation rather than structure.
	 *
	 * @param string $markup
	 * @return string[] e.g. array( '0:core/group', '1:core/heading' )
	 */
	public static function block_shape( $markup ) {
		$shape = array();
		$walk  = static function ( $blocks, $depth ) use ( &$walk, &$shape ) {
			foreach ( (array) $blocks as $block ) {
				if ( null === $block['blockName'] ) {
					if ( '' !== trim( (string) $block['innerHTML'] ) ) {
						$shape[] = $depth . ':(freeform)';
					}
				} else {
					$shape[] = $depth . ':' . $block['blockName'];
				}
				if ( ! empty( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'], $depth + 1 );
				}
			}
		};
		$walk( parse_blocks( (string) $markup ), 0 );
		return $shape;
	}

	/**
	 * Does every opening delimiter have its closer, and no closer arrive
	 * before what it closes?
	 *
	 * Counted rather than parsed, because the parser is precisely what hides
	 * the fault: WordPress auto-closes an unclosed block at the end of the
	 * document, so parse_blocks() returns a perfectly good tree for markup
	 * that has silently swallowed everything after the missing closer. The
	 * only place the imbalance is still visible is the token stream.
	 *
	 * Malformed tokens count as unbalanced. They are reported by their own
	 * check first; this one simply refuses to guess what they meant.
	 *
	 * @param string $markup
	 * @return bool
	 */
	public static function delimiters_balanced( $markup ) {
		if ( ! preg_match_all( self::CANDIDATE, (string) $markup, $matches ) ) {
			return true;
		}
		$depth = 0;
		foreach ( $matches[0] as $token ) {
			if ( ! preg_match( self::GRAMMAR, $token, $parts ) ) {
				return false;
			}
			if ( ! empty( $parts['void'] ) ) {
				continue;
			}
			$depth += empty( $parts['closer'] ) ? 1 : -1;
			if ( $depth < 0 ) {
				return false;
			}
		}
		return 0 === $depth;
	}

	/**
	 * Saved-HTML problems that make the block editor call a block invalid.
	 *
	 * Gutenberg decides validity by re-running the block's save() and
	 * comparing byte for byte, which cannot be done from PHP — so this is a
	 * list of the drifts actually seen, not a proof of validity. Converting
	 * the reference theme produced fifty invalid image blocks from exactly
	 * one of them.
	 *
	 * @param string $markup
	 * @return string[]
	 */
	public static function schema_violations( $markup ) {
		$found = array();
		self::walk(
			parse_blocks( (string) $markup ),
			static function ( $block ) use ( &$found ) {
				$name = (string) $block['blockName'];
				if ( 'core/image' === $name ) {
					$found = array_merge( $found, self::image_violations( $block ) );
				}
				/**
				 * Saved-HTML problems for one block, so a site or an add-on
				 * can teach the gate about block types this plugin does not
				 * know.
				 *
				 * @param string[] $violations Descriptions; any entry rejects the write.
				 * @param array    $block      One parse_blocks() node.
				 */
				$extra = apply_filters( 'clara_ve_block_gate_violations', array(), $block );
				if ( $extra ) {
					$found = array_merge( $found, array_map( 'strval', (array) $extra ) );
				}
			}
		);
		return $found;
	}

	/**
	 * core/image: loading and decoding are added by WordPress at RENDER time
	 * and are never part of what save() emits, so their presence in stored
	 * HTML is always drift. width and height are different — the block does
	 * serialize them, but only when its attributes say so, and an <img> whose
	 * dimensions disagree with its block is exactly the mismatch that flags
	 * fifty images at once.
	 *
	 * @param array $block
	 * @return string[]
	 */
	private static function image_violations( $block ) {
		$html = (string) $block['innerHTML'];
		if ( false === stripos( $html, '<img' ) ) {
			return array();
		}
		$out  = array();
		$tags = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			foreach ( array( 'loading', 'decoding' ) as $attribute ) {
				if ( null !== $tags->get_attribute( $attribute ) ) {
					$out[] = 'core/image: <img> carries ' . $attribute . '=, which WordPress adds when rendering';
				}
			}
			foreach ( array( 'width', 'height' ) as $attribute ) {
				if ( null !== $tags->get_attribute( $attribute ) && ! isset( $block['attrs'][ $attribute ] ) ) {
					$out[] = 'core/image: <img> carries ' . $attribute . '= but the block does not declare it';
				}
			}
		}
		return $out;
	}

	/**
	 * Depth-first over a parsed tree.
	 *
	 * @param array    $blocks
	 * @param callable $visit
	 * @return void
	 */
	private static function walk( $blocks, $visit ) {
		foreach ( (array) $blocks as $block ) {
			$visit( $block );
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::walk( $block['innerBlocks'], $visit );
			}
		}
	}

	/**
	 * What $now has that $before did not — as a MULTISET, so a page that
	 * already carried two of something and now carries three reports one.
	 *
	 * @param string[] $now
	 * @param string[] $before
	 * @return string[]
	 */
	private static function added( $now, $before ) {
		$seen = array_count_values( $before );
		$out  = array();
		foreach ( $now as $item ) {
			if ( ! empty( $seen[ $item ] ) ) {
				--$seen[ $item ];
				continue;
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * @param string $check   Which check failed — the REST layer and the AI
	 *                        tools both branch on this rather than the prose.
	 * @param string $message
	 * @param array  $data
	 * @return WP_Error
	 */
	private static function reject( $check, $message, $data = array() ) {
		return new WP_Error(
			self::CODE,
			$message,
			array_merge(
				array(
					'check'  => $check,
					'status' => 422,
				),
				$data
			)
		);
	}
}
