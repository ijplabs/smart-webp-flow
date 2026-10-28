<?php
/**
 * Plugin Name:       Smart WebP Flow & Media Sync
 * Plugin URI:        https://github.com/ijplabs/smart-webp-flow
 * Description:       Lightweight and modern WebP converter for WordPress. Converts JPG/PNG uploads automatically, provides background chunked bulk sync for existing media, and seamlessly updates post content references without website downtime.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            IJPLabs
 * Author URI:        https://ijplabs.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       smart-webp-flow
 * Domain Path:       /languages
 *
 * @package SmartWebPFlow
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define root plugin constants.
define( 'SMART_WEBP_FLOW_VERSION', '1.1.0' );
define( 'SMART_WEBP_FLOW_FILE', __FILE__ );
define( 'SMART_WEBP_FLOW_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMART_WEBP_FLOW_URL', plugin_dir_url( __FILE__ ) );

// LiteSpace Public Key for Ed25519 Libsodium verification.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
define( 'LITESPACE_SMART_WEBP_PUBLIC_KEY', '8a8209d3606b45692641807e48ce8fd9d604b6a04633469bd32a5fc6ad78bea1' );

/**
 * Checks system requirements before loading the plugin.
 *
 * @return bool True if requirements are met, false otherwise.
 */
function smart_webp_flow_check_requirements(): bool {
	$min_php = '7.4';
	$min_wp  = '5.8';

	// Check PHP version.
	if ( version_compare( PHP_VERSION, $min_php, '<' ) ) {
		add_action(
			'admin_notices',
			function () use ( $min_php ) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					sprintf(
						/* translators: 1: Plugin name, 2: Minimum PHP version, 3: Current PHP version */
						esc_html__( '%1$s requires PHP version %2$s or higher. Your server is running PHP version %3$s. Please upgrade your PHP version.', 'smart-webp-flow' ),
						'<strong>Smart WebP Flow</strong>',
						esc_html( $min_php ),
						esc_html( PHP_VERSION )
					)
				);
			}
		);
		return false;
	}

	// Check WordPress version.
	global $wp_version;
	if ( version_compare( $wp_version, $min_wp, '<' ) ) {
		add_action(
			'admin_notices',
			function () use ( $min_wp, $wp_version ) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					sprintf(
						/* translators: 1: Plugin name, 2: Minimum WP version, 3: Current WP version */
						esc_html__( '%1$s requires WordPress version %2$s or higher. Your site is running WordPress version %3$s.', 'smart-webp-flow' ),
						'<strong>Smart WebP Flow</strong>',
						esc_html( $min_wp ),
						esc_html( $wp_version )
					)
				);
			}
		);
		return false;
	}

	return true;
}

// Halt if minimum requirements are not met.
if ( ! smart_webp_flow_check_requirements() ) {
	return;
}

// Require LiteSpace License SDK.
require_once SMART_WEBP_FLOW_DIR . 'includes/class-litespace-license.php';

// Require and register the PSR-4 Autoloader.
require_once SMART_WEBP_FLOW_DIR . 'src/Autoloader.php';
\SmartWebPFlow\Autoloader::register();

/**
 * Returns the LiteSpace license manager singleton instance.
 *
 * @return \LiteSpace_License
 */
function smart_webp_flow_license(): \LiteSpace_License {
	static $instance = null;
	if ( null === $instance ) {
		$api_url  = defined( 'LITESPACE_API_URL' ) ? LITESPACE_API_URL : 'https://api.litespace.id';
		$instance = new \LiteSpace_License(
			'smart-webp-flow',
			'Smart WebP Flow & Media Sync',
			SMART_WEBP_FLOW_VERSION,
			LITESPACE_SMART_WEBP_PUBLIC_KEY,
			SMART_WEBP_FLOW_FILE,
			$api_url
		);
	}
	return $instance;
}

/**
 * Returns the main plugin kernel instance.
 *
 * @return \SmartWebPFlow\Plugin
 */
function smart_webp_flow(): \SmartWebPFlow\Plugin {
	return \SmartWebPFlow\Plugin::get_instance( SMART_WEBP_FLOW_FILE );
}

// Register activation and deactivation hooks.
register_activation_hook(
	__FILE__,
	function () {
		smart_webp_flow()->activate();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		smart_webp_flow()->deactivate();
	}
);

// Initialize LiteSpace License Gateway (Priority 5, before plugin kernel).
add_action(
	'plugins_loaded',
	function () {
		smart_webp_flow_license();
	},
	5
);

// Initialize plugin kernel.
add_action(
	'plugins_loaded',
	function () {
		smart_webp_flow()->init();
	}
);
