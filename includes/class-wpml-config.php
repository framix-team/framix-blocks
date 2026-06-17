<?php
/**
 * WPML config derivation from block.json translatable flags.
 *
 * Pure logic, no WordPress calls. Reads the per-attribute translatable
 * convention and normalizes it into descriptors the loader aggregates and
 * feeds to WPML (branch A: runtime filter) or renders to wpml-config.xml
 * (branch B: generated file). See docs/translatable-attributes.md.
 *
 * Convention:
 *   plain string attr:     "translatable": true
 *   array-of-objects attr: "translatable": { "fields": [ "title", "body", "label" ] }
 *
 * @package Framix_Blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Framix_Blocks_WPML_Config {

	/**
	 * Extract translatable attributes from one block's attributes map.
	 *
	 * @param string              $block_name namespace/slug.
	 * @param array<string,mixed> $attributes block.json `attributes`.
	 * @return array{block:string,keys:array<int,string>,repeaters:array<int,array{attr:string,fields:array<int,string>}>}
	 */
	public static function extract( $block_name, array $attributes ) {
		$keys      = array();
		$repeaters = array();

		foreach ( $attributes as $attr_name => $def ) {
			if ( ! is_array( $def ) || ! isset( $def['translatable'] ) ) {
				continue;
			}

			$flag = $def['translatable'];

			// Plain string attr: "translatable": true
			if ( true === $flag ) {
				$keys[] = (string) $attr_name;
				continue;
			}

			// Array-of-objects attr: "translatable": { "fields": [ ... ] }
			if ( is_array( $flag ) && isset( $flag['fields'] ) && is_array( $flag['fields'] ) ) {
				$fields = array();
				foreach ( $flag['fields'] as $field ) {
					if ( is_string( $field ) && '' !== $field ) {
						$fields[] = $field;
					}
				}
				if ( ! empty( $fields ) ) {
					$repeaters[] = array(
						'attr'   => (string) $attr_name,
						'fields' => $fields,
					);
				}
			}
			// Any other shape (e.g. translatable:false) is ignored.
		}

		return array(
			'block'     => (string) $block_name,
			'keys'      => $keys,
			'repeaters' => $repeaters,
		);
	}

	/**
	 * Drop descriptors that carry nothing translatable.
	 *
	 * @param array<int,array> $descriptors extract() outputs.
	 * @return array<int,array>
	 */
	public static function aggregate( array $descriptors ) {
		$out = array();
		foreach ( $descriptors as $d ) {
			if ( ! is_array( $d ) ) {
				continue;
			}
			if ( ! empty( $d['keys'] ) || ! empty( $d['repeaters'] ) ) {
				$out[] = $d;
			}
		}
		return $out;
	}

	/**
	 * Branch-B fallback: render descriptors as a wpml-config.xml string.
	 *
	 * Mirrors the shape WPML expects (and that Kadence ships):
	 *   <wpml-config><gutenberg-blocks>
	 *     <gutenberg-block type="ns/slug" translate="1">
	 *       <key name="title" />
	 *       <key name="items"><key name="*"><key name="label" /></key></key>
	 *     </gutenberg-block>
	 *   </gutenberg-blocks></wpml-config>
	 *
	 * @param array<int,array> $descriptors aggregate() output.
	 * @return string
	 */
	public static function to_xml( array $descriptors ) {
		$lines   = array();
		$lines[] = '<wpml-config>';
		$lines[] = "\t<gutenberg-blocks>";

		foreach ( $descriptors as $d ) {
			$type    = htmlspecialchars( (string) $d['block'], ENT_QUOTES );
			$lines[] = sprintf( "\t\t<gutenberg-block type=\"%s\" translate=\"1\">", $type );

			foreach ( (array) ( $d['keys'] ?? array() ) as $key ) {
				$lines[] = sprintf( "\t\t\t<key name=\"%s\" />", htmlspecialchars( (string) $key, ENT_QUOTES ) );
			}

			foreach ( (array) ( $d['repeaters'] ?? array() ) as $rep ) {
				$attr    = htmlspecialchars( (string) $rep['attr'], ENT_QUOTES );
				$lines[] = sprintf( "\t\t\t<key name=\"%s\">", $attr );
				$lines[] = "\t\t\t\t<key name=\"*\">";
				foreach ( (array) $rep['fields'] as $field ) {
					$lines[] = sprintf( "\t\t\t\t\t<key name=\"%s\" />", htmlspecialchars( (string) $field, ENT_QUOTES ) );
				}
				$lines[] = "\t\t\t\t</key>";
				$lines[] = "\t\t\t</key>";
			}

			$lines[] = "\t\t</gutenberg-block>";
		}

		$lines[] = "\t</gutenberg-blocks>";
		$lines[] = '</wpml-config>';

		return implode( "\n", $lines ) . "\n";
	}
}
