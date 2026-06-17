<?php
/**
 * WPML-config derivation tests — extract / aggregate / to_xml.
 *
 * Plain PHP, zero-dependency (matches tests/run.sh). Asserts the per-attribute
 * translatable convention and the wpml-config.xml render shape.
 *
 * @package Framix_Blocks
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-wpml-config.php';

$W = 'Framix_Blocks_WPML_Config';

// extract — plain string attrs (true), ignoring non-translatable + false.
$d = $W::extract( 'ampeco/tw-card', array(
	'title'   => array( 'type' => 'string', 'translatable' => true ),
	'excerpt' => array( 'type' => 'string', 'translatable' => true ),
	'newTab'  => array( 'type' => 'boolean' ),
	'postId'  => array( 'type' => 'number', 'translatable' => false ),
) );
assert_true( 'ampeco/tw-card' === $d['block'], 'extract keeps block name' );
assert_true( array( 'title', 'excerpt' ) === $d['keys'], 'extract collects only translatable:true string keys' );
assert_true( array() === $d['repeaters'], 'extract: no repeaters when none declared' );

// extract — repeater fields, dropping empty-string field.
$d = $W::extract( 'ampeco/tw-stats', array(
	'items' => array(
		'type'         => 'array',
		'control'      => 'repeater',
		'translatable' => array( 'fields' => array( 'label', 'body', '' ) ),
	),
) );
assert_true( array() === $d['keys'], 'repeater attr produces no plain keys' );
assert_true( 1 === count( $d['repeaters'] ) && 'items' === $d['repeaters'][0]['attr'], 'repeater attr recorded' );
assert_true( array( 'label', 'body' ) === $d['repeaters'][0]['fields'], 'empty-string repeater field dropped' );

// extract — nothing translatable.
$d = $W::extract( 'ns/x', array( 'x' => array( 'type' => 'string' ) ) );
assert_true( array() === $d['keys'] && array() === $d['repeaters'], 'no flags -> empty descriptor' );

// aggregate — drops empty blocks.
$a   = $W::extract( 'ns/a', array( 'title' => array( 'type' => 'string', 'translatable' => true ) ) );
$b   = $W::extract( 'ns/b', array( 'x' => array( 'type' => 'string' ) ) );
$agg = $W::aggregate( array( $a, $b ) );
assert_true( 1 === count( $agg ) && 'ns/a' === $agg[0]['block'], 'aggregate drops blocks with nothing translatable' );

// to_xml — shape + well-formedness.
$r   = $W::extract( 'ampeco/tw-stats', array( 'items' => array( 'type' => 'array', 'control' => 'repeater', 'translatable' => array( 'fields' => array( 'label' ) ) ) ) );
$xml = $W::to_xml( $W::aggregate( array( $a, $r ) ) );
assert_true( false !== strpos( $xml, '<gutenberg-block type="ns/a" translate="1">' ), 'to_xml emits the block element' );
assert_true( false !== strpos( $xml, '<key name="title" />' ), 'to_xml emits a string key' );
assert_true( false !== strpos( $xml, '<key name="items">' ) && false !== strpos( $xml, '<key name="*">' ) && false !== strpos( $xml, '<key name="label" />' ), 'to_xml emits the repeater key tree' );
assert_true( false !== simplexml_load_string( $xml ), 'to_xml output is well-formed XML' );

// Validator accepts the translatable convention and rejects misuse.
require_once dirname( __DIR__ ) . '/includes/class-blockjson-validator.php';
$V  = 'Framix_Blocks_BlockJSON_Validator';
$ok = $V::validate( frx_make_block( array(
	'name'       => 'framix/t',
	'attributes' => array( 'title' => array( 'type' => 'string', 'translatable' => true ) ),
) ) );
assert_true( true === $ok, 'validator accepts translatable:true' );
assert_error_contains(
	$V::validate( frx_make_block( array(
		'name'       => 'framix/t2',
		'attributes' => array( 'title' => array( 'type' => 'string', 'translatable' => 'yes' ) ),
	) ) ),
	'must be true or an object',
	'validator rejects malformed translatable'
);

echo "\n";
exit( $GLOBALS['frx_tests_failed'] > 0 ? 1 : 0 );
