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
				<div>
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
		</div>
		<?php
	}
}
