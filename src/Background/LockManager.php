<?php
/**
 * Background Concurrency Lock Manager (Transient Mutex).
 *
 * @package SmartWebPFlow\Background
 */

namespace SmartWebPFlow\Background;

use SmartWebPFlow\Common\Constants;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LockManager
 * Ensures single-worker mutual exclusion to prevent zombie processes and CPU overload.
 */
class LockManager {

	/**
	 * Unique token identifying the lock owner for this worker session.
	 *
	 * @var string
	 */
	private $session_token = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->session_token = function_exists( 'wp_generate_password' )
			? wp_generate_password( 16, false )
			: bin2hex( random_bytes( 8 ) );
	}

	/**
	 * Attempts to acquire the concurrency mutex lock.
	 *
	 * @param string $owner Optional owner identifier (e.g. process ID or session tag).
	 * @return bool True if lock was acquired, false if already held.
	 */
	public function acquire_lock( string $owner = '' ): bool {
		$existing = get_transient( Constants::TRANSIENT_BULK_LOCK );
		if ( false !== $existing ) {
			return false;
		}

		$lock_data = array(
			'token' => $this->session_token,
			'owner' => ! empty( $owner ) ? $owner : (string) getmypid(),
			'time'  => time(),
		);

		set_transient( Constants::TRANSIENT_BULK_LOCK, $lock_data, Constants::LOCK_TTL_SECONDS );
		return true;
	}

	/**
	 * Checks if the mutex lock is currently active.
	 *
	 * @return bool True if locked, false otherwise.
	 */
	public function is_locked(): bool {
		return false !== get_transient( Constants::TRANSIENT_BULK_LOCK );
	}

	/**
	 * Refreshes lock expiration time (Heartbeat) to prevent premature timeout during active processing.
	 *
	 * @return void
	 */
	public function heartbeat(): void {
		$current = get_transient( Constants::TRANSIENT_BULK_LOCK );
		if ( is_array( $current ) ) {
			$current['time'] = time();
			set_transient( Constants::TRANSIENT_BULK_LOCK, $current, Constants::LOCK_TTL_SECONDS );
		} else {
			$lock_data = array(
				'token' => $this->session_token,
				'owner' => (string) getmypid(),
				'time'  => time(),
			);
			set_transient( Constants::TRANSIENT_BULK_LOCK, $lock_data, Constants::LOCK_TTL_SECONDS );
		}
	}

	/**
	 * Releases the concurrency mutex lock if owned by this session.
	 *
	 * @return void
	 */
	public function release_lock(): void {
		$current = get_transient( Constants::TRANSIENT_BULK_LOCK );
		if ( is_array( $current ) && isset( $current['token'] ) ) {
			if ( $current['token'] === $this->session_token ) {
				delete_transient( Constants::TRANSIENT_BULK_LOCK );
			}
		} else {
			delete_transient( Constants::TRANSIENT_BULK_LOCK );
		}
	}

	/**
	 * Force releases the concurrency mutex lock unconditionally (admin override / reset).
	 *
	 * @return void
	 */
	public function force_release(): void {
		delete_transient( Constants::TRANSIENT_BULK_LOCK );
	}

	/**
	 * Gets current lock metadata.
	 *
	 * @return array<string, mixed>|null Lock metadata or null if unlocked.
	 */
	public function get_lock_info(): ?array {
		$data = get_transient( Constants::TRANSIENT_BULK_LOCK );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Gets the age of the active lock in seconds.
	 *
	 * @return int Seconds elapsed since lock creation/update, or 0 if unlocked.
	 */
	public function get_lock_age(): int {
		$data = $this->get_lock_info();
		if ( null === $data || empty( $data['time'] ) ) {
			return 0;
		}
		return max( 0, time() - (int) $data['time'] );
	}

	/**
	 * Gets the session token for this instance.
	 *
	 * @return string
	 */
	public function get_session_token(): string {
		return $this->session_token;
	}
}
