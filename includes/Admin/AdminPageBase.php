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

		// AdminHub already opens a `.wrap.bn-admin-hub` and renders the
		// section H1 + tab strip before calling this method. We only emit
		// the body content; the subtitle hangs under the Hub's H1.
		$this->render_page_header();
		$this->render_content();
	}

	// ── Chrome helpers ─────────────────────────────────────────────────────────

	/**
	 * Render the page title + subtitle header block.
	 *
	 * @return void
	 */
	protected function render_page_header(): void {
		// Hub paints the section H1. We only emit the subtitle so the page
		// still gets its one-line context. Empty subtitle = nothing rendered.
		$sub = $this->get_subtitle();
		if ( '' === $sub ) {
			return;
		}
		?>
		<p class="bn-admin-hub__subtitle"><?php echo esc_html( $sub ); ?></p>
		<?php
	}

	/**
	 * Render a horizontal tab bar.
	 *
	 * Each tab carries the full WAI-ARIA tab semantics: a stable `id`
	 * (`bn-tab-{slug}`), `aria-selected` reflecting the active state, and
	 * `aria-controls` pointing at its panel (`bn-panel-{slug}`). The matching
	 * panel wrapper is emitted by open_tab_panel() / close_tab_panel() around
	 * the active tab's content so screen readers announce the active tab and
	 * its associated region. Tabs are links (full page reload), so no JS
	 * roving-tabindex is needed.
	 *
	 * @param array<string, string> $tabs       Slug → label map.
	 * @param string                $active_tab Currently active tab slug.
	 * @param string                $base_url   Base URL for ?tab= links.
	 * @return void
	 */
	protected function render_tab_bar( array $tabs, string $active_tab, string $base_url ): void {
		?>
		<div class="bn-admin-tabs" role="tablist">
			<?php
			foreach ( $tabs as $slug => $label ) :
				$is_active = ( $slug === $active_tab );
				?>
				<a href="<?php echo esc_url( add_query_arg( 'tab', $slug, $base_url ) ); ?>"
					class="bn-atab<?php echo $is_active ? ' active' : ''; ?>"
					id="bn-tab-<?php echo esc_attr( $slug ); ?>"
					role="tab"
					aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
					aria-controls="bn-panel-<?php echo esc_attr( $slug ); ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Open the active tab's content panel.
	 *
	 * Wraps the active tab's body in a `role="tabpanel"` region linked back to
	 * its tab via `aria-labelledby="bn-tab-{slug}"`. `tabindex="0"` makes the
	 * panel keyboard-focusable per the WAI-ARIA tabs pattern. Must be closed
	 * with a matching close_tab_panel() call.
	 *
	 * @param string $active_tab Currently active tab slug.
	 * @return void
	 */
	protected function open_tab_panel( string $active_tab ): void {
		?>
		<div class="bn-admin-tabpanel"
			id="bn-panel-<?php echo esc_attr( $active_tab ); ?>"
			role="tabpanel"
			aria-labelledby="bn-tab-<?php echo esc_attr( $active_tab ); ?>"
			tabindex="0">
		<?php
	}

	/**
	 * Close the active tab's content panel opened by open_tab_panel().
	 *
	 * @return void
	 */
	protected function close_tab_panel(): void {
		?>
		</div><!-- .bn-admin-tabpanel -->
		<?php
	}

	/**
	 * Open a settings section card.
	 *
	 * Must be closed with a matching close_section() call.
	 *
	 * Passing an empty title (and no action HTML) suppresses the whole
	 * `.bn-ss-header` bar — the S5 standard for single-section tabs whose
	 * card title would only repeat the page H1 (e.g. Features).
	 *
	 * The optional `$id` puts an anchor on the card itself. Sections were only
	 * ever anchorable at FIELD level (render_sections() ids each `.bn-opt` for
	 * the command palette), so a screen that wanted to link to a whole card —
	 * "Add Plan", "Default plan for new members" — had nowhere to hang the
	 * target. Membership solved that by forking this method with an `$id` in
	 * the second position, which is exactly the kind of near-identical copy that
	 * then drifts. Third position, so no existing caller changes meaning.
	 *
	 * @param string $title       Section heading. Empty string suppresses the header bar.
	 * @param string $action_html Optional raw HTML for the header action slot.
	 *                            Caller is responsible for escaping this value.
	 * @param string $id          Optional anchor id for the section wrapper.
	 * @return void
	 */
	protected function open_section( string $title, string $action_html = '', string $id = '' ): void {
		?>
		<div class="bn-settings-section"<?php echo '' !== $id ? ' id="' . esc_attr( $id ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr() applied inline. ?>>
			<?php if ( '' !== $title || '' !== $action_html ) : ?>
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php echo esc_html( $title ); ?></span>
				<?php
				if ( '' !== $action_html ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $action_html;
				}
				?>
			</div>
			<?php endif; ?>
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
	 * Render ordered sections of option fields from descriptors.
	 *
	 * Each Section becomes an open_section() card; each Field dispatches to the
	 * matching render_*_row() helper and is wrapped in a stable anchor id so the
	 * command palette can deep-link and scroll-highlight the exact control. This
	 * is the single render path for descriptor-declared options — page classes
	 * declare fields via ProvidesSettings::settings_fields() and never call the
	 * row helpers by hand.
	 *
	 * @param \BuddyNext\Admin\Settings\Section[] $sections Ordered sections.
	 * @return void
	 */
	protected function render_sections( array $sections ): void {
		foreach ( $sections as $section ) {
			$this->open_section( $section->title );
			foreach ( $section->fields as $field ) {
				if ( 'custom' === $field->type && null !== $field->render_callback ) {
					printf( '<div id="%s" class="bn-opt">', esc_attr( $field->anchor() ) );
					call_user_func( $field->render_callback );
					echo '</div>';
					continue;
				}
				printf( '<div id="%s" class="bn-opt">', esc_attr( $field->anchor() ) );
				$value = $field->display_value();
				$hint  = $field->hint();
				switch ( $field->type ) {
					case 'toggle':
						$this->render_toggle_row( $field->key, $field->label, $hint, (bool) $value, $field->disabled() );
						break;
					case 'number':
						$this->render_number_row( $field->key, $field->label, (int) $value, $hint, (int) ( $field->min ?? 0 ), $field->max );
						break;
					case 'optional_limit':
						$this->render_optional_limit_row(
							$field->key,
							$field->label,
							(int) $value,
							$field->toggle_label,
							$hint,
							max( 1, (int) ( $field->min ?? 1 ) )
						);
						break;
					case 'select':
						$this->render_select_row( $field->key, $field->label, (string) $value, $field->choices(), $hint );
						break;
					case 'textarea':
						$this->render_textarea_row( $field->key, $field->label, (string) $value, $hint );
						break;
					case 'color':
						$this->render_color_row( $field->key, $field->label, (string) $value, $hint );
						break;
					case 'media':
						self::render_media_row( $field->key, $field->label, (string) $value, $hint );
						break;
					case 'password':
					case 'secret':
						$this->render_password_row( $field->key, $field->label, (string) $value, $hint );
						break;
					default: // text, email, url, readonly.
						$this->render_text_row( $field->key, $field->label, (string) $value, $hint );
				}
				echo '</div>';
			}
			$this->close_section();
		}
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
	 * @param bool   $disabled    Render read-only (e.g. a required plugin is inactive); the option is left untouched on save.
	 * @return void
	 */
	protected function render_toggle_row(
		string $option_name,
		string $title,
		string $desc,
		bool $value,
		bool $disabled = false
	): void {
		$input_id = 'bn-toggle-' . sanitize_key( $option_name );
		?>
		<div class="bn-toggle-row<?php echo $disabled ? ' is-disabled' : ''; ?>">
			<div class="bn-tl-label">
				<label for="<?php echo esc_attr( $input_id ); ?>" class="bn-tl-title">
					<?php echo esc_html( $title ); ?>
				</label>
				<span class="bn-tl-desc"><?php echo esc_html( $desc ); ?></span>
			</div>
			<label class="bn-toggle">
				<?php // The active (ON) state is driven purely by the nested checkbox via CSS `.bn-toggle:has(:checked)` — no hardcoded `.on` class, which previously stuck the visual ON after unchecking because nothing removed it client-side. ?>
				<?php if ( $disabled ) : ?>
					<?php // Disabled (e.g. a required plugin is inactive): render read-only and emit NO hidden 0, so saving the form leaves the stored option untouched rather than silently flipping it off. ?>
					<input type="checkbox"
							id="<?php echo esc_attr( $input_id ); ?>"
							value="1"
							class="screen-reader-text"
							disabled
							<?php checked( $value ); ?>>
				<?php else : ?>
					<?php // Hidden 0 before the checkbox: an unchecked checkbox is never POSTed, so without this the Settings API skips update_option() and the old value persists. When checked, the checkbox's "1" comes later in the body and wins. ?>
					<input type="hidden" name="<?php echo esc_attr( $option_name ); ?>" value="0">
					<input type="checkbox"
							id="<?php echo esc_attr( $input_id ); ?>"
							name="<?php echo esc_attr( $option_name ); ?>"
							value="1"
							class="screen-reader-text"
							<?php checked( $value ); ?>>
				<?php endif; ?>
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
	 * Render a labelled masked input field for secrets (API keys, tokens).
	 *
	 * Identical to render_text_row() but emits type="password" so the value is
	 * masked on screen and never rendered in clear text — the right control for
	 * any credential (e.g. a Stripe secret key). autocomplete is disabled so the
	 * browser's password manager does not offer to store or autofill it.
	 *
	 * @param string $option_name WP option name.
	 * @param string $label       Field label.
	 * @param string $value       Current value.
	 * @param string $hint        Optional hint text beneath the input.
	 * @param int    $max_width   Input max-width in px. Default 400.
	 * @return void
	 */
	protected function render_password_row(
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
			<input type="password"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $option_name ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					class="bn-text-input regular-text"
					autocomplete="off"
					spellcheck="false"
					style="max-width:<?php echo absint( $max_width ); ?>px">
			<?php if ( '' !== $hint ) : ?>
				<span class="bn-field-hint"><?php echo esc_html( $hint ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a colour field — a native swatch picker paired with a hex text
	 * input, kept in sync by assets/js/admin/settings.js. The text input is
	 * canonical (it carries the option name and is what posts/saves); the
	 * swatch is just the picker. Self-descriptive so owners know exactly what
	 * the colour drives.
	 *
	 * @param string $option_name WP option name.
	 * @param string $label       Field label.
	 * @param string $value       Current hex value (e.g. `Appearance::DEFAULT_BRAND`).
	 * @param string $hint        Optional hint text beneath the field.
	 * @return void
	 */
	protected function render_color_row(
		string $option_name,
		string $label,
		string $value,
		string $hint = ''
	): void {
		$input_id = 'bn-field-' . sanitize_key( $option_name );
		$value    = '' !== $value ? $value : \BuddyNext\Theme\Appearance::DEFAULT_BRAND;
		?>
		<div class="bn-field bn-color-field">
			<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $label ); ?></label>
			<div class="bn-color-row">
				<input type="color"
						class="bn-color-swatch"
						value="<?php echo esc_attr( $value ); ?>"
						data-bn-color-for="<?php echo esc_attr( $input_id ); ?>"
						aria-label="<?php /* translators: %s: field label. */ echo esc_attr( sprintf( __( '%s picker', 'buddynext' ), $label ) ); ?>">
				<input type="text"
						id="<?php echo esc_attr( $input_id ); ?>"
						name="<?php echo esc_attr( $option_name ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
						class="bn-text-input bn-color-hex"
						maxlength="7"
						pattern="#?[0-9a-fA-F]{6}"
						spellcheck="false">
			</div>
			<?php if ( '' !== $hint ) : ?>
				<span class="bn-field-hint"><?php echo esc_html( $hint ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a media-library picker row for a single image (e.g. a logo).
	 *
	 * Emits a thumbnail preview, a Select button that opens the WordPress
	 * media library, a Remove button, and a URL input that carries the posted
	 * value. The buttons are wired by assets/js/admin/media-picker.js (handle
	 * 'bn-admin-media'); the screen that renders this row must enqueue that
	 * handle plus wp_enqueue_media(). Without JS the URL input still posts, so
	 * the row degrades gracefully — owners can paste an image URL directly.
	 *
	 * Stores a plain URL string, back-compatible with previously saved upload
	 * URLs. Public static so screens outside this base class (Settings →
	 * Appearance, Pro White-label) share the exact same pattern.
	 *
	 * @param string $option_name  WP option name (input name + id basis).
	 * @param string $label        Field label.
	 * @param string $value        Current image URL ('' = none).
	 * @param string $hint         Optional hint text beneath the field.
	 * @param string $select_label Select-button label. Defaults to "Select image".
	 * @return void
	 */
	public static function render_media_row(
		string $option_name,
		string $label,
		string $value,
		string $hint = '',
		string $select_label = ''
	): void {
		if ( '' === $select_label ) {
			$select_label = __( 'Select image', 'buddynext' );
		}
		$input_id = 'bn-field-' . sanitize_key( $option_name );
		$has_img  = '' !== $value;
		?>
		<div class="bn-field bn-media-field" data-bn-media-field>
			<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $label ); ?></label>
			<div class="bn-media-preview" data-bn-media-preview <?php echo $has_img ? '' : 'hidden'; ?>>
				<img src="<?php echo esc_url( $value ); ?>" alt="" class="bn-a-logo-preview">
			</div>
			<div class="bn-media-controls">
				<button type="button"
						class="bn-btn"
						data-variant="secondary"
						data-bn-media-select
						data-title="<?php echo esc_attr( $select_label ); ?>">
					<?php echo esc_html( $select_label ); ?>
				</button>
				<button type="button"
						class="bn-btn"
						data-variant="ghost"
						data-bn-media-remove
						<?php echo $has_img ? '' : 'hidden'; ?>>
					<?php esc_html_e( 'Remove', 'buddynext' ); ?>
				</button>
				<input type="url"
						id="<?php echo esc_attr( $input_id ); ?>"
						name="<?php echo esc_attr( $option_name ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
						class="bn-text-input bn-media-url"
						placeholder="https://"
						spellcheck="false">
			</div>
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
					class="bn-text-input small-text bn-a-input-tiny">
			<?php if ( '' !== $hint ) : ?>
				<span class="bn-field-hint"><?php echo esc_html( $hint ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a branded admin notice.
	 *
	 * Admin screens hand-rolled `<div class="notice notice-success"><p>…</p></div>`
	 * in 106 places across the two plugins. That is WordPress's own notice, not
	 * BuddyNext's: core grey-and-blue chrome sitting inside a BuddyNext card,
	 * beside BuddyNext's own tokens, so the same "saved" message looked like two
	 * different products depending on which screen said it. The `.bn-notice`
	 * styles and their dismiss/announce behaviour already existed — what was
	 * missing was anything for a call site to CALL, which is why the raw markup
	 * kept being copied.
	 *
	 * Static, and public, because the screens needing it are split roughly evenly
	 * between classes that extend this base and ones that do not (ToolsTab,
	 * EmailEditor, AvatarSettings, MemberEditForm…). A protected instance method
	 * would have been reachable from half the call sites it exists for, which is
	 * how the raw markup would have survived in the other half.
	 *
	 * `bn-admin-dialogs.js` wires every `.bn-notice` on load: it sets
	 * `role="alert"` on errors and `role="status"` otherwise, injects the dismiss
	 * button, and auto-dismisses after 12s with a hover/focus pause. So there is
	 * no dismissible variant to opt into — every notice rendered here is
	 * dismissible and announced, which is what core's `is-dismissible` was being
	 * used to ask for.
	 *
	 * @param string $message Notice text. Escaped unless $allow_links is true.
	 * @param string $tone    One of success|error|warning|info. Unknown tones
	 *                        fall back to info rather than rendering untoned.
	 * @param bool   $allow_links Permit inline links/emphasis in $message, run
	 *                            through wp_kses with a small allowlist. Use for
	 *                            a message that points at another screen.
	 * @return void
	 */
	public static function render_notice( string $message, string $tone = 'info', bool $allow_links = false ): void {
		if ( '' === trim( $message ) ) {
			return;
		}

		if ( ! in_array( $tone, array( 'success', 'error', 'warning', 'info' ), true ) ) {
			$tone = 'info';
		}

		$body = $allow_links
			? wp_kses(
				$message,
				array(
					'a'      => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
					'strong' => array(),
					'em'     => array(),
					'code'   => array(),
					'br'     => array(),
				)
			)
			: esc_html( $message );

		printf(
			'<div class="bn-notice bn-notice-%1$s">%2$s</div>',
			esc_attr( $tone ),
			$body // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html() or wp_kses() applied above.
		);
	}

	/**
	 * Render a limit that can be switched off, instead of a magic zero.
	 *
	 * Several settings across both plugins store "no limit" as `0` and explain it
	 * in a hint — "0 = unlimited", "Set to 0 to disable rate limiting". The hint
	 * is the tell: a control that needs a footnote to be read is not readable.
	 * `0` in a field labelled "Posts per minute" states, in the only language the
	 * control has, that no posts are allowed — the opposite of what it does. An
	 * owner who skims sets 0 meaning "off" and is right by accident, or reads it
	 * as a hard stop and never touches it.
	 *
	 * So the sentinel becomes a checkbox and the number means only what it says.
	 * `0` stays the STORED value, so nothing migrates and every reader downstream
	 * keeps working — this is presentation, not schema.
	 *
	 * Deliberately no JavaScript. The checkbox is authoritative on save, so the
	 * pair behaves correctly with scripts blocked or broken, and the number keeps
	 * its value while switched off — unchecking and rechecking does not wipe what
	 * the owner typed. Callers read it back with read_optional_limit().
	 *
	 * @param string $option_name  Field name (also the checkbox's basis: `<name>_limited`).
	 * @param string $label        Field label.
	 * @param int    $value        Current stored value; 0 means no limit.
	 * @param string $toggle_label Checkbox label, e.g. "Limit how many times it can be used".
	 * @param string $hint         Optional hint beneath the pair.
	 * @param int    $min          Minimum when a limit IS set. Default 1 — a limit of
	 *                             zero is the state the checkbox now expresses.
	 * @return void
	 */
	protected function render_optional_limit_row(
		string $option_name,
		string $label,
		int $value,
		string $toggle_label,
		string $hint = '',
		int $min = 1
	): void {
		$input_id  = 'bn-field-' . sanitize_key( $option_name );
		$toggle_id = $input_id . '-limited';
		$limited   = $value > 0;
		?>
		<div class="bn-field bn-field--optional-limit">
			<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $label ); ?></label>
			<label class="bn-check-inline" for="<?php echo esc_attr( $toggle_id ); ?>">
				<input type="checkbox"
						id="<?php echo esc_attr( $toggle_id ); ?>"
						name="<?php echo esc_attr( $option_name ); ?>_limited"
						value="1"
						<?php checked( $limited ); ?>>
				<span><?php echo esc_html( $toggle_label ); ?></span>
			</label>
			<input type="number"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $option_name ); ?>"
					value="<?php echo esc_attr( (string) ( $limited ? $value : $min ) ); ?>"
					min="<?php echo absint( $min ); ?>"
					class="bn-text-input small-text bn-a-input-tiny">
			<?php if ( '' !== $hint ) : ?>
				<span class="bn-field-hint"><?php echo esc_html( $hint ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Read back a value written by render_optional_limit_row().
	 *
	 * The checkbox decides, not the number: unchecked stores 0 whatever is in the
	 * box, which is what lets the number keep its value while the limit is off.
	 *
	 * Lives beside the renderer on purpose. The two halves of a sentinel have to
	 * agree, and the way this goes wrong is one screen reading the number and
	 * ignoring the checkbox — which silently reinstates the limit an owner just
	 * switched off.
	 *
	 * Callers are responsible for nonce and capability checks before calling.
	 *
	 * @param array<string,mixed> $source      Request array, already unslashed by the caller.
	 * @param string              $option_name Field name used in render_optional_limit_row().
	 * @param int                 $min         Minimum when a limit is set. Default 1.
	 * @return int Stored value: 0 for "no limit", otherwise at least $min.
	 */
	public static function read_optional_limit( array $source, string $option_name, int $min = 1 ): int {
		if ( empty( $source[ $option_name . '_limited' ] ) ) {
			return 0;
		}

		return max( $min, (int) ( $source[ $option_name ] ?? $min ) );
	}

	/**
	 * Render a labelled select input field.
	 *
	 * @param string                   $option_name WP option name.
	 * @param string                   $label       Field label.
	 * @param string                   $value       Currently selected value.
	 * @param array<int|string,string> $options     Value → label map. Integer keys
	 *                                              are valid (e.g. page-ID pickers);
	 *                                              PHP casts numeric string keys to int.
	 * @param string                   $hint        Optional hint text beneath the select.
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
			<button type="submit" class="bn-btn" data-variant="primary"><?php echo esc_html( $button_label ); ?></button>
		</div>
		<?php
	}

	// ── Pagination ───────────────────────────────────────────────────────────

	/**
	 * Render a windowed paginator: a "Showing X-Y of N" summary plus Prev /
	 * first / window(current +/-2) / last / Next links. Replaces the naive
	 * "print every page number" loop so a list with thousands of rows shows a
	 * handful of links instead of hundreds — the big-site readiness baseline.
	 *
	 * The caller supplies a URL builder so each list keeps its own query args
	 * (search, status, type, role, etc.).
	 *
	 * @param int      $current     Current page (1-based).
	 * @param int      $total_pages Total number of pages.
	 * @param int      $total_items Total row count (for the summary).
	 * @param int      $per_page    Rows per page (for the summary).
	 * @param callable $url_for     fn( int $page ): string — page URL.
	 * @param string   $label       Accessible nav label.
	 * @return void
	 */
	public static function render_pagination(
		int $current,
		int $total_pages,
		int $total_items,
		int $per_page,
		callable $url_for,
		string $label = ''
	): void {
		if ( '' === $label ) {
			$label = __( 'Pagination', 'buddynext' );
		}

		$current = max( 1, min( $current, max( 1, $total_pages ) ) );

		// Always show the range summary, even on a single page — it answers
		// "how many are there?" at a glance.
		$first_row = $total_items > 0 ? ( ( $current - 1 ) * $per_page ) + 1 : 0;
		$last_row  = min( $current * $per_page, $total_items );
		?>
		<div class="bn-pagination-bar">
			<span class="bn-pagination-summary">
				<?php
				printf(
					/* translators: 1: first row number, 2: last row number, 3: total rows. */
					esc_html__( 'Showing %1$s-%2$s of %3$s', 'buddynext' ),
					esc_html( number_format_i18n( $first_row ) ),
					esc_html( number_format_i18n( $last_row ) ),
					esc_html( number_format_i18n( $total_items ) )
				);
				?>
			</span>

			<?php if ( $total_pages > 1 ) : ?>
				<nav class="bn-pagination" aria-label="<?php echo esc_attr( $label ); ?>">
					<?php
					// Build a compact page window: 1 … (cur-2 … cur+2) … last.
					$window  = 2;
					$pages   = array();
					$pages[] = 1;
					for ( $p = $current - $window; $p <= $current + $window; $p++ ) {
						if ( $p > 1 && $p < $total_pages ) {
							$pages[] = $p;
						}
					}
					if ( $total_pages > 1 ) {
						$pages[] = $total_pages;
					}
					$pages = array_values( array_unique( $pages ) );

					// Prev.
					if ( $current > 1 ) :
						?>
						<a href="<?php echo esc_url( $url_for( $current - 1 ) ); ?>"
							class="bn-page-link bn-page-link--nav" rel="prev">
							<?php esc_html_e( 'Prev', 'buddynext' ); ?>
						</a>
						<?php
					else :
						?>
						<span class="bn-page-link bn-page-link--nav is-disabled" aria-disabled="true"><?php esc_html_e( 'Prev', 'buddynext' ); ?></span>
						<?php
					endif;

					$prev_page = 0;
					foreach ( $pages as $p ) :
						if ( $prev_page && $p - $prev_page > 1 ) :
							?>
							<span class="bn-page-ellipsis" aria-hidden="true">&hellip;</span>
							<?php
						endif;
						?>
						<a href="<?php echo esc_url( $url_for( $p ) ); ?>"
							class="bn-page-link<?php echo $p === $current ? ' current' : ''; ?>"
							<?php echo $p === $current ? 'aria-current="page"' : ''; ?>>
							<?php echo esc_html( number_format_i18n( $p ) ); ?>
						</a>
						<?php
						$prev_page = $p;
					endforeach;

					// Next.
					if ( $current < $total_pages ) :
						?>
						<a href="<?php echo esc_url( $url_for( $current + 1 ) ); ?>"
							class="bn-page-link bn-page-link--nav" rel="next">
							<?php esc_html_e( 'Next', 'buddynext' ); ?>
						</a>
						<?php
					else :
						?>
						<span class="bn-page-link bn-page-link--nav is-disabled" aria-disabled="true"><?php esc_html_e( 'Next', 'buddynext' ); ?></span>
						<?php
					endif;
					?>
				</nav>
			<?php endif; ?>
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

