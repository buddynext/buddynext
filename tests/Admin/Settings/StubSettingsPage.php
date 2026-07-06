<?php
/**
 * Test double implementing ProvidesSettings for registry/driver tests.
 *
 * @package BuddyNext\Tests\Admin\Settings
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin\Settings;

use BuddyNext\Admin\Settings\Section;
use BuddyNext\Contracts\ProvidesSettings;

/**
 * A settings page whose sections are supplied at construction.
 */
final class StubSettingsPage implements ProvidesSettings {

	/**
	 * Sections this stub returns.
	 *
	 * @var Section[]
	 */
	private array $sections;

	/**
	 * AdminHub section key this stub reports.
	 *
	 * @var string
	 */
	private string $page_section;

	/**
	 * Build the stub from its sections and reported page section.
	 *
	 * @param Section[] $sections     Sections to return from settings_fields().
	 * @param string    $page_section AdminHub section key.
	 */
	public function __construct( array $sections, string $page_section = 'members' ) {
		$this->sections     = $sections;
		$this->page_section = $page_section;
	}

	/**
	 * Return the configured sections.
	 *
	 * @return Section[]
	 */
	public function settings_fields(): array {
		return $this->sections;
	}

	/**
	 * Return the configured AdminHub section key.
	 *
	 * @return string
	 */
	public function settings_page_section(): string {
		return $this->page_section;
	}
}
