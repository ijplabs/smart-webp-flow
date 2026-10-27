<?php
/**
 * LiteSpace Updater Service.
 *
 * Integrates WordPress core updates and the "View Details" popup with LiteSpace Platform Hub
 * and RustFS tokenized S3 storage distribution.
 *
 * @package SmartWebPFlow\Services
 */

namespace SmartWebPFlow\Services;

use SmartWebPFlow\Common\Constants;

// Exit if accessed directly.
if ( ! defined( "ABSPATH" ) ) {
	exit;
}

/**
 * Class LiteSpaceUpdater
 * Manages plugin updates, licensing checks, and WordPress transient synchronization.
 */
class LiteSpaceUpdater {

	/**
	 * Main plugin file path.
	 *
	 * @var string
	 */
	private $plugin_file = "";

	/**
	 * Plugin basename (e.g. smart-webp-flow/smart-webp-flow.php).
	 *
	 * @var string
	 */
	private $plugin_basename = "";

	/**
	 * Plugin slug identifier.
	 *
	 * @var string
	 */
	private $slug = "smart-webp-flow";

	/**
	 * Current plugin version.
	 *
	 * @var string
	 */
	private $version = Constants::VERSION;

	/**
	 * Option name for storing the license key.
	 */
	const OPTION_LICENSE_KEY = "smart_webp_flow_license_key";

	/**
	 * Option name for caching license validation data.
	 */
	const OPTION_LICENSE_DATA = "smart_webp_flow_license_data";

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Main plugin file path.
	 * @param string $version Current version string.
	 */
	public function __construct( string $plugin_file = "", string $version = "" ) {
		$this->plugin_file     = ! empty( $plugin_file ) ? $plugin_file : ( defined( "SMART_WEBP_FLOW_FILE" ) ? SMART_WEBP_FLOW_FILE : "" );
		$this->plugin_basename = ! empty( $this->plugin_file ) ? plugin_basename( $this->plugin_file ) : "smart-webp-flow/smart-webp-flow.php";
		$this->version         = ! empty( $version ) ? $version : ( defined( "SMART_WEBP_FLOW_VERSION" ) ? SMART_WEBP_FLOW_VERSION : "1.0.0" );
	}

	/**
	 * Registers all hooks for WordPress update transients and details popup.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( "pre_set_site_transient_update_plugins", array( $this, "filter_update_plugins" ) );
		add_filter( "plugins_api", array( $this, "filter_plugin_information" ), 20, 3 );
		add_action( "upgrader_process_complete", array( $this, "clean_transients" ), 10, 2 );
	}

	/**
	 * Resolves the active LiteSpace API endpoint URL.
	 *
	 * @return string
	 */
	public function get_api_url(): string {
		$default_url = "http://192.168.0.11:3000";

		if ( defined( "LITESPACE_API_URL" ) && ! empty( constant( "LITESPACE_API_URL" ) ) ) {
			$default_url = constant( "LITESPACE_API_URL" );
		} elseif ( getenv( "LITESPACE_API_URL" ) ) {
			$default_url = getenv( "LITESPACE_API_URL" );
		}

		$api_url = apply_filters( "smart_webp_flow_litespace_api_url", $default_url );
		return untrailingslashit( $api_url );
	}

	/**
	 * Retrieves the saved license key from WordPress options.
	 *
	 * @return string
	 */
	public function get_license_key(): string {
		return trim( (string) get_option( self::OPTION_LICENSE_KEY, "" ) );
	}

	/**
	 * Retrieves normalized site domain for licensing authorization.
	 *
	 * @return string
	 */
	public function get_site_domain(): string {
		$home = home_url();
		$host = wp_parse_url( $home, PHP_URL_HOST );
		if ( empty( $host ) ) {
			$host = ! empty( $_SERVER["HTTP_HOST"] ) ? sanitize_text_field( wp_unslash( $_SERVER["HTTP_HOST"] ) ) : "127.0.0.1";
		}
		$parts = explode( ":", $host );
		return $parts[0];
	}

	/**
	 * Filters WordPress update transient and checks LiteSpace Platform for newer releases.
	 *
	 * @param object|bool $transient Current transient object.
	 * @return object|bool
	 */
	public function filter_update_plugins( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		$api_url     = $this->get_api_url();
		$license_key = $this->get_license_key();
		$domain      = $this->get_site_domain();
		$site_url    = home_url();

		$payload = array(
			"slug"           => $this->slug,
			"currentVersion" => $this->version,
			"licenseKey"     => ! empty( $license_key ) ? $license_key : null,
			"domain"         => $domain,
			"siteUrl"        => $site_url,
		);

		$response = wp_remote_post(
			$api_url . "/api/v1/updates/check",
			array(
				"headers" => array(
					"Content-Type" => "application/json",
					"Accept"       => "application/json",
				),
				"body"    => wp_json_encode( $payload ),
				"timeout" => 12,
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return $transient;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return $transient;
		}

		$update_available = ! empty( $body["updateAvailable"] );
		$update_data      = isset( $body["updateData"] ) && is_array( $body["updateData"] ) ? $body["updateData"] : array();

		if ( $update_available && ! empty( $update_data ) ) {
			$item = (object) array(
				"id"            => isset( $update_data["id"] ) ? $update_data["id"] : $this->plugin_basename,
				"slug"          => isset( $update_data["slug"] ) ? $update_data["slug"] : $this->slug,
				"plugin"        => isset( $update_data["plugin"] ) ? $update_data["plugin"] : $this->plugin_basename,
				"new_version"   => isset( $update_data["newVersion"] ) ? $update_data["newVersion"] : ( isset( $update_data["new_version"] ) ? $update_data["new_version"] : "" ),
				"url"           => isset( $update_data["url"] ) ? $update_data["url"] : "",
				"package"       => isset( $update_data["package"] ) ? $update_data["package"] : "",
				"requires"      => isset( $update_data["requires"] ) ? $update_data["requires"] : "5.8",
				"requires_php"  => isset( $update_data["requiresPhp"] ) ? $update_data["requiresPhp"] : ( isset( $update_data["requires_php"] ) ? $update_data["requires_php"] : "7.4" ),
				"tested"        => isset( $update_data["tested"] ) ? $update_data["tested"] : "6.7",
				"icons"         => isset( $update_data["icons"] ) && is_array( $update_data["icons"] ) ? $update_data["icons"] : array(),
				"banners"       => isset( $update_data["banners"] ) && is_array( $update_data["banners"] ) ? $update_data["banners"] : array(),
			);

			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}
			$transient->response[ $this->plugin_basename ] = $item;

			if ( isset( $transient->no_update[ $this->plugin_basename ] ) ) {
				unset( $transient->no_update[ $this->plugin_basename ] );
			}
		} else {
			$no_update_item = (object) array(
				"id"            => $this->plugin_basename,
				"slug"          => $this->slug,
				"plugin"        => $this->plugin_basename,
				"new_version"   => $this->version,
				"url"           => "",
				"package"       => "",
				"requires"      => "5.8",
				"requires_php"  => "7.4",
			);

			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}
			$transient->no_update[ $this->plugin_basename ] = $no_update_item;

			if ( isset( $transient->response[ $this->plugin_basename ] ) ) {
				unset( $transient->response[ $this->plugin_basename ] );
			}
		}

		return $transient;
	}

	/**
	 * Intercepts WordPress View Details API requests for plugin modal window.
	 *
	 * @param false|object|array $result Default result.
	 * @param string             $action Action requested (e.g. plugin_information).
	 * @param object             $args Arguments object.
	 * @return false|object
	 */
	public function filter_plugin_information( $result, $action, $args ) {
		if ( "plugin_information" !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$api_url     = $this->get_api_url();
		$license_key = $this->get_license_key();

		$payload = array(
			"slug"       => $this->slug,
			"licenseKey" => ! empty( $license_key ) ? $license_key : null,
		);

		$response = wp_remote_post(
			$api_url . "/api/v1/updates/info",
			array(
				"headers" => array(
					"Content-Type" => "application/json",
					"Accept"       => "application/json",
				),
				"body"    => wp_json_encode( $payload ),
				"timeout" => 12,
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return $result;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return $result;
		}

		$info = (object) array(
			"name"          => isset( $body["name"] ) ? $body["name"] : "Smart WebP Flow",
			"slug"          => isset( $body["slug"] ) ? $body["slug"] : $this->slug,
			"version"       => isset( $body["version"] ) ? $body["version"] : $this->version,
			"author"        => isset( $body["author"] ) ? $body["author"] : '<a href="https://ijplabs.com">IJP Labs</a>',
			"homepage"      => isset( $body["homepage"] ) ? $body["homepage"] : "https://ijplabs.com",
			"requires"      => isset( $body["requires"] ) ? $body["requires"] : "5.8",
			"tested"        => isset( $body["tested"] ) ? $body["tested"] : "6.7",
			"requires_php"  => isset( $body["requiresPhp"] ) ? $body["requiresPhp"] : ( isset( $body["requires_php"] ) ? $body["requires_php"] : "7.4" ),
			"last_updated"  => isset( $body["lastUpdated"] ) ? $body["lastUpdated"] : ( isset( $body["last_updated"] ) ? $body["last_updated"] : gmdate( "Y-m-d H:i:s" ) ),
			"sections"      => isset( $body["sections"] ) && is_array( $body["sections"] ) ? $body["sections"] : array(),
			"download_link" => isset( $body["downloadLink"] ) ? $body["downloadLink"] : ( isset( $body["download_link"] ) ? $body["download_link"] : "" ),
			"banners"       => isset( $body["banners"] ) && is_array( $body["banners"] ) ? $body["banners"] : array(),
		);

		return $info;
	}

	/**
	 * Activates license key against LiteSpace Platform Hub.
	 *
	 * @param string $license_key License key to activate.
	 * @return array{success: bool, message: string}
	 */
	public function activate_license( string $license_key ): array {
		$api_url  = $this->get_api_url();
		$domain   = $this->get_site_domain();
		$site_url = home_url();

		$payload = array(
			"licenseKey" => trim( $license_key ),
			"domain"     => $domain,
			"siteUrl"    => $site_url,
		);

		$response = wp_remote_post(
			$api_url . "/api/v1/licenses/activate",
			array(
				"headers" => array(
					"Content-Type" => "application/json",
					"Accept"       => "application/json",
				),
				"body"    => wp_json_encode( $payload ),
				"timeout" => 12,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				"success" => false,
				"message" => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code === 200 && ! empty( $body["success"] ) ) {
			update_option( self::OPTION_LICENSE_KEY, trim( $license_key ) );
			update_option( self::OPTION_LICENSE_DATA, $body );
			return array(
				"success" => true,
				"message" => isset( $body["message"] ) ? $body["message"] : "License activated successfully.",
			);
		}

		return array(
			"success" => false,
			"message" => isset( $body["message"] ) ? $body["message"] : ( isset( $body["error"] ) ? $body["error"] : "Failed to activate license." ),
		);
	}

	/**
	 * Cleans cached transients when plugin is upgraded.
	 *
	 * @param object $upgrader Upgrader instance.
	 * @param array  $options Upgrade options.
	 * @return void
	 */
	public function clean_transients( $upgrader, array $options ): void {
		if ( isset( $options["action"] ) && "update" === $options["action"] && isset( $options["type"] ) && "plugin" === $options["type"] ) {
			delete_site_transient( "update_plugins" );
		}
	}
}
