<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Abstract base class for BuddyNext admin pages.
 *
 * Provides the premium chrome wrapper — header, tab bar, section cards,
 * toggle rows, text/select fields, save bar — shared across all BuddyNext
 * admin submenu pages.
 *
 * Subclasses must implement:
 *   - get_title()    — page heading shown in the admin header
 *   - get_subtitle() — short descriptor beneath the heading
 *   - render_content() — the page body (tabs, forms, tables, etc.)
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

/**
 * Premium admin page chrome for all BuddyNext submenu pages.
 */
abstract class AdminPageBase {

	// ── Abstract interface ─────────────────────────────────────────────────────

	/**
	 * Human-readable page title shown in the admin header.
	 *
	 * @return string
	 */
	abstract protected function get_title(): string;

	/**
	 * Short subtitle / description shown below the title.
	 *
	 * @return string
	 */
	abstract protected function get_subtitle(): string;

	/**
	 * Render the page body inside the premium chrome wrapper.
	 *
	 * Called by render_page() after the header is output.
	 *
	 * @return void
	 */
	abstract protected function render_content(): void;

	// ── Template method ────────────────────────────────────────────────────────

	/**
	 * Main render callback registered with add_menu_page / add_submenu_page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'buddynext' ) );
		}

		$this->output_shared_styles();
		?>
		<div class="wrap bn-admin-wrap">
			<?php $this->render_page_header(); ?>
			<?php $this->render_content(); ?>
		</div>
		<?php
	}

	// ── Chrome helpers ─────────────────────────────────────────────────────────

	/**
	 * Render the page title + subtitle header block.
	 *
	 * @return void
	 */
	protected function render_page_header(): void {
		?>
		<div class="bn-admin-header">
			<h1 class="bn-admin-title"><?php echo esc_html( $this->get_title() ); ?></h1>
			<p class="bn-admin-sub"><?php echo esc_html( $this->get_subtitle() ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render a horizontal tab bar.
	 *
	 * @param array<string, string> $tabs       Slug → label map.
	 * @param string                $active_tab Currently active tab slug.
	 * @param string                $base_url   Base URL for ?tab= links.
	 * @return void
	 */
	protected function render_tab_bar( array $tabs, string $active_tab, string $base_url ): void {
		?>
		<div class="bn-admin-tabs" role="tablist">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'tab', $slug, $base_url ) ); ?>"
					class="bn-atab<?php echo $slug === $active_tab ? ' active' : ''; ?>"
					role="tab">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Open a settings section card.
	 *
	 * Must be closed with a matching close_section() call.
	 *
	 * @param string $title       Section heading.
	 * @param string $action_html Optional raw HTML for the header action slot.
	 *                            Caller is responsible for escaping this value.
	 * @return void
	 */
	protected function open_section( string $title, string $action_html = '' ): void {
		?>
		<div class="bn-settings-section">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php echo esc_html( $title ); ?></span>
				<?php
				if ( '' !== $action_html ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $action_html;
				}
				?>
			</div>
			<div class="bn-ss-body">
		<?php
	}

	/**
	 * Close a settings section card opened by open_section().
	 *
	 * @return void
	 */
	protected function close_section(): void {
		?>
			</div><!-- .bn-ss-body -->
		</div><!-- .bn-settings-section -->
		<?php
	}

	/**
	 * Render a labelled toggle-switch row inside a section card.
	 *
	 * The underlying input is a checkbox with screen-reader-text class so it
	 * is accessible without being visually distracting.
	 *
	 * @param string $option_name WP option name (input name + id basis).
	 * @param string $title       Toggle label.
	 * @param string $desc        Short descriptor beneath the label.
	 * @param bool   $value       Current checked state.
	 * @return void
	 */
	protected function render_toggle_row(
		string $option_name,
		string $title,
		string $desc,
		bool $value
	): void {
		$input_id = 'bn-toggle-' . sanitize_key( $option_name );
		?>
		<div class="bn-toggle-row">
			<div class="bn-tl-label">
				<label for="<?php echo esc_attr( $input_id ); ?>" class="bn-tl-title">
					<?php echo esc_html( $title ); ?>
				</label>
				<span class="bn-tl-desc"><?php echo esc_html( $desc ); ?></span>
			</div>
			<label class="bn-toggle<?php echo $value ? ' on' : ''; ?>">
				<input type="checkbox"
						id="<?php echo esc_attr( $input_id ); ?>"
						name="<?php echo esc_attr( $option_name ); ?>"
						value="1"
						class="screen-reader-text"
						<?php checked( $value ); ?>>
			</label>
		</div>
		<?php
	}

	/**
	 * Render a labelled text input field.
	 *
	 * @param string $option_name WP option name.
	 * @param string $label       Field label.
	 * @param string $value       Current value.
	 * @param string $hint        Optional hint text beneath the input.
	 * @param int    $max_width   Input max-width in px. Default 400.
	 * @return void
	 */
	protected function render_text_row(
		string $option_name,
		string $label,
		string $value,
		string $hint = '',
		int $max_width = 400
	): void {
		$input_id = 'bn-field-' . sanitize_key( $option_name );
		?>
		<div class="bn-field">
			<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="text"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $option_name ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					class="bn-text-input regular-text"
					style="max-width:<?php echo absint( $max_width ); ?>px">
			<?php if ( '' !== $hint ) : ?>
				<span class="bn-field-hint"><?php echo esc_html( $hint ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a labelled textarea field.
	 *
	 * @param string $option_name WP option name.
	 * @param string $label       Field label.
	 * @param string $value       Current value.
	 * @param string $hint        Optional hint text beneath the textarea.
	 * @param int    $rows        Visible row count. Default 4.
	 * @param int    $max_width   Textarea max-width in px. Default 540.
	 * @return void
	 */
	protected function render_textarea_row(
		string $option_name,
		string $label,
		string $value,
		string $hint = '',
		int $rows = 4,
		int $max_width = 540
	): void {
		$input_id = 'bn-field-' . sanitize_key( $option_name );
		?>
		<div class="bn-field">
			<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $label ); ?></label>
			<textarea
				id="<?php echo esc_attr( $input_id ); ?>"
				name="<?php echo esc_attr( $option_name ); ?>"
				rows="<?php echo absint( $rows ); ?>"
				class="bn-text-input"
				style="width:100%;max-width:<?php echo absint( $max_width ); ?>px;resize:vertical"
			><?php echo esc_textarea( $value ); ?></textarea>
			<?php if ( '' !== $hint ) : ?>
				<span class="bn-field-hint"><?php echo esc_html( $hint ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a labelled number input field.
	 *
	 * @param string   $option_name WP option name.
	 * @param string   $label       Field label.
	 * @param int      $value       Current value.
	 * @param string   $hint        Optional hint text beneath the input.
	 * @param int      $min         Minimum allowed value. Default 0.
	 * @param int|null $max         Maximum allowed value. Null means no maximum.
	 * @return void
	 */
	protected function render_number_row(
		string $option_name,
		string $label,
		int $value,
		string $hint = '',
		int $min = 0,
		?int $max = null
	): void {
		$input_id = 'bn-field-' . sanitize_key( $option_name );
		?>
		<div class="bn-field">
			<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="number"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $option_name ); ?>"
					value="<?php echo absint( $value ); ?>"
					min="<?php echo absint( $min ); ?>"
					<?php if ( null !== $max ) : ?>
					max="<?php echo absint( $max ); ?>"
					<?php endif; ?>
					class="bn-text-input small-text"
					style="max-width:100px">
			<?php if ( '' !== $hint ) : ?>
				<span class="bn-field-hint"><?php echo esc_html( $hint ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a labelled select input field.
	 *
	 * @param string               $option_name WP option name.
	 * @param string               $label       Field label.
	 * @param string               $value       Currently selected value.
	 * @param array<string,string> $options     Value → label map.
	 * @param string               $hint        Optional hint text beneath the select.
	 * @return void
	 */
	protected function render_select_row(
		string $option_name,
		string $label,
		string $value,
		array $options,
		string $hint = ''
	): void {
		$input_id = 'bn-select-' . sanitize_key( $option_name );
		?>
		<div class="bn-field">
			<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $label ); ?></label>
			<select id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $option_name ); ?>"
					class="bn-select-input">
				<?php foreach ( $options as $opt_value => $opt_label ) : ?>
					<option value="<?php echo esc_attr( $opt_value ); ?>" <?php selected( $value, $opt_value ); ?>>
						<?php echo esc_html( $opt_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php if ( '' !== $hint ) : ?>
				<span class="bn-field-hint"><?php echo esc_html( $hint ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the sticky save bar at the bottom of a settings form.
	 *
	 * Must be called inside a <form> element.
	 *
	 * @param string $button_label Submit button label. Defaults to "Save Settings".
	 * @return void
	 */
	protected function render_save_bar( string $button_label = '' ): void {
		if ( '' === $button_label ) {
			$button_label = __( 'Save Settings', 'buddynext' );
		}
		?>
		<div class="bn-save-bar">
			<span class="bn-save-msg" id="bn-save-msg" aria-live="polite"></span>
			<?php submit_button( $button_label, 'primary bn-btn-save', 'submit', false ); ?>
		</div>
		<?php
	}

	// ── Shared CSS ─────────────────────────────────────────────────────────────

	/**
	 * No-op: admin styles are now loaded via bn-admin.css (AssetService::enqueue_admin_assets).
	 *
	 * Kept to avoid breaking any subclass that calls parent::output_shared_styles().
	 *
	 * @return void
	 */
	protected function output_shared_styles(): void {
		// Styles are enqueued via AssetService on admin_enqueue_scripts.
		// This method is intentionally empty.
	}
}

