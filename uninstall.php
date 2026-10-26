<?php
/**
 * Uninstall Handler.
 * Fired when the plugin is uninstalled via WordPress Admin.
 *
 * @package SmartWebPFlow
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Clean up background worker locks.
delete_transient( 'smart_webp_flow_bulk_lock' );

// Retrieve plugin settings to check if data cleanup is requested.
$smart_webp_flow_settings = get_option( 'smart_webp_flow_settings', array() );

if ( ! empty( $smart_webp_flow_settings['delete_data_on_uninstall'] ) ) {
	// Purge options.
	delete_option( 'smart_webp_flow_settings' );
	delete_option( 'smart_webp_flow_bulk_state' );
	delete_option( 'smart_webp_flow_migration_log' );
}
