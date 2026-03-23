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
class CareerBoard {

	/**
	 * Attach hooks.
	 *
	 * Called from Plugin::init() via buddynext_load_bridges action.
	 */
	public function init(): void {
		if ( ! function_exists( 'wcb_get_job' ) || ! class_exists( 'WCB_Career_Board' ) ) {
			return;
		}

		add_action( 'wcb_job_created', array( $this, 'on_job_created' ), 10, 4 );
		add_action( 'wcb_application_submitted', array( $this, 'on_application_submitted' ), 10, 3 );
		add_action( 'wcb_application_status_changed', array( $this, 'on_application_status_changed' ), 10, 4 );
		add_action( 'wcb_application_withdrawn', array( $this, 'on_application_withdrawn' ), 10, 3 );
	}

	/**
	 * Index a job posting in bn_search_index.
	 *
	 * Hooked on: wcb_job_created($job_id, $employer_id, $title, $description)
	 *
	 * @param int    $job_id      Job post ID.
	 * @param int    $employer_id Employer user ID.
	 * @param string $title       Job title.
	 * @param string $description Job description.
	 */
	public function on_job_created( int $job_id, int $employer_id, string $title, string $description ): void {
		( new SearchService() )->index( 'job', $job_id, $title, $description, $employer_id );
	}

	/**
	 * Notify employer when a candidate applies.
	 *
	 * Hooked on: wcb_application_submitted($app_id, $job_id, $candidate_id)
	 *
	 * Employer ID is resolved from the job post's post_author field rather than
	 * being passed as a hook argument (Career Board fires 3 args, not 4).
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
	 * @param int    $app_id      Application ID.
	 * @param string $old_status  Previous status.
	 * @param string $new_status  New status.
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
	 * Hooked on: wcb_application_withdrawn($app_id, $job_id, $candidate_id)
	 *
	 * Employer ID is resolved from the job post's post_author field.
	 *
	 * @param int $app_id       Application ID.
	 * @param int $job_id       Job post ID.
	 * @param int $candidate_id Withdrawing candidate.
	 */
	public function on_application_withdrawn( int $app_id, int $job_id, int $candidate_id ): void {
		$employer_id = (int) get_post_field( 'post_author', $job_id );

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
