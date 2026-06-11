<?php
/**
 * Static-asset URL helper for server-rendered blocks.
 *
 * Lets a block's render.php resolve the public URL of a file it ships in
 * its own directory (e.g. a decorative SVG icon) without touching the
 * filesystem or hardcoding the plugins-dir path.
 *
 * @package Framix_Blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'framix_block_asset_url' ) ) {
	/**
	 * Resolve the public URL of a file inside a block's own directory.
	 *
	 * Usage from a block's render.php:
	 *
	 *     $url = framix_block_asset_url( __DIR__, 'assets/icon.svg' );
	 *     printf( '<img src="%s" alt="">', esc_url( $url ) );
	 *
	 * Pure string composition — does NOT touch the filesystem. Rejects
	 * absolute paths, URL schemes, and `..` traversal segments (returns '').
	 *
	 * @param string $block_dir Absolute path to the block directory (pass __DIR__).
	 * @param string $relative  Path relative to the block directory, e.g.
	 *                          'assets/icon.svg'. A leading slash is stripped.
	 * @return string Public URL, or '' on failure.
	 */
	function framix_block_asset_url( $block_dir, $relative ) {
		if ( ! is_string( $block_dir ) || '' === $block_dir ) {
			return '';
		}

		$relative = (string) $relative;
		if ( '' === $relative ) {
			return '';
		}

		// Reject schemes (http://, https://, javascript:, //example, etc.).
		if ( preg_match( '#^([a-z][a-z0-9+.\-]*:)?//#i', $relative ) ) {
			return '';
		}

		$relative = ltrim( $relative, '/' );

		if ( false !== strpos( $relative, '..' ) ) {
			return '';
		}

		// plugins_url() builds the URL from the block's PHP file location,
		// so it is correct regardless of where the plugin folder is renamed to.
		return plugins_url( $relative, trailingslashit( $block_dir ) . 'render.php' );
	}
}

if ( ! function_exists( 'framix_block_edit_attr' ) ) {
	/**
	 * Inline-edit marker for server-rendered templates.
	 *
	 * Prints data-framix-edit attributes that the editor-side inline-edit
	 * shim turns into click-to-edit targets. Emitted ONLY during REST/SSR
	 * editor previews — the front-end render stays byte-identical (markers
	 * never reach page caches or optimizers).
	 *
	 * Usage in render.php (inside the element's opening tag):
	 *   <h3 class="card-title"<?php echo framix_block_edit_attr( 'title' ); ?>>
	 * Repeater row field (index into the RAW attribute array — if your
	 * template sorts or slices the array, pass the original array index,
	 * not the loop counter):
	 *   <li<?php echo framix_block_edit_attr( 'items', $i, 'label' ); ?>>
	 *
	 * @param string      $attr  Attribute name from block.json.
	 * @param int|null    $index Repeater row index (raw array index).
	 * @param string|null $field Repeater field key. Requires $index.
	 * @return string Escaped attribute string (leading space) or ''.
	 */
	function framix_block_edit_attr( $attr, $index = null, $field = null ) {
		if ( ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return '';
		}
		// A field without a row index is not a supported combination.
		if ( null !== $field && null === $index ) {
			return '';
		}
		$out = ' data-framix-edit="' . esc_attr( $attr ) . '"';
		if ( null !== $index ) {
			$out .= ' data-framix-edit-i="' . esc_attr( (string) $index ) . '"';
		}
		if ( null !== $field ) {
			$out .= ' data-framix-edit-f="' . esc_attr( $field ) . '"';
		}
		return $out;
	}
}
