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
// WordPress boundary stubs for the media-defaults engine.
//
// Honest stubs: only the WP boundary is faked (options, transients, posts,
// sideload, post-meta). The engine's real logic — hashing, mtime/size
// memoization, dedupe-key construction, option shape/writes — runs unstubbed.
//
// Controllable state lives in $GLOBALS['frx_wp'] and is reset per test by
// frx_wp_reset().
// ---------------------------------------------------------------------------

/**
 * Reset the in-memory WP boundary state to a clean slate.
 *
 * @return void
 */
function frx_wp_reset() {
	$GLOBALS['frx_wp'] = array(
		'options'           => array(),  // name => value.
		'autoload'          => array(),  // name => last autoload flag passed.
		'transients'        => array(),  // name => value.
		'posts'             => array(),  // id   => post_type (existing attachments).
		'post_meta'         => array(),  // id   => array(meta_key => value).
		'get_option_calls'  => 0,        // how many times get_option() was called.
		'sideload_calls'    => 0,        // how many times media_handle_sideload() ran.
		'next_id'           => 100,      // incrementing attachment id source.
		'sideload_error'    => false,    // when true, sideload returns WP_Error.
		'sideload_throw'    => false,    // when true, sideload THROWS (not WP_Error).
		'last_tmp_name'     => null,     // tmp_name handed to the last sideload call.
	);
	// Engine caches the option per request; clear it so each test is a fresh request.
	if ( class_exists( 'Framix_Blocks_Media_Defaults' ) ) {
		Framix_Blocks_Media_Defaults::reset_cache();
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $name    Option name.
	 * @param mixed  $default Default when absent.
	 * @return mixed
	 */
	function get_option( $name, $default = false ) {
		++$GLOBALS['frx_wp']['get_option_calls'];
		return array_key_exists( $name, $GLOBALS['frx_wp']['options'] )
			? $GLOBALS['frx_wp']['options'][ $name ]
			: $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $name     Option name.
	 * @param mixed  $value    Value.
	 * @param mixed  $autoload Autoload flag (recorded for assertions).
	 * @return bool
	 */
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['frx_wp']['options'][ $name ]  = $value;
		$GLOBALS['frx_wp']['autoload'][ $name ] = $autoload;
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * @param string $name Transient name.
	 * @return mixed False when absent.
	 */
	function get_transient( $name ) {
		return array_key_exists( $name, $GLOBALS['frx_wp']['transients'] )
			? $GLOBALS['frx_wp']['transients'][ $name ]
			: false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * @param string $name       Transient name.
	 * @param mixed  $value      Value.
	 * @param int    $expiration TTL (ignored).
	 * @return bool
	 */
	function set_transient( $name, $value, $expiration = 0 ) {
		$GLOBALS['frx_wp']['transients'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * @param string $name Transient name.
	 * @return bool
	 */
	function delete_transient( $name ) {
		unset( $GLOBALS['frx_wp']['transients'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Returns an object with ->post_type for ids in the existing-attachment
	 * map, else null (mirrors WordPress for a non-existent id).
	 *
	 * @param int $id Post id.
	 * @return object|null
	 */
	function get_post( $id ) {
		$id = (int) $id;
		if ( ! isset( $GLOBALS['frx_wp']['posts'][ $id ] ) ) {
			return null;
		}
		return (object) array(
			'ID'        => $id,
			'post_type' => $GLOBALS['frx_wp']['posts'][ $id ],
		);
	}
}

if ( ! function_exists( 'media_handle_sideload' ) ) {
	/**
	 * Controllable sideload: counts calls; returns an incrementing id and
	 * records it as an existing attachment, a forced WP_Error, or a forced
	 * Throwable. Always records the tmp_name it was handed so tests can
	 * assert temp-file cleanup on the failure paths.
	 *
	 * @param array $file    File descriptor (name + tmp_name).
	 * @param int   $post_id Parent post id (ignored).
	 * @return int|WP_Error
	 * @throws RuntimeException When the sideload_throw flag is set.
	 */
	function media_handle_sideload( $file, $post_id = 0 ) {
		++$GLOBALS['frx_wp']['sideload_calls'];
		$GLOBALS['frx_wp']['last_tmp_name'] = isset( $file['tmp_name'] ) ? $file['tmp_name'] : null;

		if ( $GLOBALS['frx_wp']['sideload_throw'] ) {
			throw new RuntimeException( 'forced sideload throw' );
		}

		// WordPress moves the temp file on success; emulate cleanup so the
		// engine's failure-path @unlink is the only one that fires on error.
		if ( $GLOBALS['frx_wp']['sideload_error'] ) {
			return new WP_Error( 'sideload_failed', 'forced sideload failure' );
		}

		if ( isset( $file['tmp_name'] ) && is_file( $file['tmp_name'] ) ) {
			@unlink( $file['tmp_name'] );
		}

		$id = $GLOBALS['frx_wp']['next_id']++;
		$GLOBALS['frx_wp']['posts'][ $id ] = 'attachment';
		return $id;
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	/**
	 * @param int    $id    Post id.
	 * @param string $key   Meta key.
	 * @param mixed  $value Meta value.
	 * @return bool
	 */
	function update_post_meta( $id, $key, $value ) {
		$GLOBALS['frx_wp']['post_meta'][ (int) $id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_tempnam' ) ) {
	/**
	 * Real temp file so copy()/is_file() behave honestly.
	 *
	 * @param string $filename Suggested basename (ignored).
	 * @return string Absolute temp-file path.
	 */
	function wp_tempnam( $filename = '' ) {
		return tempnam( sys_get_temp_dir(), 'frx-md-' );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Passthrough trim (enough for alt-text assertions).
	 *
	 * @param string $str Input.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		return trim( (string) $str );
	}
}

/**
 * Create a temp ABSPATH tree with empty wp-admin/includes/{file,media,image}.php
 * so the engine's require_once calls succeed. ABSPATH is defined once (above);
 * this only ensures the stub files exist under it.
 *
 * @return void
 */
function frx_make_abspath_stubs() {
	$inc = rtrim( ABSPATH, '/' ) . '/wp-admin/includes';
	@mkdir( $inc, 0777, true );
	foreach ( array( 'file.php', 'media.php', 'image.php' ) as $f ) {
		$path = $inc . '/' . $f;
		if ( ! is_file( $path ) ) {
			file_put_contents( $path, "<?php\n" );
		}
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
