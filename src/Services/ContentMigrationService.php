<?php
/**
 * Database Post Content Migration & Rollback Service.
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
 * Class ContentMigrationService
 * Performs permanent chunked database replacement of legacy image URLs in post_content with dry-run and rollback snapshots.
 */
class ContentMigrationService {

	/**
	 * Post content service instance.
	 *
	 * @var PostContentService
	 */
	private $content_service;

	/**
	 * Constructor.
	 *
	 * @param PostContentService|null $content_service Post content service.
	 */
	public function __construct( ?PostContentService $content_service = null ) {
		$this->content_service = $content_service ?? new PostContentService();
	}

	/**
	 * Counts total published posts and pages in the database.
	 *
	 * @return int Total candidate posts.
	 */
	public function count_candidate_posts(): int {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(ID) FROM {$wpdb->posts}
				WHERE post_type IN (%s, %s)
				AND post_status IN (%s, %s, %s)",
				'post',
				'page',
				'publish',
				'draft',
				'private'
			)
		);
	}

	/**
	 * Retrieves candidate post records using Keyset pagination (ID > last_id).
	 *
	 * @param int $last_id    Last processed post ID.
	 * @param int $chunk_size Number of posts per batch.
	 * @return array<object> List of post objects with ID, post_title, post_content.
	 */
	public function get_candidate_posts( int $last_id, int $chunk_size = 10 ): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_content FROM {$wpdb->posts}
				WHERE post_type IN (%s, %s)
				AND post_status IN (%s, %s, %s)
				AND ID > %d
				ORDER BY ID ASC
				LIMIT %d",
				'post',
				'page',
				'publish',
				'draft',
				'private',
				$last_id,
				$chunk_size
			)
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Executes a Dry-Run preview for a chunk of posts without updating the database.
	 *
	 * @param int $last_id    Last post ID.
	 * @param int $chunk_size Batch size.
	 * @return array<string, mixed> Dry-run summary and preview diffs.
	 */
	public function dry_run_batch( int $last_id = 0, int $chunk_size = 10 ): array {
		$posts        = $this->get_candidate_posts( $last_id, $chunk_size );
		$previews     = array();
		$total_found  = 0;
		$new_last_id  = $last_id;
		$is_completed = count( $posts ) < $chunk_size;

		foreach ( $posts as $post ) {
			$post_id     = (int) $post->ID;
			$new_last_id = max( $new_last_id, $post_id );

			$transformed = $this->content_service->replace_image_urls( $post->post_content );

			if ( $transformed !== $post->post_content ) {
				$replacements = $this->extract_replacement_diffs( $post->post_content, $transformed );
				$total_found += count( $replacements );

				$previews[] = array(
					'post_id'      => $post_id,
					'post_title'   => $post->post_title,
					'count'        => count( $replacements ),
					'replacements' => $replacements,
				);
			}
		}

		return array(
			'dry_run'       => true,
			'scanned_posts' => count( $posts ),
			'matched_posts' => count( $previews ),
			'replacements'  => $total_found,
			'last_id'       => $new_last_id,
			'is_completed'  => $is_completed,
			'previews'      => $previews,
		);
	}

	/**
	 * Permanently migrates image URLs in post_content with rollback snapshot creation.
	 *
	 * @param int $last_id    Last processed post ID.
	 * @param int $chunk_size Batch size.
	 * @return array<string, mixed> Migration batch summary.
	 */
	public function migrate_batch( int $last_id = 0, int $chunk_size = 10 ): array {
		global $wpdb;

		$posts        = $this->get_candidate_posts( $last_id, $chunk_size );
		$updated      = 0;
		$replacements = 0;
		$new_last_id  = $last_id;
		$is_completed = count( $posts ) < $chunk_size;
		$log_entries  = array();

		foreach ( $posts as $post ) {
			$post_id     = (int) $post->ID;
			$new_last_id = max( $new_last_id, $post_id );

			$transformed = $this->content_service->replace_image_urls( $post->post_content );

			if ( $transformed !== $post->post_content ) {
				$diffs         = $this->extract_replacement_diffs( $post->post_content, $transformed );
				$diff_count    = count( $diffs );
				$replacements += $diff_count;

				// 1. Save rollback snapshot in postmeta.
				$snapshot = array(
					'original_content' => $post->post_content,
					'migrated_at'      => current_time( 'mysql' ),
					'replacements'     => $diffs,
				);
				update_post_meta( $post_id, Constants::META_CONTENT_BACKUP, $snapshot );

				// 2. Perform database update.
				if ( isset( $wpdb ) && is_object( $wpdb ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$wpdb->posts,
						array( 'post_content' => $transformed ),
						array( 'ID' => $post_id ),
						array( '%s' ),
						array( '%d' )
					);
				} elseif ( function_exists( 'wp_update_post' ) ) {
					wp_update_post(
						array(
							'ID'           => $post_id,
							'post_content' => $transformed,
						)
					);
				}

				++$updated;

				$log_entries[] = array(
					'post_id'      => $post_id,
					'post_title'   => $post->post_title,
					'timestamp'    => current_time( 'mysql' ),
					'replacements' => $diff_count,
				);
			}
		}

		// Append to audit log.
		if ( ! empty( $log_entries ) ) {
			$this->append_to_audit_log( $log_entries );
		}

		return array(
			'dry_run'       => false,
			'scanned_posts' => count( $posts ),
			'updated_posts' => $updated,
			'replacements'  => $replacements,
			'last_id'       => $new_last_id,
			'is_completed'  => $is_completed,
		);
	}

	/**
	 * Rolls back post content from the saved backup snapshot.
	 *
	 * @param int $post_id Post ID to revert.
	 * @return array<string, mixed> Result of rollback attempt.
	 */
	public function rollback_post( int $post_id ): array {
		global $wpdb;

		$snapshot = get_post_meta( $post_id, Constants::META_CONTENT_BACKUP, true );

		if ( empty( $snapshot ) || ! is_array( $snapshot ) || empty( $snapshot['original_content'] ) ) {
			return array(
				'success' => false,
				'message' => 'No rollback snapshot found for post ID ' . $post_id,
			);
		}

		$original_content = $snapshot['original_content'];

		// Revert post_content in database.
		if ( isset( $wpdb ) && is_object( $wpdb ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->posts,
				array( 'post_content' => $original_content ),
				array( 'ID' => $post_id ),
				array( '%s' ),
				array( '%d' )
			);
		} elseif ( function_exists( 'wp_update_post' ) ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $original_content,
				)
			);
		}

		// Remove backup snapshot after successful rollback.
		delete_post_meta( $post_id, Constants::META_CONTENT_BACKUP );

		// Record rollback in audit log.
		$this->append_to_audit_log(
			array(
				array(
					'post_id'   => $post_id,
					'action'    => 'rollback',
					'timestamp' => current_time( 'mysql' ),
				),
			)
		);

		return array(
			'success' => true,
			'post_id' => $post_id,
			'message' => 'Post content successfully rolled back from backup snapshot.',
		);
	}

	/**
	 * Extracts URL diffs between original content and transformed content.
	 *
	 * @param string $original    Original HTML content.
	 * @param string $transformed Transformed HTML content.
	 * @return array<array<string, string>> Array of before/after URL pairs.
	 */
	public function extract_replacement_diffs( string $original, string $transformed ): array {
		preg_match_all( '/(https?:\/\/[^\s"\']+\.(?:jpe?g|png))/i', $original, $matches_orig );
		$diffs = array();

		if ( ! empty( $matches_orig[1] ) ) {
			foreach ( array_unique( $matches_orig[1] ) as $orig_url ) {
				$webp_url = (string) preg_replace( '/\.(jpe?g|png)$/i', '.webp', $orig_url );
				if ( str_contains( $transformed, $webp_url ) ) {
					$diffs[] = array(
						'original' => $orig_url,
						'webp'     => $webp_url,
					);
				}
			}
		}

		return $diffs;
	}

	/**
	 * Retrieves the content migration audit log.
	 *
	 * @return array<array<string, mixed>>
	 */
	public function get_audit_log(): array {
		$log = get_option( Constants::OPTION_CONTENT_LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Appends new entries to the content migration audit log.
	 *
	 * @param array<array<string, mixed>> $new_entries List of entries.
	 * @return void
	 */
	public function append_to_audit_log( array $new_entries ): void {
		$log    = $this->get_audit_log();
		$merged = array_merge( $log, $new_entries );

		// Keep last 100 entries to prevent option bloat.
		if ( count( $merged ) > 100 ) {
			$merged = array_slice( $merged, -100 );
		}

		update_option( Constants::OPTION_CONTENT_LOG, $merged );
	}

	/**
	 * Clears the content migration audit log.
	 *
	 * @return void
	 */
	public function clear_audit_log(): void {
		delete_option( Constants::OPTION_CONTENT_LOG );
	}
}
