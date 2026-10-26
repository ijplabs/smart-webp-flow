<?php
/**
 * Bulk Migration State Machine & Checkpoint Tracker.
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
 * Class StateTracker
 * Persists migration state and execution metrics in wp_options for fault tolerance and resume capabilities.
 */
class StateTracker {

	/**
	 * Default state schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_default_state(): array {
		return array(
			'status'          => Constants::STATE_IDLE,
			'last_id'         => 0,
			'total_items'     => 0,
			'processed_count' => 0,
			'converted_count' => 0,
			'failed_count'    => 0,
			'skipped_count'   => 0,
			'saved_bytes'     => 0,
			'original_bytes'  => 0,
			'webp_bytes'      => 0,
			'start_time'      => 0,
			'updated_time'    => time(),
			'mode'            => 'interactive',
		);
	}

	/**
	 * Retrieves current state from database.
	 *
	 * @return array<string, mixed>
	 */
	public function get_state(): array {
		$state    = get_option( Constants::OPTION_BULK_STATE, array() );
		$defaults = self::get_default_state();

		if ( ! is_array( $state ) ) {
			return $defaults;
		}

		return array_merge( $defaults, $state );
	}

	/**
	 * Updates state attributes in database.
	 *
	 * @param array<string, mixed> $updates Associative array of state fields to update.
	 * @return bool True on success, false on failure.
	 */
	public function update_state( array $updates ): bool {
		$current                = $this->get_state();
		$merged                 = array_merge( $current, $updates );
		$merged['updated_time'] = time();

		return update_option( Constants::OPTION_BULK_STATE, $merged );
	}

	/**
	 * Sets current execution status.
	 *
	 * @param string $status State flag (e.g. idle, running_interactive, running_background, paused, completed).
	 * @return bool
	 */
	public function set_status( string $status ): bool {
		$updates = array( 'status' => $status );
		if ( 0 === (int) $this->get_state()['start_time'] && str_starts_with( $status, 'running' ) ) {
			$updates['start_time'] = time();
		}
		return $this->update_state( $updates );
	}

	/**
	 * Resets state to default idle state.
	 *
	 * @return bool
	 */
	public function reset_state(): bool {
		return update_option( Constants::OPTION_BULK_STATE, self::get_default_state() );
	}

	/**
	 * Records metrics from a completed chunk execution.
	 *
	 * @param int $last_id    Highest attachment ID in the chunk.
	 * @param int $processed  Number of items processed in chunk.
	 * @param int $converted  Number of items converted.
	 * @param int $failed     Number of items failed.
	 * @param int $skipped    Number of items skipped.
	 * @param int $orig_bytes Original bytes of converted items.
	 * @param int $webp_bytes WebP bytes of converted items.
	 * @return array<string, mixed> Updated state array.
	 */
	public function record_chunk(
		int $last_id,
		int $processed,
		int $converted,
		int $failed,
		int $skipped,
		int $orig_bytes,
		int $webp_bytes
	): array {
		$state = $this->get_state();

		$saved = max( 0, $orig_bytes - $webp_bytes );

		$state['last_id']          = max( (int) $state['last_id'], $last_id );
		$state['processed_count'] += $processed;
		$state['converted_count'] += $converted;
		$state['failed_count']    += $failed;
		$state['skipped_count']   += $skipped;
		$state['original_bytes']  += $orig_bytes;
		$state['webp_bytes']      += $webp_bytes;
		$state['saved_bytes']     += $saved;
		$state['updated_time']     = time();

		// Auto complete check if processed reaches total_items.
		if ( $state['total_items'] > 0 && $state['processed_count'] >= $state['total_items'] ) {
			$state['status'] = Constants::STATE_COMPLETED;
		}

		update_option( Constants::OPTION_BULK_STATE, $state );

		return $state;
	}

	/**
	 * Calculates comprehensive progress and statistics.
	 *
	 * @return array<string, mixed> Progress indicators and ETA.
	 */
	public function calculate_progress(): array {
		$state     = $this->get_state();
		$total     = (int) $state['total_items'];
		$processed = (int) $state['processed_count'];

		$percent = 0.0;
		if ( $total > 0 ) {
			$percent = round( min( 100.0, ( $processed / $total ) * 100 ), 1 );
		} elseif ( Constants::STATE_COMPLETED === $state['status'] ) {
			$percent = 100.0;
		}

		$orig_bytes  = (int) $state['original_bytes'];
		$webp_bytes  = (int) $state['webp_bytes'];
		$saved_bytes = (int) $state['saved_bytes'];

		$savings_pct = 0.0;
		if ( $orig_bytes > 0 && $orig_bytes > $webp_bytes ) {
			$savings_pct = round( ( ( $orig_bytes - $webp_bytes ) / $orig_bytes ) * 100, 2 );
		}

		// Calculate ETA.
		$eta_seconds = 0;
		$start_time  = (int) $state['start_time'];
		$elapsed     = ( $start_time > 0 ) ? max( 1, time() - $start_time ) : 0;

		if ( $processed > 0 && $total > $processed && $elapsed > 0 ) {
			$rate        = $processed / $elapsed; // items per second
			$remaining   = $total - $processed;
			$eta_seconds = (int) ceil( $remaining / max( 0.01, $rate ) );
		}

		return array(
			'status'          => $state['status'],
			'percent'         => $percent,
			'total'           => $total,
			'processed'       => $processed,
			'converted'       => (int) $state['converted_count'],
			'failed'          => (int) $state['failed_count'],
			'skipped'         => (int) $state['skipped_count'],
			'saved_bytes'     => $saved_bytes,
			'saved_formatted' => $this->format_bytes( $saved_bytes ),
			'savings_pct'     => $savings_pct,
			'eta_seconds'     => $eta_seconds,
			'is_completed'    => ( Constants::STATE_COMPLETED === $state['status'] || ( $total > 0 && $processed >= $total ) ),
		);
	}

	/**
	 * Formats bytes to human-readable string.
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
