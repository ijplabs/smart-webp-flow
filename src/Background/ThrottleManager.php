<?php
/**
 * Background Load Monitor & Adaptive Throttle Manager.
 *
 * @package SmartWebPFlow\Background
 */

namespace SmartWebPFlow\Background;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ThrottleManager
 * Protects server resources from saturation via pacing and adaptive backoff.
 */
class ThrottleManager {

	/**
	 * Default pacing delay in milliseconds.
	 */
	const DEFAULT_PACING_MS = 250;

	/**
	 * High-load backoff delay in milliseconds.
	 */
	const BACKOFF_DELAY_MS = 2000;

	/**
	 * Memory usage threshold ratio before backoff is triggered (80%).
	 */
	const MEMORY_THRESHOLD_RATIO = 0.80;

	/**
	 * Evaluates whether the system is experiencing high resource pressure.
	 *
	 * @return bool True if throttling/backoff is recommended.
	 */
	public function should_throttle(): bool {
		// 1. Check CPU load average (Linux/Unix).
		$cpu_load = $this->get_cpu_load();
		$cores    = $this->get_cpu_core_count();
		$max_load = max( 2.0, (float) $cores * 0.85 );

		if ( $cpu_load > $max_load ) {
			return true;
		}

		// 2. Check PHP memory usage ratio.
		$mem_ratio = $this->get_memory_usage_ratio();
		if ( $mem_ratio > self::MEMORY_THRESHOLD_RATIO ) {
			return true;
		}

		return false;
	}

	/**
	 * Applies pacing delay and adaptive backoff if resource load is elevated.
	 *
	 * @param int $pacing_ms Custom pacing delay in milliseconds.
	 * @return array<string, mixed> Pacing execution details.
	 */
	public function apply_pacing( int $pacing_ms = self::DEFAULT_PACING_MS ): array {
		$throttled = $this->should_throttle();

		if ( $throttled ) {
			// Trigger garbage collection to free image buffers.
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}

			// Apply backoff delay.
			usleep( self::BACKOFF_DELAY_MS * 1000 );

			return array(
				'throttled' => true,
				'delay_ms'  => self::BACKOFF_DELAY_MS,
				'reason'    => 'High server load or memory headroom constraint',
			);
		}

		// Apply normal pacing delay.
		if ( $pacing_ms > 0 ) {
			usleep( $pacing_ms * 1000 );
		}

		return array(
			'throttled' => false,
			'delay_ms'  => $pacing_ms,
			'reason'    => 'Normal server state',
		);
	}

	/**
	 * Retrieves 1-minute CPU load average.
	 *
	 * @return float Load average or 0.0 if unsupported.
	 */
	public function get_cpu_load(): float {
		if ( function_exists( 'sys_getloadavg' ) ) {
			$loads = sys_getloadavg();
			if ( is_array( $loads ) && isset( $loads[0] ) ) {
				return round( (float) $loads[0], 2 );
			}
		}
		return 0.0;
	}

	/**
	 * Detects CPU core count on Linux.
	 *
	 * @return int Number of detected cores (minimum 1).
	 */
	public function get_cpu_core_count(): int {
		if ( is_readable( '/proc/cpuinfo' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$cpuinfo = file_get_contents( '/proc/cpuinfo' );
			if ( false !== $cpuinfo ) {
				$cores = substr_count( $cpuinfo, 'processor' );
				if ( $cores > 0 ) {
					return $cores;
				}
			}
		}
		return 1;
	}

	/**
	 * Calculates memory consumption ratio against configured memory_limit.
	 *
	 * @return float Value between 0.0 and 1.0 (or 0.0 if unlimited/unspecified).
	 */
	public function get_memory_usage_ratio(): float {
		$limit_bytes = $this->get_memory_limit_bytes();
		if ( $limit_bytes <= 0 ) {
			return 0.0;
		}

		$used_bytes = (int) memory_get_usage( true );
		return round( (float) ( $used_bytes / $limit_bytes ), 4 );
	}

	/**
	 * Converts PHP memory_limit string to integer bytes.
	 *
	 * @return int Limit in bytes, or -1 if unlimited.
	 */
	public function get_memory_limit_bytes(): int {
		$limit = ini_get( 'memory_limit' );
		if ( ! $limit || '-1' === $limit ) {
			return -1;
		}

		$limit = trim( $limit );
		$last  = strtolower( substr( $limit, -1 ) );
		$bytes = (int) $limit;

		switch ( $last ) {
			case 'g':
				$bytes *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$bytes *= 1024 * 1024;
				break;
			case 'k':
				$bytes *= 1024;
				break;
		}

		return max( 0, $bytes );
	}

	/**
	 * Generates structured server health diagnostic metrics.
	 *
	 * @return array<string, mixed>
	 */
	public function get_health_report(): array {
		return array(
			'cpu_load'           => $this->get_cpu_load(),
			'cpu_cores'          => $this->get_cpu_core_count(),
			'memory_used_bytes'  => memory_get_usage( true ),
			'memory_peak_bytes'  => memory_get_peak_usage( true ),
			'memory_limit_bytes' => $this->get_memory_limit_bytes(),
			'memory_ratio'       => $this->get_memory_usage_ratio(),
			'is_throttled'       => $this->should_throttle(),
		);
	}
}
