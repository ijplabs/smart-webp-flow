<?php
/**
 * External REST API Conversion Engine Driver.
 *
 * @package SmartWebPFlow\Engines
 */

namespace SmartWebPFlow\Engines;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ExternalApiEngine
 * Fallback pluggable driver connecting to a remote image converter microservice.
 */
class ExternalApiEngine implements ConversionEngineInterface {

	/**
	 * Remote API endpoint URL.
	 *
	 * @var string
	 */
	private $api_endpoint = '';

	/**
	 * Remote API authentication key.
	 *
	 * @var string
	 */
	private $api_key = '';

	/**
	 * Last error message.
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Constructor.
	 *
	 * @param string $api_endpoint Endpoint URL.
	 * @param string $api_key      API Key.
	 */
	public function __construct( string $api_endpoint = '', string $api_key = '' ) {
		$this->api_endpoint = esc_url_raw( trim( $api_endpoint ) );
		$this->api_key      = trim( $api_key );
	}

	/**
	 * Checks whether this external API engine is configured and usable.
	 *
	 * @return bool
	 */
	public function is_supported(): bool {
		if ( empty( $this->api_endpoint ) ) {
			return false;
		}

		return $this->is_safe_url( $this->api_endpoint );
	}

	/**
	 * Validates the API endpoint URL against SSRF and unsafe schemes.
	 *
	 * @param string $url URL to validate.
	 * @return bool True if safe, false if unsafe or invalid.
	 */
	public function is_safe_url( string $url ): bool {
		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback when WordPress HTTP API is not loaded in standalone mode.
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}

		// Only HTTP and HTTPS schemes are allowed.
		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		// Allow test suite filter to bypass private IP check for local mock tests.
		$allow_local = false;
		if ( function_exists( 'apply_filters' ) ) {
			$allow_local = (bool) apply_filters( 'smart_webp_flow_allow_local_api', false );
		}

		if ( $allow_local ) {
			return true;
		}

		$host = strtolower( $parts['host'] );

		// Disallow localhost and link-local.
		if ( 'localhost' === $host || '127.0.0.1' === $host || '::1' === $host ) {
			return false;
		}

		// Resolve host IP to protect against DNS rebinding and private IP ranges.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Fallback DNS lookup without fatal on network isolation.
		$ips = @dns_get_record( $host, DNS_A + DNS_AAAA );
		if ( empty( $ips ) ) {
			// Fallback check if host is an IP literal.
			if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
				return (bool) filter_var(
					$host,
					FILTER_VALIDATE_IP,
					FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
				);
			}
			return true;
		}

		foreach ( $ips as $ip_record ) {
			$ip = $ip_record['ip'] ?? ( $ip_record['ipv6'] ?? '' );
			if ( ! empty( $ip ) ) {
				$is_public = filter_var(
					$ip,
					FILTER_VALIDATE_IP,
					FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
				);
				if ( ! $is_public ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Converts source image by sending it to the remote conversion service.
	 *
	 * @param string $source_path      Absolute path to source image.
	 * @param string $destination_path Absolute path to destination WebP file.
	 * @param int    $quality          Quality level (1-100).
	 * @return bool
	 */
	public function convert( string $source_path, string $destination_path, int $quality ): bool {
		$this->last_error = '';

		if ( ! $this->is_supported() ) {
			$this->last_error = 'External API endpoint is not configured or invalid.';
			return false;
		}

		if ( ! file_exists( $source_path ) || ! is_readable( $source_path ) ) {
			$this->last_error = sprintf( 'Source file does not exist or is unreadable: %s', $source_path );
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file binary read for upload.
		$file_content = file_get_contents( $source_path );
		if ( false === $file_content ) {
			$this->last_error = sprintf( 'Failed to read source file: %s', $source_path );
			return false;
		}

		$boundary = wp_generate_password( 24, false );
		$filename = basename( $source_path );

		// Build multipart/form-data payload.
		$payload  = '--' . $boundary . "\r\n";
		$payload .= 'Content-Disposition: form-data; name="quality"' . "\r\n\r\n";
		$payload .= max( 1, min( 100, $quality ) ) . "\r\n";
		$payload .= '--' . $boundary . "\r\n";
		$payload .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n";
		$payload .= 'Content-Type: application/octet-stream' . "\r\n\r\n";
		$payload .= $file_content . "\r\n";
		$payload .= '--' . $boundary . "--\r\n";

		$headers = array(
			'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
			'Accept'       => 'image/webp',
		);

		if ( ! empty( $this->api_key ) ) {
			$headers['X-API-Key']     = $this->api_key;
			$headers['Authorization'] = 'Bearer ' . $this->api_key;
		}

		$response = null;

		if ( function_exists( 'wp_remote_post' ) ) {
			$response = wp_remote_post(
				$this->api_endpoint,
				array(
					'headers'   => $headers,
					'body'      => $payload,
					'timeout'   => 30,
					'sslverify' => true,
				)
			);
		} else {
			// Fallback cURL implementation if WordPress HTTP API is not loaded in standalone mode.
			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_error
			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, $this->api_endpoint );
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );

			$curl_headers = array();
			foreach ( $headers as $k => $v ) {
				$curl_headers[] = $k . ': ' . $v;
			}
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $curl_headers );

			$raw_body  = curl_exec( $ch );
			$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$curl_err  = curl_error( $ch );
			// phpcs:enable

			if ( ! empty( $curl_err ) ) {
				$this->last_error = sprintf( 'cURL Error: %s', $curl_err );
				return false;
			}

			$response = array(
				'response' => array( 'code' => $http_code ),
				'body'     => $raw_body,
			);
		}

		if ( is_wp_error( $response ) ) {
			$this->last_error = sprintf( 'API HTTP request failed: %s', $response->get_error_message() );
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $status_code ) {
			$this->last_error = sprintf( 'External API returned HTTP status code %d', $status_code );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			$this->last_error = 'External API returned empty response body.';
			return false;
		}

		// Security Guard: Verify payload contains authentic WebP RIFF header and WEBP signature.
		if ( strlen( $body ) < 12 || 'RIFF' !== substr( $body, 0, 4 ) || 'WEBP' !== substr( $body, 8, 4 ) ) {
			$this->last_error = 'External API returned invalid WebP binary payload (missing RIFF/WEBP header).';
			return false;
		}

		$dest_dir = dirname( $destination_path );
		if ( ! is_dir( $dest_dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $dest_dir );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $destination_path, $body );
		if ( false === $written || 0 === $written ) {
			$this->last_error = sprintf( 'Failed to write received WebP payload to %s', $destination_path );
			$this->delete_file( $destination_path );
			return false;
		}

		return true;
	}

	/**
	 * Deletes a file safely using WordPress helpers.
	 *
	 * @param string $file_path Path to file.
	 * @return void
	 */
	private function delete_file( string $file_path ): void {
		if ( ! file_exists( $file_path ) ) {
			return;
		}
		if ( function_exists( 'wp_delete_file' ) ) {
			wp_delete_file( $file_path );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $file_path );
		}
	}

	/**
	 * Gets engine display name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'External REST API (Cloud/Microservice)';
	}

	/**
	 * Gets engine identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'api';
	}

	/**
	 * Gets last error message.
	 *
	 * @return string
	 */
	public function get_last_error(): string {
		return $this->last_error;
	}
}
