<?php
/**
 * Conversion Engine Interface.
 *
 * @package SmartWebPFlow\Engines
 */

namespace SmartWebPFlow\Engines;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface ConversionEngineInterface
 * Standard contract for all image conversion drivers (Imagick, GD, External API).
 */
interface ConversionEngineInterface {

	/**
	 * Checks whether this engine is supported by the current server environment.
	 *
	 * @return bool True if supported, false otherwise.
	 */
	public function is_supported(): bool;

	/**
	 * Converts a source image (JPEG/PNG) to WebP.
	 *
	 * @param string $source_path      Absolute path to the source image file.
	 * @param string $destination_path Absolute path where the WebP file should be written.
	 * @param int    $quality          Compression quality level (1 to 100).
	 * @return bool True if conversion succeeded, false otherwise.
	 */
	public function convert( string $source_path, string $destination_path, int $quality ): bool;

	/**
	 * Gets the human-readable display name of the engine.
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Gets the internal identifier of the engine (e.g. 'imagick', 'gd', 'api').
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Gets the last error message encountered during conversion, if any.
	 *
	 * @return string
	 */
	public function get_last_error(): string;
}
