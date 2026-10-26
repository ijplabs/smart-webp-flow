<?php
/**
 * Attachment Metadata and Lifecycle Service.
 *
 * @package SmartWebPFlow\Services
 */

namespace SmartWebPFlow\Services;

use SmartWebPFlow\Common\Constants;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MetadataService
 * Manages postmeta persistence, savings calculations, admin media columns, and lifecycle cleanup.
 */
class MetadataService {

	/**
	 * Registers WordPress metadata and admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Capture metadata generation for new attachments.
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'process_attachment_metadata' ), 10, 2 );

		// Admin Media Library column hooks.
		add_filter( 'manage_media_columns', array( $this, 'add_media_columns' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_media_custom_column' ), 10, 2 );

		// Cleanup backup file on attachment deletion.
		add_action( 'delete_attachment', array( $this, 'cleanup_backup_file' ) );
	}

	/**
	 * Processes generated attachment metadata and records conversion metrics.
	 *
	 * @param array<string, mixed> $metadata      Attachment metadata array.
	 * @param int                  $attachment_id Attachment Post ID.
	 * @return array<string, mixed>
	 */
	public function process_attachment_metadata( array $metadata, int $attachment_id ): array {
		if ( empty( $attachment_id ) ) {
			return $metadata;
		}

		$attached_file = get_attached_file( $attachment_id );
		if ( empty( $attached_file ) && ! empty( $metadata['file'] ) ) {
			$upload_dir    = wp_upload_dir();
			$attached_file = $upload_dir['basedir'] . '/' . $metadata['file'];
		}

		if ( empty( $attached_file ) ) {
			return $metadata;
		}

		// Retrieve registry entry from UploadService if converted during this request.
		$registry_entry = UploadService::get_registry_entry( $attached_file );

		if ( null !== $registry_entry ) {
			$original_size = (int) ( $registry_entry['original_size'] ?? 0 );
			$webp_size     = (int) ( $registry_entry['webp_size'] ?? 0 );
			$backup_path   = (string) ( $registry_entry['original_path'] ?? '' );
			$savings_pct   = $this->calculate_savings( $original_size, $webp_size );

			// Store metadata.
			update_post_meta( $attachment_id, Constants::META_STATUS, Constants::STATUS_CONVERTED );
			update_post_meta( $attachment_id, Constants::META_CONVERTED_TIME, current_time( 'mysql' ) );
			update_post_meta( $attachment_id, Constants::META_ORIGINAL_SIZE, $original_size );
			update_post_meta( $attachment_id, Constants::META_WEBP_SIZE, $webp_size );
			update_post_meta( $attachment_id, Constants::META_SAVINGS_PCT, $savings_pct );

			if ( ! empty( $backup_path ) ) {
				update_post_meta( $attachment_id, Constants::META_ORIGINAL_BACKUP, $backup_path );
			}

			// Ensure attachment post_mime_type is image/webp.
			$current_mime = get_post_mime_type( $attachment_id );
			if ( 'image/webp' !== $current_mime ) {
				wp_update_post(
					array(
						'ID'             => $attachment_id,
						'post_mime_type' => 'image/webp',
					)
				);
			}
		}

		return $metadata;
	}

	/**
	 * Calculates percentage savings between original size and WebP size.
	 *
	 * @param int $original_size Original file size in bytes.
	 * @param int $webp_size     WebP file size in bytes.
	 * @return float Percentage savings (positive number, e.g. 74.25).
	 */
	public function calculate_savings( int $original_size, int $webp_size ): float {
		if ( $original_size <= 0 || $webp_size <= 0 ) {
			return 0.0;
		}

		if ( $webp_size >= $original_size ) {
			return 0.0;
		}

		$diff    = $original_size - $webp_size;
		$percent = ( $diff / $original_size ) * 100;

		return round( (float) $percent, 2 );
	}

	/**
	 * Adds custom WebP Flow column to Media Library.
	 *
	 * @param array<string, string> $columns Existing media columns.
	 * @return array<string, string>
	 */
	public function add_media_columns( array $columns ): array {
		$columns['webp_flow_status'] = esc_html__( 'WebP Flow', 'smart-webp-flow' );
		return $columns;
	}

	/**
	 * Renders content for the custom WebP Flow column.
	 *
	 * @param string $column_name Column identifier.
	 * @param int    $post_id     Attachment ID.
	 * @return void
	 */
	public function render_media_custom_column( string $column_name, int $post_id ): void {
		if ( 'webp_flow_status' !== $column_name ) {
			return;
		}

		$status = get_post_meta( $post_id, Constants::META_STATUS, true );

		if ( Constants::STATUS_CONVERTED === $status ) {
			$savings  = get_post_meta( $post_id, Constants::META_SAVINGS_PCT, true );
			$orig_sz  = (int) get_post_meta( $post_id, Constants::META_ORIGINAL_SIZE, true );
			$webp_sz  = (int) get_post_meta( $post_id, Constants::META_WEBP_SIZE, true );
			$pct_text = ( '' !== $savings && false !== $savings ) ? (string) $savings . '%' : '0%';

			printf(
				'<span class="badge-webp-success" style="display:inline-block;padding:2px 8px;border-radius:3px;background:#e6f4ea;color:#137333;font-weight:600;font-size:11px;">%s (-%s)</span>',
				esc_html__( 'WebP', 'smart-webp-flow' ),
				esc_html( $pct_text )
			);

			if ( $orig_sz > 0 && $webp_sz > 0 ) {
				printf(
					'<div style="font-size:11px;color:#666;margin-top:2px;">%s &rarr; %s</div>',
					esc_html( $this->format_bytes( $orig_sz ) ),
					esc_html( $this->format_bytes( $webp_sz ) )
				);
			}
		} elseif ( Constants::STATUS_SKIPPED === $status ) {
			printf(
				'<span style="color:#666;font-size:12px;">%s</span>',
				esc_html__( 'Skipped', 'smart-webp-flow' )
			);
		} else {
			$mime = get_post_mime_type( $post_id );
			if ( 'image/webp' === $mime ) {
				printf(
					'<span style="color:#137333;font-size:12px;">%s</span>',
					esc_html__( 'Native WebP', 'smart-webp-flow' )
				);
			} else {
				printf(
					'<span style="color:#999;font-size:12px;">%s</span>',
					esc_html__( 'Unconverted', 'smart-webp-flow' )
				);
			}
		}
	}

	/**
	 * Cleans up original backup file when attachment is permanently deleted.
	 *
	 * @param int $post_id Attachment ID.
	 * @return void
	 */
	public function cleanup_backup_file( int $post_id ): void {
		$backup_path = get_post_meta( $post_id, Constants::META_ORIGINAL_BACKUP, true );

		if ( ! empty( $backup_path ) && is_string( $backup_path ) && file_exists( $backup_path ) ) {
			// Security Path Traversal Guard: Disallow deletion of system files or root configuration files.
			$real_path = realpath( $backup_path );
			if ( false === $real_path || is_dir( $real_path ) ) {
				return;
			}

			// Block critical files & sensitive directories.
			if ( str_contains( $real_path, 'wp-config.php' ) || str_contains( $real_path, '.htaccess' ) || 0 === strpos( $real_path, '/etc' ) || 0 === strpos( $real_path, '/proc' ) || 0 === strpos( $real_path, '/sys' ) ) {
				return;
			}

			if ( function_exists( 'wp_delete_file' ) ) {
				wp_delete_file( $backup_path );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $backup_path );
			}
		}
	}

	/**
	 * Formats bytes into human-readable string.
	 *
	 * @param int $bytes Number of bytes.
	 * @return string
	 */
	public function format_bytes( int $bytes ): string {
		if ( $bytes <= 0 ) {
			return '0 B';
		}

		$units = array( 'B', 'KB', 'MB', 'GB' );
		$i     = (int) floor( log( $bytes, 1024 ) );

		if ( ! isset( $units[ $i ] ) ) {
			$i = count( $units ) - 1;
		}

		return round( $bytes / pow( 1024, $i ), 1 ) . ' ' . $units[ $i ];
	}
}
