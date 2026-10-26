<?php
/**
 * Plugin Constants definition.
 *
 * @package SmartWebPFlow
 */

namespace SmartWebPFlow\Common;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Constants
 * Centralizes all static identifiers, options, and meta keys used by the plugin.
 */
class Constants {

	/**
	 * Plugin version.
	 */
	const VERSION = '1.0.0';

	/**
	 * Minimum PHP version required.
	 */
	const MIN_PHP_VERSION = '7.4';

	/**
	 * Minimum WordPress version required.
	 */
	const MIN_WP_VERSION = '5.8';

	/**
	 * Text domain for translations.
	 */
	const TEXT_DOMAIN = 'smart-webp-flow';

	/**
	 * Settings option name in wp_options.
	 */
	const OPTION_SETTINGS = 'smart_webp_flow_settings';

	/**
	 * Bulk migration state option name in wp_options.
	 */
	const OPTION_BULK_STATE = 'smart_webp_flow_bulk_state';

	/**
	 * Transient key used for the background worker concurrency mutex lock.
	 */
	const TRANSIENT_BULK_LOCK = 'smart_webp_flow_bulk_lock';

	/**
	 * Lock Time-to-Live (TTL) in seconds.
	 */
	const LOCK_TTL_SECONDS = 60;

	/**
	 * Post meta keys for attachment tracking.
	 */
	const META_STATUS          = '_webp_flow_status';
	const META_CONVERTED_TIME  = '_webp_flow_converted_time';
	const META_ORIGINAL_PATH   = '_webp_flow_original_path';
	const META_ORIGINAL_SIZE   = '_webp_flow_original_size';
	const META_WEBP_SIZE       = '_webp_flow_webp_size';
	const META_SAVINGS_PCT     = '_webp_flow_savings_pct';
	const META_ORIGINAL_BACKUP = '_webp_flow_backup_path';
	const META_CONTENT_BACKUP  = '_webp_flow_content_backup';

	/**
	 * Option key for content migration audit log.
	 */
	const OPTION_CONTENT_LOG = 'smart_webp_flow_content_log';

	/**
	 * Conversion status flags.
	 */
	const STATUS_CONVERTED = 'converted';
	const STATUS_FAILED    = 'failed';
	const STATUS_SKIPPED   = 'skipped';

	/**
	 * Bulk runner state flags.
	 */
	const STATE_IDLE                = 'idle';
	const STATE_RUNNING_INTERACTIVE = 'running_interactive';
	const STATE_RUNNING_BACKGROUND  = 'running_background';
	const STATE_PAUSED              = 'paused';
	const STATE_COMPLETED           = 'completed';

	/**
	 * File retention policies.
	 */
	const RETENTION_KEEP_ORIGINAL   = 'keep_original';
	const RETENTION_DELETE_ORIGINAL = 'delete_original';
	const DEFAULT_RETENTION_POLICY  = 'keep_original';

	/**
	 * Administrator capability required to run tasks.
	 */
	const ADMIN_CAPABILITY = 'manage_options';

	/**
	 * REST API Namespace.
	 */
	const REST_NAMESPACE = 'smart-webp/v1';

	/**
	 * Default image compression qualities.
	 */
	const DEFAULT_QUALITY_JPEG = 82;
	const DEFAULT_QUALITY_PNG  = 85;

	/**
	 * Default chunk size for bulk processing.
	 */
	const DEFAULT_CHUNK_SIZE = 5;

	/**
	 * Supported MIME types for conversion.
	 *
	 * @return array<string>
	 */
	public static function get_supported_mimes(): array {
		return array(
			'image/jpeg',
			'image/jpg',
			'image/png',
		);
	}
}
