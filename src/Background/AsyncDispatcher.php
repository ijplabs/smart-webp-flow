<?php
/**
 * Asynchronous Non-Blocking HTTP Loopback Dispatcher.
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
 * Class AsyncDispatcher
 * Spawns an independent background PHP process using non-blocking loopback HTTP requests.
 */
class AsyncDispatcher {

	/**
	 * Transient key for single-use internal loopback dispatch authentication.
	 */
	const DISPATCH_TOKEN_KEY = 'smart_webp_dispatch_token';

	/**
	 * Dispatches an asynchronous non-blocking loopback request to execute background worker.
	 *
	 * @return bool True if dispatch request was initiated, false on failure.
	 */
	public function dispatch(): bool {
		$token       = $this->generate_token();
		$process_url = $this->get_endpoint_url();

		$args = array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => apply_filters( 'smart_webp_flow_ssl_verify', false ),
			'headers'   => array(
				'X-Smart-WebP-Dispatch' => '1',
			),
			'body'      => array(
				'token' => $token,
			),
		);

		$response = wp_remote_post( $process_url, $args );

		// wp_remote_post with blocking=false may return a WP_Error or response array.
		if ( is_wp_error( $response ) ) {
			// On some local hosts, loopback connections might be rejected.
			return false;
		}

		return true;
	}

	/**
	 * Generates and stores a short-lived transient token for dispatch authentication.
	 *
	 * @return string Single-use token.
	 */
	public function generate_token(): string {
		$token = function_exists( 'wp_generate_password' )
			? wp_generate_password( 32, false )
			: bin2hex( random_bytes( 16 ) );

		set_transient( self::DISPATCH_TOKEN_KEY, $token, 120 );
		return $token;
	}

	/**
	 * Verifies whether the provided token matches the stored dispatch token.
	 *
	 * @param string $token Received token string.
	 * @return bool True if valid, false otherwise.
	 */
	public function verify_token( string $token ): bool {
		if ( empty( $token ) ) {
			return false;
		}

		$stored = get_transient( self::DISPATCH_TOKEN_KEY );
		if ( false === $stored || ! is_string( $stored ) ) {
			return false;
		}

		if ( hash_equals( $stored, $token ) ) {
			delete_transient( self::DISPATCH_TOKEN_KEY );
			return true;
		}

		return false;
	}

	/**
	 * Resolves the loopback endpoint URL.
	 *
	 * @return string Loopback REST URL.
	 */
	public function get_endpoint_url(): string {
		if ( function_exists( 'rest_url' ) ) {
			return rest_url( Constants::REST_NAMESPACE . '/background/process' );
		}
		return admin_url( 'admin-ajax.php?action=smart_webp_bg_process' );
	}
}
