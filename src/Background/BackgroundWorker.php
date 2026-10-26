<?php
/**
 * Autonomous Background Chunk Worker & Keyset Pagination Processor.
 *
 * @package SmartWebPFlow\Background
 */

namespace SmartWebPFlow\Background;

use SmartWebPFlow\Common\Constants;
use SmartWebPFlow\Engines\EngineFactory;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BackgroundWorker
 * Coordinates chunk-based conversion of existing media library attachments with zero downtime.
 */
class BackgroundWorker {

	/**
	 * Lock manager instance.
	 *
	 * @var LockManager
	 */
	private $lock_manager;

	/**
	 * Throttle manager instance.
	 *
	 * @var ThrottleManager
	 */
	private $throttle_manager;

	/**
	 * State tracker instance.
	 *
	 * @var StateTracker
	 */
	private $state_tracker;

	/**
	 * Async dispatcher instance.
	 *
	 * @var AsyncDispatcher
	 */
	private $dispatcher;

	/**
	 * Constructor.
	 *
	 * @param LockManager|null      $lock_manager     Lock manager instance.
	 * @param ThrottleManager|null  $throttle_manager Throttle manager instance.
	 * @param StateTracker|null     $state_tracker    State tracker instance.
	 * @param AsyncDispatcher|null  $dispatcher       Async dispatcher instance.
	 */
	public function __construct(
		?LockManager $lock_manager = null,
		?ThrottleManager $throttle_manager = null,
		?StateTracker $state_tracker = null,
		?AsyncDispatcher $dispatcher = null
	) {
		$this->lock_manager     = $lock_manager ?? new LockManager();
		$this->throttle_manager = $throttle_manager ?? new ThrottleManager();
		$this->state_tracker    = $state_tracker ?? new StateTracker();
		$this->dispatcher       = $dispatcher ?? new AsyncDispatcher();
	}

	/**
	 * Counts total eligible image attachments in the database.
	 *
	 * @return int Total eligible attachments.
	 */
	public function count_eligible_attachments(): int {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = %s AND post_mime_type IN (%s, %s)",
				'attachment',
				'image/jpeg',
				'image/png'
			)
		);
	}

	/**
	 * Retrieves the next batch of attachment IDs using Keyset Pagination (ID > last_id).
	 *
	 * @param int $last_id    Last processed attachment ID.
	 * @param int $chunk_size Number of items per chunk.
	 * @return array<int> List of attachment IDs.
	 */
	public function get_next_chunk_ids( int $last_id, int $chunk_size ): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = 'attachment'
				AND post_mime_type IN ('image/jpeg', 'image/png')
				AND ID > %d
				ORDER BY ID ASC
				LIMIT %d",
				$last_id,
				$chunk_size
			)
		);

		return is_array( $results ) ? array_map( 'intval', $results ) : array();
	}

	/**
	 * Processes a single chunk of images.
	 *
	 * @param int $chunk_size Number of items to process in this chunk.
	 * @return array<string, mixed> Chunk execution summary.
	 */
	public function process_next_chunk( int $chunk_size = Constants::DEFAULT_CHUNK_SIZE ): array {
		$state   = $this->state_tracker->get_state();
		$last_id = (int) $state['last_id'];

		// Initialize total_items if not yet set.
		if ( 0 === (int) $state['total_items'] ) {
			$total = $this->count_eligible_attachments();
			$this->state_tracker->update_state( array( 'total_items' => $total ) );
			$state['total_items'] = $total;
		}

		$ids = $this->get_next_chunk_ids( $last_id, $chunk_size );

		// If no items remain, mark as completed.
		if ( empty( $ids ) ) {
			$this->state_tracker->set_status( Constants::STATE_COMPLETED );
			$this->lock_manager->release_lock();

			return array(
				'processed'    => 0,
				'converted'    => 0,
				'failed'       => 0,
				'skipped'      => 0,
				'orig_bytes'   => 0,
				'webp_bytes'   => 0,
				'saved_bytes'  => 0,
				'last_id'      => $last_id,
				'is_completed' => true,
				'state'        => $this->state_tracker->calculate_progress(),
			);
		}

		$settings   = get_option( Constants::OPTION_SETTINGS, array() );
		$pacing_ms  = (int) ( $settings['throttle_pacing_ms'] ?? ThrottleManager::DEFAULT_PACING_MS );
		$processed  = 0;
		$converted  = 0;
		$failed     = 0;
		$skipped    = 0;
		$orig_bytes = 0;
		$webp_bytes = 0;

		foreach ( $ids as $attachment_id ) {
			// Heartbeat update to maintain lock active.
			$this->lock_manager->heartbeat();

			$result = $this->process_attachment( $attachment_id, $settings );

			++$processed;
			$last_id = max( $last_id, $attachment_id );

			if ( 'converted' === $result['status'] ) {
				++$converted;
				$orig_bytes += (int) $result['original_bytes'];
				$webp_bytes += (int) $result['webp_bytes'];
			} elseif ( 'skipped' === $result['status'] ) {
				++$skipped;
			} else {
				++$failed;
			}

			// Apply pacing delay and adaptive backoff.
			$this->throttle_manager->apply_pacing( $pacing_ms );
		}

		// Persist chunk results in StateTracker.
		$this->state_tracker->record_chunk(
			$last_id,
			$processed,
			$converted,
			$failed,
			$skipped,
			$orig_bytes,
			$webp_bytes
		);

		$saved_bytes  = max( 0, $orig_bytes - $webp_bytes );
		$progress     = $this->state_tracker->calculate_progress();
		$is_completed = (bool) $progress['is_completed'];

		if ( $is_completed ) {
			$this->lock_manager->release_lock();
		}

		return array(
			'processed'    => $processed,
			'converted'    => $converted,
			'failed'       => $failed,
			'skipped'      => $skipped,
			'orig_bytes'   => $orig_bytes,
			'webp_bytes'   => $webp_bytes,
			'saved_bytes'  => $saved_bytes,
			'last_id'      => $last_id,
			'is_completed' => $is_completed,
			'state'        => $progress,
		);
	}

	/**
	 * Converts an individual attachment and all its generated sub-sizes to WebP.
	 *
	 * @param int                  $attachment_id Attachment Post ID.
	 * @param array<string, mixed> $settings      Plugin configuration options.
	 * @return array<string, mixed> Result of conversion attempt.
	 */
	public function process_attachment( int $attachment_id, array $settings = array() ): array {
		if ( empty( $settings ) ) {
			$settings = get_option( Constants::OPTION_SETTINGS, array() );
		}

		$status = get_post_meta( $attachment_id, Constants::META_STATUS, true );
		if ( Constants::STATUS_CONVERTED === $status ) {
			return array(
				'status'  => 'skipped',
				'message' => 'Already converted',
			);
		}

		$source_path = get_attached_file( $attachment_id );
		if ( empty( $source_path ) || ! file_exists( $source_path ) ) {
			update_post_meta( $attachment_id, Constants::META_STATUS, Constants::STATUS_FAILED );
			return array(
				'status'  => 'failed',
				'message' => 'Attached file not found on disk',
			);
		}

		// Security Check: Verify file is authentic image and not a corrupt file / polyglot.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Safe inspection of image bitmap.
		$image_info = @getimagesize( $source_path );
		if ( false === $image_info || empty( $image_info[0] ) || empty( $image_info[1] ) ) {
			update_post_meta( $attachment_id, Constants::META_STATUS, Constants::STATUS_FAILED );
			return array(
				'status'  => 'failed',
				'message' => 'Invalid or corrupt image bitmap file',
			);
		}

		// Security Check: Decompression Bomb / Pixel Flood Defense (max 10,000px or 50 Megapixels).
		if ( $image_info[0] > 10000 || $image_info[1] > 10000 || ( $image_info[0] * $image_info[1] > 50000000 ) ) {
			update_post_meta( $attachment_id, Constants::META_STATUS, Constants::STATUS_SKIPPED );
			return array(
				'status'  => 'skipped',
				'message' => 'Image exceeds safety dimensions (potential decompression bomb)',
			);
		}

		$mime_type = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime_type, Constants::get_supported_mimes(), true ) ) {
			update_post_meta( $attachment_id, Constants::META_STATUS, Constants::STATUS_SKIPPED );
			return array(
				'status'  => 'skipped',
				'message' => 'Unsupported MIME type',
			);
		}

		$engine = EngineFactory::create( $settings );
		if ( ! $engine->is_supported() ) {
			return array(
				'status'  => 'failed',
				'message' => 'No active conversion engine supported on server',
			);
		}

		if ( 'image/png' === $mime_type ) {
			$quality = (int) ( $settings['quality_png'] ?? Constants::DEFAULT_QUALITY_PNG );
		} else {
			$quality = (int) ( $settings['quality_jpeg'] ?? Constants::DEFAULT_QUALITY_JPEG );
		}

		$orig_total_bytes = 0;
		$webp_total_bytes = 0;

		$path_info = pathinfo( $source_path );
		$dest_dir  = $path_info['dirname'];
		$filename  = $path_info['filename'];
		$dest_path = $dest_dir . '/' . $filename . '.webp';

		$original_master_size = (int) filesize( $source_path );
		$orig_total_bytes    += $original_master_size;

		// 1. Convert master image.
		$master_converted = $engine->convert( $source_path, $dest_path, $quality );
		if ( ! $master_converted || ! file_exists( $dest_path ) ) {
			update_post_meta( $attachment_id, Constants::META_STATUS, Constants::STATUS_FAILED );
			return array(
				'status'  => 'failed',
				'message' => 'Failed to convert master image',
			);
		}

		$webp_master_size  = (int) filesize( $dest_path );
		$webp_total_bytes += $webp_master_size;

		$retention_policy = $settings['retention_policy'] ?? Constants::DEFAULT_RETENTION_POLICY;
		$backup_path      = '';

		if ( 'delete_original' === $retention_policy ) {
			$this->delete_file( $source_path );
		} else {
			$backup_path = $source_path;
		}

		// 2. Convert all registered sub-sizes.
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_name => $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}

				$sub_source = $dest_dir . '/' . $size_data['file'];
				if ( ! file_exists( $sub_source ) ) {
					continue;
				}

				$sub_orig_size     = (int) filesize( $sub_source );
				$orig_total_bytes += $sub_orig_size;

				$sub_dest = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $sub_source );
				if ( null === $sub_dest || $sub_dest === $sub_source ) {
					$sub_dest = $dest_dir . '/' . pathinfo( $size_data['file'], PATHINFO_FILENAME ) . '.webp';
				}

				$sub_converted = $engine->convert( $sub_source, $sub_dest, $quality );
				if ( $sub_converted && file_exists( $sub_dest ) ) {
					$sub_webp_size     = (int) filesize( $sub_dest );
					$webp_total_bytes += $sub_webp_size;

					if ( 'delete_original' === $retention_policy ) {
						$this->delete_file( $sub_source );
					}

					// Update metadata for this size.
					$metadata['sizes'][ $size_name ]['file']      = basename( $sub_dest );
					$metadata['sizes'][ $size_name ]['mime-type'] = 'image/webp';
					$metadata['sizes'][ $size_name ]['filesize']  = $sub_webp_size;
				} else {
					$webp_total_bytes += $sub_orig_size;
				}
			}
		}

		// Update attachment metadata file pointer.
		if ( is_array( $metadata ) ) {
			if ( ! empty( $metadata['file'] ) ) {
				$metadata['file'] = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $metadata['file'] );
			}
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		// Update attached file and post record.
		if ( function_exists( 'update_attached_file' ) ) {
			update_attached_file( $attachment_id, $dest_path );
		} else {
			update_post_meta( $attachment_id, '_wp_attached_file', $dest_path );
		}

		wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => 'image/webp',
			)
		);

		// Record conversion metrics in postmeta.
		$savings_pct = 0.0;
		if ( $orig_total_bytes > 0 && $orig_total_bytes > $webp_total_bytes ) {
			$diff        = $orig_total_bytes - $webp_total_bytes;
			$savings_pct = round( ( (float) $diff / (float) $orig_total_bytes ) * 100, 2 );
		}

		update_post_meta( $attachment_id, Constants::META_STATUS, Constants::STATUS_CONVERTED );
		update_post_meta( $attachment_id, Constants::META_CONVERTED_TIME, current_time( 'mysql' ) );
		update_post_meta( $attachment_id, Constants::META_ORIGINAL_SIZE, $orig_total_bytes );
		update_post_meta( $attachment_id, Constants::META_WEBP_SIZE, $webp_total_bytes );
		update_post_meta( $attachment_id, Constants::META_SAVINGS_PCT, $savings_pct );

		if ( ! empty( $backup_path ) ) {
			update_post_meta( $attachment_id, Constants::META_ORIGINAL_BACKUP, $backup_path );
		}

		return array(
			'status'         => 'converted',
			'original_bytes' => $orig_total_bytes,
			'webp_bytes'     => $webp_total_bytes,
			'savings_pct'    => $savings_pct,
		);
	}

	/**
	 * Autonomous loop execution for background daemon mode.
	 * Runs batches continuously while refreshing heartbeat until complete or execution time expires.
	 *
	 * @param int $max_seconds Maximum continuous seconds to run before dispatching next loopback.
	 * @return array<string, mixed>
	 */
	public function run_autonomous_loop( int $max_seconds = 20 ): array {
		// Ensure lock is acquired.
		if ( ! $this->lock_manager->acquire_lock( 'background_daemon' ) ) {
			return array(
				'success' => false,
				'message' => 'Could not acquire concurrency lock. Another worker is active.',
			);
		}

		$this->state_tracker->set_status( Constants::STATE_RUNNING_BACKGROUND );
		$start_time = time();

		while ( true ) {
			$state = $this->state_tracker->get_state();

			// If paused or completed, terminate loop cleanly.
			if ( Constants::STATE_PAUSED === $state['status'] ) {
				$this->lock_manager->release_lock();
				return array(
					'success' => true,
					'status'  => Constants::STATE_PAUSED,
					'message' => 'Worker halted due to pause request.',
				);
			}

			if ( Constants::STATE_COMPLETED === $state['status'] ) {
				$this->lock_manager->release_lock();
				return array(
					'success' => true,
					'status'  => Constants::STATE_COMPLETED,
					'message' => 'Migration fully completed.',
				);
			}

			// Execute one chunk.
			$chunk_res = $this->process_next_chunk( Constants::DEFAULT_CHUNK_SIZE );

			if ( $chunk_res['is_completed'] ) {
				$this->lock_manager->release_lock();
				return array(
					'success' => true,
					'status'  => Constants::STATE_COMPLETED,
					'message' => 'Migration fully completed.',
				);
			}

			// Check elapsed runtime. If approaching time threshold, spawn next loopback and return.
			$elapsed = time() - $start_time;
			if ( $elapsed >= $max_seconds ) {
				$this->dispatcher->dispatch();
				return array(
					'success' => true,
					'status'  => Constants::STATE_RUNNING_BACKGROUND,
					'message' => 'Time slice reached. Dispatched next autonomous iteration.',
				);
			}
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
}
