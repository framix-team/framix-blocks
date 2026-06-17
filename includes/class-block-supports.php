<?php
/**
 * Standard block supports — the curated, token-bound control set.
 *
 * Pure logic, no WordPress calls. The map is merged onto every
 * framix-registered block's `supports` at registration (via the
 * block_type_metadata filter wired in the loader), with a shallow,
 * block-wins merge: a block's own `supports` for any top-level key
 * REPLACES the standard default, so any block can disable or narrow a
 * control by declaring that key in its block.json.
 *
 * Token binding is NOT done here. Core only enables the supports
 * (the controls). Each site's theme.json `settings` supplies the
 * presets + custom:false that make the controls render token dropdowns.
 * See docs/standard-supports.md.
 *
 * @package Framix_Blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Framix_Blocks_Block_Supports {

	/**
	 * The curated standard supports. Conservative on purpose to limit
	 * backward-compat output shifts on existing blocks; a block opts out
	 * of any key via its own block.json `supports`.
	 *
	 * Keys are the stable block.json `supports` names (the block_type_metadata
	 * filter operates on decoded block.json metadata, which uses `border`,
	 * `spacing`, `dimensions`, `typography`, `color` — not the internal
	 * `__experimentalBorder` supports key).
	 *
	 * @var array<string,mixed>
	 */
	const STANDARD_SUPPORTS = array(
		'anchor'     => true,
		'reusable'   => true,
		'spacing'    => array(
			'margin'   => true,
			'padding'  => true,
			'blockGap' => true,
		),
		'dimensions' => array(
			'minHeight' => true,
		),
		'border'     => array(
			'color'  => true,
			'radius' => true,
			'style'  => true,
			'width'  => true,
		),
		'typography' => array(
			'fontSize'   => true,
			'lineHeight' => true,
		),
		'color'      => array(
			'text'       => true,
			'background' => true,
		),
	);

	/**
	 * Shallow, block-wins merge of the block's supports onto the standard set.
	 *
	 * For every top-level key the block declares, the block's value REPLACES
	 * the standard value wholesale (no recursion): so a block setting
	 * `"spacing": { "padding": false }` ends up with EXACTLY that spacing
	 * object (padding disabled, margin/blockGap absent) rather than a deep
	 * merge. This is the documented opt-out contract: declare the key to take
	 * full control of it.
	 *
	 * @param array<string,mixed> $block_supports The block.json `supports` (possibly empty).
	 * @return array<string,mixed> Merged supports.
	 */
	public static function merge( array $block_supports ) {
		// array_merge is shallow and right-wins on string keys: exactly the
		// block-wins, no-deep-merge contract.
		return array_merge( self::STANDARD_SUPPORTS, $block_supports );
	}
}
