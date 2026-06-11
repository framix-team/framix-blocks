<?php
/**
 * Minimal test bootstrap for framix-blocks.
 *
 * Zero-dependency: defines just the WordPress surface the validator under
 * test touches (the ABSPATH guard constant + WP_Error) plus a couple of
 * inline assertion helpers. Never shipped — excluded from the release zip.
 *
 * @package Framix_Blocks
 */

// The shipped validator opens with `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
// Define it so the file can be required in isolation under plain PHP.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Tiny WP_Error stand-in — only the surface the validator + loader use:
	 * construction with (code, message) and get_error_message().
	 */
	class WP_Error {

		/**
		 * Error code.
		 *
		 * @var string
		 */
		private $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		private $message;

		/**
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		/**
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}

		/**
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Value to check.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation passthrough.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain (ignored).
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

// ---------------------------------------------------------------------------
// Test-runner state + assertion helpers.
// ---------------------------------------------------------------------------

$GLOBALS['frx_tests_failed'] = 0;

/**
 * Assert a condition is true.
 *
 * @param bool   $cond  Condition.
 * @param string $label Case label.
 * @return void
 */
function assert_true( $cond, $label ) {
	if ( $cond ) {
		echo "PASS: {$label}\n";
		return;
	}
	echo "FAIL: {$label}\n";
	++$GLOBALS['frx_tests_failed'];
}

/**
 * Assert that $result is a WP_Error whose message contains $needle.
 *
 * @param mixed  $result WP_Error|true from validate().
 * @param string $needle Substring expected in the error message.
 * @param string $label  Case label.
 * @return void
 */
function assert_error_contains( $result, $needle, $label ) {
	if ( is_wp_error( $result ) && false !== strpos( $result->get_error_message(), $needle ) ) {
		echo "PASS: {$label}\n";
		return;
	}
	$got = is_wp_error( $result ) ? $result->get_error_message() : var_export( $result, true );
	echo "FAIL: {$label} — expected error containing \"{$needle}\", got: {$got}\n";
	++$GLOBALS['frx_tests_failed'];
}

/**
 * Write a block dir under sys_get_temp_dir(): block.json + optional assets.
 *
 * @param array               $block_json Decoded block.json content.
 * @param array<string,mixed> $assets     Map of relative-under-assets/ path => contents|int-bytes.
 *                                         An int value creates a file of that many bytes (sparse).
 * @return string Absolute path to the written block.json.
 */
function frx_make_block( array $block_json, array $assets = array() ) {
	$dir = sys_get_temp_dir() . '/frx-block-' . uniqid( '', true );
	mkdir( $dir, 0777, true );
	file_put_contents( $dir . '/block.json', json_encode( $block_json ) );

	foreach ( $assets as $rel => $content ) {
		$full = $dir . '/assets/' . $rel;
		@mkdir( dirname( $full ), 0777, true );
		if ( is_int( $content ) ) {
			// Sparse file of $content bytes — fast, no real allocation.
			$fh = fopen( $full, 'w' );
			if ( $content > 0 ) {
				fseek( $fh, $content - 1 );
				fwrite( $fh, "\0" );
			}
			fclose( $fh );
		} else {
			file_put_contents( $full, (string) $content );
		}
	}

	return $dir . '/block.json';
}
