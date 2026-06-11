<?php
/**
 * Media-defaults engine.
 *
 * A block.json `control: "media"` attribute (type integer, default 0) may
 * declare `media: { default_asset: "assets/x.webp", alt: "..." }`. At block
 * registration time this engine ensures that asset exists in the site's media
 * library — sideloading it on first encounter — and rewrites the attribute's
 * `default` to the resulting attachment ID, so the editor opens with the image
 * pre-selected and the frontend renders it.
 *
 * Idempotent per environment via a sha256 dedupe map persisted in a single
 * (autoload: false) WP option. Sideloads are serialized with a short transient
 * lock; a lost race is harmless (the dedupe map wins next request; an orphaned
 * duplicate attachment is acceptable — attachments are never deleted).
 *
 * Defensive by construction: resolve() never throws. Any per-attribute failure
 * degrades to "this attribute's default stays as-is" so a bad asset can never
 * break block registration for the rest of the page.
 *
 * @package Framix_Blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Framix_Blocks_Media_Defaults — resolves media default_asset → attachment ID.
 */
class Framix_Blocks_Media_Defaults {

	/**
	 * Option name holding the dedupe map + the `_files` hash memo.
	 *
	 * Shape:
	 *   array(
	 *     '<block-name>:<default_asset>:<sha16>' => <int attachment_id>,
	 *     '_files' => array(
	 *       '<abs-path>' => array( 'mtime' => int, 'size' => int, 'sha16' => string ),
	 *     ),
	 *   )
	 *
	 * @var string
	 */
	const OPTION = 'framix_blocks_media_defaults';

	/**
	 * Transient name for the sideload lock.
	 *
	 * @var string
	 */
	const LOCK = 'framix_blocks_md_lock';

	/**
	 * Lock TTL in seconds.
	 *
	 * @var int
	 */
	const LOCK_TTL = 30;

	/**
	 * Per-request static cache of the option (loaded at most once per pass).
	 *
	 * Null until first load; thereafter an array mirroring the option so
	 * several blocks resolving in one load_blocks() pass share one read.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * How many times hash_asset() actually hashed a file this request.
	 *
	 * Purely observational — lets tests assert the `_files` memo prevented a
	 * re-hash. Reset alongside the option cache by reset_cache().
	 *
	 * @var int
	 */
	private static $hash_calls = 0;

	/**
	 * Resolve an asset's media defaults into rewritten attribute defaults.
	 *
	 * Scans `$meta['attributes']` for any attribute carrying
	 * `media.default_asset`. If none do, returns null immediately (zero option
	 * reads) so the loader adds no `attributes` override. Otherwise returns the
	 * block's FULL attributes array (from `$meta`) with only the resolved
	 * defaults rewritten to attachment IDs.
	 *
	 * @param string $dir  Absolute path to the block directory.
	 * @param array  $meta Decoded block.json (expects `name` + `attributes`).
	 * @return array|null Attributes array with rewritten defaults, or null when
	 *                    no attribute carries `media.default_asset`.
	 */
	public static function resolve( $dir, $meta ) {
		if ( ! is_string( $dir ) || '' === $dir || ! is_array( $meta ) ) {
			return null;
		}

		$attributes = isset( $meta['attributes'] ) && is_array( $meta['attributes'] ) ? $meta['attributes'] : array();
		if ( empty( $attributes ) ) {
			return null;
		}

		// Fast path: bail before touching options unless some attribute
		// actually carries a media.default_asset.
		$targets = array();
		foreach ( $attributes as $attr_name => $attr_def ) {
			if ( is_array( $attr_def )
				&& isset( $attr_def['media']['default_asset'] )
				&& is_string( $attr_def['media']['default_asset'] )
				&& '' !== $attr_def['media']['default_asset']
			) {
				$targets[] = $attr_name;
			}
		}

		if ( empty( $targets ) ) {
			return null;
		}

		$block_name = isset( $meta['name'] ) ? (string) $meta['name'] : '';

		foreach ( $targets as $attr_name ) {
			try {
				$id = self::resolve_attribute( $dir, $block_name, $attributes[ $attr_name ] );
				if ( null !== $id ) {
					$attributes[ $attr_name ]['default'] = (int) $id;
				}
			} catch ( \Throwable $e ) {
				// Never throw out of resolve() — leave this default as-is.
				self::log( sprintf(
					'[framix-blocks] media-defaults: unexpected error resolving "%s" on "%s": %s',
					$attr_name,
					$block_name,
					$e->getMessage()
				) );
			}
		}

		return $attributes;
	}

	/**
	 * Resolve one media attribute to an attachment ID (or null to leave as-is).
	 *
	 * @param string $dir        Absolute block directory.
	 * @param string $block_name block.json `name`.
	 * @param array  $attr_def   The attribute definition (carries `media`).
	 * @return int|null Attachment ID, or null when nothing should be rewritten.
	 */
	private static function resolve_attribute( $dir, $block_name, $attr_def ) {
		$default_asset = (string) $attr_def['media']['default_asset'];
		$asset_path    = rtrim( $dir, '/' ) . '/' . $default_asset;

		// 1. Asset must exist on disk.
		if ( ! is_file( $asset_path ) ) {
			self::log( sprintf(
				'[framix-blocks] media-defaults: asset not found "%s" for "%s" — skipping.',
				$asset_path,
				$block_name
			) );
			return null;
		}

		// 2. sha16, memoized on mtime+size.
		$sha16 = self::sha16( $asset_path );
		if ( '' === $sha16 ) {
			self::log( sprintf(
				'[framix-blocks] media-defaults: could not hash "%s" for "%s" — skipping.',
				$asset_path,
				$block_name
			) );
			return null;
		}

		// 3. Dedupe key.
		$key = $block_name . ':' . $default_asset . ':' . $sha16;

		$option = self::option();

		// 5. Hit — reuse the stored attachment if it still exists.
		if ( isset( $option[ $key ] ) ) {
			$existing = (int) $option[ $key ];
			$post     = get_post( $existing );
			if ( null !== $post && isset( $post->post_type ) && 'attachment' === $post->post_type ) {
				return $existing;
			}
			// Attachment gone — fall through and re-sideload.
		}

		// 6. Miss — sideload under a short lock; never wait.
		if ( false !== get_transient( self::LOCK ) ) {
			// Another request is mid-sideload; skip this pass (default stays 0).
			return null;
		}

		set_transient( self::LOCK, 1, self::LOCK_TTL );

		$alt = isset( $attr_def['media']['alt'] ) && is_string( $attr_def['media']['alt'] ) ? $attr_def['media']['alt'] : '';

		try {
			$id = self::sideload( $asset_path, $default_asset, $alt );
		} finally {
			// Exception-safe release: should sideload() THROW (the
			// admin-includes require or media_handle_sideload itself), the
			// outer catch in resolve() swallows it — but the lock must not
			// stay wedged for its full TTL.
			delete_transient( self::LOCK );
		}

		if ( null === $id ) {
			return null;
		}

		// 8. Persist: dedupe key → id, refresh the file memo, write once.
		// Dedupe keys + _files memos accrue one entry per (block, asset,
		// content-version) — unbounded in theory, negligible in practice;
		// pruning could piggyback on this write if it ever matters.
		$option           = self::option();
		$option[ $key ]   = (int) $id;
		self::$cache      = $option;
		self::remember_file( $asset_path, $sha16 );
		update_option( self::OPTION, self::$cache, false );

		return (int) $id;
	}

	/**
	 * Compute the first 16 hex chars of sha256( file ), memoized on mtime+size.
	 *
	 * Reuses the stored sha16 when the file's current mtime+size match the
	 * memo; otherwise hashes and refreshes the memo (persisted on the next
	 * option write). Returns '' on any hash failure.
	 *
	 * @param string $asset_path Absolute path to the asset.
	 * @return string 16-hex-char digest, or '' on failure.
	 */
	private static function sha16( $asset_path ) {
		$mtime = @filemtime( $asset_path );
		$size  = @filesize( $asset_path );

		$option = self::option();
		$files  = isset( $option['_files'] ) && is_array( $option['_files'] ) ? $option['_files'] : array();

		if ( isset( $files[ $asset_path ] )
			&& is_array( $files[ $asset_path ] )
			&& isset( $files[ $asset_path ]['mtime'], $files[ $asset_path ]['size'], $files[ $asset_path ]['sha16'] )
			&& (int) $files[ $asset_path ]['mtime'] === (int) $mtime
			&& (int) $files[ $asset_path ]['size'] === (int) $size
			&& '' !== (string) $files[ $asset_path ]['sha16']
		) {
			return (string) $files[ $asset_path ]['sha16'];
		}

		$full = self::hash_asset( $asset_path );
		if ( false === $full || ! is_string( $full ) || '' === $full ) {
			return '';
		}

		$sha16 = substr( $full, 0, 16 );

		// Refresh the in-memory memo now; it is persisted on the next option
		// write (the sideload-success path). A read-only hit path needs no write.
		self::remember_file( $asset_path, $sha16 );

		return $sha16;
	}

	/**
	 * Hash one asset file (sha256) — counted seam around hash_file().
	 *
	 * The only place the engine hashes file contents. Counting invocations
	 * here lets tests assert that a fresh `_files` memo really does prevent
	 * a re-hash (see hash_call_count()).
	 *
	 * @param string $asset_path Absolute path to the asset.
	 * @return string|false Full hex digest, or false on failure.
	 */
	protected static function hash_asset( $asset_path ) {
		++self::$hash_calls;
		return @hash_file( 'sha256', $asset_path );
	}

	/**
	 * Number of real file-hash calls this request (test seam, read-only).
	 *
	 * @return int
	 */
	public static function hash_call_count() {
		return self::$hash_calls;
	}

	/**
	 * Update the `_files` memo for one asset in the cached option.
	 *
	 * @param string $asset_path Absolute path to the asset.
	 * @param string $sha16      16-hex-char digest.
	 * @return void
	 */
	private static function remember_file( $asset_path, $sha16 ) {
		$option = self::option();
		if ( ! isset( $option['_files'] ) || ! is_array( $option['_files'] ) ) {
			$option['_files'] = array();
		}
		$option['_files'][ $asset_path ] = array(
			'mtime' => (int) @filemtime( $asset_path ),
			'size'  => (int) @filesize( $asset_path ),
			'sha16' => (string) $sha16,
		);
		self::$cache = $option;
	}

	/**
	 * Sideload an asset into the media library.
	 *
	 * @param string $asset_path    Absolute source path (exists on disk).
	 * @param string $default_asset The block-relative asset path (for basename).
	 * @param string $alt           Optional alt text.
	 * @return int|null Attachment ID on success, null on any failure.
	 */
	private static function sideload( $asset_path, $default_asset, $alt ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$basename = basename( $default_asset );

		$tmp = wp_tempnam( $basename );
		if ( ! is_string( $tmp ) || '' === $tmp ) {
			self::log( sprintf( '[framix-blocks] media-defaults: wp_tempnam failed for "%s" — skipping.', $asset_path ) );
			return null;
		}

		try {
			if ( ! @copy( $asset_path, $tmp ) ) {
				self::log( sprintf( '[framix-blocks] media-defaults: copy to temp failed for "%s" — skipping.', $asset_path ) );
				return null;
			}

			$file = array(
				'name'     => $basename,
				'tmp_name' => $tmp,
			);

			$id = media_handle_sideload( $file, 0 );

			if ( is_wp_error( $id ) ) {
				self::log( sprintf(
					'[framix-blocks] media-defaults: media_handle_sideload failed for "%s": %s — skipping.',
					$asset_path,
					$id->get_error_message()
				) );
				return null;
			}

			$id = (int) $id;

			if ( '' !== $alt ) {
				update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
			}

			return $id;
		} finally {
			// Temp-file cleanup on every exit path — error return, WP_Error,
			// or a Throwable out of media_handle_sideload. Double-unlink-safe:
			// on success WordPress has already moved/removed the temp file, so
			// this @unlink is a harmless no-op.
			@unlink( $tmp );
		}
	}

	/**
	 * Load the option once per request (static cache).
	 *
	 * @return array The option contents (always an array).
	 */
	private static function option() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = is_array( $stored ) ? $stored : array();
		}
		return self::$cache;
	}

	/**
	 * Emit a diagnostic line, gated behind WP_DEBUG (loader convention).
	 *
	 * Mirrors Framix_Blocks_Loader::log() exactly — WP_DEBUG-gated (and
	 * WP_DEBUG_LOG when defined), `[framix-blocks]`-tagged, passed verbatim —
	 * so a recurring asset problem can't spam a production error log.
	 *
	 * @param string $message Pre-formatted log line.
	 * @return void
	 */
	private static function log( $message ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		if ( defined( 'WP_DEBUG_LOG' ) && ! WP_DEBUG_LOG ) {
			return;
		}
		error_log( $message );
	}

	/**
	 * Reset the per-request static cache (test seam).
	 *
	 * Lets a test simulate a fresh request after mutating the backing option
	 * store. No effect in production (each request starts with a null cache).
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$cache      = null;
		self::$hash_calls = 0;
	}
}
