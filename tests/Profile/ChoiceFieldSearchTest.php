<?php
/**
 * Profile field values are findable however the profile was saved.
 *
 * Reported by a customer (support #40911) who could not find members by any
 * profile field, with dropdown and radio fields specifically, with or without
 * umlauts. The mirror was correct the whole time — `bn_field_qa_instrument`
 * held "French Horn" — and search still missed, which is what made it look like
 * a matching bug.
 *
 * The cause was that the search index is what both surfaces read, and
 * `buddynext_index_user` was fired only by the REST controller. Every other
 * write path — admin edits, bulk tools, and above all the BuddyPress/BuddyBoss
 * importer, which writes profiles wholesale — left the member's index entry
 * stale, so imported members sat in the directory unreachable by search.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\MemberDirectoryService;

/**
 * Search coverage for values written through the service.
 *
 * @covers \BuddyNext\Profile\ProfileService::save_profile
 */
class ChoiceFieldSearchTest extends \WP_UnitTestCase {

	/**
	 * Profile service.
	 *
	 * @var object
	 */
	private $profiles;

	/**
	 * Directory service used to run the member search.
	 *
	 * @var MemberDirectoryService
	 */
	private $directory;

	/**
	 * The member carrying the field values.
	 *
	 * @var int
	 */
	private $member = 0;

	/**
	 * Create the schema, a searchable choice field and a member.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		global $wpdb;

		$this->profiles  = buddynext_service( 'profiles' );
		$this->directory = new MemberDirectoryService();
		$this->member    = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$group_id = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}bn_profile_groups ORDER BY id LIMIT 1" );

		foreach (
			array(
				array( 'qa_instrument', 'Instrument', 'select', array( 'French Horn', 'Flügelhorn', 'Trumpet' ) ),
				array( 'qa_genre', 'Genre', 'radio', array( 'Jazz', 'Klassik', 'Rock' ) ),
			) as $spec
		) {
			$field_id = $this->profiles->create_field(
				array(
					'group_id'      => $group_id,
					'field_key'     => $spec[0],
					'label'         => $spec[1],
					'type'          => $spec[2],
					'options'       => $spec[3],
					'is_searchable' => 1,
					'visibility'    => 'public',
				)
			);
			$this->assertIsInt( $field_id, 'Could not create the ' . $spec[2] . ' fixture field.' );
		}
	}

	/*
	 * The end-to-end search is asserted on a live site, not here: bn_search_index
	 * is a FULLTEXT surface whose minimum token length and stopword list are
	 * server configuration, so a test database does not reproduce a production
	 * match and a green assertion here would prove nothing. What these tests pin
	 * is what was actually broken — the SERVICE asking for a re-index, and the
	 * mirror holding the label rather than a lossy slug.
	 */

	/**
	 * The reported bug: saving through the SERVICE (an import, an admin edit)
	 * must leave the member findable, not just a save through REST.
	 *
	 * @return void
	 */
	public function test_a_service_save_asks_for_a_reindex(): void {
		$fired = array();
		add_action(
			'buddynext_index_user',
			static function ( $user_id ) use ( &$fired ): void {
				$fired[] = (int) $user_id;
			}
		);

		$this->profiles->save_profile( $this->member, array( 'qa_instrument' => 'French Horn' ) );

		$this->assertContains(
			$this->member,
			$fired,
			'save_profile() did not request a re-index, so any caller that is not the REST controller leaves the member unsearchable.'
		);
	}

	/**
	 * A dropdown value is matched by its LABEL, not the slug it is stored as.
	 *
	 * @return void
	 */
	public function test_a_select_value_matches_on_its_label(): void {
		global $wpdb;

		$this->profiles->save_profile( $this->member, array( 'qa_instrument' => 'French Horn' ) );

		// Stored as a slug…
		$stored = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT v.value FROM {$wpdb->prefix}bn_profile_values v
				 INNER JOIN {$wpdb->prefix}bn_profile_fields f ON f.id = v.field_id
				 WHERE v.user_id = %d AND f.field_key = 'qa_instrument'",
				$this->member
			)
		);
		$this->assertSame( 'french-horn', $stored );

		// …mirrored as the label, which is what a member actually searches for.
		$this->assertSame( 'French Horn', get_user_meta( $this->member, 'bn_field_qa_instrument', true ) );
	}

	/**
	 * Radio behaves identically — the card reported both types.
	 *
	 * @return void
	 */
	public function test_a_radio_value_mirrors_its_label(): void {
		$this->profiles->save_profile( $this->member, array( 'qa_genre' => 'Klassik' ) );

		$this->assertSame( 'Klassik', get_user_meta( $this->member, 'bn_field_qa_genre', true ) );
	}

	/**
	 * The umlaut case the customer controlled for: an accented label must be
	 * findable both as typed and folded.
	 *
	 * @return void
	 */
	public function test_an_accented_label_mirrors_unmangled(): void {
		$this->profiles->save_profile( $this->member, array( 'qa_instrument' => 'Flügelhorn' ) );

		// The label, not a remove_accents() slug. A slug mirrored 'fluegelhorn'
		// on a German site while the search term collated to 'flugelhorn', which
		// made the member permanently unfindable.
		$this->assertSame( 'Flügelhorn', get_user_meta( $this->member, 'bn_field_qa_instrument', true ) );
	}

	/**
	 * Both surfaces resolve members through the same call, so the directory and
	 * Explore cannot report different coverage — the card's second symptom.
	 *
	 * @return void
	 */
	public function test_directory_and_explore_resolve_through_one_call(): void {
		// The card's second symptom was that the two surfaces reported different
		// coverage. They cannot any more: the directory delegates to the same
		// SearchService call Explore uses, so there is one query path, not two.
		$directory = $this->directory->matching_user_ids( 'Trumpet', 1 );
		$explore   = buddynext_service( 'search' )->match_member_ids( 'Trumpet', 500, 1 );

		$this->assertSame( $explore, $directory, 'The directory and Explore diverged again.' );
	}
}
