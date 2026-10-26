<?php
/**
 * REST API Controller for Autonomous Background Subsystem.
 *
 * @package SmartWebPFlow\Rest
 */

namespace SmartWebPFlow\Rest;

use SmartWebPFlow\Common\Constants;
use SmartWebPFlow\Background\AsyncDispatcher;
use SmartWebPFlow\Background\BackgroundWorker;
use SmartWebPFlow\Background\LockManager;
use SmartWebPFlow\Background\StateTracker;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BackgroundController
 * Controls starting, pausing, resuming, and executing the autonomous background migration daemon.
 */
class BackgroundController {

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
	 * Async dispatcher instance.
	 *
	 * @var AsyncDispatcher
	 */
	private $dispatcher;

	/**
	 * Background worker instance.
	 *
	 * @var BackgroundWorker
	 */
	private $worker;

	/**
	 * Constructor.
	 *
	 * @param StateTracker|null     $state_tracker State tracker.
	 * @param LockManager|null      $lock_manager  Lock manager.
	 * @param AsyncDispatcher|null  $dispatcher    Async dispatcher.
	 * @param BackgroundWorker|null $worker        Background worker.
	 */
	public function __construct(
		?StateTracker $state_tracker = null,
		?LockManager $lock_manager = null,
		?AsyncDispatcher $dispatcher = null,
		?BackgroundWorker $worker = null
	) {
		$this->state_tracker = $state_tracker ?? new StateTracker();
		$this->lock_manager  = $lock_manager ?? new LockManager();
		$this->dispatcher    = $dispatcher ?? new AsyncDispatcher();
		$this->worker        = $worker ?? new BackgroundWorker( $this->lock_manager, null, $this->state_tracker, $this->dispatcher );
	}

	/**
	 * Registers REST routes under smart-webp/v1 namespace.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			'/background/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start_background' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			Constants::REST_NAMESPACE,
			'/background/pause',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'pause_background' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			Constants::REST_NAMESPACE,
			'/background/resume',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resume_background' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			Constants::REST_NAMESPACE,
			'/background/process',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'execute_process' ),
				'permission_callback' => array( $this, 'verify_dispatch_permission' ),
			)
		);
	}

	/**
	 * Verifies administrator capability.
	 *
	 * @return bool True if authorized, false otherwise.
	 */
	public function check_admin_permission(): bool {
		return current_user_can( Constants::ADMIN_CAPABILITY );
	}

	/**
	 * Verifies dispatch token for internal loopback requests or admin session.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool True if authorized.
	 */
	public function verify_dispatch_permission( \WP_REST_Request $request ): bool {
		// Allow administrator requests.
		if ( current_user_can( Constants::ADMIN_CAPABILITY ) ) {
			return true;
		}

		// Verify internal dispatch token.
		$token = (string) $request->get_param( 'token' );
		if ( ! empty( $token ) && $this->dispatcher->verify_token( $token ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Endpoint handler: POST /smart-webp/v1/background/start
	 *
	 * @return \WP_REST_Response
	 */
	public function start_background(): \WP_REST_Response {
		$state = $this->state_tracker->get_state();

		// Calculate total_items if zero.
		if ( 0 === (int) $state['total_items'] ) {
			$total = $this->worker->count_eligible_attachments();
			$this->state_tracker->update_state(
				array(
					'total_items' => $total,
					'mode'        => 'background',
				)
			);
		}

		$this->state_tracker->set_status( Constants::STATE_RUNNING_BACKGROUND );
		$dispatched = $this->dispatcher->dispatch();

		$response = array(
			'success'    => true,
			'message'    => $dispatched ? 'Autonomous background worker spawned.' : 'Background marked running (dispatch pending).',
			'dispatched' => $dispatched,
			'state'      => $this->state_tracker->calculate_progress(),
		);

		return new \WP_REST_Response( $response, 200 );
	}

	/**
	 * Endpoint handler: POST /smart-webp/v1/background/pause
	 *
	 * @return \WP_REST_Response
	 */
	public function pause_background(): \WP_REST_Response {
		$this->state_tracker->set_status( Constants::STATE_PAUSED );
		$this->lock_manager->release_lock();

		$response = array(
			'success' => true,
			'message' => 'Background worker paused.',
			'state'   => $this->state_tracker->calculate_progress(),
		);

		return new \WP_REST_Response( $response, 200 );
	}

	/**
	 * Endpoint handler: POST /smart-webp/v1/background/resume
	 *
	 * @return \WP_REST_Response
	 */
	public function resume_background(): \WP_REST_Response {
		$this->state_tracker->set_status( Constants::STATE_RUNNING_BACKGROUND );
		$dispatched = $this->dispatcher->dispatch();

		$response = array(
			'success'    => true,
			'message'    => 'Background worker resumed.',
			'dispatched' => $dispatched,
			'state'      => $this->state_tracker->calculate_progress(),
		);

		return new \WP_REST_Response( $response, 200 );
	}

	/**
	 * Endpoint handler: POST /smart-webp/v1/background/process
	 *
	 * @return \WP_REST_Response
	 */
	public function execute_process(): \WP_REST_Response {
		$result = $this->worker->run_autonomous_loop( 20 );

		return new \WP_REST_Response(
			array(
				'success' => $result['success'] ?? false,
				'result'  => $result,
			),
			200
		);
	}
}
