<?php
/**
 * Media Upload Interceptor Service.
 *
 * @package SmartWebPFlow\Services
 */

namespace SmartWebPFlow\Services;

use SmartWebPFlow\Common\Constants;
use SmartWebPFlow\Engines\EngineFactory;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class UploadService
 * Intercepts new image uploads, converts them to WebP, and forces WebP thumbnail sub-sizes.
 */
class UploadService {

	/**
	 * In-memory map of newly converted uploads during this request.
	 * Key: webp file path, Value: array of original stats.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static $upload_registry = array();

	/**
	 * Registers WordPress upload lifecycle hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Hook into standard and sideload upload completion.
		add_filter( 'wp_handle_upload', array( $this, 'intercept_upload' ) );
		add_filter( 'wp_handle_sideload', array( $this, 'intercept_upload' ) );

		// Enforce WebP output format for all generated thumbnail sub-sizes.
		add_filter( 'image_editor_output_format', array( $this, 'filter_output_format' ) );
	}

	/**
	 * Enforces WebP output format for core image sub-size generation.
	 *
	 * @param array<string, string> $output_format Map of source MIME to destination MIME.
	 * @return array<string, string>
	 */
	public function filter_output_format( array $output_format ): array {
		$output_format['image/jpeg'] = 'image/webp';
		$output_format['image/jpg']  = 'image/webp';
		$output_format['image/png']  = 'image/webp';

		return $output_format;
	}

	/**
	 * Intercepts uploaded files and converts them to WebP.
	 *
	 * @param array<string, string> $file Upload array containing 'file', 'url', 'type'.
	 * @return array<string, string>
	 */
	public function intercept_upload( array $file ): array {
		// Check for upload errors.
		if ( ! empty( $file['error'] ) || empty( $file['file'] ) || empty( $file['type'] ) ) {
			return $file;
		}

		// Only process supported formats (JPEG, PNG).
		if ( ! in_array( $file['type'], Constants::get_supported_mimes(), true ) ) {
			return $file;
		}

		$source_path = $file['file'];
		if ( ! file_exists( $source_path ) ) {
			return $file;
		}

		// Security Check: Verify file is an authentic raster image and not a disguised script/polyglot.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Safe inspection of uploaded file bitmap.
		$image_info = @getimagesize( $source_path );
		if ( false === $image_info || empty( $image_info[0] ) || empty( $image_info[1] ) ) {
			return $file;
		}

		// Security Check: Decompression Bomb / Pixel Flood Defense (max 10,000px or 50 Megapixels).
		if ( $image_info[0] > 10000 || $image_info[1] > 10000 || ( $image_info[0] * $image_info[1] > 50000000 ) ) {
			return $file;
		}

		// Retrieve active settings.
		$settings = get_option( Constants::OPTION_SETTINGS, array() );
		$engine   = EngineFactory::create( $settings );

		if ( ! $engine->is_supported() ) {
			return $file;
		}

		// Determine target compression quality based on MIME type.
		if ( 'image/png' === $file['type'] ) {
			$quality = (int) ( $settings['quality_png'] ?? Constants::DEFAULT_QUALITY_PNG );
		} else {
			$quality = (int) ( $settings['quality_jpeg'] ?? Constants::DEFAULT_QUALITY_JPEG );
		}

		// Generate destination WebP path.
		$path_info = pathinfo( $source_path );
		$dest_dir  = $path_info['dirname'];
		$filename  = $path_info['filename'];
		$dest_path = $dest_dir . '/' . $filename . '.webp';

		// Handle filename collision if destination already exists.
		if ( file_exists( $dest_path ) ) {
			if ( function_exists( 'wp_unique_filename' ) ) {
				$unique_name = wp_unique_filename( $dest_dir, $filename . '.webp' );
				$dest_path   = $dest_dir . '/' . $unique_name;
			} else {
				$dest_path = $dest_dir . '/' . $filename . '-' . time() . '.webp';
			}
		}

		$original_size = (int) filesize( $source_path );

		// Execute conversion.
		$converted = $engine->convert( $source_path, $dest_path, $quality );

		if ( ! $converted || ! file_exists( $dest_path ) ) {
			// On conversion failure, keep original file and return untouched.
			return $file;
		}

		$webp_size        = (int) filesize( $dest_path );
		$retention_policy = $settings['retention_policy'] ?? Constants::DEFAULT_RETENTION_POLICY;
		$backup_path      = '';

		if ( 'delete_original' === $retention_policy ) {
			$this->delete_file( $source_path );
		} else {
			$backup_path = $source_path;
		}

		// Record stats in request registry for MetadataService to store in postmeta.
		$webp_filename = basename( $dest_path );
		$orig_filename = basename( $source_path );

		self::$upload_registry[ $dest_path ] = array(
			'original_path' => $backup_path,
			'original_size' => $original_size,
			'webp_size'     => $webp_size,
			'original_mime' => $file['type'],
			'converted_at'  => time(),
		);

		// Update return array pointing to WebP.
		$file['file'] = $dest_path;
		$file['url']  = str_replace( $orig_filename, $webp_filename, $file['url'] );
		$file['type'] = 'image/webp';

		return $file;
	}

	/**
	 * Retrieves upload registry entry for a given WebP file.
	 *
	 * @param string $webp_path Path to WebP file.
	 * @return array<string, mixed>|null
	 */
	public static function get_registry_entry( string $webp_path ): ?array {
		if ( isset( self::$upload_registry[ $webp_path ] ) ) {
			return self::$upload_registry[ $webp_path ];
		}

		// Also check by basename if relative/absolute paths differ.
		$target_base = basename( $webp_path );
		foreach ( self::$upload_registry as $path => $entry ) {
			if ( basename( $path ) === $target_base ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Clears in-memory upload registry.
	 *
	 * @return void
	 */
	public static function clear_registry(): void {
		self::$upload_registry = array();
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
}
