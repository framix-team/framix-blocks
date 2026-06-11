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
