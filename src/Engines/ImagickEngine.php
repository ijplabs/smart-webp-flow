<?php
/**
 * ImageMagick Conversion Engine Driver.
 *
 * @package SmartWebPFlow\Engines
 */

namespace SmartWebPFlow\Engines;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ImagickEngine
 * Primary conversion engine utilizing PHP ImageMagick (Imagick extension).
 */
class ImagickEngine implements ConversionEngineInterface {

	/**
	 * Last error message.
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Checks whether Imagick with WebP support is available on this server.
	 *
	 * @return bool
	 */
	public function is_supported(): bool {
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( '\Imagick' ) ) {
			return false;
		}

		try {
			$formats = \Imagick::queryFormats( 'WEBP' );
			return is_array( $formats ) && in_array( 'WEBP', $formats, true );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Converts source image to WebP using ImageMagick.
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

		$dest_dir = dirname( $destination_path );
		if ( ! is_dir( $dest_dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $dest_dir );
			} elseif ( ! file_exists( $dest_dir ) ) {
				wp_mkdir_p( $dest_dir );
			}
		}

		$imagick = null;

		try {
			$imagick = new \Imagick( $source_path );

			// Auto-orient based on EXIF orientation flag before stripping metadata.
			if ( method_exists( $imagick, 'getImageOrientation' ) && method_exists( $imagick, 'setImageOrientation' ) ) {
				$orientation = $imagick->getImageOrientation();
				switch ( $orientation ) {
					case \Imagick::ORIENTATION_BOTTOMRIGHT:
						$imagick->rotateImage( '#000', 180 );
						break;
					case \Imagick::ORIENTATION_RIGHTTOP:
						$imagick->rotateImage( '#000', 90 );
						break;
					case \Imagick::ORIENTATION_LEFTBOTTOM:
						$imagick->rotateImage( '#000', -90 );
						break;
				}
				$imagick->setImageOrientation( \Imagick::ORIENTATION_TOPLEFT );
			}

			// Set target format to WebP.
			$imagick->setImageFormat( 'webp' );

			// Clamp quality between 1 and 100.
			$clamped_quality = max( 1, min( 100, $quality ) );
			$imagick->setImageCompressionQuality( $clamped_quality );

			// Tune WebP compression method for optimal compression vs speed.
			$imagick->setOption( 'webp:method', '6' );

			// Preserve alpha transparency for PNGs or 32-bit images.
			if ( method_exists( $imagick, 'getImageAlphaChannel' ) && $imagick->getImageAlphaChannel() ) {
				if ( defined( '\Imagick::ALPHACHANNEL_ACTIVATE' ) ) {
					$imagick->setImageAlphaChannel( \Imagick::ALPHACHANNEL_ACTIVATE );
				}
			}

			// Strip unnecessary EXIF and comments to minimize file size.
			$imagick->stripImage();

			// Write output file.
			$success = $imagick->writeImage( $destination_path );

			// Explicit memory cleanup.
			$imagick->clear();
			$imagick->destroy();
			$imagick = null;

			if ( ! $success || ! file_exists( $destination_path ) || filesize( $destination_path ) === 0 ) {
				$this->last_error = 'Failed to write valid WebP image to destination.';
				$this->delete_file( $destination_path );
				return false;
			}

			return true;
		} catch ( \Throwable $e ) {
			$this->last_error = $e->getMessage();

			if ( null !== $imagick ) {
				try {
					$imagick->clear();
					$imagick->destroy();
				} catch ( \Throwable $cleanup_err ) {
					// Record cleanup error notice if necessary.
					$this->last_error .= ' Cleanup notice: ' . $cleanup_err->getMessage();
				}
			}

			$this->delete_file( $destination_path );

			return false;
		}
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
		return 'PHP Imagick (Native)';
	}

	/**
	 * Gets engine identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'imagick';
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
