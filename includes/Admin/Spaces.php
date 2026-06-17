<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext admin spaces panel.
 *
 * Provides a submenu page under the BuddyNext top-level menu for
 * listing, searching, and deleting community spaces.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

/**
 * Admin panel for managing BuddyNext community spaces.
 */
class Spaces extends AdminPageBase {

	/**
	 * Default items per page for the spaces listing.
	 */
	private const DEFAULT_PER_PAGE = 20;

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_bn_delete_space', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_bn_save_space_category', array( $this, 'handle_save_category' ) );
		add_action( 'admin_post_bn_delete_space_category', array( $this, 'handle_delete_category' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		AdminHub::register_tab(
			'spaces',
			'directory',
			__( 'Directory', 'buddynext' ),
			array( $this, 'render_page' ),
			array(
				'subtitle' => __( 'Manage all spaces and categories.', 'buddynext' ),
			)
		);
	}

	/**
	 * Enqueue the Spaces admin script on this page only.
	 *
	 * The matching CSS lives in assets/css/bn-admin.css and is enqueued
	 * globally for all BuddyNext admin pages by AssetService.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'buddynext_page_buddynext-spaces' !== $hook_suffix ) {
			return;
		}

		$plugin_url = defined( 'BUDDYNEXT_FILE' )
			? plugin_dir_url( (string) constant( 'BUDDYNEXT_FILE' ) )
			: plugins_url( '/', __DIR__ . '/../../buddynext.php' );

		$version = defined( 'BUDDYNEXT_VERSION' ) ? (string) constant( 'BUDDYNEXT_VERSION' ) : '1.0.0';

		wp_enqueue_script(
			'bn-admin-spaces',
			$plugin_url . 'assets/js/admin/spaces.js',
			array(),
			$version,
			true
		);
	}

	/**
	 * Add the Spaces submenu under the BuddyNext top-level menu.
	 *
	 * @return void
	 */
	public function add_submenu(): void {
		add_submenu_page(
			'buddynext',
			__( 'Spaces', 'buddynext' ),
			__( 'Spaces', 'buddynext' ),
			'manage_options',
			'buddynext-spaces',
			array( $this, 'render_page' )
		);
	}

	// ── AdminPageBase interface ────────────────────────────────────────────────

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function get_title(): string {
		return __( 'Community Spaces', 'buddynext' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function get_subtitle(): string {
		return __( 'Manage all spaces and categories.', 'buddynext' );
	}

	/**
	 * Suppress the in-body subtitle paragraph.
	 *
	 * AdminHub renders this screen's subtitle in the standardized sub-header
	 * bar via the `subtitle` arg passed to register_tab(), so the base class's
	 * in-body subtitle paragraph must not duplicate it.
	 *
	 * @return void
	 */
	protected function render_page_header(): void {
		// Subtitle is emitted by AdminHub's sub-header bar; nothing to render here.
	}

	/**
	 * Render the spaces admin page content.
	 *
	 * @return void
	 */
	protected function render_content(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page   = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) );
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$type   = sanitize_key( wp_unslash( $_GET['type'] ?? '' ) );
		$subtab = sanitize_key( wp_unslash( $_GET['subtab'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Subtab nav — Spaces list (default) | Categories management.
		$base_url = admin_url( 'admin.php?page=buddynext-spaces' );
		$cats_url = add_query_arg( 'subtab', 'categories', $base_url );
		$is_cats  = ( 'categories' === $subtab );
		?>
		<nav class="bn-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Spaces admin sections', 'buddynext' ); ?>" style="margin-block-end:var(--bn-s4,16px)">
			<a href="<?php echo esc_url( $base_url ); ?>" class="bn-tab" role="tab" aria-selected="<?php echo $is_cats ? 'false' : 'true'; ?>">
				<?php esc_html_e( 'Spaces', 'buddynext' ); ?>
			</a>
			<a href="<?php echo esc_url( $cats_url ); ?>" class="bn-tab" role="tab" aria-selected="<?php echo $is_cats ? 'true' : 'false'; ?>">
				<?php esc_html_e( 'Categories', 'buddynext' ); ?>
			</a>
		</nav>
		<?php

		if ( $is_cats ) {
			$this->render_categories_subtab();
			return;
		}

		$data   = $this->list_spaces(
			array(
				'page'   => $page,
				'search' => $search,
				'type'   => $type,
			)
		);
		$spaces = $data['spaces'];
		$total  = $data['total'];
		$pages  = $data['pages'];
		$counts = $this->get_type_counts();

		$base_url = admin_url( 'admin.php?page=buddynext-spaces' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['deleted'] ) ) {
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Space deleted successfully.', 'buddynext' ); ?></p>
			</div>
			<?php
		}
		?>

		<div class="bn-stat-grid">
			<div class="bn-stat">
				<div class="bn-stat__label"><?php esc_html_e( 'Total Spaces', 'buddynext' ); ?></div>
				<div class="bn-stat__value"><?php echo esc_html( (string) $counts['total'] ); ?></div>
			</div>
			<div class="bn-stat">
				<div class="bn-stat__label"><?php esc_html_e( 'Open', 'buddynext' ); ?></div>
				<div class="bn-stat__value"><?php echo esc_html( (string) $counts['open'] ); ?></div>
			</div>
			<div class="bn-stat">
				<div class="bn-stat__label"><?php esc_html_e( 'Private', 'buddynext' ); ?></div>
				<div class="bn-stat__value"><?php echo esc_html( (string) $counts['private'] ); ?></div>
			</div>
			<div class="bn-stat">
				<div class="bn-stat__label"><?php esc_html_e( 'Secret', 'buddynext' ); ?></div>
				<div class="bn-stat__value"><?php echo esc_html( (string) $counts['secret'] ); ?></div>
			</div>
		</div>

		<div class="bn-settings-section">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Spaces', 'buddynext' ); ?></span>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" role="search" class="bn-admin-hub__form-bare">
					<input type="hidden" name="page" value="buddynext-spaces">
					<?php if ( '' !== $type ) : ?>
						<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>">
					<?php endif; ?>
					<label class="screen-reader-text" for="bn-spaces-search">
						<?php esc_html_e( 'Search spaces', 'buddynext' ); ?>
					</label>
					<input type="search"
							id="bn-spaces-search"
							class="bn-input"
							name="s"
							value="<?php echo esc_attr( $search ); ?>"
							placeholder="<?php esc_attr_e( 'Search spaces…', 'buddynext' ); ?>">
				</form>
			</div>
			<div class="bn-ss-body">
				<div class="bn-segment" role="group" aria-label="<?php esc_attr_e( 'Filter spaces by visibility', 'buddynext' ); ?>">
					<a href="<?php echo esc_url( add_query_arg( 's', $search, $base_url ) ); ?>"
						class="bn-segment__item<?php echo '' === $type ? ' is-active' : ''; ?>"
						aria-selected="<?php echo '' === $type ? 'true' : 'false'; ?>">
						<?php esc_html_e( 'All', 'buddynext' ); ?>
						<span class="bn-segment__count">(<?php echo esc_html( (string) $counts['total'] ); ?>)</span>
					</a>
					<?php
					$type_labels = array(
						'open'    => __( 'Open', 'buddynext' ),
						'private' => __( 'Private', 'buddynext' ),
						'secret'  => __( 'Secret', 'buddynext' ),
					);
					foreach ( $type_labels as $t_slug => $t_label ) :
						$tab_url = add_query_arg(
							array(
								'type' => $t_slug,
								's'    => $search,
							),
							$base_url
						);
						?>
						<a href="<?php echo esc_url( $tab_url ); ?>"
							class="bn-segment__item<?php echo $type === $t_slug ? ' is-active' : ''; ?>"
							aria-selected="<?php echo $type === $t_slug ? 'true' : 'false'; ?>">
							<?php echo esc_html( $t_label ); ?>
							<span class="bn-segment__count">(<?php echo esc_html( (string) ( $counts[ $t_slug ] ?? 0 ) ); ?>)</span>
						</a>
					<?php endforeach; ?>
				</div>

			<div class="bn-table-wrap__scroll">
				<table class="bn-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Space', 'buddynext' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Type', 'buddynext' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Members', 'buddynext' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Created', 'buddynext' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'buddynext' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $spaces ) ) : ?>
							<tr>
								<td colspan="5">
									<p class="description"><?php esc_html_e( 'No spaces found.', 'buddynext' ); ?></p>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $spaces as $space ) : ?>
								<?php
								// created_at is stored in UTC; convert to the site timezone
								// for display (mysql2date would print the raw UTC value).
								$owner    = get_userdata( $space['owner_id'] );
								$created  = get_date_from_gmt( (string) $space['created_at'], (string) get_option( 'date_format' ) );
								$type_key = sanitize_key( (string) $space['type'] );
								$tone     = \BuddyNext\Spaces\SpaceTypeRegistry::instance()->tone( $type_key );
								?>
								<tr>
									<td>
										<div class="bn-space-row-info">
											<strong><?php echo esc_html( (string) $space['name'] ); ?></strong>
											<?php if ( ! empty( $space['is_archived'] ) ) : ?>
												<span class="bn-badge" data-tone="warning"><?php esc_html_e( 'Archived', 'buddynext' ); ?></span>
											<?php endif; ?>
											<?php if ( $owner ) : ?>
												<span class="bn-row-meta">
													<?php
													$bn_owner_label = '' !== (string) $owner->display_name ? $owner->display_name : $owner->user_login;
													$bn_owner_link  = '<a href="' . esc_url( \BuddyNext\Core\PageRouter::profile_url( (int) $space['owner_id'] ) ) . '">' . esc_html( $bn_owner_label ) . '</a>';
													printf(
														/* translators: %s: owner profile link */
														esc_html__( 'Owner: %s', 'buddynext' ),
														$bn_owner_link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- href is esc_url'd and the label is esc_html'd above.
													);
													?>
												</span>
											<?php endif; ?>
										</div>
									</td>
									<td>
										<span class="bn-badge" data-tone="<?php echo esc_attr( $tone ); ?>">
											<?php echo esc_html( ucfirst( $type_key ) ); ?>
										</span>
									</td>
									<td><?php echo esc_html( (string) $space['member_count'] ); ?></td>
									<td><?php echo esc_html( (string) $created ); ?></td>
									<td>
										<div class="bn-row-actions">
											<a href="<?php echo esc_url( buddynext_space_url( (string) ( $space['slug'] ?? '' ) ) ); ?>"
													class="bn-btn" data-variant="ghost" data-size="sm" target="_blank" rel="noopener">
												<?php esc_html_e( 'View', 'buddynext' ); ?>
											</a>
											<?php // Manage links to the space's own settings screen — site admins pass its manage-settings capability, so this is the admin edit surface (rename, type, category, cover, who-can-post, transfer ownership). ?>
											<a href="<?php echo esc_url( trailingslashit( buddynext_space_url( (string) ( $space['slug'] ?? '' ) ) ) . 'settings/' ); ?>"
													class="bn-btn" data-variant="secondary" data-size="sm">
												<?php esc_html_e( 'Manage', 'buddynext' ); ?>
											</a>
											<form method="post"
													action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
													class="bn-delete-space-form">
												<?php wp_nonce_field( 'bn_delete_space' ); ?>
												<input type="hidden" name="action" value="bn_delete_space">
												<input type="hidden" name="space_id" value="<?php echo esc_attr( (string) $space['id'] ); ?>">
												<button type="submit"
														class="bn-btn"
														data-variant="danger"
														data-size="sm"
														data-bn-delete-space-trigger
														data-space-name="<?php echo esc_attr( (string) $space['name'] ); ?>">
													<?php esc_html_e( 'Delete', 'buddynext' ); ?>
												</button>
											</form>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<?php
			$this->render_pagination(
				$page,
				(int) $pages,
				(int) $total,
				self::DEFAULT_PER_PAGE,
				static function ( int $p ) use ( $search, $type, $base_url ): string {
					return add_query_arg(
						array_filter(
							array(
								'paged' => $p > 1 ? $p : false,
								's'     => '' !== $search ? $search : false,
								'type'  => '' !== $type ? $type : false,
							)
						),
						$base_url
					);
				},
				__( 'Spaces pagination', 'buddynext' )
			);
			?>
			</div><!-- .bn-ss-body -->
		</div><!-- .bn-settings-section -->

		<?php $this->render_delete_modal(); ?>
		<?php
	}

	/**
	 * Render the v2 delete-space confirm modal.
	 *
	 * Hidden by default; opened by JS (assets/js/admin/spaces.js) when a
	 * row's Delete button is clicked. Confirm posts the underlying form.
	 *
	 * @return void
	 */
	/**
	 * Render the Categories management subtab.
	 *
	 * Lists existing space categories with name + slug + space-count + delete
	 * affordance, and ships a create-category form below. Without this surface
	 * the SpaceCategoryController REST endpoints are unreachable for non-CLI
	 * site admins, which means Create-Space + Settings → General both ship a
	 * permanently empty category select.
	 *
	 * @return void
	 */
	private function render_categories_subtab(): void {
		$service = new \BuddyNext\Spaces\SpaceCategoryService();

		// Flash message after a save/delete (handlers redirect back here).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cat_msg = sanitize_key( wp_unslash( $_GET['cat_msg'] ?? '' ) );
		if ( 'saved' === $cat_msg ) {
			echo '<div class="bn-notice bn-notice-success">' . esc_html__( 'Category saved.', 'buddynext' ) . '</div>';
		} elseif ( 'deleted' === $cat_msg ) {
			echo '<div class="bn-notice bn-notice-success">' . esc_html__( 'Category deleted.', 'buddynext' ) . '</div>';
		} elseif ( 'error' === $cat_msg ) {
			echo '<div class="bn-notice bn-notice-error">' . esc_html__( 'Could not save category. Check that the name is set and the slug is unique.', 'buddynext' ) . '</div>';
		}

		// Edit mode: when ?edit_cat=ID is present, pre-fill the editor for that row.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bn_edit_id  = isset( $_GET['edit_cat'] ) ? absint( wp_unslash( $_GET['edit_cat'] ) ) : 0;
		$bn_edit_cat = $bn_edit_id > 0 ? $service->get_by_id( $bn_edit_id ) : null;

		$cats = $service->get_all_with_counts();
		?>
		<div class="bn-settings-section">
			<div class="bn-ss-header">
				<span class="bn-ss-title"><?php esc_html_e( 'Categories', 'buddynext' ); ?></span>
			</div>
			<div class="bn-ss-body">
			<div class="bn-table-wrap__scroll">
				<table class="bn-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Category', 'buddynext' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Slug', 'buddynext' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Spaces', 'buddynext' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Directory', 'buddynext' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Sort', 'buddynext' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'buddynext' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $cats ) ) : ?>
							<tr><td colspan="6"><p class="description"><?php esc_html_e( 'No categories yet. Create one below.', 'buddynext' ); ?></p></td></tr>
						<?php else : ?>
							<?php foreach ( $cats as $cat ) : ?>
								<tr>
									<td>
										<span class="bn-tax-badge-preview"
											style="background:<?php echo esc_attr( (string) $cat['color'] ); ?>;color:<?php echo esc_attr( (string) $cat['text_color'] ); ?>">
											<?php if ( '' !== (string) $cat['icon_svg'] ) : ?>
												<?php echo $cat['icon_svg']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized via wp_kses on save. ?>
											<?php endif; ?>
											<span class="bn-tax-badge-label"><?php echo esc_html( (string) $cat['name'] ); ?></span>
										</span>
									</td>
									<td><code><?php echo esc_html( (string) $cat['slug'] ); ?></code></td>
									<td><?php echo esc_html( (string) $cat['space_count'] ); ?></td>
									<td>
										<?php if ( ! empty( $cat['show_in_dir'] ) ) : ?>
											<span class="bn-badge bn-badge-active"><?php esc_html_e( 'Yes', 'buddynext' ); ?></span>
										<?php else : ?>
											<span class="bn-badge bn-badge-private"><?php esc_html_e( 'No', 'buddynext' ); ?></span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( (string) $cat['sort_order'] ); ?></td>
									<td>
										<a class="bn-btn" data-variant="ghost" data-size="sm"
											href="<?php echo esc_url( add_query_arg( 'edit_cat', (int) $cat['id'] ) . '#bn-cat-form' ); ?>"
										><?php esc_html_e( 'Edit', 'buddynext' ); ?></a>
										<form method="post" class="bn-cat-delete-form" data-bn-cat-delete-form
											action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<?php wp_nonce_field( 'bn_delete_space_category' ); ?>
											<input type="hidden" name="action" value="bn_delete_space_category">
											<input type="hidden" name="cat_id" value="<?php echo esc_attr( (string) $cat['id'] ); ?>">
											<button type="submit"
												class="bn-btn"
												data-variant="danger"
												data-size="sm"
												data-bn-confirm="<?php esc_attr_e( 'Delete this category? Spaces using it will be uncategorised.', 'buddynext' ); ?>"
												data-bn-confirm-title="<?php esc_attr_e( 'Delete category?', 'buddynext' ); ?>"
												data-bn-confirm-ok="<?php esc_attr_e( 'Delete category', 'buddynext' ); ?>"
												data-bn-confirm-cancel="<?php esc_attr_e( 'Cancel', 'buddynext' ); ?>"
											><?php esc_html_e( 'Delete', 'buddynext' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div><!-- .bn-table-wrap__scroll -->
			</div><!-- .bn-ss-body -->
		</div><!-- .bn-settings-section -->

		<?php
		// Shared taxonomy editor — creates a new category, or edits the selected
		// one when an Edit link set ?edit_cat.
		?>
		<div id="bn-cat-form">
			<?php
			buddynext_get_template(
				'parts/taxonomy-editor.php',
				array(
					'entity'   => 'space-category',
					'title'    => $bn_edit_cat ? __( 'Edit category', 'buddynext' ) : __( 'Add a category', 'buddynext' ),
					'action'   => 'bn_save_space_category',
					'nonce'    => 'bn_save_space_category',
					'edit'     => $bn_edit_cat,
					'hidden'   => array( 'cat_id' => $bn_edit_cat ? (string) $bn_edit_cat['id'] : '0' ),
					'toggles'  => array(
						array(
							'name'    => 'show_in_dir',
							'label'   => __( 'Show in the spaces directory', 'buddynext' ),
							'default' => true,
						),
					),
					'supports' => array( 'has_icon' => true ),
					'cancel'   => remove_query_arg( 'edit_cat' ),
				)
			);
			?>
		</div>
		<?php
	}

	/**
	 * Handle the create / update space-category form submission.
	 *
	 * Routes through SpaceCategoryService (single owner of the category table)
	 * so the admin screen holds no inline $wpdb logic.
	 *
	 * @return void
	 */
	public function handle_save_category(): void {
		check_admin_referer( 'bn_save_space_category' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage categories.', 'buddynext' ), 403 );
		}

		$service = new \BuddyNext\Spaces\SpaceCategoryService();

		// Shared editor field names; SpaceCategoryService::validate() sanitises.
		$data = array(
			'name'        => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'slug'        => sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) ),
			'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
			'color'       => sanitize_text_field( wp_unslash( $_POST['color'] ?? '#0073aa' ) ),
			'text_color'  => sanitize_text_field( wp_unslash( $_POST['text_color'] ?? '' ) ),
			'icon_svg'    => (string) wp_unslash( $_POST['icon_svg'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- SVG is sanitised via wp_kses inside SpaceCategoryService::validate().
			'sort_order'  => absint( wp_unslash( $_POST['sort_order'] ?? 0 ) ),
			'show_in_dir' => ! empty( $_POST['show_in_dir'] ),
		);

		$cat_id = absint( wp_unslash( $_POST['cat_id'] ?? 0 ) );
		$result = $cat_id > 0 ? $service->update( $cat_id, $data ) : $service->create( $data );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'buddynext-spaces',
					'subtab'  => 'categories',
					'cat_msg' => is_wp_error( $result ) ? 'error' : 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle the delete space-category form submission.
	 *
	 * @return void
	 */
	public function handle_delete_category(): void {
		check_admin_referer( 'bn_delete_space_category' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage categories.', 'buddynext' ), 403 );
		}

		$cat_id = absint( wp_unslash( $_POST['cat_id'] ?? 0 ) );
		if ( $cat_id > 0 ) {
			( new \BuddyNext\Spaces\SpaceCategoryService() )->delete( $cat_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'buddynext-spaces',
					'subtab'  => 'categories',
					'cat_msg' => 'deleted',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the v2 delete-space confirm modal.
	 *
	 * Hidden by default; opened by JS (assets/js/admin/spaces.js) when a
	 * row's Delete button is clicked. Confirm posts the underlying form.
	 *
	 * @return void
	 */
	private function render_delete_modal(): void {
		?>
		<div
			class="bn-modal-backdrop"
			role="dialog"
			aria-modal="true"
			aria-labelledby="bn-delete-space-title"
			hidden
			data-bn-modal="delete-space"
		>
			<div class="bn-modal__panel" data-tone="danger" data-size="sm">
				<header class="bn-modal__head">
					<h2 class="bn-modal__title" id="bn-delete-space-title">
						<?php esc_html_e( 'Delete this space?', 'buddynext' ); ?>
					</h2>
					<button
						type="button"
						class="bn-modal__close"
						aria-label="<?php esc_attr_e( 'Close', 'buddynext' ); ?>"
						data-bn-modal-close
					>&times;</button>
				</header>
				<div class="bn-modal__body">
					<p><?php esc_html_e( 'This will permanently delete the space and all of its member associations. This cannot be undone.', 'buddynext' ); ?></p>
				</div>
				<div class="bn-modal__foot">
					<button
						type="button"
						class="bn-btn"
						data-variant="ghost"
						data-size="md"
						data-bn-modal-close
					><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
					<button
						type="button"
						class="bn-btn"
						data-variant="danger"
						data-size="md"
						data-bn-confirm-delete
					><?php esc_html_e( 'Delete permanently', 'buddynext' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	// ── Query ──────────────────────────────────────────────────────────────────

	/**
	 * Return a paginated list of spaces.
	 *
	 * Accepted args:
	 *   'page'     int    Current page (1-based). Default 1.
	 *   'per_page' int    Items per page. Default 20.
	 *   'search'   string Optional name search string.
	 *   'type'     string Optional type filter: 'open' | 'private' | 'secret'.
	 *   'orderby'  string Column to order by. Default 'created_at'.
	 *   'order'    string 'ASC' | 'DESC'. Default 'DESC'.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{ spaces: array<int, array<string, mixed>>, total: int, pages: int }
	 */
	public function list_spaces( array $args = array() ): array {
		global $wpdb;

		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, (int) ( $args['per_page'] ?? self::DEFAULT_PER_PAGE ) );
		$search   = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$type     = sanitize_key( (string) ( $args['type'] ?? '' ) );
		$orderby  = sanitize_key( (string) ( $args['orderby'] ?? 'created_at' ) );
		$order    = strtoupper( sanitize_text_field( (string) ( $args['order'] ?? 'DESC' ) ) );
		$order    = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';

		$offset = ( $page - 1 ) * $per_page;
		$table  = $wpdb->prefix . 'bn_spaces';

		$allowed_columns = array( 'id', 'name', 'member_count', 'created_at', 'type' );
		if ( ! in_array( $orderby, $allowed_columns, true ) ) {
			$orderby = 'created_at';
		}

		$allowed_types = array( 'open', 'private', 'secret' );

		// Build WHERE conditions.
		$conditions = array();
		$params     = array();

		if ( '' !== $search ) {
			$conditions[] = 'name LIKE %s';
			$params[]     = '%' . $wpdb->esc_like( $search ) . '%';
		}

		if ( '' !== $type && in_array( $type, $allowed_types, true ) ) {
			$conditions[] = 'type = %s';
			$params[]     = $type;
		}

		if ( ! empty( $conditions ) ) {
			$where = 'WHERE ' . implode( ' AND ', $conditions );
			// Placeholders live in $where (dynamic) — static analysis cannot count them, false positives below.
			$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} {$where}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					$params
				)
			);
			$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
					"SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					array_merge( $params, array( $per_page, $offset ) )
				)
			);
		} else {
			$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT COUNT(*) FROM {$table}" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT * FROM {$table} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$per_page,
					$offset
				)
			);
		}

		$spaces = array();
		foreach ( (array) $rows as $row ) {
			$spaces[] = array(
				'id'           => (int) $row->id,
				'name'         => $row->name,
				'slug'         => (string) $row->slug,
				'owner_id'     => (int) $row->owner_id,
				'member_count' => (int) $row->member_count,
				'type'         => $row->type,
				'is_archived'  => ! empty( $row->is_archived ),
				'created_at'   => $row->created_at,
			);
		}

		$pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

		return compact( 'spaces', 'total', 'pages' );
	}

	/**
	 * Return the total number of spaces in bn_spaces.
	 *
	 * @return int
	 */
	public function get_space_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) FROM {$wpdb->prefix}bn_spaces" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	// ── Write ──────────────────────────────────────────────────────────────────

	/**
	 * Permanently delete a space and all its associated data.
	 *
	 * Delegates to SpaceService::delete() — the single cleanup routine — so the
	 * admin path cascades the space's posts (and their reactions/comments/
	 * bookmarks/shares/hashtags/poll/feed rows) and clears bn_space_bans /
	 * bn_reports / bn_mod_log / bn_space_members, instead of deleting only the
	 * space + member rows and orphaning everything else. The admin_post handler
	 * has already gated on manage_options, which the service's permission check
	 * also honours. SpaceService::delete() fires buddynext_space_deleted itself.
	 *
	 * @param int $space_id bn_spaces.id.
	 * @return void
	 */
	public function delete_space( int $space_id ): void {
		buddynext_service( 'spaces' )->delete( $space_id, get_current_user_id() );
	}

	// ── Admin-post handlers ────────────────────────────────────────────────────

	/**
	 * Handle admin_post_bn_delete_space form submission.
	 *
	 * @return void
	 */
	public function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}

		check_admin_referer( 'bn_delete_space' );

		$space_id = absint( wp_unslash( $_POST['space_id'] ?? 0 ) );
		if ( $space_id > 0 ) {
			$this->delete_space( $space_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'buddynext-spaces',
					'deleted'  => '1',
					'space_id' => $space_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	/**
	 * Return space counts grouped by type.
	 *
	 * @return array{ total: int, open: int, private: int, secret: int }
	 */
	private function get_type_counts(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'bn_spaces';
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT type, COUNT(*) AS cnt FROM {$table} GROUP BY type", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$counts = array(
			'total'   => 0,
			'open'    => 0,
			'private' => 0,
			'secret'  => 0,
		);

		foreach ( (array) $rows as $row ) {
			$t   = sanitize_key( (string) ( $row['type'] ?? '' ) );
			$cnt = (int) ( $row['cnt'] ?? 0 );
			if ( array_key_exists( $t, $counts ) ) {
				$counts[ $t ] = $cnt;
			}
			$counts['total'] += $cnt;
		}

		return $counts;
	}
}
