<?php
/**
 * Validator tests — media-defaults schema + asset guards + regression.
 *
 * Plain PHP, zero-dependency. Builds temp block dirs, writes block.json
 * fixtures, asserts validate() outcomes. Exits non-zero on any failure.
 *
 * @package Framix_Blocks
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-blockjson-validator.php';

$V = 'Framix_Blocks_BlockJSON_Validator';

// Reusable valid base — a legal block with no media, no assets.
$base = array(
	'name'       => 'framix/test-block',
	'attributes' => array(),
);

// ---------------------------------------------------------------------------
// Part 1 — media-defaults schema.
// ---------------------------------------------------------------------------

// Legal media attr (default_asset + alt).
$json = $base;
$json['attributes'] = array(
	'image' => array(
		'type'    => 'integer',
		'control' => 'media',
		'default' => 0,
		'media'   => array(
			'default_asset' => 'assets/hero.webp',
			'alt'           => 'Hero image',
		),
	),
);
assert_true( true === $V::validate( frx_make_block( $json ) ), 'legal media attr (default_asset + alt) passes' );

// media without control: media.
$json = $base;
$json['attributes'] = array(
	'image' => array(
		'type'  => 'integer',
		'media' => array( 'default_asset' => 'assets/hero.webp' ),
	),
);
assert_error_contains( $V::validate( frx_make_block( $json ) ), 'only supported with control: "media"', 'media without control:media is an error' );

// Unknown media key.
$json = $base;
$json['attributes'] = array(
	'image' => array(
		'type'    => 'integer',
		'control' => 'media',
		'media'   => array( 'default_asset' => 'assets/hero.webp', 'caption' => 'x' ),
	),
);
assert_error_contains( $V::validate( frx_make_block( $json ) ), 'unsupported key "caption"', 'unknown media key is an error' );

// alt must be a string.
$json = $base;
$json['attributes'] = array(
	'image' => array(
		'type'    => 'integer',
		'control' => 'media',
		'media'   => array( 'alt' => 123 ),
	),
);
assert_error_contains( $V::validate( frx_make_block( $json ) ), '"media.alt" must be a string', 'non-string alt is an error' );

// Absolute path.
$json = $base;
$json['attributes'] = array(
	'image' => array(
		'type'    => 'integer',
		'control' => 'media',
		'media'   => array( 'default_asset' => '/etc/passwd' ),
	),
);
assert_error_contains( $V::validate( frx_make_block( $json ) ), 'must not be an absolute path', 'absolute default_asset is an error' );

// Traversal segment.
$json = $base;
$json['attributes'] = array(
	'image' => array(
		'type'    => 'integer',
		'control' => 'media',
		'media'   => array( 'default_asset' => 'assets/../../secret.png' ),
	),
);
assert_error_contains( $V::validate( frx_make_block( $json ) ), 'must not contain a ".." path segment', 'traversal default_asset is an error' );

// URL scheme.
$json = $base;
$json['attributes'] = array(
	'image' => array(
		'type'    => 'integer',
		'control' => 'media',
		'media'   => array( 'default_asset' => 'https://evil.test/x.png' ),
	),
);
assert_error_contains( $V::validate( frx_make_block( $json ) ), 'must not contain a URL scheme', 'scheme default_asset is an error' );

// SVG default_asset rejected.
$json = $base;
$json['attributes'] = array(
	'image' => array(
		'type'    => 'integer',
		'control' => 'media',
		'media'   => array( 'default_asset' => 'assets/icon.svg' ),
	),
);
assert_error_contains( $V::validate( frx_make_block( $json ) ), 'extension "svg" is not allowed', 'svg default_asset is rejected' );

// Non-zero default alongside default_asset.
$json = $base;
$json['attributes'] = array(
	'image' => array(
		'type'    => 'integer',
		'control' => 'media',
		'default' => 42,
		'media'   => array( 'default_asset' => 'assets/hero.webp' ),
	),
);
assert_error_contains( $V::validate( frx_make_block( $json ) ), 'the schema "default" must be 0 or absent', 'non-zero default alongside default_asset is an error' );

// default_asset must start with assets/.
$json = $base;
$json['attributes'] = array(
	'image' => array(
		'type'    => 'integer',
		'control' => 'media',
		'media'   => array( 'default_asset' => 'img/hero.webp' ),
	),
);
assert_error_contains( $V::validate( frx_make_block( $json ) ), 'starting with "assets/"', 'default_asset outside assets/ is an error' );

// ---------------------------------------------------------------------------
// Regression — legal block WITHOUT media key passes unchanged.
// ---------------------------------------------------------------------------

$json = $base;
$json['attributes'] = array(
	'heading' => array( 'type' => 'string' ),
	'count'   => array( 'type' => 'integer', 'default' => 5 ),
);
assert_true( true === $V::validate( frx_make_block( $json ) ), 'legal block without media key passes (regression)' );

// Legal block with a conventional svg-icon asset dir passes.
$json = $base;
assert_true(
	true === $V::validate( frx_make_block( $json, array( 'icons/star.svg' => '<svg/>', 'hero.webp' => 'x' ) ) ),
	'block with svg-icon + webp asset dir passes (regression)'
);

// ---------------------------------------------------------------------------
// Part 2 — asset guards.
// ---------------------------------------------------------------------------

// assets/ tree with a .php file rejected.
assert_error_contains(
	$V::validate( frx_make_block( $base, array( 'evil.php' => '<?php evil();' ) ) ),
	'disallowed file "evil.php"',
	'assets/ with .php file is rejected'
);

// assets/ tree with a .js file rejected.
assert_error_contains(
	$V::validate( frx_make_block( $base, array( 'app.js' => 'x' ) ) ),
	'disallowed file "app.js"',
	'assets/ with .js file is rejected'
);

// assets/ tree with only svg + webp passes.
assert_true(
	true === $V::validate( frx_make_block( $base, array( 'a.svg' => 'x', 'b.webp' => 'y' ) ) ),
	'assets/ with only svg + webp passes'
);

// Nested disallowed file is caught (recursive scan).
assert_error_contains(
	$V::validate( frx_make_block( $base, array( 'sub/dir/.htaccess' => 'deny' ) ) ),
	'disallowed file ".htaccess"',
	'nested .htaccess is rejected (recursive)'
);

// >20MB cap — one sparse 21MB file.
assert_error_contains(
	$V::validate( frx_make_block( $base, array( 'big.webp' => 21 * 1024 * 1024 ) ) ),
	'exceeds the 20971520-byte cap',
	'assets/ over 20MB is rejected'
);

// Just under the cap passes.
assert_true(
	true === $V::validate( frx_make_block( $base, array( 'ok.webp' => 1024 ) ) ),
	'small assets/ tree under cap passes'
);

// Extensionless file (LICENSE) rejected with the explicit missing-extension message.
assert_error_contains(
	$V::validate( frx_make_block( $base, array( 'LICENSE' => 'MIT' ) ) ),
	'missing extension',
	'extensionless asset file is rejected (missing extension)'
);

// Symlink inside assets/ rejected — even when named like an allowlisted raster.
$block_json_path = frx_make_block( $base, array( 'ok.webp' => 'x' ) );
$assets_dir      = dirname( $block_json_path ) . '/assets';
$target          = dirname( $block_json_path ) . '/outside.php';
file_put_contents( $target, '<?php evil();' );
if ( @symlink( $target, $assets_dir . '/pretty.webp' ) ) {
	assert_error_contains(
		$V::validate( $block_json_path ),
		'symlink "pretty.webp"',
		'symlink inside assets/ is rejected'
	);
} else {
	echo "SKIP: symlink inside assets/ is rejected (symlink() unavailable on this filesystem)\n";
}

// Unreadable subdir inside assets/ — error string, never a fatal.
// chmod 0000 does not block root reads (e.g. CI containers), so skip as root
// when posix is available, and attempt-and-detect otherwise.
$block_json_path = frx_make_block( $base, array( 'sub/inner.webp' => 'x' ) );
$assets_dir      = dirname( $block_json_path ) . '/assets';
$unreadable      = $assets_dir . '/sub';
$is_root         = function_exists( 'posix_geteuid' ) && 0 === posix_geteuid();
chmod( $unreadable, 0000 );
if ( $is_root || is_readable( $unreadable ) ) {
	echo "SKIP: unreadable assets/ subdir yields an error (perms do not block this user)\n";
} else {
	assert_error_contains(
		$V::validate( $block_json_path ),
		'unreadable directory "sub"',
		'unreadable assets/ subdir yields an error (no fatal)'
	);
}
chmod( $unreadable, 0755 );

// ---------------------------------------------------------------------------
// Exit.
// ---------------------------------------------------------------------------

if ( $GLOBALS['frx_tests_failed'] > 0 ) {
	echo "\n{$GLOBALS['frx_tests_failed']} test(s) FAILED.\n";
	exit( 1 );
}
echo "\nAll validator tests passed.\n";
exit( 0 );
