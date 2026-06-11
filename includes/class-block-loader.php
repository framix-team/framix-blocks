<?php
/**
 * Block discovery + registration.
 *
 * On `init` (priority 5), collects the block-source directories registered
 * through the `framix_blocks_block_dirs` filter, then for each immediate
 * subdirectory holding a `block.json` validates it defensively, requires the
 * matching `render.php` (which defines the block's render function), and calls
 * `register_block_type()` on the directory so WordPress 7.0 reads the metadata
 * and — via `supports.autoRegister` — generates the editor preview + Inspector.
 *
 * Core ships no blocks of its own: sources (e.g. the per-site
 * framix-site-blocks plugin) add their `blocks/` dir through the filter.
 *
 * The loader is fully generic: it never reads site config. The render
 * callback name is derived from each block's block.json `name`:
 *
 *   namespace/slug  →  <namespace_underscored>_<slug_underscored>_render
 *   framix/sample-card  →  framix_sample_card_render
 *
 * @package Framix_Blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Framix_Blocks_Loader — discovers and registers blocks from filter-registered dirs.
 */
class Framix_Blocks_Loader {

	/**
	 * Singleton.
	 *
	 * @var Framix_Blocks_Loader|null
	 */
	private static $instance = null;

	/**
	 * Block names this plugin registered (e.g. ['framix/sample-card']).
	 *
	 * Populated during load_blocks(); consumed by enqueue_media_control()
	 * to localize the registered-block list for the editor JS.
	 *
	 * @var string[]
	 */
	private $loaded_blocks = array();

	/**
	 * Get the singleton instance.
	 *
	 * @return Framix_Blocks_Loader
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire the hooks.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_blocks' ), 5 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_media_control' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_repeater_control' ) );
	}

	/**
	 * Discover and register every block under the filter-registered dirs.
	 *
	 * Runs on `init` priority 5. Collects the block-source directories from
	 * the `framix_blocks_block_dirs` filter and registers every block found
	 * in their immediate subdirectories via {@see load_block()}.
	 *
	 * @return void
	 */
	public function load_blocks() {
		/**
		 * Filters the list of directories to load blocks from.
		 *
		 * Each entry is an absolute path to a directory whose immediate
		 * subdirectories are blocks (<dir>/<slug>/block.json + render.php).
		 * Companion plugins register their dirs here — e.g. the per-site
		 * framix-site-blocks plugin adds its blocks/ dir. Core ships none.
		 *
		 * @param string[] $dirs Absolute block-source directory paths.
		 */
		$block_dirs = apply_filters( 'framix_blocks_block_dirs', array() );

		foreach ( (array) $block_dirs as $blocks_dir ) {
			if ( ! is_string( $blocks_dir ) || '' === $blocks_dir || ! is_dir( $blocks_dir ) ) {
				continue;
			}

			$entries = glob( rtrim( $blocks_dir, '/' ) . '/*', GLOB_ONLYDIR );
			if ( empty( $entries ) ) {
				continue;
			}

			foreach ( $entries as $dir ) {
				$this->load_block( $dir );
			}
		}
	}

	/**
	 * Validate and register a single block directory.
	 *
	 * For a block directory <dir>/ that holds a block.json:
	 *   1. Validate block.json defensively — skip + log if invalid.
	 *   2. Derive the render-fn name from block.json `name`.
	 *   3. Require render.php (defines the render function).
	 *   4. register_block_type( $dir, [ 'render_callback' => $fn ] ).
	 *   5. Register its editor category and track the registered name.
	 *
	 * A missing render fn or invalid block.json is skipped + logged — it
	 * never fatals, so one bad block can't break the editor for the rest.
	 *
	 * @param string $dir Absolute path to a single block directory.
	 * @return void
	 */
	private function load_block( $dir ) {
		$block_json = $dir . '/block.json';

		if ( ! file_exists( $block_json ) ) {
			return;
		}

		// Defensive validation — skip + log invalid blocks.
		$valid = Framix_Blocks_BlockJSON_Validator::validate( $block_json );
		if ( is_wp_error( $valid ) ) {
			$this->log( sprintf(
				'[framix-blocks] Skipping "%s": %s',
				$dir,
				$valid->get_error_message()
			) );
			return;
		}

		$meta = json_decode( (string) file_get_contents( $block_json ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$name = isset( $meta['name'] ) ? (string) $meta['name'] : '';

		$render_fn = $this->derive_render_fn( $name );
		if ( '' === $render_fn ) {
			$this->log( sprintf( '[framix-blocks] Skipping "%s": could not derive render fn from name "%s".', $dir, $name ) );
			return;
		}

		$render_file = $dir . '/render.php';
		if ( file_exists( $render_file ) ) {
			require_once $render_file;
		}

		if ( ! function_exists( $render_fn ) ) {
			$this->log( sprintf(
				'[framix-blocks] Skipping "%s": render function %s() not defined in render.php.',
				$name,
				$render_fn
			) );
			return;
		}

		$type = register_block_type( $dir, array( 'render_callback' => $render_fn ) );

		if ( $type instanceof WP_Block_Type ) {
			$this->loaded_blocks[] = $type->name;

			// Register the editor category this block declares.
			if ( isset( $meta['category'] ) && '' !== $meta['category'] ) {
				Framix_Blocks_Category::instance()->register_slug( (string) $meta['category'] );
			}
		}
	}

	/**
	 * Derive the render-callback name from a block.json `name`.
	 *
	 *   namespace/slug → <namespace_underscored>_<slug_underscored>_render
	 *   framix/sample-card → framix_sample_card_render
	 *   acme-co/hero → acme_co_hero_render
	 *
	 * @param string $name The block.json `name` (namespace/slug).
	 * @return string Render-fn name, or '' if the name is malformed.
	 */
	private function derive_render_fn( $name ) {
		if ( ! is_string( $name ) || false === strpos( $name, '/' ) ) {
			return '';
		}

		list( $namespace, $slug ) = explode( '/', $name, 2 );
		if ( '' === $namespace || '' === $slug ) {
			return '';
		}

		return str_replace( '-', '_', $namespace ) . '_' . str_replace( '-', '_', $slug ) . '_render';
	}

	/**
	 * Emit a diagnostic skip-reason line, gated behind WP_DEBUG.
	 *
	 * A persistently-malformed block.json shipped from a site-code repo
	 * must never spam the production PHP error log on every request, so
	 * these developer-facing diagnostics only emit when debugging is on
	 * (WP_DEBUG, additionally requiring WP_DEBUG_LOG if it is defined —
	 * matching WordPress's own debug-logging convention). The skip itself
	 * still happens unconditionally at the call site; only the log line is
	 * gated. Message text + the `[framix-blocks]` tag are passed through
	 * verbatim.
	 *
	 * @param string $message Pre-formatted log line.
	 * @return void
	 */
	private function log( $message ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		if ( defined( 'WP_DEBUG_LOG' ) && ! WP_DEBUG_LOG ) {
			return;
		}
		error_log( $message );
	}

	/**
	 * Enqueue the editor-side media-control shim.
	 *
	 * WordPress 7.0 autoRegister already generates the editor preview and
	 * Inspector for each block. This script only swaps the auto-generated
	 * integer control for a MediaUpload picker on any attribute declaring
	 * `control: "media"`, storing the attachment ID.
	 *
	 * @return void
	 */
	public function enqueue_media_control() {
		if ( empty( $this->loaded_blocks ) ) {
			return;
		}

		wp_enqueue_script(
			'framix-blocks-media-control',
			FRAMIX_BLOCKS_URL . 'assets/media-control.js',
			array(
				'wp-blocks',
				'wp-block-editor',
				'wp-element',
				'wp-components',
				'wp-hooks',
				'wp-compose',
				'wp-data',
			),
			FRAMIX_BLOCKS_VERSION,
			true
		);
	}

	/**
	 * Enqueue the editor-side repeater-control shim.
	 *
	 * Sidebar CRUD UI for any attribute declaring `control: "repeater"`
	 * (always type: array) — simple string rows, or object rows via the
	 * attribute's `fields` schema, with nesting through recursion. Like
	 * the media control, it never renders the block body; the
	 * server-rendered preview stays canonical.
	 *
	 * @return void
	 */
	public function enqueue_repeater_control() {
		if ( empty( $this->loaded_blocks ) ) {
			return;
		}

		wp_enqueue_script(
			'framix-blocks-repeater-control',
			FRAMIX_BLOCKS_URL . 'assets/repeater-control.js',
			array(
				'wp-blocks',
				'wp-block-editor',
				'wp-element',
				'wp-components',
				'wp-hooks',
				'wp-compose',
				'wp-data',
			),
			FRAMIX_BLOCKS_VERSION,
			true
		);
	}
}
