<?php
/**
 * Media-defaults engine tests.
 *
 * Plain PHP, zero-dependency. Stubs only the WordPress boundary (options,
 * transients, posts, sideload, post-meta — see bootstrap.php); the engine's
 * real logic (hashing, mtime/size memoization, dedupe-key construction, option
 * shape + writes) runs unstubbed. Builds real temp block dirs with real asset
 * files so hashing and is_file() are honest. Exits non-zero on any failure.
 *
 * @package Framix_Blocks
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-media-defaults.php';

frx_make_abspath_stubs();

$E = 'Framix_Blocks_Media_Defaults';

/**
 * Build a block dir with a single media attribute + a real asset file.
 *
 * @param string $content       Asset file bytes (drives the sha).
 * @param string $alt           Alt text ('' to omit the key).
 * @param string $default_asset Relative asset path.
 * @return array{dir:string, meta:array} The block dir + decoded block.json meta.
 */
function frx_media_block( $content = 'image-bytes-v1', $alt = 'Hero image', $default_asset = 'assets/hero.webp' ) {
	$dir = sys_get_temp_dir() . '/frx-mblock-' . uniqid( '', true );
	mkdir( $dir, 0777, true );

	$media = array( 'default_asset' => $default_asset );
	if ( '' !== $alt ) {
		$media['alt'] = $alt;
	}

	$meta = array(
		'name'       => 'framix/hero',
		'attributes' => array(
			'image' => array(
				'type'    => 'integer',
				'control' => 'media',
				'default' => 0,
				'media'   => $media,
			),
		),
	);

	file_put_contents( $dir . '/block.json', json_encode( $meta ) );

	$full = $dir . '/' . $default_asset;
	@mkdir( dirname( $full ), 0777, true );
	file_put_contents( $full, $content );

	return array( 'dir' => $dir, 'meta' => $meta );
}

// ===========================================================================
// Case 1 — no media.default_asset anywhere → null, zero option reads.
// ===========================================================================
frx_wp_reset();
$dir  = sys_get_temp_dir() . '/frx-plain-' . uniqid( '', true );
mkdir( $dir, 0777, true );
$meta = array(
	'name'       => 'framix/plain',
	'attributes' => array(
		'heading' => array( 'type' => 'string', 'default' => 'Hi' ),
		'count'   => array( 'type' => 'integer', 'default' => 3 ),
	),
);
$out = $E::resolve( $dir, $meta );
assert_true( null === $out, 'no media.default_asset → resolve() returns null' );
assert_true( 0 === $GLOBALS['frx_wp']['get_option_calls'], 'no media.default_asset → zero option reads (fast path)' );

// ===========================================================================
// Case 2 — first encounter → sideload once, option gains key→id, default
//          rewritten to the id, alt meta recorded.
// ===========================================================================
frx_wp_reset();
$b   = frx_media_block();
$out = $E::resolve( $b['dir'], $b['meta'] );
$id  = $out['image']['default'];
assert_true( is_array( $out ), 'first encounter → returns attributes array' );
assert_true( 1 === $GLOBALS['frx_wp']['sideload_calls'], 'first encounter → sideload called exactly once' );
assert_true( $id > 0, 'first encounter → default rewritten to a positive attachment id' );
$opt = $GLOBALS['frx_wp']['options'][ Framix_Blocks_Media_Defaults::OPTION ];
$keys = array_keys( $opt );
$dedupe_key = '';
foreach ( $keys as $k ) {
	if ( '_files' !== $k ) {
		$dedupe_key = $k;
	}
}
assert_true( 0 === strpos( $dedupe_key, 'framix/hero:assets/hero.webp:' ), 'first encounter → option key is block:asset:sha16' );
assert_true( 16 === strlen( substr( $dedupe_key, strrpos( $dedupe_key, ':' ) + 1 ) ), 'first encounter → sha16 segment is 16 hex chars' );
assert_true( $id === $opt[ $dedupe_key ], 'first encounter → option maps the dedupe key to the id' );
assert_true( false === $GLOBALS['frx_wp']['autoload'][ Framix_Blocks_Media_Defaults::OPTION ], 'first encounter → update_option autoload arg is false' );
assert_true(
	isset( $GLOBALS['frx_wp']['post_meta'][ $id ]['_wp_attachment_image_alt'] )
		&& 'Hero image' === $GLOBALS['frx_wp']['post_meta'][ $id ]['_wp_attachment_image_alt'],
	'first encounter → alt meta recorded'
);

// ===========================================================================
// Case 3 — second resolve, same state → dedupe hit: no second sideload, same
//          id, and the _files memo means no re-hash (memo present + unchanged).
// ===========================================================================
// Carry the option store; reset the per-request cache only.
$saved_options = $GLOBALS['frx_wp']['options'];
$saved_posts   = $GLOBALS['frx_wp']['posts'];
$saved_meta    = $GLOBALS['frx_wp']['post_meta'];
$saved_next_id = $GLOBALS['frx_wp']['next_id'];
$abs_path      = $b['dir'] . '/assets/hero.webp';
$memo_before   = $saved_options[ Framix_Blocks_Media_Defaults::OPTION ]['_files'][ $abs_path ];

frx_wp_reset();
$GLOBALS['frx_wp']['options']   = $saved_options;
$GLOBALS['frx_wp']['posts']     = $saved_posts;
$GLOBALS['frx_wp']['post_meta'] = $saved_meta;
$GLOBALS['frx_wp']['next_id']   = $saved_next_id; // keep ids monotonic across the simulated request.

$out2 = $E::resolve( $b['dir'], $b['meta'] );
assert_true( 0 === $GLOBALS['frx_wp']['sideload_calls'], 'dedupe hit → no second sideload' );
assert_true( $id === $out2['image']['default'], 'dedupe hit → same attachment id returned' );
$memo_after = $GLOBALS['frx_wp']['options'][ Framix_Blocks_Media_Defaults::OPTION ]['_files'][ $abs_path ];
assert_true(
	$memo_before['sha16'] === $memo_after['sha16']
		&& $memo_before['mtime'] === $memo_after['mtime']
		&& $memo_before['size'] === $memo_after['size'],
	'dedupe hit → _files memo present and unchanged (no re-hash needed)'
);

// ===========================================================================
// Case 4 — attachment deleted (get_post → null) → re-sideload, new id stored.
// ===========================================================================
$saved_options = $GLOBALS['frx_wp']['options'];
$saved_next_id = $GLOBALS['frx_wp']['next_id'];
frx_wp_reset();
$GLOBALS['frx_wp']['options'] = $saved_options;
$GLOBALS['frx_wp']['next_id'] = $saved_next_id; // keep ids monotonic across the simulated request.
// posts map intentionally empty → the stored id no longer resolves.
$out4    = $E::resolve( $b['dir'], $b['meta'] );
$new_id  = $out4['image']['default'];
assert_true( 1 === $GLOBALS['frx_wp']['sideload_calls'], 'attachment gone → re-sideload happens' );
assert_true( $new_id > 0 && $new_id !== $id, 'attachment gone → option updated to a new id' );
$opt4 = $GLOBALS['frx_wp']['options'][ Framix_Blocks_Media_Defaults::OPTION ];
assert_true( $new_id === $opt4[ $dedupe_key ], 'attachment gone → same dedupe key now maps to the new id' );

// ===========================================================================
// Case 5 — asset content changed → new sha → new key → second sideload, new
//          id, old key retained in the option.
// ===========================================================================
frx_wp_reset();
$b5  = frx_media_block( 'image-bytes-v1' );
$r5a = $E::resolve( $b5['dir'], $b5['meta'] );
$id5a = $r5a['image']['default'];
$opt5a = $GLOBALS['frx_wp']['options'][ Framix_Blocks_Media_Defaults::OPTION ];
$old_key = '';
foreach ( array_keys( $opt5a ) as $k ) {
	if ( '_files' !== $k ) {
		$old_key = $k;
	}
}

// Rewrite the asset with different bytes + a guaranteed-newer mtime.
$asset5 = $b5['dir'] . '/assets/hero.webp';
file_put_contents( $asset5, 'image-bytes-v2-different-length' );
touch( $asset5, time() + 5 );
clearstatcache(); // PHP caches filemtime/filesize — clear so the memo sees the new stat.

$saved_options = $GLOBALS['frx_wp']['options'];
$saved_posts   = $GLOBALS['frx_wp']['posts'];
$saved_next_id = $GLOBALS['frx_wp']['next_id'];
frx_wp_reset();
$GLOBALS['frx_wp']['options'] = $saved_options;
$GLOBALS['frx_wp']['posts']   = $saved_posts;
$GLOBALS['frx_wp']['next_id'] = $saved_next_id; // keep ids monotonic across the simulated request.

$r5b  = $E::resolve( $b5['dir'], $b5['meta'] );
$id5b = $r5b['image']['default'];
assert_true( 1 === $GLOBALS['frx_wp']['sideload_calls'], 'content changed → second sideload (new sha → new key)' );
assert_true( $id5b > 0 && $id5b !== $id5a, 'content changed → new attachment id' );
$opt5b = $GLOBALS['frx_wp']['options'][ Framix_Blocks_Media_Defaults::OPTION ];
assert_true( isset( $opt5b[ $old_key ] ) && $id5a === $opt5b[ $old_key ], 'content changed → old dedupe key retained in option' );
$new_key = '';
foreach ( array_keys( $opt5b ) as $k ) {
	if ( '_files' !== $k && $k !== $old_key ) {
		$new_key = $k;
	}
}
assert_true( '' !== $new_key && $id5b === $opt5b[ $new_key ], 'content changed → new dedupe key maps to the new id' );

// ===========================================================================
// Case 6 — missing asset file → no sideload, default untouched, no throw.
// ===========================================================================
frx_wp_reset();
$dir6 = sys_get_temp_dir() . '/frx-missing-' . uniqid( '', true );
mkdir( $dir6, 0777, true );
$meta6 = array(
	'name'       => 'framix/hero',
	'attributes' => array(
		'image' => array(
			'type'    => 'integer',
			'control' => 'media',
			'default' => 0,
			'media'   => array( 'default_asset' => 'assets/nope.webp' ),
		),
	),
);
// No asset file written.
$out6 = $E::resolve( $dir6, $meta6 );
assert_true( is_array( $out6 ), 'missing asset → resolve() still returns an array (no throw)' );
assert_true( 0 === $GLOBALS['frx_wp']['sideload_calls'], 'missing asset → no sideload' );
assert_true( 0 === $out6['image']['default'], 'missing asset → default stays 0' );

// ===========================================================================
// Case 7 — media_handle_sideload → WP_Error → no rewrite, lock released, no throw.
// ===========================================================================
frx_wp_reset();
$GLOBALS['frx_wp']['sideload_error'] = true;
$b7  = frx_media_block();
$out7 = $E::resolve( $b7['dir'], $b7['meta'] );
assert_true( is_array( $out7 ), 'sideload WP_Error → resolve() returns array (no throw)' );
assert_true( 0 === $out7['image']['default'], 'sideload WP_Error → default not rewritten (stays 0)' );
assert_true( false === get_transient( Framix_Blocks_Media_Defaults::LOCK ), 'sideload WP_Error → lock released' );
assert_true( ! isset( $GLOBALS['frx_wp']['options'][ Framix_Blocks_Media_Defaults::OPTION ][ '_skip' ] ), 'sideload WP_Error → no spurious option key' );

// ===========================================================================
// Case 8 — lock already held → no sideload this pass, default untouched, lock
//          NOT deleted by the skipping request.
// ===========================================================================
frx_wp_reset();
$b8 = frx_media_block();
set_transient( Framix_Blocks_Media_Defaults::LOCK, 1, 30 );
$out8 = $E::resolve( $b8['dir'], $b8['meta'] );
assert_true( 0 === $GLOBALS['frx_wp']['sideload_calls'], 'lock held → no sideload this pass' );
assert_true( 0 === $out8['image']['default'], 'lock held → default untouched (stays 0)' );
assert_true( false !== get_transient( Framix_Blocks_Media_Defaults::LOCK ), 'lock held → skipping request did NOT delete the lock' );

// ===========================================================================
// Case 9 — alt absent → no update_post_meta call.
// ===========================================================================
frx_wp_reset();
$b9  = frx_media_block( 'image-bytes-noalt', '' );
$out9 = $E::resolve( $b9['dir'], $b9['meta'] );
$id9  = $out9['image']['default'];
assert_true( $id9 > 0, 'alt absent → still sideloads + rewrites the default' );
assert_true( empty( $GLOBALS['frx_wp']['post_meta'][ $id9 ] ), 'alt absent → no update_post_meta call' );

// ===========================================================================
// Case 10 — loader-level: register_block_type captures $args.
//   - block WITH default_asset → $args['attributes'] carries the resolved id.
//   - block WITHOUT media       → $args has NO 'attributes' key (regression).
// ===========================================================================
require_once dirname( __DIR__ ) . '/includes/class-blockjson-validator.php';

if ( ! class_exists( 'WP_Block_Type' ) ) {
	/**
	 * Minimal WP_Block_Type stand-in — the loader only reads ->name.
	 */
	class WP_Block_Type {
		/** @var string */
		public $name;
		/**
		 * @param string $name Block name.
		 */
		public function __construct( $name ) {
			$this->name = $name;
		}
	}
}

if ( ! function_exists( 'register_block_type' ) ) {
	/**
	 * Capture the $args the loader passes; return a WP_Block_Type.
	 *
	 * @param string $dir  Block dir.
	 * @param array  $args Registration args.
	 * @return WP_Block_Type
	 */
	function register_block_type( $dir, $args = array() ) {
		$meta = json_decode( (string) file_get_contents( $dir . '/block.json' ), true );
		$GLOBALS['frx_wp']['last_register_args'] = $args;
		return new WP_Block_Type( isset( $meta['name'] ) ? $meta['name'] : 'x/y' );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param string $tag   Hook name (ignored).
	 * @param mixed  $value Value passed through.
	 * @return mixed
	 */
	function apply_filters( $tag, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	/** @return bool */
	function add_action() {
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	/** @return bool */
	function add_filter() {
		return true;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * @param string $key Raw key.
	 * @return string Lowercased, restricted to [a-z0-9_-].
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-block-category.php';
require_once dirname( __DIR__ ) . '/includes/class-block-loader.php';

/**
 * Invoke the loader's private load_block() on one block dir via reflection.
 *
 * @param string $dir Block directory.
 * @return void
 */
function frx_invoke_load_block( $dir ) {
	$loader = Framix_Blocks_Loader::instance();
	$ref    = new ReflectionMethod( 'Framix_Blocks_Loader', 'load_block' );
	$ref->setAccessible( true );
	$ref->invoke( $loader, $dir );
}

/**
 * Write a registerable block dir (block.json + a matching render.php).
 *
 * @param array  $meta     block.json content.
 * @param string $asset    Optional asset bytes (when meta carries default_asset).
 * @return string Block dir.
 */
function frx_loader_block( array $meta, $asset = null ) {
	$dir = sys_get_temp_dir() . '/frx-loadblk-' . uniqid( '', true );
	mkdir( $dir, 0777, true );
	file_put_contents( $dir . '/block.json', json_encode( $meta ) );
	// render.php defining framix_hero_render so the loader proceeds.
	file_put_contents(
		$dir . '/render.php',
		"<?php\nif ( ! function_exists( 'framix_hero_render' ) ) { function framix_hero_render() { return ''; } }\n"
	);
	if ( null !== $asset && isset( $meta['attributes']['image']['media']['default_asset'] ) ) {
		$rel  = $meta['attributes']['image']['media']['default_asset'];
		$full = $dir . '/' . $rel;
		@mkdir( dirname( $full ), 0777, true );
		file_put_contents( $full, $asset );
	}
	return $dir;
}

// 10a — block WITH media default_asset.
frx_wp_reset();
$meta_with = array(
	'name'       => 'framix/hero',
	'category'   => 'framix',
	'attributes' => array(
		'image' => array(
			'type'    => 'integer',
			'control' => 'media',
			'default' => 0,
			'media'   => array( 'default_asset' => 'assets/hero.webp', 'alt' => 'A' ),
		),
	),
);
$GLOBALS['frx_wp']['last_register_args'] = null;
frx_invoke_load_block( frx_loader_block( $meta_with, 'loader-asset-bytes' ) );
$args_with = $GLOBALS['frx_wp']['last_register_args'];
assert_true( is_array( $args_with ) && isset( $args_with['attributes'] ), 'loader: block with default_asset → $args has an attributes override' );
assert_true(
	isset( $args_with['attributes']['image']['default'] ) && $args_with['attributes']['image']['default'] > 0,
	'loader: attributes override carries the resolved attachment id as default'
);
assert_true( isset( $args_with['render_callback'] ), 'loader: render_callback still present alongside attributes' );

// 10b — block WITHOUT media → no attributes key (regression).
frx_wp_reset();
$meta_without = array(
	'name'       => 'framix/hero',
	'category'   => 'framix',
	'attributes' => array(
		'heading' => array( 'type' => 'string', 'default' => 'Hi' ),
	),
);
$GLOBALS['frx_wp']['last_register_args'] = null;
frx_invoke_load_block( frx_loader_block( $meta_without ) );
$args_without = $GLOBALS['frx_wp']['last_register_args'];
assert_true( is_array( $args_without ) && ! isset( $args_without['attributes'] ), 'loader: block without media → $args has NO attributes key (regression)' );

// ---------------------------------------------------------------------------
// Exit.
// ---------------------------------------------------------------------------

if ( $GLOBALS['frx_tests_failed'] > 0 ) {
	echo "\n{$GLOBALS['frx_tests_failed']} media-defaults test(s) FAILED.\n";
	exit( 1 );
}
echo "\nAll media-defaults tests passed.\n";
exit( 0 );
