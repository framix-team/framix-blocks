<?php
/**
 * block.json schema validator.
 *
 * Used defensively by the loader: a block whose block.json fails these
 * checks is skipped + logged rather than registered, so one malformed
 * block can never break the editor for the others. Authoritative
 * scaffold-time enforcement lives in the `php-block` skill.
 *
 * @package Framix_Blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Framix_Blocks_BlockJSON_Validator — validates one block's block.json.
 *
 * Trimmed from the Framix Components validator: drops all deploy-time
 * concerns (size caps, client/slug matching, default-asset sideload
 * rules) and keeps the generic structural rules every block must obey.
 */
class Framix_Blocks_BlockJSON_Validator {

	/**
	 * Block-name regex (no PHP delimiters). Generic — namespace/slug, both
	 * lowercase kebab-case. NOT tied to any site prefix.
	 *
	 * @var string
	 */
	const BLOCK_NAME_REGEX = '^[a-z0-9-]+/[a-z0-9-]+$';

	/**
	 * Reserved attribute names — block.json MUST NOT define any of these.
	 * They collide with WordPress core block-supports attributes.
	 *
	 * @var string[]
	 */
	private static $reserved_attribute_names = array(
		'style',
		'className',
		'align',
		'anchor',
		'backgroundColor',
		'textColor',
		'gradient',
		'fontSize',
		'fontFamily',
		'lock',
		'metadata',
	);

	/**
	 * Allowed attribute types. Scalars, plus `array` for repeaters
	 * (control: "repeater" — consumed by repeater-control.js).
	 *
	 * @var string[]
	 */
	private static $allowed_attribute_types = array(
		'string',
		'integer',
		'number',
		'boolean',
		'array',
	);

	/**
	 * Allowed custom control values (consumed by media-control.js and
	 * repeater-control.js).
	 *
	 * @var string[]
	 */
	private static $allowed_controls = array(
		'media',
		'textarea',
		'repeater',
	);

	/**
	 * Allowed keys inside an attribute's `media` object.
	 *
	 * @var string[]
	 */
	private static $allowed_media_keys = array(
		'default_asset',
		'alt',
	);

	/**
	 * Sideloadable raster extensions allowed for a media `default_asset`
	 * (lowercase). SVG is deliberately excluded — WordPress refuses SVG
	 * uploads; decorative SVGs use framix_block_asset_url() instead.
	 *
	 * @var string[]
	 */
	private static $allowed_default_asset_ext = array(
		'webp',
		'png',
		'jpg',
		'jpeg',
		'gif',
		'avif',
	);

	/**
	 * Extensions permitted for any file under a block's assets/ dir
	 * (lowercase). Anything else — .php, .js, .html, dotfiles — is rejected.
	 *
	 * @var string[]
	 */
	private static $allowed_asset_ext = array(
		'webp',
		'png',
		'jpg',
		'jpeg',
		'gif',
		'svg',
		'avif',
		'woff',
		'woff2',
		'ttf',
		'otf',
		'json',
	);

	/**
	 * Total size cap (bytes) for a block's assets/ dir. 20 MB.
	 *
	 * @var int
	 */
	const ASSETS_MAX_BYTES = 20971520;

	/**
	 * Validate a block's block.json.
	 *
	 * @param string $block_json_path Absolute path to block.json.
	 * @return true|WP_Error True if valid, WP_Error with a `|`-joined message otherwise.
	 */
	public static function validate( $block_json_path ) {
		if ( ! is_string( $block_json_path ) || '' === $block_json_path ) {
			return new WP_Error( 'framix_blocks_bad_path', 'block.json validator received an empty file path.' );
		}

		if ( ! file_exists( $block_json_path ) || ! is_readable( $block_json_path ) ) {
			return new WP_Error( 'framix_blocks_unreadable', 'block.json is missing or unreadable.' );
		}

		$raw = file_get_contents( $block_json_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw ) {
			return new WP_Error( 'framix_blocks_unreadable', 'Could not read block.json contents.' );
		}

		$data = json_decode( $raw, true );
		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'framix_blocks_invalid_json',
				sprintf( 'block.json is not valid JSON: %s', json_last_error_msg() )
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'framix_blocks_not_object', 'block.json must contain a JSON object at the top level.' );
		}

		$errors = array();

		// 1. Name — required, must match the generic namespace/slug pattern.
		if ( ! isset( $data['name'] ) || ! is_string( $data['name'] ) || '' === $data['name'] ) {
			$errors[] = 'block.json "name" must be a non-empty string.';
		} elseif ( ! preg_match( '#' . self::BLOCK_NAME_REGEX . '#', $data['name'] ) ) {
			$errors[] = sprintf(
				'block.json name "%s" does not match required pattern "%s".',
				$data['name'],
				self::BLOCK_NAME_REGEX
			);
		}

		// 2. Attributes — reserved-name rejection + allowed types + control rules.
		if ( isset( $data['attributes'] ) ) {
			if ( ! is_array( $data['attributes'] ) ) {
				$errors[] = 'block.json "attributes" must be an object.';
			} else {
				foreach ( $data['attributes'] as $attr_name => $attr_def ) {
					foreach ( self::validate_attribute( (string) $attr_name, $attr_def ) as $msg ) {
						$errors[] = $msg;
					}
				}
			}
		}

		// 3. Asset tree — when the block dir holds an assets/ subdir, vet it.
		$assets_dir = dirname( $block_json_path ) . '/assets';
		if ( is_dir( $assets_dir ) ) {
			foreach ( self::validate_assets_dir( $assets_dir ) as $msg ) {
				$errors[] = $msg;
			}
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'framix_blocks_invalid', implode( ' | ', $errors ) );
		}

		return true;
	}

	/**
	 * Validate a single attribute definition.
	 *
	 * @param string $attr_name Attribute key.
	 * @param mixed  $attr_def  Attribute definition (expected: associative array).
	 * @return array<int, string> Error messages (empty on success).
	 */
	private static function validate_attribute( $attr_name, $attr_def ) {
		$errors = array();

		// Reserved-name rejection.
		if ( in_array( $attr_name, self::$reserved_attribute_names, true ) ) {
			$errors[] = sprintf( 'block.json attribute "%s" uses a reserved name.', $attr_name );
			return $errors;
		}

		if ( ! is_array( $attr_def ) ) {
			$errors[] = sprintf( 'block.json attribute "%s" must be defined as an object.', $attr_name );
			return $errors;
		}

		// Type — must be present and in the allowed scalar set.
		if ( ! isset( $attr_def['type'] ) ) {
			$errors[] = sprintf( 'block.json attribute "%s" is missing the required "type" key.', $attr_name );
		} elseif ( ! in_array( $attr_def['type'], self::$allowed_attribute_types, true ) ) {
			$errors[] = sprintf(
				'block.json attribute "%1$s" has unsupported type "%2$s"; allowed: %3$s.',
				$attr_name,
				is_scalar( $attr_def['type'] ) ? (string) $attr_def['type'] : gettype( $attr_def['type'] ),
				implode( ', ', self::$allowed_attribute_types )
			);
		}

		// Control — allowed values: media | textarea | repeater.
		if ( isset( $attr_def['control'] ) ) {
			if ( ! in_array( $attr_def['control'], self::$allowed_controls, true ) ) {
				$errors[] = sprintf(
					'block.json attribute "%1$s" control "%2$s" is not supported; allowed: %3$s.',
					$attr_name,
					is_scalar( $attr_def['control'] ) ? (string) $attr_def['control'] : gettype( $attr_def['control'] ),
					implode( ', ', self::$allowed_controls )
				);
			}

			// control: media requires type: integer (stores an attachment ID).
			if ( 'media' === $attr_def['control'] && ( ! isset( $attr_def['type'] ) || 'integer' !== $attr_def['type'] ) ) {
				$errors[] = sprintf(
					'block.json attribute "%s" uses control: media — type must be "integer".',
					$attr_name
				);
			}

			// control: repeater requires type: array (rows live in the attribute).
			if ( 'repeater' === $attr_def['control'] && ( ! isset( $attr_def['type'] ) || 'array' !== $attr_def['type'] ) ) {
				$errors[] = sprintf(
					'block.json attribute "%s" uses control: repeater — type must be "array".',
					$attr_name
				);
			}
		}

		// media — optional object, only with control: media; vetted below.
		if ( isset( $attr_def['media'] ) ) {
			foreach ( self::validate_media( $attr_name, $attr_def ) as $msg ) {
				$errors[] = $msg;
			}
		}

		// type: array is only meaningful to the repeater control.
		if ( isset( $attr_def['type'] ) && 'array' === $attr_def['type'] && ( ! isset( $attr_def['control'] ) || 'repeater' !== $attr_def['control'] ) ) {
			$errors[] = sprintf(
				'block.json attribute "%s" has type "array" — only supported with control: "repeater".',
				$attr_name
			);
		}

		// fields — optional object-repeater schema; validate recursively.
		if ( isset( $attr_def['fields'] ) ) {
			if ( ! isset( $attr_def['control'] ) || 'repeater' !== $attr_def['control'] ) {
				$errors[] = sprintf(
					'block.json attribute "%s" declares "fields" — only supported with control: "repeater".',
					$attr_name
				);
			} elseif ( ! is_array( $attr_def['fields'] ) || empty( $attr_def['fields'] ) ) {
				$errors[] = sprintf(
					'block.json attribute "%s" "fields" must be a non-empty object of field definitions.',
					$attr_name
				);
			} else {
				foreach ( $attr_def['fields'] as $field_name => $field_def ) {
					foreach ( self::validate_attribute( $attr_name . '.' . (string) $field_name, $field_def ) as $msg ) {
						$errors[] = $msg;
					}
				}
			}
		}

		return $errors;
	}

	/**
	 * Validate an attribute's `media` object (media-defaults schema).
	 *
	 * Only legal alongside control: media. Allows exactly default_asset + alt;
	 * default_asset must be a safe relative path into the block's assets/ dir
	 * with a sideloadable raster extension, and a media default_asset forbids
	 * a hardcoded non-zero schema `default`. File existence is NOT checked
	 * here — that is runtime engine behavior, not schema validation.
	 *
	 * @param string $attr_name Attribute key (for messages).
	 * @param array  $attr_def  Full attribute definition (holds `media`).
	 * @return array<int, string> Error messages (empty on success).
	 */
	private static function validate_media( $attr_name, $attr_def ) {
		$errors = array();
		$media  = $attr_def['media'];

		// media only legal alongside control: media.
		if ( ! isset( $attr_def['control'] ) || 'media' !== $attr_def['control'] ) {
			$errors[] = sprintf(
				'block.json attribute "%s" declares "media" — only supported with control: "media".',
				$attr_name
			);
			return $errors;
		}

		if ( ! is_array( $media ) ) {
			$errors[] = sprintf( 'block.json attribute "%s" "media" must be an object.', $attr_name );
			return $errors;
		}

		// Allowed keys: exactly default_asset + alt.
		foreach ( $media as $media_key => $unused ) {
			if ( ! in_array( $media_key, self::$allowed_media_keys, true ) ) {
				$errors[] = sprintf(
					'block.json attribute "%1$s" "media" has unsupported key "%2$s"; allowed: %3$s.',
					$attr_name,
					is_scalar( $media_key ) ? (string) $media_key : gettype( $media_key ),
					implode( ', ', self::$allowed_media_keys )
				);
			}
		}

		// alt — when present, a string.
		if ( isset( $media['alt'] ) && ! is_string( $media['alt'] ) ) {
			$errors[] = sprintf( 'block.json attribute "%s" "media.alt" must be a string.', $attr_name );
		}

		// default_asset — when present, a safe relative assets/ path.
		if ( isset( $media['default_asset'] ) ) {
			$path = $media['default_asset'];

			if ( ! is_string( $path ) || '' === $path ) {
				$errors[] = sprintf( 'block.json attribute "%s" "media.default_asset" must be a non-empty string.', $attr_name );
			} else {
				if ( 0 !== strpos( $path, 'assets/' ) ) {
					$errors[] = sprintf( 'block.json attribute "%s" "media.default_asset" must be a relative path starting with "assets/".', $attr_name );
				}
				if ( false !== strpos( $path, '://' ) ) {
					$errors[] = sprintf( 'block.json attribute "%s" "media.default_asset" must not contain a URL scheme.', $attr_name );
				}
				if ( 0 === strpos( $path, '/' ) ) {
					$errors[] = sprintf( 'block.json attribute "%s" "media.default_asset" must not be an absolute path.', $attr_name );
				}
				if ( in_array( '..', explode( '/', $path ), true ) ) {
					$errors[] = sprintf( 'block.json attribute "%s" "media.default_asset" must not contain a ".." path segment.', $attr_name );
				}
				if ( false !== strpos( $path, "\0" ) ) {
					$errors[] = sprintf( 'block.json attribute "%s" "media.default_asset" must not contain a NUL byte.', $attr_name );
				}

				$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
				if ( ! in_array( $ext, self::$allowed_default_asset_ext, true ) ) {
					$errors[] = sprintf(
						'block.json attribute "%1$s" "media.default_asset" extension "%2$s" is not allowed; allowed: %3$s.',
						$attr_name,
						$ext,
						implode( ', ', self::$allowed_default_asset_ext )
					);
				}
			}

			// A media default_asset forbids a hardcoded non-zero schema default
			// (the engine supplies the real attachment ID at runtime).
			if ( isset( $attr_def['default'] ) && 0 !== $attr_def['default'] ) {
				$errors[] = sprintf(
					'block.json attribute "%s" carries "media.default_asset" — the schema "default" must be 0 or absent.',
					$attr_name
				);
			}
		}

		return $errors;
	}

	/**
	 * Validate a block's assets/ directory (extension allowlist + size cap).
	 *
	 * Recursive but cheap — block asset dirs are small, so no caching. Every
	 * file must carry an allowlisted extension: anything else (notably
	 * .php/.js/.html/.htaccess/dotfiles) is an error, and extensionless
	 * files (LICENSE, Makefile) are deliberately rejected too. Symlinks are
	 * rejected outright — a link named pretty.webp can target a .php outside
	 * the tree, and block authors have no reason to ship them. Unreadable
	 * subdirectories are reported as errors, and the scan itself can never
	 * fatal: the iterator runs with CATCH_GET_CHILD and the whole walk is
	 * wrapped in a Throwable guard that converts failures into validation
	 * errors (this runs on `init` every request — it must only ever skip).
	 *
	 * @param string $assets_dir Absolute path to the block's assets/ dir.
	 * @return array<int, string> Error messages (empty on success).
	 */
	private static function validate_assets_dir( $assets_dir ) {
		$errors = array();
		$total  = 0;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $assets_dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);

			foreach ( $iterator as $file ) {
				// Symlinks bypass the extension allowlist — reject outright.
				if ( $file->isLink() ) {
					$errors[] = sprintf(
						'block assets/ contains a symlink "%s" — symlinks are not allowed in asset trees.',
						$file->getFilename()
					);
					continue;
				}

				if ( $file->isDir() ) {
					if ( ! $file->isReadable() ) {
						$errors[] = sprintf(
							'block assets/ contains an unreadable directory "%s" — could not scan it.',
							$file->getFilename()
						);
					}
					continue;
				}

				if ( ! $file->isFile() ) {
					continue;
				}

				$ext = strtolower( $file->getExtension() );
				if ( '' === $ext ) {
					$errors[] = sprintf(
						'block assets/ contains a file "%s" with a missing extension — every asset must carry an allowlisted extension.',
						$file->getFilename()
					);
				} elseif ( ! in_array( $ext, self::$allowed_asset_ext, true ) ) {
					$errors[] = sprintf(
						'block assets/ contains a disallowed file "%s" (extension not in the asset allowlist).',
						$file->getFilename()
					);
				}

				$total += (int) $file->getSize();
			}
		} catch ( \Throwable $e ) {
			$errors[] = sprintf( 'block assets/ could not be scanned: %s.', $e->getMessage() );
			return $errors;
		}

		if ( $total > self::ASSETS_MAX_BYTES ) {
			$errors[] = sprintf(
				'block assets/ total size %1$d bytes exceeds the %2$d-byte cap.',
				$total,
				self::ASSETS_MAX_BYTES
			);
		}

		return $errors;
	}

	/**
	 * Expose the reserved-attribute list (for tooling / cross-checks).
	 *
	 * @return string[]
	 */
	public static function reserved_attribute_names() {
		return self::$reserved_attribute_names;
	}

	/**
	 * Expose the allowed-attribute-types list (for tooling / cross-checks).
	 *
	 * @return string[]
	 */
	public static function allowed_attribute_types() {
		return self::$allowed_attribute_types;
	}
}
