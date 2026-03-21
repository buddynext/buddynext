<?php
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
	 * Render a labelled number input field.
	 *
	 * @param string $option_name WP option name.
	 * @param string $label       Field label.
	 * @param int    $value       Current value.
	 * @param string $hint        Optional hint text beneath the input.
	 * @param int    $min         Minimum allowed value. Default 0.
	 * @return void
	 */
	protected function render_number_row(
		string $option_name,
		string $label,
		int $value,
		string $hint = '',
		int $min = 0
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
	 * Output the shared BuddyNext admin CSS styles.
	 *
	 * A static guard prevents multiple outputs on a single page load.
	 *
	 * @return void
	 */
	protected function output_shared_styles(): void {
		static $already_output = false;
		if ( $already_output ) {
			return;
		}
		$already_output = true;
		?>
		<style id="bn-admin-styles">
		/* ── BuddyNext Admin Premium Chrome ───────────────────────── */
		.bn-admin-wrap { max-width: 1100px; }
		.bn-admin-header { margin-bottom: 24px; }
		.bn-admin-title { font-size: 22px !important; font-weight: 700 !important; color: #111827 !important; margin: 0 0 4px !important; line-height: 1.3 !important; }
		.bn-admin-sub { font-size: 13px; color: #6b7280; margin: 0; }

		/* ── Tab bar ───────────────────────────────────────────────── */
		.bn-admin-tabs { display: flex; background: #fff; border: 1px solid #e9ecef; border-radius: 8px; overflow: hidden; margin-bottom: 24px; }
		.bn-atab { padding: 10px 18px; font-size: 13px; font-weight: 500; color: #6b7280; cursor: pointer; border-right: 1px solid #e9ecef; text-decoration: none; display: inline-block; white-space: nowrap; }
		.bn-atab:last-child { border-right: none; }
		.bn-atab.active, .bn-atab.active:visited { background: #0073aa; color: #fff; font-weight: 600; }
		.bn-atab:hover:not(.active) { background: #f9fafb; color: #374151; }

		/* ── Section cards ─────────────────────────────────────────── */
		.bn-settings-section { background: #fff; border: 1px solid #e9ecef; border-radius: 8px; margin-bottom: 16px; overflow: hidden; }
		.bn-ss-header { padding: 14px 18px; border-bottom: 1px solid #f3f4f6; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
		.bn-ss-title { font-weight: 700; font-size: 14px; color: #111827; }
		.bn-ss-body { padding: 18px; }

		/* ── Fields ────────────────────────────────────────────────── */
		.bn-field { margin-bottom: 18px; }
		.bn-field:last-child { margin-bottom: 0; }
		.bn-field > label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #374151; }
		.bn-field-hint { display: block; font-size: 11px; color: #9ca3af; margin-top: 4px; }
		input.bn-text-input { border: 1px solid #ddd !important; border-radius: 4px !important; padding: 8px 10px !important; font-size: 13px !important; box-shadow: none !important; line-height: 1.4 !important; }
		input.bn-text-input:focus { border-color: #0073aa !important; box-shadow: 0 0 0 1px #0073aa !important; outline: none !important; }
		select.bn-select-input { border: 1px solid #ddd !important; border-radius: 4px !important; padding: 7px 10px !important; font-size: 13px !important; background: #fff !important; height: auto !important; min-width: 200px; line-height: 1.4 !important; }

		/* ── Toggle row ────────────────────────────────────────────── */
		.bn-toggle-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 12px 0; border-bottom: 1px solid #f9fafb; }
		.bn-toggle-row:last-child { border-bottom: none; }
		.bn-tl-label { flex: 1; }
		.bn-tl-title { display: block; font-weight: 600; font-size: 13px; color: #374151; cursor: pointer; }
		.bn-tl-desc { display: block; font-size: 11px; color: #6b7280; margin-top: 2px; }
		.bn-toggle { width: 40px; height: 22px; background: #e9ecef; border-radius: 11px; position: relative; cursor: pointer; flex-shrink: 0; display: inline-block; transition: background .15s; }
		.bn-toggle.on { background: #0073aa; }
		.bn-toggle::after { content: ''; position: absolute; width: 16px; height: 16px; background: #fff; border-radius: 50%; top: 3px; left: 3px; transition: left .15s; box-shadow: 0 1px 2px rgba(0,0,0,.2); }
		.bn-toggle.on::after { left: 21px; }
		.bn-toggle .screen-reader-text { border: 0; clip: rect(1px,1px,1px,1px); -webkit-clip-path: inset(50%); clip-path: inset(50%); height: 1px; margin: -1px; overflow: hidden; padding: 0; position: absolute; width: 1px; word-wrap: normal !important; }

		/* ── Save bar ──────────────────────────────────────────────── */
		.bn-save-bar { background: #fff; border: 1px solid #e9ecef; border-radius: 8px; padding: 14px 18px; display: flex; align-items: center; justify-content: flex-end; gap: 12px; margin-top: 8px; }
		.bn-save-msg { font-size: 12px; color: #059669; font-weight: 600; margin-right: auto; }
		input.bn-btn-save.button-primary { background: #0073aa !important; border-color: #005177 !important; color: #fff !important; padding: 7px 20px !important; border-radius: 4px !important; font-size: 13px !important; font-weight: 600 !important; cursor: pointer !important; box-shadow: none !important; }
		input.bn-btn-save.button-primary:hover { background: #005f8e !important; }

		/* ── Stats cards ───────────────────────────────────────────── */
		.bn-stats-row { display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
		.bn-stat-card { background: #fff; border: 1px solid #e9ecef; border-radius: 8px; padding: 16px 20px; flex: 1; min-width: 140px; }
		.bn-stat-val { font-size: 26px; font-weight: 700; color: #111827; line-height: 1; margin-bottom: 4px; }
		.bn-stat-label { font-size: 12px; color: #6b7280; }

		/* ── Data table ────────────────────────────────────────────── */
		.bn-data-table { background: #fff; border: 1px solid #e9ecef; border-radius: 8px; overflow: hidden; margin-bottom: 16px; }
		.bn-table-header { padding: 14px 18px; border-bottom: 1px solid #f3f4f6; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
		input.bn-table-search { border: 1px solid #ddd !important; border-radius: 4px !important; padding: 7px 10px !important; font-size: 13px !important; width: 240px !important; box-shadow: none !important; }
		input.bn-table-search:focus { border-color: #0073aa !important; box-shadow: 0 0 0 1px #0073aa !important; outline: none !important; }
		table.bn-table { width: 100%; border-collapse: collapse; }
		table.bn-table th { padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; border-bottom: 1px solid #f3f4f6; background: #f9fafb; }
		table.bn-table td { padding: 12px 16px; font-size: 13px; color: #374151; border-bottom: 1px solid #f9fafb; vertical-align: middle; }
		table.bn-table tr:last-child td { border-bottom: none; }
		table.bn-table tr:hover td { background: #fafafa; }

		/* ── Badges ────────────────────────────────────────────────── */
		.bn-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; }
		.bn-badge-active    { background: #d1fae5; color: #065f46; }
		.bn-badge-suspended { background: #fee2e2; color: #991b1b; }
		.bn-badge-open      { background: #dbeafe; color: #1e40af; }
		.bn-badge-private   { background: #f3f4f6; color: #374151; }
		.bn-badge-secret    { background: #fef3c7; color: #92400e; }

		/* ── Integration cards ─────────────────────────────────────── */
		.bn-addon-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px; }
		.bn-addon-card { border: 1px solid #e9ecef; border-radius: 8px; padding: 16px; display: flex; align-items: flex-start; gap: 12px; background: #fff; }
		.bn-addon-icon { font-size: 24px; flex-shrink: 0; }
		.bn-addon-info { flex: 1; }
		.bn-addon-name { font-weight: 700; font-size: 13px; margin-bottom: 4px; color: #111827; }
		.bn-addon-desc { font-size: 12px; color: #6b7280; line-height: 1.5; }
		.bn-addon-status { padding: 3px 10px; border-radius: 12px; font-size: 10px; font-weight: 700; flex-shrink: 0; }
		.bn-status-active   { background: #d1fae5; color: #065f46; }
		.bn-status-inactive { background: #f3f4f6; color: #9ca3af; }

		/* ── Nav manager ───────────────────────────────────────────── */
		.bn-nav-row { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-bottom: 1px solid #f6f7f7; }
		.bn-nav-row:last-child { border-bottom: none; }
		.bn-drag-handle { display: flex; flex-direction: column; gap: 3px; padding: 4px 2px; flex-shrink: 0; cursor: grab; opacity: .5; }
		.bn-drag-handle span { display: block; width: 18px; height: 2px; background: #c3c4c7; border-radius: 1px; }
		.bn-row-info { flex: 1; }
		.bn-row-name { font-weight: 600; font-size: 13px; color: #1d2327; }
		.bn-row-desc { font-size: 11px; color: #787c82; margin-top: 2px; }
		.bn-row-badge { display: inline-block; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 10px; margin-left: 6px; background: #0073aa; color: #fff; vertical-align: middle; }

		/* ── Action buttons ────────────────────────────────────────── */
		.bn-btn { display: inline-block; padding: 5px 12px; border-radius: 4px; font-size: 12px; font-weight: 500; cursor: pointer; text-decoration: none; border: 1px solid #ddd; background: #fff; color: #374151; }
		a.bn-btn-danger, button.bn-btn-danger { border-color: #fca5a5 !important; color: #dc2626 !important; background: #fff; }
		a.bn-btn-danger:hover, button.bn-btn-danger:hover { background: #fee2e2; border-color: #dc2626 !important; }
		.bn-toolbar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

		/* ── Pagination ────────────────────────────────────────────── */
		.bn-pagination { display: flex; align-items: center; gap: 4px; padding: 14px 16px; border-top: 1px solid #f3f4f6; justify-content: center; }
		.bn-page-link { padding: 5px 10px; border: 1px solid #e9ecef; border-radius: 4px; font-size: 12px; color: #374151; text-decoration: none; }
		.bn-page-link.current { background: #0073aa; color: #fff !important; border-color: #0073aa; }
		.bn-page-link:hover:not(.current) { background: #f3f4f6; }

		/* ── Responsive ────────────────────────────────────────────── */
		@media (max-width: 782px) {
			.bn-admin-tabs { flex-wrap: wrap; }
			.bn-atab { border-right: none; border-bottom: 1px solid #e9ecef; flex: 1 1 auto; text-align: center; }
			.bn-stats-row { flex-direction: column; }
			.bn-addon-grid { grid-template-columns: 1fr; }
			.bn-table-header { flex-direction: column; align-items: flex-start; }
			input.bn-table-search { width: 100% !important; }
		}
		</style>
		<?php
	}
}
