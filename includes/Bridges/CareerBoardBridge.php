<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Career Board bridge.
 *
 * Routes Career Board events into BuddyNext surfaces:
 *
 * - Job created → bn_search_index (type: job)
 * - Application submitted → notify employer (type: cb.application_received)
 * - Application status changed → notify candidate (type: cb.application_status)
 * - Application withdrawn → notify employer (type: cb.application_withdrawn)
 *
 * @package BuddyNext\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Bridges;

use BuddyNext\Notifications\NotificationService;
use BuddyNext\Search\SearchService;

/**
 * Career Board ↔ BuddyNext integration layer.
 */
class CareerBoardBridge {

	/**
	 * Attach hooks.
	 *
	 * Called from Plugin::init() via buddynext_load_bridges action.
	 */
	public function init(): void {
		if ( ! function_exists( 'wcb_get_job' ) || ! class_exists( 'WCB_Career_Board' ) ) {
			return;
		}

		add_action( 'wcb_job_created', array( $this, 'on_job_created' ), 10, 3 );
		add_action( 'wcb_job_expired', array( $this, 'on_job_expired' ), 10, 1 );
		add_action( 'wcb_application_submitted', array( $this, 'on_application_submitted' ), 10, 3 );
		add_action( 'wcb_application_status_changed', array( $this, 'on_application_status_changed' ), 10, 4 );
		add_action( 'wcb_application_withdrawn', array( $this, 'on_application_withdrawn' ), 10, 4 );
	}

	/**
	 * Index a job posting in bn_search_index.
	 *
	 * Hooked on: wcb_job_created($job_id, $job_data, $user_id) — 3 args.
	 * Title and description are extracted from $job_data array.
	 *
	 * @param int   $job_id   Job post ID.
	 * @param array $job_data Associative array of job fields (title, description, etc.).
	 * @param int   $user_id  Employer user ID.
	 */
	public function on_job_created( int $job_id, array $job_data, int $user_id ): void {
		$title       = (string) ( $job_data['title'] ?? '' );
		$description = (string) ( $job_data['description'] ?? '' );

		( new SearchService() )->index( 'job', $job_id, $title, $description, $user_id );
	}

	/**
	 * Remove or archive the bn_posts entry for a job when it expires.
	 *
	 * Hooked on: wcb_job_expired($job_id) — 1 arg.
	 * Deletes the feed card (type: job_post) associated with the expired job so
	 * the listing no longer appears in the BuddyNext activity feed.
	 *
	 * @param int $job_id Expired job post ID.
	 */
	public function on_job_expired( int $job_id ): void {
		$permalink = get_permalink( $job_id );
		if ( ! $permalink ) {
			return;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_posts',
			array(
				'type'     => 'job_post',
				'link_url' => $permalink,
			),
			array( '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Notify employer when a candidate applies.
	 *
	 * Hooked on: wcb_application_submitted($app_id, $job_id, $candidate_id) — 3 args.
	 * Employer ID is resolved from the job post's author field.
	 *
	 * @param int $app_id       Application ID.
	 * @param int $job_id       Job post ID.
	 * @param int $candidate_id Applying candidate.
	 */
	public function on_application_submitted( int $app_id, int $job_id, int $candidate_id ): void {
		$employer_id = (int) get_post_field( 'post_author', $job_id );
		if ( 0 === $employer_id ) {
			return;
		}

		( new NotificationService() )->create(
			array(
				'recipient_id' => $employer_id,
				'sender_id'    => $candidate_id,
				'type'         => 'cb.application_received',
				'object_type'  => 'job',
				'object_id'    => $job_id,
				'group_key'    => "cb_app_{$job_id}_{$employer_id}",
				'data'         => array( 'app_id' => $app_id ),
			)
		);
	}

	/**
	 * Notify candidate when their application status changes.
	 *
	 * Hooked on: wcb_application_status_changed($app_id, $old_status, $new_status, $candidate_id)
	 *
	 * @param int    $app_id       Application ID.
	 * @param string $old_status   Previous status.
	 * @param string $new_status   New status.
	 * @param int    $candidate_id Candidate to notify.
	 */
	public function on_application_status_changed( int $app_id, string $old_status, string $new_status, int $candidate_id ): void {
		( new NotificationService() )->create(
			array(
				'recipient_id' => $candidate_id,
				'type'         => 'cb.application_status',
				'object_type'  => 'application',
				'object_id'    => $app_id,
				'data'         => array(
					'old_status' => $old_status,
					'new_status' => $new_status,
				),
			)
		);
	}

	/**
	 * Notify employer when a candidate withdraws their application.
	 *
	 * Hooked on: wcb_application_withdrawn($app_id, $job_id, $candidate_id, $employer_id)
	 *
	 * @param int $app_id       Application ID.
	 * @param int $job_id       Job post ID.
	 * @param int $candidate_id Withdrawing candidate.
	 * @param int $employer_id  Employer user ID.
	 */
	public function on_application_withdrawn( int $app_id, int $job_id, int $candidate_id, int $employer_id ): void {
		if ( 0 === $employer_id ) {
			return;
		}

		( new NotificationService() )->create(
			array(
				'recipient_id' => $employer_id,
				'sender_id'    => $candidate_id,
				'type'         => 'cb.application_withdrawn',
				'object_type'  => 'job',
				'object_id'    => $job_id,
				'data'         => array( 'app_id' => $app_id ),
			)
		);
	}
}
