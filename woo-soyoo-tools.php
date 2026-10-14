<?php
/**
 * Plugin Name:       Woo SOYOO Tools
 * Plugin URI:        https://github.com/SOYOO974/woo-soyoo-tools/
 * Description:       Boîte à outils et améliorations transversales SOYOO pour WooCommerce (fiabilisation des e-mails différés Action Scheduler, correctifs natifs, optimisations back-office & front-office) avec gestion modulaire par fonctionnalité.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            SOYOO
 * Author URI:        https://soyoo.re
 * Text Domain:       woo-soyoo-tools
 * Domain Path:       /languages
 *
 * @package           WooSoyooTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Plugin constants.
define( 'WOO_SOYOO_TOOLS_VERSION', '1.0.0' );
define( 'WOO_SOYOO_TOOLS_FILE', __FILE__ );
define( 'WOO_SOYOO_TOOLS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOO_SOYOO_TOOLS_URL', plugin_dir_url( __FILE__ ) );
define( 'WOO_SOYOO_TOOLS_BASENAME', plugin_basename( __FILE__ ) );

// 1. Initialize the Plugin Update Checker for GitHub releases.
require_once WOO_SOYOO_TOOLS_DIR . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$woo_soyoo_tools_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/SOYOO974/woo-soyoo-tools/',
	__FILE__,
	'woo-soyoo-tools'
);

// Set the branch that contains the stable release.
$woo_soyoo_tools_update_checker->setBranch( 'main' );
$woo_soyoo_tools_update_checker->getVcsApi()->enableReleaseAssets();

// 2. Declare WooCommerce HPOS & Product Caching compatibility.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'product_instance_caching', __FILE__, true );
	}
} );

// 3. Load core classes and initialize features.
require_once WOO_SOYOO_TOOLS_DIR . 'includes/features/class-feature-force-french-emails.php';
require_once WOO_SOYOO_TOOLS_DIR . 'includes/class-feature-manager.php';
require_once WOO_SOYOO_TOOLS_DIR . 'includes/class-tools-admin.php';

/**
 * Boot Woo SOYOO Tools once plugins are loaded.
 */
function woo_soyoo_tools_init() {
	\SOYOO\Tools\Feature_Manager::instance()->boot();

	if ( is_admin() ) {
		new \SOYOO\Tools\Tools_Admin();
	}
}
add_action( 'plugins_loaded', 'woo_soyoo_tools_init', 15 );

/**
 * Add direct link to settings page on the Plugins screen.
 *
 * @param array $links Existing plugin action links.
 * @return array
 */
function woo_soyoo_tools_action_links( $links ) {
	$settings_url  = admin_url( 'admin.php?page=woo-soyoo-tools' );
	$settings_link = '<a href="' . esc_url( $settings_url ) . '" style="font-weight:600;color:#0f172a;">' . esc_html__( 'Fonctionnalités & Réglages', 'woo-soyoo-tools' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . WOO_SOYOO_TOOLS_BASENAME, 'woo_soyoo_tools_action_links' );
