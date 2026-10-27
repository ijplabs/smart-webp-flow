<?php
/**
 * Main Plugin Kernel.
 *
 * @package SmartWebPFlow
 */

namespace SmartWebPFlow;

use SmartWebPFlow\Common\Constants;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 * Central orchestrator and singleton application container.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private $plugin_file = '';

	/**
	 * Absolute directory path to the plugin root.
	 *
	 * @var string
	 */
	private $plugin_dir = '';

	/**
	 * Base URL to the plugin root.
	 *
	 * @var string
	 */
	private $plugin_url = '';

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $version = Constants::VERSION;

	/**
	 * Upload service instance.
	 *
	 * @var Services\UploadService|null
	 */
	private $upload_service = null;

	/**
	 * Metadata service instance.
	 *
	 * @var Services\MetadataService|null
	 */
	private $metadata_service = null;

	/**
	 * Post content service instance.
	 *
	 * @var Services\PostContentService|null
	 */
	private $post_content_service = null;

	/**
	 * LiteSpace updater service instance.
	 *
	 * @var Services\LiteSpaceUpdater|null
	 */
	private $updater_service = null;

	/**
	 * Admin page management instance.
	 *
	 * @var Admin\AdminPage|null
	 */
	private $admin_page = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @param string $plugin_file Main plugin file path.
	 * @return Plugin
	 */
	public static function get_instance( string $plugin_file = '' ): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self( $plugin_file );
		}
		return self::$instance;
	}

	/**
	 * Private constructor to enforce singleton.
	 *
	 * @param string $plugin_file Main plugin file path.
	 */
	private function __construct( string $plugin_file = '' ) {
		$this->plugin_file = $plugin_file;
		$this->plugin_dir  = plugin_dir_path( $plugin_file );
		$this->plugin_url  = plugin_dir_url( $plugin_file );
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserializing.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton instance.' );
	}

	/**
	 * Bootstraps the plugin lifecycle and registers core WordPress hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		// Load internationalization text domain.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Register plugin action links (e.g. "Settings" on Plugins list page).
		if ( ! empty( $this->plugin_file ) ) {
			add_filter( 'plugin_action_links_' . plugin_basename( $this->plugin_file ), array( $this, 'add_action_links' ) );
		}

		// Register core REST API routes.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Initialize core services.
		$this->upload_service       = new Services\UploadService();
		$this->metadata_service     = new Services\MetadataService();
		$this->post_content_service = new Services\PostContentService();

		$this->upload_service->register_hooks();
		$this->metadata_service->register_hooks();
		$this->post_content_service->register_hooks();

		// Initialize LiteSpace auto-updater & licensing service.
		$this->updater_service = new Services\LiteSpaceUpdater( $this->plugin_file, $this->version );
		$this->updater_service->register_hooks();

		// Initialize admin interface if in admin context.
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			$this->admin_page = new Admin\AdminPage();
			$this->admin_page->register_hooks();
		}
	}

	/**
	 * Registers REST API routes for bulk synchronization, background daemon, and content sync.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		$bulk_sync_controller    = new Rest\BulkSyncController();
		$background_controller   = new Rest\BackgroundController();
		$content_sync_controller = new Rest\ContentSyncController();

		$bulk_sync_controller->register_routes();
		$background_controller->register_routes();
		$content_sync_controller->register_routes();
	}

	/**
	 * Loads plugin translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			Constants::TEXT_DOMAIN,
			false,
			dirname( plugin_basename( $this->plugin_file ) ) . '/languages'
		);
	}

	/**
	 * Adds quick links to the plugins page.
	 *
	 * @param array<string, string> $links Existing action links.
	 * @return array<string, string>
	 */
	public function add_action_links( array $links ): array {
		$settings_url  = admin_url( 'options-general.php?page=smart-webp-flow' );
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $settings_url ),
			esc_html__( 'Settings', 'smart-webp-flow' )
		);

		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Activation hook logic.
	 * Sets default options and initializes state machine.
	 *
	 * @return void
	 */
	public function activate(): void {
		// Initialize default settings if not already present.
		$current_settings = get_option( Constants::OPTION_SETTINGS, false );
		if ( false === $current_settings ) {
			$defaults = array(
				'engine_mode'        => 'auto', // Supported engines: auto, imagick, gd, api.
				'quality_jpeg'       => Constants::DEFAULT_QUALITY_JPEG,
				'quality_png'        => Constants::DEFAULT_QUALITY_PNG,
				'retention_policy'   => Constants::DEFAULT_RETENTION_POLICY, // Default: keep_original (Allows easy rollback).
				'dynamic_filter'     => 1, // Set to 1 to enable the_content dynamic webp replacement.
				'throttle_pacing_ms' => 250, // Pacing delay in ms between images.
				'api_endpoint'       => '',
				'api_key'            => '',
			);
			update_option( Constants::OPTION_SETTINGS, $defaults );
		}

		// Initialize bulk state machine if not present.
		$current_state = get_option( Constants::OPTION_BULK_STATE, false );
		if ( false === $current_state ) {
			$initial_state = array(
				'status'          => Constants::STATE_IDLE,
				'last_id'         => 0,
				'total_items'     => 0,
				'processed_count' => 0,
				'saved_bytes'     => 0,
				'updated_time'    => time(),
			);
			update_option( Constants::OPTION_BULK_STATE, $initial_state );
		}
	}

	/**
	 * Deactivation hook logic.
	 * Releases any active mutex locks to avoid stale state.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		delete_transient( Constants::TRANSIENT_BULK_LOCK );
	}

	/**
	 * Gets LiteSpace updater service.
	 *
	 * @return Services\LiteSpaceUpdater|null
	 */
	public function get_updater_service(): ?Services\LiteSpaceUpdater {
		return $this->updater_service;
	}

	/**
	 * Gets plugin version.
	 *
	 * @return string
	 */
	public function get_version(): string {
		return $this->version;
	}

	/**
	 * Gets plugin directory path.
	 *
	 * @return string
	 */
	public function get_plugin_dir(): string {
		return $this->plugin_dir;
	}

	/**
	 * Gets plugin directory URL.
	 *
	 * @return string
	 */
	public function get_plugin_url(): string {
		return $this->plugin_url;
	}

	/**
	 * Gets main plugin file path.
	 *
	 * @return string
	 */
	public function get_plugin_file(): string {
		return $this->plugin_file;
	}

	/**
	 * Gets upload service.
	 *
	 * @return Services\UploadService|null
	 */
	public function get_upload_service(): ?Services\UploadService {
		return $this->upload_service;
	}

	/**
	 * Gets metadata service.
	 *
	 * @return Services\MetadataService|null
	 */
	public function get_metadata_service(): ?Services\MetadataService {
		return $this->metadata_service;
	}

	/**
	 * Gets post content service.
	 *
	 * @return Services\PostContentService|null
	 */
	public function get_post_content_service(): ?Services\PostContentService {
		return $this->post_content_service;
	}

	/**
	 * Gets admin page instance.
	 *
	 * @return Admin\AdminPage|null
	 */
	public function get_admin_page(): ?Admin\AdminPage {
		return $this->admin_page;
	}
}
