<?php
/**
 * A banned word blocks that word, not every word containing it.
 *
 * The list matched by substring, so a three-letter entry was unusable: adding
 * `ass` blocked "passionate", "assertive", "class", "pass", "grass" and
 * "embarrassed". The member saw "Your post contains a prohibited word" naming no
 * word, so there was nothing to rewrite and nothing to appeal, and the owner had
 * no way to tell it was happening — the post is refused, never queued, so no
 * moderation record exists to review.
 *
 * The entries below are the real ones an owner types. A test written with
 * `foo` / `barfoo` would pass against either implementation while proving
 * nothing about the case that sent this card in.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Moderation\SafeguardService;

/**
 * Whole-word matching for the banned-word list.
 *
 * @covers \BuddyNext\Moderation\SafeguardService::check_banned_words
 * @covers \BuddyNext\Moderation\SafeguardService::banned_word_pattern
 */
class BannedWordMatchTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var SafeguardService
	 */
	private SafeguardService $safeguard;

	/**
	 * Fresh service per test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->safeguard = new SafeguardService();
	}

	/**
	 * Set the site-wide banned-word list.
	 *
	 * @param string $list Newline-separated entries.
	 * @return void
	 */
	private function ban( string $list ): void {
		update_option( 'buddynext_banned_words', $list );
	}

	/**
	 * Is this content refused?
	 *
	 * @param string $content Post content.
	 * @param int    $space_id Optional space to include the per-space list from.
	 * @return bool
	 */
	private function blocked( string $content, int $space_id = 0 ): bool {
		return is_wp_error( $this->safeguard->check_content( $content, '', 0, $space_id ) );
	}

	// ── The bug ──────────────────────────────────────────────────────────────────

	/**
	 * Ordinary words that merely contain a banned word are allowed.
	 *
	 * @return void
	 */
	public function test_a_banned_word_does_not_block_words_that_contain_it(): void {
		$this->ban( 'ass' );

		foreach ( array( 'passionate', 'assertive', 'class', 'pass', 'grass', 'embarrassed' ) as $innocent ) {
			$this->assertFalse(
				$this->blocked( "I am really {$innocent} about this" ),
				"The word '{$innocent}' was refused because it contains a banned word."
			);
		}
	}

	/**
	 * ...and the banned word itself is still refused.
	 *
	 * Without this the whole fix could be "never block anything".
	 *
	 * @return void
	 */
	public function test_the_banned_word_itself_is_still_refused(): void {
		$this->ban( 'ass' );

		$this->assertTrue( $this->blocked( 'you are an ass' ), 'The banned word was allowed through.' );
	}

	// ── Boundaries a real list depends on ────────────────────────────────────────

	/**
	 * Punctuation and case do not smuggle a banned word past the check.
	 *
	 * A member who is blocked once tries exactly these next, and every one of
	 * them is the same word to a reader.
	 *
	 * @return void
	 */
	public function test_punctuation_and_case_do_not_evade_the_check(): void {
		$this->ban( 'spam' );

		foreach ( array( 'this is SPAM', 'this is Spam!', '(spam)', 'buy this: spam.', "line\nspam\nline" ) as $evasion ) {
			$this->assertTrue(
				$this->blocked( $evasion ),
				"A banned word escaped the check as: {$evasion}"
			);
		}
	}

	/**
	 * A multi-word phrase matches as a phrase, whatever spacing was typed.
	 *
	 * @return void
	 */
	public function test_a_phrase_matches_across_whitespace(): void {
		$this->ban( 'free money' );

		$this->assertTrue( $this->blocked( 'get free money now' ), 'A banned phrase was allowed.' );
		$this->assertTrue( $this->blocked( "get free  money now" ), 'A banned phrase was allowed with doubled spacing.' );
		$this->assertFalse( $this->blocked( 'free advice about money' ), 'A phrase matched two words that were not adjacent.' );
	}

	/**
	 * A star is the owner's opt-in to variants, and it stays inside one word.
	 *
	 * This is what an owner reaches for after the fix above, so it has to work:
	 * without it, blocking every form of a word means typing every form.
	 *
	 * @return void
	 */
	public function test_a_star_matches_variants_of_that_word_only(): void {
		$this->ban( 'spam*' );

		$this->assertTrue( $this->blocked( 'you are a spammer' ), 'A trailing star did not match the longer form.' );
		$this->assertTrue( $this->blocked( 'stop spamming' ), 'A trailing star did not match the inflected form.' );
		$this->assertFalse( $this->blocked( 'this is not related' ), 'A star widened the match beyond its own word.' );
	}

	/**
	 * A line of nothing but stars does not mute the whole community.
	 *
	 * `*` expands to "any run of word characters", so an entry made only of stars
	 * matches the empty string and every post ever written fails. An owner types
	 * that line by accident — while trying out the syntax — and the site stops
	 * accepting content with no message that explains why.
	 *
	 * @return void
	 */
	public function test_a_list_of_only_stars_blocks_nothing(): void {
		$this->ban( "*\n**" );

		$this->assertFalse( $this->blocked( 'hello everyone' ), 'A stray star line silenced the entire site.' );
	}

	/**
	 * Non-Latin scripts get whole-word matching too.
	 *
	 * The rule is only a whole-word rule if the boundary understands the alphabet
	 * in use; an ASCII-only boundary hands every non-English community the same
	 * substring bug back.
	 *
	 * @return void
	 */
	public function test_whole_word_matching_applies_to_non_latin_scripts(): void {
		$this->ban( 'спам' );

		$this->assertTrue( $this->blocked( 'это спам' ), 'A non-Latin banned word was not matched at all.' );
		$this->assertFalse( $this->blocked( 'это спамер' ), 'A non-Latin banned word matched inside a longer word.' );
	}

	/**
	 * A regex metacharacter in the list is a literal, not a pattern.
	 *
	 * Owners paste things like `c++` and `$$$`. Compiling those as regex source
	 * would either throw or match nothing.
	 *
	 * @return void
	 */
	public function test_regex_metacharacters_are_treated_literally(): void {
		$this->ban( "c++\n.*" );

		$this->assertTrue( $this->blocked( 'i code in c++' ), 'A word containing metacharacters was not matched literally.' );
		$this->assertFalse( $this->blocked( 'anything at all' ), 'A list entry was compiled as a wildcard pattern.' );
	}

	// ── The two lists together ───────────────────────────────────────────────────

	/**
	 * A space's own list is applied with the same rule as the site's.
	 *
	 * The two lists are concatenated and compiled together, so a space list that
	 * kept substring matching would reintroduce the bug for exactly the owners
	 * who curate most closely.
	 *
	 * @return void
	 */
	public function test_the_space_list_matches_whole_words_too(): void {
		$space_id = 4;

		$this->ban( '' );
		update_space_meta( $space_id, 'banned_words', 'ass' );

		$this->assertFalse(
			$this->blocked( 'a passionate discussion', $space_id ),
			"A space's banned word blocked an ordinary word containing it."
		);
		$this->assertTrue(
			$this->blocked( 'you are an ass', $space_id ),
			"A space's banned word stopped being enforced."
		);
	}

	/**
	 * An empty list allows everything.
	 *
	 * @return void
	 */
	public function test_an_empty_list_blocks_nothing(): void {
		$this->ban( '' );

		$this->assertFalse( $this->blocked( 'anything at all' ), 'Content was refused with no banned words configured.' );
	}
}
