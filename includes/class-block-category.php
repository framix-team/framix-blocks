<?php
/**
 * Editor block-category registrar.
 *
 * The loader hands us the set of category slugs it actually saw in the
 * loaded blocks' block.json files. We append any not already registered
 * by core, so this plugin's blocks get their own group in the inserter.
 *
 * @package Framix_Blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Framix_Blocks_Category — registers the editor category for loaded blocks.
 *
 * Ported and simplified from the Framix Components category registrar:
 * drops the DB registry / per-client lookups. Categories are collected
 * live from the blocks the loader discovered.
 */
class Framix_Blocks_Category {

	/**
	 * Singleton.
	 *
	 * @var Framix_Blocks_Category|null
	 */
	private static $instance = null;

	/**
	 * Category slug => human label, collected from loaded blocks.
	 *
	 * @var array<string, string>
	 */
	private $categories = array();

	/**
	 * Get the singleton instance.
	 *
	 * @return Framix_Blocks_Category
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire the filter.
	 */
	private function __construct() {
		add_filter( 'block_categories_all', array( $this, 'filter_block_categories' ), 10, 1 );
	}

	/**
	 * Register a category slug discovered on a loaded block.
	 *
	 * Idempotent. Called by the loader for each block it registers.
	 * A humanized label is derived from the slug ("framix" → "Framix Blocks",
	 * "acme-cards" → "Acme Cards").
	 *
	 * @param string $slug Category slug from block.json `category`.
	 * @return void
	 */
	public function register_slug( $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( '' === $slug || isset( $this->categories[ $slug ] ) ) {
			return;
		}

		$label = 'framix' === $slug
			? __( 'Framix Blocks', 'framix-blocks' )
			: ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );

		$this->categories[ $slug ] = $label;
	}

	/**
	 * Filter callback — append each collected category not already present.
	 *
	 * @param array $categories Existing block categories.
	 * @return array
	 */
	public function filter_block_categories( $categories ) {
		if ( ! is_array( $categories ) ) {
			$categories = array();
		}

		$existing = array();
		foreach ( $categories as $category ) {
			if ( is_array( $category ) && isset( $category['slug'] ) ) {
				$existing[ $category['slug'] ] = true;
			}
		}

		foreach ( $this->categories as $slug => $label ) {
			if ( isset( $existing[ $slug ] ) ) {
				continue;
			}
			$categories[] = array(
				'slug'  => $slug,
				'title' => $label,
				'icon'  => null,
			);
		}

		return $categories;
	}
}
