<?php
/**
 * PHP GD Conversion Engine Driver.
 *
 * @package SmartWebPFlow\Engines
 */

namespace SmartWebPFlow\Engines;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GdEngine
 * Fallback native conversion engine utilizing PHP GD library.
 */
class GdEngine implements ConversionEngineInterface {

	/**
	 * Last error message.
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Checks whether PHP GD with WebP support is available.
	 *
	 * @return bool
	 */
	public function is_supported(): bool {
		if ( ! extension_loaded( 'gd' ) || ! function_exists( 'imagewebp' ) ) {
			return false;
		}

		if ( function_exists( 'gd_info' ) ) {
			$info = gd_info();
			return ! empty( $info['WebP Support'] );
		}

		return false;
	}

	/**
	 * Converts source image to WebP using PHP GD.
	 *
	 * @param string $source_path      Absolute path to source image.
	 * @param string $destination_path Absolute path to destination WebP file.
	 * @param int    $quality          Quality level (1-100).
	 * @return bool
	 */
	public function convert( string $source_path, string $destination_path, int $quality ): bool {
		$this->last_error = '';

		if ( ! file_exists( $source_path ) || ! is_readable( $source_path ) ) {
			$this->last_error = sprintf( 'Source file does not exist or is unreadable: %s', $source_path );
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Suppress binary parsing warnings on corrupted headers.
		$image_info = @getimagesize( $source_path );
		if ( false === $image_info ) {
			$this->last_error = sprintf( 'Cannot determine image dimensions or type for: %s', $source_path );
			return false;
		}

		$mime_type = $image_info['mime'] ?? '';
		$image     = null;

		switch ( $mime_type ) {
			case 'image/jpeg':
			case 'image/jpg':
				if ( ! function_exists( 'imagecreatefromjpeg' ) ) {
					$this->last_error = 'PHP GD does not support JPEG decoding (imagecreatefromjpeg missing).';
					return false;
				}
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$image = @imagecreatefromjpeg( $source_path );
				break;

			case 'image/png':
				if ( ! function_exists( 'imagecreatefrompng' ) ) {
					$this->last_error = 'PHP GD does not support PNG decoding (imagecreatefrompng missing).';
					return false;
				}
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$image = @imagecreatefrompng( $source_path );

				if ( false !== $image && null !== $image ) {
					// Convert paletted image to true color if necessary.
					if ( function_exists( 'imagepalettetotruecolor' ) && ! imageistruecolor( $image ) ) {
						imagepalettetotruecolor( $image );
					}
					// Preserve alpha channel for transparent PNGs.
					imagealphablending( $image, false );
					imagesavealpha( $image, true );
				}
				break;

			default:
				$this->last_error = sprintf( 'Unsupported image MIME type: %s', $mime_type );
				return false;
		}

		if ( false === $image || null === $image ) {
			$this->last_error = sprintf( 'Failed to decode image using PHP GD: %s', $source_path );
			return false;
		}

		$dest_dir = dirname( $destination_path );
		if ( ! is_dir( $dest_dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $dest_dir );
			}
		}

		$clamped_quality = max( 1, min( 100, $quality ) );

		// Write WebP file using imagewebp.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$success = @imagewebp( $image, $destination_path, $clamped_quality );

		// Explicit memory cleanup: in PHP 8.0+, GdImage is an object automatically garbage collected.
		if ( PHP_VERSION_ID < 80000 && is_resource( $image ) ) {
			// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated
			imagedestroy( $image );
		}
		$image = null;

		if ( ! $success || ! file_exists( $destination_path ) || filesize( $destination_path ) === 0 ) {
			$this->last_error = 'PHP GD imagewebp failed to write WebP image.';
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
		return 'PHP GD (Native)';
	}

	/**
	 * Gets engine identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'gd';
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
