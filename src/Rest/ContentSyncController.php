<?php
/**
 * REST API Controller for Post Content Synchronization & Rollback.
 *
 * @package SmartWebPFlow\Rest
 */

namespace SmartWebPFlow\Rest;

use SmartWebPFlow\Common\Constants;
use SmartWebPFlow\Services\ContentMigrationService;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ContentSyncController
 * Endpoints for scanning post content, dry-run simulation, execution, and rollback.
 */
class ContentSyncController {

	/**
	 * Content migration service.
	 *
	 * @var ContentMigrationService
	 */
	private $migration_service;

	/**
	 * Constructor.
	 *
	 * @param ContentMigrationService|null $migration_service Service instance.
	 */
	public function __construct( ?ContentMigrationService $migration_service = null ) {
		$this->migration_service = $migration_service ?? new ContentMigrationService();
	}

	/**
	 * Registers REST routes under smart-webp/v1 namespace.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			'/content-sync/scan',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'scan_content' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			Constants::REST_NAMESPACE,
			'/content-sync/dry-run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'dry_run_batch' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'last_id'    => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'chunk_size' => array(
						'type'              => 'integer',
						'default'           => 10,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			Constants::REST_NAMESPACE,
			'/content-sync/execute',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'execute_batch' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'last_id'    => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'chunk_size' => array(
						'type'              => 'integer',
						'default'           => 10,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			Constants::REST_NAMESPACE,
			'/content-sync/rollback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rollback_post' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'post_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			Constants::REST_NAMESPACE,
			'/content-sync/log',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_log' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Permission callback verifying current user can manage WordPress options.
	 *
	 * @return bool True if authorized, false otherwise.
	 */
	public function check_permission(): bool {
		return current_user_can( Constants::ADMIN_CAPABILITY );
	}

	/**
	 * Endpoint handler: POST /smart-webp/v1/content-sync/scan
	 *
	 * @return \WP_REST_Response
	 */
	public function scan_content(): \WP_REST_Response {
		$total = $this->migration_service->count_candidate_posts();

		return new \WP_REST_Response(
			array(
				'success'         => true,
				'candidate_posts' => $total,
			),
			200
		);
	}

	/**
	 * Endpoint handler: POST /smart-webp/v1/content-sync/dry-run
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function dry_run_batch( \WP_REST_Request $request ): \WP_REST_Response {
		$last_id    = (int) $request->get_param( 'last_id' );
		$chunk_size = (int) $request->get_param( 'chunk_size' );
		if ( $chunk_size <= 0 ) {
			$chunk_size = 10;
		}

		$result = $this->migration_service->dry_run_batch( $last_id, $chunk_size );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Endpoint handler: POST /smart-webp/v1/content-sync/execute
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function execute_batch( \WP_REST_Request $request ): \WP_REST_Response {
		$last_id    = (int) $request->get_param( 'last_id' );
		$chunk_size = (int) $request->get_param( 'chunk_size' );
		if ( $chunk_size <= 0 ) {
			$chunk_size = 10;
		}

		$result = $this->migration_service->migrate_batch( $last_id, $chunk_size );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Endpoint handler: POST /smart-webp/v1/content-sync/rollback
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function rollback_post( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		if ( $post_id <= 0 ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Invalid post ID provided.',
				),
				400
			);
		}

		$result = $this->migration_service->rollback_post( $post_id );

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Endpoint handler: GET /smart-webp/v1/content-sync/log
	 *
	 * @return \WP_REST_Response
	 */
	public function get_log(): \WP_REST_Response {
		$log = $this->migration_service->get_audit_log();

		return new \WP_REST_Response(
			array(
				'success' => true,
				'log'     => $log,
			),
			200
		);
	}
}
