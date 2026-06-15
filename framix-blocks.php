<?php
/**
 * Plugin Name:       Framix Blocks
 * Plugin URI:        https://github.com/framix-team/framix-blocks
 * Description:       Powers your site's custom-designed content blocks — fast-loading sections your team edits visually right inside WordPress. Built and kept up to date automatically by Framix.
 * Version:           1.2.1
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Framix
 * Author URI:        https://framix.net
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       framix-blocks
 * Domain Path:       /languages
 * Update URI:        https://github.com/framix-team/framix-blocks
 *
 * @package Framix_Blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FRAMIX_BLOCKS_VERSION', '1.2.1' );
define( 'FRAMIX_BLOCKS_DIR', plugin_dir_path( __FILE__ ) );
define( 'FRAMIX_BLOCKS_URL', plugin_dir_url( __FILE__ ) );

require_once FRAMIX_BLOCKS_DIR . 'inc/helpers.php';
require_once FRAMIX_BLOCKS_DIR . 'includes/class-blockjson-validator.php';
require_once FRAMIX_BLOCKS_DIR . 'includes/class-media-defaults.php';
require_once FRAMIX_BLOCKS_DIR . 'includes/class-block-category.php';
require_once FRAMIX_BLOCKS_DIR . 'includes/class-block-loader.php';

Framix_Blocks_Loader::instance();
Framix_Blocks_Category::instance();

// Native updates from this repo's GitHub Releases (asset framix-blocks.zip).
require_once FRAMIX_BLOCKS_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
$framix_blocks_puc = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/framix-team/framix-blocks/',
	__FILE__,
	'framix-blocks'
);
$framix_blocks_puc->getVcsApi()->enableReleaseAssets();
