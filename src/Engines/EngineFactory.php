<?php
/**
 * Conversion Engine Factory.
 *
 * @package SmartWebPFlow\Engines
 */

namespace SmartWebPFlow\Engines;

use SmartWebPFlow\Common\Constants;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EngineFactory
 * Instantiates and resolves the most appropriate conversion engine adapter based on server capabilities and user settings.
 */
class EngineFactory {

	/**
	 * Creates and returns the active conversion engine.
	 *
	 * @param array<string, mixed> $settings Optional plugin settings. If omitted, loads from get_option.
	 * @return ConversionEngineInterface
	 */
	public static function create( array $settings = array() ): ConversionEngineInterface {
		if ( empty( $settings ) ) {
			$settings = get_option( Constants::OPTION_SETTINGS, array() );
		}

		$mode     = $settings['engine_mode'] ?? 'auto';
		$endpoint = $settings['api_endpoint'] ?? '';
		$api_key  = $settings['api_key'] ?? '';

		$imagick = new ImagickEngine();
		$gd      = new GdEngine();
		$api     = new ExternalApiEngine( (string) $endpoint, (string) $api_key );

		// Specific engine explicitly requested by admin.
		switch ( $mode ) {
			case 'imagick':
				if ( $imagick->is_supported() ) {
					return $imagick;
				}
				break;

			case 'gd':
				if ( $gd->is_supported() ) {
					return $gd;
				}
				break;

			case 'api':
				if ( $api->is_supported() ) {
					return $api;
				}
				break;
		}

		// 'auto' mode priority cascade:
		// 1. PHP Imagick (highest compression efficiency & memory safety).
		if ( $imagick->is_supported() ) {
			return $imagick;
		}

		// 2. PHP GD (standard fallback native).
		if ( $gd->is_supported() ) {
			return $gd;
		}

		// 3. External API if configured.
		if ( $api->is_supported() ) {
			return $api;
		}

		// Fallback to Imagick even if unsupported so caller receives descriptive error on execution.
		return $imagick;
	}

	/**
	 * Returns an inventory of all engines and their availability on this server.
	 *
	 * @param array<string, mixed> $settings Optional settings to inspect API configuration.
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_available_engines( array $settings = array() ): array {
		if ( empty( $settings ) ) {
			$settings = get_option( Constants::OPTION_SETTINGS, array() );
		}

		$endpoint = $settings['api_endpoint'] ?? '';
		$api_key  = $settings['api_key'] ?? '';

		$imagick = new ImagickEngine();
		$gd      = new GdEngine();
		$api     = new ExternalApiEngine( (string) $endpoint, (string) $api_key );

		return array(
			'imagick' => array(
				'id'        => $imagick->get_id(),
				'name'      => $imagick->get_name(),
				'supported' => $imagick->is_supported(),
				'type'      => 'native_php',
			),
			'gd'      => array(
				'id'        => $gd->get_id(),
				'name'      => $gd->get_name(),
				'supported' => $gd->is_supported(),
				'type'      => 'native_php',
			),
			'api'     => array(
				'id'        => $api->get_id(),
				'name'      => $api->get_name(),
				'supported' => $api->is_supported(),
				'type'      => 'external_service',
			),
		);
	}

	/**
	 * Checks whether at least one conversion engine is supported.
	 *
	 * @param array<string, mixed> $settings Optional settings.
	 * @return bool
	 */
	public static function is_any_supported( array $settings = array() ): bool {
		$engines = self::get_available_engines( $settings );
		foreach ( $engines as $engine ) {
			if ( true === $engine['supported'] ) {
				return true;
			}
		}
		return false;
	}
}
