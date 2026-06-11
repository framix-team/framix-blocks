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
