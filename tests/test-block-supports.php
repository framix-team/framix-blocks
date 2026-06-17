<?php
/**
 * Standard-supports tests — the curated set shape + block-wins merge.
 *
 * Plain PHP, zero-dependency (matches the repo's tests/run.sh convention).
 * Asserts the merge contract: block-declared supports replace the standard
 * default per top-level key (no deep merge), so any block can opt out.
 *
 * @package Framix_Blocks
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-block-supports.php';

$S = 'Framix_Blocks_Block_Supports';

// The curated set carries the expected keys.
$std = $S::STANDARD_SUPPORTS;
assert_true( true === ( $std['anchor'] ?? null ), 'standard set keeps anchor' );
assert_true( true === ( $std['reusable'] ?? null ), 'standard set keeps reusable' );
assert_true(
	array( 'margin' => true, 'padding' => true, 'blockGap' => true ) === $std['spacing'],
	'standard set spacing = margin/padding/blockGap'
);
assert_true( array( 'minHeight' => true ) === $std['dimensions'], 'standard set dimensions = minHeight' );
assert_true(
	array( 'color' => true, 'radius' => true, 'style' => true, 'width' => true ) === $std['border'],
	'standard set border = color/radius/style/width (stable key)'
);
assert_true(
	array( 'fontSize' => true, 'lineHeight' => true ) === $std['typography'],
	'standard set typography = fontSize/lineHeight'
);
assert_true( array( 'text' => true, 'background' => true ) === $std['color'], 'standard set color = text/background' );

// Empty block supports yields exactly the standard set.
assert_true(
	$S::STANDARD_SUPPORTS === $S::merge( array() ),
	'empty block supports yields the standard set'
);

// Block-declared keys survive; untouched standard keys remain.
$merged = $S::merge( array( 'anchor' => true, 'reusable' => true ) );
assert_true( true === $merged['anchor'], 'block anchor preserved' );
assert_true( isset( $merged['spacing'], $merged['color'] ), 'untouched standard keys still present' );

// A block can disable a whole support.
$merged = $S::merge( array( 'color' => false ) );
assert_true( false === $merged['color'], 'block can disable color wholesale' );

// Override replaces wholesale — no deep merge.
$merged = $S::merge( array( 'spacing' => array( 'padding' => false ) ) );
assert_true(
	array( 'padding' => false ) === $merged['spacing']
		&& ! isset( $merged['spacing']['margin'] )
		&& ! isset( $merged['spacing']['blockGap'] ),
	'declaring spacing takes full control (no deep merge)'
);

echo "\n";
exit( $GLOBALS['frx_tests_failed'] > 0 ? 1 : 0 );
