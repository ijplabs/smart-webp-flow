<?php
/**
 * Admin Dashboard & Settings Management Page.
 *
 * @package SmartWebPFlow\Admin
 */

namespace SmartWebPFlow\Admin;

use SmartWebPFlow\Common\Constants;
use SmartWebPFlow\Engines\EngineFactory;
use SmartWebPFlow\Background\StateTracker;
use SmartWebPFlow\Background\ThrottleManager;
use SmartWebPFlow\Background\LockManager;
use SmartWebPFlow\Services\ContentMigrationService;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AdminPage
 * Renders the administrative dashboard tabs, system diagnostics, and settings controls.
 */
class AdminPage {

	/**
	 * Hook suffix for the options page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Registers WordPress admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// LiteSpace AJAX Actions.
		add_action( 'wp_ajax_litespace_save_settings', array( $this, 'ajax_build_on_save' ) );
		add_action( 'wp_ajax_litespace_sync_license', array( $this, 'ajax_sync_license' ) );
		add_action( 'wp_ajax_litespace_deactivate_license', array( $this, 'ajax_deactivate_license' ) );
		add_action( 'wp_ajax_litespace_save_compiled_css', array( $this, 'ajax_save_compiled_css' ) );
	}

	/**
	 * Adds the Smart WebP Flow options page under Settings.
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		$this->hook_suffix = add_options_page(
			esc_html__( 'Smart WebP Flow Settings', 'smart-webp-flow' ),
			esc_html__( 'Smart WebP Flow', 'smart-webp-flow' ),
			Constants::ADMIN_CAPABILITY,
			'smart-webp-flow',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueues admin stylesheet and JavaScript script on the plugin page only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_smart-webp-flow' !== $hook_suffix && $this->hook_suffix !== $hook_suffix && false === strpos( $hook_suffix, 'smart-webp-flow' ) ) {
			return;
		}

		$plugin_url = '';
		if ( defined( 'SMART_WEBP_FLOW_URL' ) && ! empty( SMART_WEBP_FLOW_URL ) ) {
			$plugin_url = SMART_WEBP_FLOW_URL;
		} elseif ( class_exists( '\SmartWebPFlow\Plugin' ) && ! empty( \SmartWebPFlow\Plugin::get_instance()->get_plugin_url() ) ) {
			$plugin_url = \SmartWebPFlow\Plugin::get_instance()->get_plugin_url();
		} else {
			$plugin_url = plugin_dir_url( dirname( dirname( __DIR__ ) ) . '/smart-webp-flow.php' );
		}

		wp_enqueue_style(
			'smart-webp-flow-admin',
			$plugin_url . 'assets/css/admin.css',
			array(),
			Constants::VERSION
		);

		wp_enqueue_script(
			'smart-webp-flow-admin',
			$plugin_url . 'assets/js/admin.js',
			array(),
			Constants::VERSION,
			true
		);

		// Localize script data with REST API configuration and nonce.
		$script_data = array(
			'restUrl' => esc_url_raw( rest_url( Constants::REST_NAMESPACE ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		);

		wp_localize_script( 'smart-webp-flow-admin', 'smartWebPFlowData', $script_data );

		// Enqueue LiteSpace React + WebAssembly Bundle.
		wp_enqueue_script(
			'smart-webp-flow-license-admin',
			$plugin_url . 'assets/js/admin-license-bundle.js',
			array( 'wp-element' ),
			Constants::VERSION,
			true
		);

		// Determine browser-accessible LiteSpace API URL.
		$api_url = 'https://api.litespace.id';
		if ( defined( 'LITESPACE_API_URL' ) && ! empty( LITESPACE_API_URL ) ) {
			$api_url = LITESPACE_API_URL;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$current_host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		if ( 0 === strpos( $current_host, '192.168.' ) || 0 === strpos( $current_host, '127.0.0.1' ) || 0 === strpos( $current_host, 'localhost' ) ) {
			$host_ip = explode( ':', $current_host )[0];
			$api_url = 'http://' . $host_ip . ':3000';
		}

		$license_manager = function_exists( 'smart_webp_flow_license' ) ? smart_webp_flow_license() : null;
		$license_data    = $license_manager ? $license_manager->get_license_data() : null;

		wp_localize_script(
			'smart-webp-flow-license-admin',
			'LiteSpaceConfig',
			array(
				'productSlug'        => Constants::LITESPACE_PRODUCT_SLUG,
				'productName'        => 'Smart WebP Flow Pro',
				'publicKey'          => Constants::LITESPACE_PUBLIC_KEY,
				'apiUrl'             => esc_url_raw( $api_url ),
				'wasmUrl'            => esc_url_raw( $plugin_url . 'assets/js/litespace_core_bg.wasm' ),
				'initialLicenseData' => $license_data,
				'nonce'              => wp_create_nonce( 'litespace_save_settings' ),
				'ajaxUrl'            => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			)
		);
	}

	/**
	 * Handles settings form submission with security verification.
	 *
	 * @return void
	 */
	public function handle_save_settings(): void {
		if ( ! isset( $_POST['smart_webp_flow_save'] ) ) {
			return;
		}

		check_admin_referer( 'smart_webp_flow_save_settings', 'smart_webp_flow_nonce' );

		if ( ! current_user_can( Constants::ADMIN_CAPABILITY ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'smart-webp-flow' ) );
		}

		$current = get_option( Constants::OPTION_SETTINGS, array() );

		$allowed_engines = array( 'auto', 'imagick', 'gd', 'api' );
		$posted_engine   = sanitize_text_field( wp_unslash( $_POST['engine_mode'] ?? 'auto' ) );
		$engine_mode     = in_array( $posted_engine, $allowed_engines, true ) ? $posted_engine : 'auto';

		$api_url = esc_url_raw( wp_unslash( $_POST['api_endpoint'] ?? '' ) );
		if ( ! empty( $api_url ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Safe scheme check.
			$scheme = parse_url( $api_url, PHP_URL_SCHEME );
			if ( ! in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) ) {
				$api_url = '';
			}
		}

		$new_settings = array(
			'engine_mode'        => $engine_mode,
			'quality_jpeg'       => max( 1, min( 100, (int) ( $_POST['quality_jpeg'] ?? Constants::DEFAULT_QUALITY_JPEG ) ) ),
			'quality_png'        => max( 1, min( 100, (int) ( $_POST['quality_png'] ?? Constants::DEFAULT_QUALITY_PNG ) ) ),
			'retention_policy'   => ( Constants::RETENTION_DELETE_ORIGINAL === ( $_POST['retention_policy'] ?? '' ) ) ? Constants::RETENTION_DELETE_ORIGINAL : Constants::RETENTION_KEEP_ORIGINAL,
			'dynamic_filter'     => ! empty( $_POST['dynamic_filter'] ) ? 1 : 0,
			'throttle_pacing_ms' => max( 0, min( 2000, (int) ( $_POST['throttle_pacing_ms'] ?? 250 ) ) ),
			'api_endpoint'       => $api_url,
			'api_key'            => sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) ),
		);

		$merged = array_merge( $current, $new_settings );
		update_option( Constants::OPTION_SETTINGS, $merged );

		// Execute Build-on-Save if LiteSpace Pro license is active.
		$license_manager = function_exists( 'smart_webp_flow_license' ) ? smart_webp_flow_license() : null;
		if ( $license_manager && $license_manager->is_active() ) {
			$build_res = $license_manager->build_and_save_artifacts( $merged, 'body { margin: 0; } .wp-block-image img { max-width: 100%; height: auto; }' );
			if ( ! empty( $build_res['success'] ) ) {
				add_settings_error(
					'smart_webp_flow_messages',
					'smart_webp_flow_built',
					esc_html__( 'LiteSpace Build-on-Save: Critical CSS and runtime artifacts compiled successfully by Rust Core Engine.', 'smart-webp-flow' ),
					'updated'
				);
			}
		}

		add_settings_error(
			'smart_webp_flow_messages',
			'smart_webp_flow_saved',
			esc_html__( 'Settings saved successfully.', 'smart-webp-flow' ),
			'updated'
		);
	}

	/**
	 * Renders the administrative settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( Constants::ADMIN_CAPABILITY ) ) {
			return;
		}

		$settings = get_option( Constants::OPTION_SETTINGS, array() );
		$tracker  = new StateTracker();
		$throttle = new ThrottleManager();
		$lock_mgr = new LockManager();
		$migrator = new ContentMigrationService();

		$progress  = $tracker->calculate_progress();
		$health    = $throttle->get_health_report();
		$engines   = EngineFactory::get_available_engines();
		$audit_log = $migrator->get_audit_log();
		$is_locked = $lock_mgr->is_locked();

		$license_manager = function_exists( 'smart_webp_flow_license' ) ? smart_webp_flow_license() : null;
		$is_pro          = $license_manager && $license_manager->is_active();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'diagnostics';
		?>
		<div class="wrap smart-webp-wrap">
			<!-- Header -->
			<div class="smart-webp-header">
				<h1>
					<?php esc_html_e( 'Smart WebP Flow & Media Sync', 'smart-webp-flow' ); ?>
					<span class="plugin-version">v<?php echo esc_html( Constants::VERSION ); ?></span>
				</h1>
				<div style="display:flex;gap:8px;align-items:center;">
					<?php if ( $is_pro ) : ?>
						<span class="health-badge" style="background:#0284c7;color:#fff;">🛡️ <?php esc_html_e( 'LiteSpace Pro (Active)', 'smart-webp-flow' ); ?></span>
					<?php else : ?>
						<span class="health-badge badge-amber"><?php esc_html_e( 'LiteSpace Free Tier', 'smart-webp-flow' ); ?></span>
					<?php endif; ?>

					<?php if ( $is_locked ) : ?>
						<span class="health-badge badge-amber"><?php esc_html_e( 'Worker Active (Locked)', 'smart-webp-flow' ); ?></span>
					<?php else : ?>
						<span class="health-badge badge-green"><?php esc_html_e( 'Engine Ready', 'smart-webp-flow' ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<?php settings_errors( 'smart_webp_flow_messages' ); ?>

			<style>
				.smart-webp-tab-content { display: none; }
				.smart-webp-tab-content.active { display: block; }
			</style>

			<!-- Navigation Tabs -->
			<nav class="nav-tab-wrapper smart-webp-tabs">
				<a href="#diagnostics" class="nav-tab <?php echo ( 'diagnostics' === $active_tab ) ? 'nav-tab-active' : ''; ?>" data-tab="diagnostics">
					<span class="dashicons dashicons-dashboard" style="margin-right:4px;"></span><?php esc_html_e( 'Diagnostics & Health', 'smart-webp-flow' ); ?>
				</a>
				<a href="#bulk-sync" class="nav-tab <?php echo ( 'bulk-sync' === $active_tab ) ? 'nav-tab-active' : ''; ?>" data-tab="bulk-sync">
					<span class="dashicons dashicons-images-alt2" style="margin-right:4px;"></span><?php esc_html_e( 'Bulk Synchronization', 'smart-webp-flow' ); ?>
				</a>
				<a href="#content-migrator" class="nav-tab <?php echo ( 'content-migrator' === $active_tab ) ? 'nav-tab-active' : ''; ?>" data-tab="content-migrator">
					<span class="dashicons dashicons-admin-post" style="margin-right:4px;"></span><?php esc_html_e( 'Content Migrator', 'smart-webp-flow' ); ?>
				</a>
				<a href="#settings" class="nav-tab <?php echo ( 'settings' === $active_tab ) ? 'nav-tab-active' : ''; ?>" data-tab="settings">
					<span class="dashicons dashicons-admin-generic" style="margin-right:4px;"></span><?php esc_html_e( 'Settings', 'smart-webp-flow' ); ?>
				</a>
				<a href="#license" class="nav-tab <?php echo ( 'license' === $active_tab ) ? 'nav-tab-active' : ''; ?>" data-tab="license">
					<span class="dashicons dashicons-shield" style="margin-right:4px;"></span><?php esc_html_e( 'LiteSpace License & Pro', 'smart-webp-flow' ); ?>
				</a>
			</nav>

			<!-- TAB 1: Diagnostics & Health -->
			<div id="tab-diagnostics" class="smart-webp-tab-content <?php echo ( 'diagnostics' === $active_tab ) ? 'active' : ''; ?>">
				<div class="webp-card">
					<h2><?php esc_html_e( 'Live Server Health & Diagnostics', 'smart-webp-flow' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Real-time telemetry measuring CPU load, PHP memory consumption, and image extension capabilities.', 'smart-webp-flow' ); ?></p>

					<div class="health-grid">
						<div class="health-card">
							<div class="label"><?php esc_html_e( 'PHP Version', 'smart-webp-flow' ); ?></div>
							<div class="value"><?php echo esc_html( PHP_VERSION ); ?></div>
						</div>
						<div class="health-card">
							<div class="label"><?php esc_html_e( 'CPU Load Average', 'smart-webp-flow' ); ?></div>
							<div class="value"><?php echo esc_html( number_format( $health['cpu_load'], 2 ) ); ?> <small>(<?php echo esc_html( $health['cpu_cores'] ); ?> cores)</small></div>
						</div>
						<div class="health-card">
							<div class="label"><?php esc_html_e( 'Memory Used', 'smart-webp-flow' ); ?></div>
							<div class="value"><?php echo esc_html( size_format( $health['memory_used_bytes'] ) ); ?></div>
						</div>
						<div class="health-card">
							<div class="label"><?php esc_html_e( 'Memory Limit', 'smart-webp-flow' ); ?></div>
							<div class="value"><?php echo esc_html( ini_get( 'memory_limit' ) ); ?></div>
						</div>
					</div>

					<h3><?php esc_html_e( 'Registered Conversion Engines', 'smart-webp-flow' ); ?></h3>
					<table class="widefat fixed striped" style="margin-top:10px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Engine Name', 'smart-webp-flow' ); ?></th>
								<th><?php esc_html_e( 'Identifier', 'smart-webp-flow' ); ?></th>
								<th><?php esc_html_e( 'Status', 'smart-webp-flow' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $engines as $eng ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $eng['name'] ); ?></strong></td>
									<td><code><?php echo esc_html( $eng['id'] ); ?></code></td>
									<td>
										<?php if ( $eng['supported'] ) : ?>
											<span class="health-badge badge-green"><?php esc_html_e( 'Available & Supported', 'smart-webp-flow' ); ?></span>
										<?php else : ?>
											<span class="health-badge badge-red"><?php esc_html_e( 'Unavailable', 'smart-webp-flow' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>

			<!-- TAB 2: Bulk Synchronization -->
			<div id="tab-bulk-sync" class="smart-webp-tab-content <?php echo ( 'bulk-sync' === $active_tab ) ? 'active' : ''; ?>">
				<div class="webp-card">
					<h2><?php esc_html_e( 'Bulk Image Synchronization', 'smart-webp-flow' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Batch convert legacy JPG/PNG images in your WordPress media library into WebP format with zero server downtime.', 'smart-webp-flow' ); ?></p>

					<!-- Progress Bar -->
					<div class="webp-progress-container">
						<div id="progress-fill" class="webp-progress-fill" style="width: <?php echo esc_attr( $progress['percent'] ); ?>%;"></div>
						<div id="progress-text" class="webp-progress-text"><?php echo esc_html( $progress['percent'] ); ?>%</div>
					</div>

					<!-- Counters -->
					<div class="stats-grid">
						<div class="stat-box">
							<div id="stat-processed" class="num"><?php echo esc_html( $progress['processed'] ); ?></div>
							<div class="desc"><?php esc_html_e( 'Processed', 'smart-webp-flow' ); ?></div>
						</div>
						<div class="stat-box">
							<div id="stat-total" class="num"><?php echo esc_html( $progress['total'] ); ?></div>
							<div class="desc"><?php esc_html_e( 'Total Images', 'smart-webp-flow' ); ?></div>
						</div>
						<div class="stat-box success">
							<div id="stat-converted" class="num"><?php echo esc_html( $progress['converted'] ); ?></div>
							<div class="desc"><?php esc_html_e( 'Converted', 'smart-webp-flow' ); ?></div>
						</div>
						<div class="stat-box success">
							<div id="stat-saved" class="num"><?php echo esc_html( $progress['saved_formatted'] ); ?></div>
							<div class="desc"><?php esc_html_e( 'Saved Space', 'smart-webp-flow' ); ?></div>
						</div>
						<div class="stat-box">
							<div id="stat-eta" class="num"><?php echo $progress['eta_seconds'] ? esc_html( $progress['eta_seconds'] . 's' ) : '--'; ?></div>
							<div class="desc"><?php esc_html_e( 'Estimated ETA', 'smart-webp-flow' ); ?></div>
						</div>
					</div>

					<!-- Actions Row -->
					<div class="controls-row">
						<button id="btn-start-interactive" class="button button-primary">
							<?php esc_html_e( 'Start in Browser (Interactive)', 'smart-webp-flow' ); ?>
						</button>
						<button id="btn-start-background" class="button button-secondary">
							<?php esc_html_e( 'Run in Background (Daemon)', 'smart-webp-flow' ); ?>
						</button>
						<button id="btn-pause" class="button button-secondary" disabled>
							<?php esc_html_e( 'Pause', 'smart-webp-flow' ); ?>
						</button>
						<button id="btn-resume" class="button button-secondary" disabled>
							<?php esc_html_e( 'Resume', 'smart-webp-flow' ); ?>
						</button>
						<button id="btn-reset" class="button button-link-delete">
							<?php esc_html_e( 'Reset State', 'smart-webp-flow' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- TAB 3: Content Migrator Wizard -->
			<div id="tab-content-migrator" class="smart-webp-tab-content <?php echo ( 'content-migrator' === $active_tab ) ? 'active' : ''; ?>">
				<div class="webp-card">
					<h2><?php esc_html_e( 'Post Content Synchronizer Wizard', 'smart-webp-flow' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Scan and migrate legacy image URLs inside post_content to WebP. Includes Dry-Run preview and atomic rollback snapshots.', 'smart-webp-flow' ); ?></p>

					<div class="controls-row" style="margin-bottom:20px;">
						<button id="btn-scan-content" class="button button-primary">
							<?php esc_html_e( 'Step 1: Scan Content', 'smart-webp-flow' ); ?>
						</button>
						<button id="btn-dry-run-content" class="button button-secondary" disabled>
							<?php esc_html_e( 'Step 2: Dry Run Preview', 'smart-webp-flow' ); ?>
						</button>
						<button id="btn-execute-content" class="button button-secondary" disabled>
							<?php esc_html_e( 'Step 3: Execute Database Migration', 'smart-webp-flow' ); ?>
						</button>
					</div>

					<div id="content-migration-results"></div>

					<h3 style="margin-top:24px;"><?php esc_html_e( 'Migration Audit Log & Rollback History', 'smart-webp-flow' ); ?></h3>
					<?php if ( empty( $audit_log ) ) : ?>
						<p style="color:#646970;"><?php esc_html_e( 'No migration logs recorded yet.', 'smart-webp-flow' ); ?></p>
					<?php else : ?>
						<table class="widefat fixed striped" style="margin-top:10px;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Post ID & Title', 'smart-webp-flow' ); ?></th>
									<th><?php esc_html_e( 'Timestamp', 'smart-webp-flow' ); ?></th>
									<th><?php esc_html_e( 'Replacements', 'smart-webp-flow' ); ?></th>
									<th><?php esc_html_e( 'Action', 'smart-webp-flow' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( array_reverse( $audit_log ) as $item ) : ?>
									<tr>
										<td><strong>#<?php echo esc_html( $item['post_id'] ?? '' ); ?></strong> <?php echo esc_html( $item['post_title'] ?? '' ); ?></td>
										<td><?php echo esc_html( $item['timestamp'] ?? '' ); ?></td>
										<td><?php echo esc_html( $item['replacements'] ?? '-' ); ?></td>
										<td>
											<?php if ( isset( $item['post_id'] ) ) : ?>
												<button type="button" class="button button-small btn-rollback-post" data-post-id="<?php echo esc_attr( $item['post_id'] ); ?>">
													<?php esc_html_e( 'Rollback Content', 'smart-webp-flow' ); ?>
												</button>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>

			<!-- TAB 4: Settings -->
			<div id="tab-settings" class="smart-webp-tab-content <?php echo ( 'settings' === $active_tab ) ? 'active' : ''; ?>">
				<div class="webp-card">
					<h2><?php esc_html_e( 'Plugin Settings & Compression Parameters', 'smart-webp-flow' ); ?></h2>

					<form method="post" action="">
						<?php wp_nonce_field( 'smart_webp_flow_save_settings', 'smart_webp_flow_nonce' ); ?>
						<input type="hidden" name="smart_webp_flow_save" value="1" />

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="engine_mode"><?php esc_html_e( 'Conversion Engine Mode', 'smart-webp-flow' ); ?></label></th>
								<td>
									<select name="engine_mode" id="engine_mode">
										<option value="auto" <?php selected( $settings['engine_mode'] ?? 'auto', 'auto' ); ?>><?php esc_html_e( 'Auto-Detect (Imagick -> GD -> API)', 'smart-webp-flow' ); ?></option>
										<option value="imagick" <?php selected( $settings['engine_mode'] ?? '', 'imagick' ); ?>><?php esc_html_e( 'PHP Imagick (Pure PHP Native)', 'smart-webp-flow' ); ?></option>
										<option value="gd" <?php selected( $settings['engine_mode'] ?? '', 'gd' ); ?>><?php esc_html_e( 'PHP GD (Pure PHP Native)', 'smart-webp-flow' ); ?></option>
										<option value="api" <?php selected( $settings['engine_mode'] ?? '', 'api' ); ?>><?php esc_html_e( 'External Cloud Conversion API', 'smart-webp-flow' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'Select the active image conversion driver.', 'smart-webp-flow' ); ?></p>
								</td>
							</tr>

							<tr id="api_settings_row" style="display:none;">
								<th scope="row"><label for="api_endpoint"><?php esc_html_e( 'API Endpoint & Key', 'smart-webp-flow' ); ?></label></th>
								<td>
									<input type="url" name="api_endpoint" id="api_endpoint" value="<?php echo esc_attr( $settings['api_endpoint'] ?? '' ); ?>" class="regular-text" placeholder="https://api.example.com/convert" />
									<br /><br />
									<input type="password" name="api_key" id="api_key" value="<?php echo esc_attr( $settings['api_key'] ?? '' ); ?>" class="regular-text" placeholder="API Secret Key" />
								</td>
							</tr>

							<tr>
								<th scope="row"><label for="quality_jpeg"><?php esc_html_e( 'JPEG Compression Quality', 'smart-webp-flow' ); ?></label></th>
								<td>
									<div class="slider-container">
										<input type="range" name="quality_jpeg" id="quality_jpeg" min="1" max="100" value="<?php echo esc_attr( $settings['quality_jpeg'] ?? Constants::DEFAULT_QUALITY_JPEG ); ?>" />
										<span id="quality_jpeg_val" class="slider-value"><?php echo esc_html( $settings['quality_jpeg'] ?? Constants::DEFAULT_QUALITY_JPEG ); ?>%</span>
									</div>
									<p class="description"><?php esc_html_e( 'Target WebP quality for JPEG images (Recommended: 80% - 85%).', 'smart-webp-flow' ); ?></p>
								</td>
							</tr>

							<tr>
								<th scope="row"><label for="quality_png"><?php esc_html_e( 'PNG Compression Quality', 'smart-webp-flow' ); ?></label></th>
								<td>
									<div class="slider-container">
										<input type="range" name="quality_png" id="quality_png" min="1" max="100" value="<?php echo esc_attr( $settings['quality_png'] ?? Constants::DEFAULT_QUALITY_PNG ); ?>" />
										<span id="quality_png_val" class="slider-value"><?php echo esc_html( $settings['quality_png'] ?? Constants::DEFAULT_QUALITY_PNG ); ?>%</span>
									</div>
									<p class="description"><?php esc_html_e( 'Target WebP quality for transparent PNGs (Recommended: 85%).', 'smart-webp-flow' ); ?></p>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Original File Retention Policy', 'smart-webp-flow' ); ?></th>
								<td>
									<fieldset>
										<label style="display:block;margin-bottom:12px;">
											<input type="radio" name="retention_policy" value="keep_original" <?php checked( $settings['retention_policy'] ?? Constants::DEFAULT_RETENTION_POLICY, Constants::RETENTION_KEEP_ORIGINAL ); ?> />
											<strong><?php esc_html_e( 'Keep original files as backup (Allows easy rollback)', 'smart-webp-flow' ); ?></strong>
											<span class="description" style="display:block;margin-left:24px;margin-top:2px;color:#646970;"><?php esc_html_e( 'Recommended: Keeps original JPG/PNG files intact so you can easily revert or restore images whenever needed.', 'smart-webp-flow' ); ?></span>
										</label>
										<label style="display:block;">
											<input type="radio" name="retention_policy" value="delete_original" <?php checked( $settings['retention_policy'] ?? Constants::DEFAULT_RETENTION_POLICY, Constants::RETENTION_DELETE_ORIGINAL ); ?> />
											<?php esc_html_e( 'Delete original JPG/PNG files after conversion (Maximizes disk space savings)', 'smart-webp-flow' ); ?>
											<span class="description" style="display:block;margin-left:24px;margin-top:2px;color:#d63638;"><?php esc_html_e( 'Caution: Permanently removes the original files after WebP conversion. Rollback will not be possible.', 'smart-webp-flow' ); ?></span>
										</label>
									</fieldset>
								</td>
							</tr>

							<tr>
								<th scope="row"><?php esc_html_e( 'Dynamic Frontend Filter', 'smart-webp-flow' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="dynamic_filter" value="1" <?php checked( ! empty( $settings['dynamic_filter'] ) ); ?> />
										<?php esc_html_e( 'Enable automatic on-the-fly replacement of image URLs in the_content on frontend.', 'smart-webp-flow' ); ?>
									</label>
								</td>
							</tr>

							<tr>
								<th scope="row"><label for="throttle_pacing_ms"><?php esc_html_e( 'Pacing Delay Between Images', 'smart-webp-flow' ); ?></label></th>
								<td>
									<div class="slider-container">
										<input type="range" name="throttle_pacing_ms" id="throttle_pacing_ms" min="0" max="1000" step="50" value="<?php echo esc_attr( $settings['throttle_pacing_ms'] ?? 250 ); ?>" />
										<span id="pacing_val" class="slider-value"><?php echo esc_html( $settings['throttle_pacing_ms'] ?? 250 ); ?> ms</span>
									</div>
									<p class="description"><?php esc_html_e( 'Microsecond pacing pause between images to maintain 0% CPU spikes for site visitors.', 'smart-webp-flow' ); ?></p>
								</td>
							</tr>
						</table>

						<?php submit_button( esc_html__( 'Save Changes', 'smart-webp-flow' ) ); ?>
					</form>
				</div>
			</div>

			<!-- TAB 5: LiteSpace License & Cloud Pro -->
			<div id="tab-license" class="smart-webp-tab-content <?php echo ( 'license' === $active_tab ) ? 'active' : ''; ?>">
				<div class="webp-card" style="background:#171717;border:1px solid #262626;color:#ededed;padding:24px;">
					<div id="litespace-license-root"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Synchronizes an activated license certificate into wp_options.
	 *
	 * @return void
	 */
	public function ajax_sync_license(): void {
		check_ajax_referer( 'litespace_save_settings', 'nonce' );

		if ( ! current_user_can( Constants::ADMIN_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized.', 'smart-webp-flow' ) ), 403 );
		}

		$license_key      = sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) );
		$certificate_json = wp_unslash( $_POST['certificate_json'] ?? '' );
		$signature        = sanitize_text_field( wp_unslash( $_POST['signature'] ?? '' ) );
		$public_key       = sanitize_text_field( wp_unslash( $_POST['public_key'] ?? Constants::LITESPACE_PUBLIC_KEY ) );

		if ( empty( $certificate_json ) || empty( $signature ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid certificate or signature payload.', 'smart-webp-flow' ) ), 400 );
		}

		$license = function_exists( 'smart_webp_flow_license' ) ? smart_webp_flow_license() : null;
		if ( ! $license ) {
			wp_send_json_error( array( 'message' => esc_html__( 'License manager not available.', 'smart-webp-flow' ) ), 500 );
		}

		// Verify signature offline via Libsodium.
		$is_valid = $license->verify_signature_offline( $certificate_json, $signature, $public_key );
		if ( ! $is_valid ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Libsodium digital signature verification failed.', 'smart-webp-flow' ) ), 400 );
		}

		$cert_data = json_decode( $certificate_json, true );
		$record    = array(
			'license_key'      => $license_key,
			'certificate_json' => $certificate_json,
			'certificate'      => is_array( $cert_data ) ? $cert_data : array(),
			'signature'        => $signature,
			'public_key'       => $public_key,
			'activated_at'     => time(),
			'last_heartbeat'   => time(),
		);

		update_option( 'litespace_license_' . Constants::LITESPACE_PRODUCT_SLUG, $record, false );

		wp_send_json_success(
			array(
				'message'     => esc_html__( 'License synchronized successfully.', 'smart-webp-flow' ),
				'certificate' => $record['certificate'],
			)
		);
	}

	/**
	 * Deactivates the LiteSpace license for this domain.
	 *
	 * @return void
	 */
	public function ajax_deactivate_license(): void {
		check_ajax_referer( 'litespace_save_settings', 'nonce' );

		if ( ! current_user_can( Constants::ADMIN_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized.', 'smart-webp-flow' ) ), 403 );
		}

		$license = function_exists( 'smart_webp_flow_license' ) ? smart_webp_flow_license() : null;
		if ( $license ) {
			$license->deactivate();
		} else {
			delete_option( 'litespace_license_' . Constants::LITESPACE_PRODUCT_SLUG );
		}

		wp_send_json_success( array( 'message' => esc_html__( 'License deactivated successfully.', 'smart-webp-flow' ) ) );
	}

	/**
	 * Build-on-Save AJAX handler offloading Critical CSS & configuration to LiteSpace Rust Engine.
	 *
	 * @return void
	 */
	public function ajax_build_on_save(): void {
		check_ajax_referer( 'litespace_save_settings', 'nonce' );

		if ( ! current_user_can( Constants::ADMIN_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized.', 'smart-webp-flow' ) ), 403 );
		}

		$license = function_exists( 'smart_webp_flow_license' ) ? smart_webp_flow_license() : null;
		if ( ! $license || ! $license->is_active() ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'License is not active! Build-on-Save compilation requires an active LiteSpace Pro license.', 'smart-webp-flow' ) ),
				403
			);
		}

		$settings = get_option( Constants::OPTION_SETTINGS, array() );
		$raw_css  = wp_unslash( $_POST['raw_css'] ?? 'body { margin: 0; } .wp-block-image img { max-width: 100%; height: auto; }' );

		$result = $license->build_and_save_artifacts( $settings, $raw_css );
		if ( empty( $result['success'] ) ) {
			wp_send_json_error( array( 'message' => $result['message'] ?? esc_html__( 'Compilation failed.', 'smart-webp-flow' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'message'   => esc_html__( 'Settings saved and Critical CSS successfully compiled by LiteSpace Rust Engine!', 'smart-webp-flow' ),
				'artifacts' => $result['artifacts'] ?? array(),
			)
		);
	}

	/**
	 * Saves compiled CSS artifacts dispatched by the React/Wasm UI.
	 *
	 * @return void
	 */
	public function ajax_save_compiled_css(): void {
		check_ajax_referer( 'litespace_save_settings', 'nonce' );

		if ( ! current_user_can( Constants::ADMIN_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized.', 'smart-webp-flow' ) ), 403 );
		}

		$raw_artifacts = wp_unslash( $_POST['artifacts'] ?? '' );
		$artifacts     = json_decode( $raw_artifacts, true );

		if ( ! is_array( $artifacts ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid artifacts payload.', 'smart-webp-flow' ) ), 400 );
		}

		$upload_dir = '';
		if ( function_exists( 'wp_upload_dir' ) ) {
			$dirs       = wp_upload_dir();
			$upload_dir = $dirs['basedir'] . '/litespace/static';
		} else {
			$upload_dir = defined( 'ABSPATH' ) ? ABSPATH . 'wp-content/uploads/litespace/static' : '/tmp/litespace/static';
		}

		if ( ! file_exists( $upload_dir ) ) {
			wp_mkdir_p( $upload_dir );
		}

		if ( ! empty( $artifacts['assetFilename'] ) && ! empty( $artifacts['criticalCss'] ) ) {
			$css_file = $upload_dir . '/' . sanitize_file_name( $artifacts['assetFilename'] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $css_file, $artifacts['criticalCss'] );
		}

		$meta = array(
			'build_hash'         => $artifacts['buildHash'] ?? '',
			'asset_filename'     => $artifacts['assetFilename'] ?? '',
			'critical_css'       => $artifacts['criticalCss'] ?? '',
			'savings_percentage' => $artifacts['savingsPercentage'] ?? 0.0,
			'compiled_at'        => $artifacts['compiledAt'] ?? time(),
			'engine'             => $artifacts['engine'] ?? 'Rust Compiler',
		);

		update_option( 'litespace_static_' . Constants::LITESPACE_PRODUCT_SLUG, $meta, false );

		wp_send_json_success( array( 'message' => esc_html__( 'Compiled artifacts saved successfully.', 'smart-webp-flow' ) ) );
	}
}
