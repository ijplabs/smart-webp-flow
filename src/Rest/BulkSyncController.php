<?php
/**
 * REST API Controller for Interactive Bulk Sync and Status Monitoring.
 *
 * @package SmartWebPFlow\Rest
 */

namespace SmartWebPFlow\Rest;

use SmartWebPFlow\Common\Constants;
use SmartWebPFlow\Background\BackgroundWorker;
use SmartWebPFlow\Background\LockManager;
use SmartWebPFlow\Background\StateTracker;
use SmartWebPFlow\Background\ThrottleManager;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BulkSyncController
 * Handles interactive chunk processing, progress telemetry, and reset endpoints.
 */
class BulkSyncController {

	/**
	 * State tracker instance.
	 *
	 * @var StateTracker
	 */
	private $state_tracker;

	/**
	 * Lock manager instance.
	 *
	 * @var LockManager
	 */
	private $lock_manager;

	/**
	 * Throttle manager instance.
	 *
	 * @var ThrottleManager
	 */
	private $throttle_manager;

	/**
	 * Background worker instance.
	 *
	 * @var BackgroundWorker
	 */
	private $worker;

	/**
	 * Constructor.
	 *
	 * @param StateTracker|null     $state_tracker    State tracker.
	 * @param LockManager|null      $lock_manager     Lock manager.
	 * @param ThrottleManager|null  $throttle_manager Throttle manager.
	 * @param BackgroundWorker|null $worker           Background worker.
	 */
	public function __construct(
		?StateTracker $state_tracker = null,
		?LockManager $lock_manager = null,
		?ThrottleManager $throttle_manager = null,
		?BackgroundWorker $worker = null
	) {
		$this->state_tracker    = $state_tracker ?? new StateTracker();
		$this->lock_manager     = $lock_manager ?? new LockManager();
		$this->throttle_manager = $throttle_manager ?? new ThrottleManager();
		$this->worker           = $worker ?? new BackgroundWorker( $this->lock_manager, $this->throttle_manager, $this->state_tracker );
	}

	/**
	 * Registers REST routes under smart-webp/v1 namespace.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			'/bulk-sync/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			Constants::REST_NAMESPACE,
			'/bulk-sync/process-chunk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'process_chunk' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'chunk_size' => array(
						'type'              => 'integer',
						'default'           => Constants::DEFAULT_CHUNK_SIZE,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			Constants::REST_NAMESPACE,
			'/bulk-sync/reset',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reset_sync' ),
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
	 * Endpoint handler: GET /smart-webp/v1/bulk-sync/status
	 *
	 * @return \WP_REST_Response
	 */
	public function get_status(): \WP_REST_Response {
		$progress = $this->state_tracker->calculate_progress();
		$health   = $this->throttle_manager->get_health_report();

		$response = array(
			'success'   => true,
			'state'     => $progress,
			'health'    => $health,
			'is_locked' => $this->lock_manager->is_locked(),
			'lock_age'  => $this->lock_manager->get_lock_age(),
		);

		return new \WP_REST_Response( $response, 200 );
	}

	/**
	 * Endpoint handler: POST /smart-webp/v1/bulk-sync/process-chunk
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function process_chunk( \WP_REST_Request $request ): \WP_REST_Response {
		$chunk_size = (int) $request->get_param( 'chunk_size' );
		if ( $chunk_size <= 0 ) {
			$chunk_size = Constants::DEFAULT_CHUNK_SIZE;
		}

		// Ensure state reflects running_interactive.
		$current_state = $this->state_tracker->get_state();
		if ( Constants::STATE_IDLE === $current_state['status'] || Constants::STATE_PAUSED === $current_state['status'] ) {
			$this->state_tracker->set_status( Constants::STATE_RUNNING_INTERACTIVE );
		}

		$result = $this->worker->process_next_chunk( $chunk_size );

		$response = array(
			'success' => true,
			'result'  => $result,
		);

		return new \WP_REST_Response( $response, 200 );
	}

	/**
	 * Endpoint handler: POST /smart-webp/v1/bulk-sync/reset
	 *
	 * @return \WP_REST_Response
	 */
	public function reset_sync(): \WP_REST_Response {
		$this->state_tracker->reset_state();
		$this->lock_manager->force_release();

		$response = array(
			'success' => true,
			'message' => 'Migration state reset and lock released successfully.',
			'state'   => $this->state_tracker->calculate_progress(),
		);

		return new \WP_REST_Response( $response, 200 );
	}
}
