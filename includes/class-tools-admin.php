<?php
/**
 * Admin interface and settings page for Woo SOYOO Tools.
 *
 * @package WooSoyooTools
 */

namespace SOYOO\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Tools_Admin
 */
class Tools_Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 68 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_head', array( $this, 'suppress_admin_notices' ), 999 );
		add_action( 'in_admin_header', array( $this, 'suppress_admin_notices' ), 999 );
		add_action( 'admin_init', array( $this, 'handle_form_save' ) );
		add_action( 'wp_ajax_woo_soyoo_tools_toggle_feature', array( $this, 'ajax_toggle_feature' ) );
	}

	/**
	 * Register submenu under WooCommerce.
	 */
	public function register_menu() {
		$hook_suffix = add_submenu_page(
			'woocommerce',
			__( 'SOYOO Tools — Améliorations WooCommerce', 'woo-soyoo-tools' ),
			__( 'SOYOO Tools', 'woo-soyoo-tools' ),
			'manage_woocommerce',
			'woo-soyoo-tools',
			array( $this, 'render_page' )
		);

		if ( $hook_suffix ) {
			add_action( 'load-' . $hook_suffix, array( $this, 'on_page_load' ) );
		}
	}

	/**
	 * Actions executed when the plugin settings page is loaded.
	 */
	public function on_page_load() {
		add_action( 'admin_head', array( $this, 'suppress_admin_notices' ), 999 );
		add_action( 'in_admin_header', array( $this, 'suppress_admin_notices' ), 999 );
	}

	/**
	 * Check whether current admin screen belongs to this plugin.
	 *
	 * @return bool
	 */
	private function is_tools_admin_screen() {
		if ( isset( $_GET['page'] ) && 'woo-soyoo-tools' === $_GET['page'] ) {
			return true;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( $screen->id, 'woo-soyoo-tools' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Suppress all third-party admin notices to keep the interface clean and focused.
	 */
	public function suppress_admin_notices() {
		if ( ! $this->is_tools_admin_screen() ) {
			return;
		}

		remove_all_actions( 'wp_admin_notices' );
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
	}

	/**
	 * Enqueue admin stylesheet and scripts.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'woo-soyoo-tools' ) ) {
			return;
		}

		wp_enqueue_style(
			'woo-soyoo-tools-admin',
			plugins_url( 'assets/css/admin.css', WOO_SOYOO_TOOLS_FILE ),
			array(),
			WOO_SOYOO_TOOLS_VERSION
		);

		wp_enqueue_script(
			'woo-soyoo-tools-admin',
			plugins_url( 'assets/js/admin.js', WOO_SOYOO_TOOLS_FILE ),
			array(),
			WOO_SOYOO_TOOLS_VERSION,
			true
		);

		wp_localize_script(
			'woo-soyoo-tools-admin',
			'wooSoyooToolsAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'woo_soyoo_tools_nonce' ),
			)
		);
	}

	/**
	 * Handle classic form submission fallback.
	 */
	public function handle_form_save() {
		if ( ! isset( $_POST['woo_soyoo_tools_save_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woo_soyoo_tools_save_nonce'] ) ), 'woo_soyoo_tools_save_settings' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$enabled_features = isset( $_POST['enabled_features'] ) && is_array( $_POST['enabled_features'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['enabled_features'] ) )
			: array();

		Feature_Manager::instance()->save_all_states( $enabled_features );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'woo-soyoo-tools',
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * AJAX handler for toggling an individual feature switch immediately.
	 */
	public function ajax_toggle_feature() {
		check_ajax_referer( 'woo_soyoo_tools_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission refusée.', 'woo-soyoo-tools' ) ), 403 );
		}

		$feature_id = isset( $_POST['feature_id'] ) ? sanitize_key( wp_unslash( $_POST['feature_id'] ) ) : '';
		$enabled    = isset( $_POST['enabled'] ) && '1' === (string) wp_unslash( $_POST['enabled'] );

		$updated = Feature_Manager::instance()->set_feature_state( $feature_id, $enabled );
		if ( ! $updated ) {
			wp_send_json_error( array( 'message' => __( 'Fonctionnalité introuvable.', 'woo-soyoo-tools' ) ), 404 );
		}

		$features      = Feature_Manager::instance()->get_features();
		$active_count  = count(
			array_filter(
				$features,
				function ( $f ) {
					return ! empty( $f['enabled'] );
				}
			)
		);
		$total_count   = count( $features );
		$disabled_count = $total_count - $active_count;

		wp_send_json_success(
			array(
				'feature_id'     => $feature_id,
				'enabled'        => $enabled,
				'active_count'   => $active_count,
				'disabled_count' => $disabled_count,
				'total_count'    => $total_count,
				'message'        => $enabled
					? __( 'Fonctionnalité activée et enregistrée.', 'woo-soyoo-tools' )
					: __( 'Fonctionnalité désactivée et enregistrée.', 'woo-soyoo-tools' ),
			)
		);
	}

	/**
	 * Render the plugin settings page.
	 */
	public function render_page() {
		$manager        = Feature_Manager::instance();
		$features       = $manager->get_features();
		$categories     = $manager->get_categories();
		$total_count    = count( $features );
		$active_count   = count(
			array_filter(
				$features,
				function ( $f ) {
					return ! empty( $f['enabled'] );
				}
			)
		);
		$disabled_count = $total_count - $active_count;
		$target_locale  = \SOYOO\Tools\Features\Feature_Force_French_Emails::get_target_locale();
		$saved_notice   = isset( $_GET['updated'] ) && '1' === $_GET['updated'];
		?>
		<div class="wrap wst-wrap">
			<hr class="wp-header-end">

			<!-- Header Banner -->
			<header class="wst-header">
				<div class="wst-header__brand">
					<div class="wst-header__eyebrow">INGÉNIERIE WOOCOMMERCE SOYOO</div>
					<h1 class="wst-header__title">
						Woo SOYOO Tools
						<span class="wst-version-pill">v<?php echo esc_html( WOO_SOYOO_TOOLS_VERSION ); ?></span>
					</h1>
					<p class="wst-header__subtitle">
						Socle mutualisé d'améliorations techniques, correctifs Action Scheduler et optimisations transversales pour les boutiques WooCommerce gérées par SOYOO.
					</p>
				</div>

				<div class="wst-header__stats">
					<div class="wst-stat-card">
						<span class="wst-stat-card__label">Total</span>
						<span class="wst-stat-card__value" id="wst-stat-total"><?php echo esc_html( $total_count ); ?></span>
					</div>
					<div class="wst-stat-card wst-stat-card--active">
						<span class="wst-stat-card__label">Actives</span>
						<span class="wst-stat-card__value" id="wst-stat-active"><?php echo esc_html( $active_count ); ?></span>
					</div>
					<div class="wst-stat-card wst-stat-card--disabled">
						<span class="wst-stat-card__label">Désactivées</span>
						<span class="wst-stat-card__value" id="wst-stat-disabled"><?php echo esc_html( $disabled_count ); ?></span>
					</div>
				</div>
			</header>

			<?php if ( $saved_notice ) : ?>
				<div class="wst-banner-notice wst-banner-notice--success">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
					<span><?php esc_html_e( 'Les états des fonctionnalités ont été enregistrés.', 'woo-soyoo-tools' ); ?></span>
				</div>
			<?php endif; ?>

			<!-- Filter & Search Bar -->
			<section class="wst-toolbar" aria-label="Filtres des fonctionnalités">
				<div class="wst-search-box">
					<svg class="wst-search-box__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
					<input
						type="search"
						id="wst-feature-search"
						class="wst-search-box__input"
						placeholder="Rechercher une fonctionnalité, un hook (ex: Action Scheduler, locale, email)..."
						autocomplete="off"
					>
				</div>

				<div class="wst-filters-group">
					<div class="wst-pill-group" role="group" aria-label="Filtrer par catégorie">
						<button type="button" class="wst-filter-pill is-active" data-filter-category="all">
							Toutes les catégories
						</button>
						<?php foreach ( $categories as $cat_slug => $cat_label ) : ?>
							<button type="button" class="wst-filter-pill" data-filter-category="<?php echo esc_attr( $cat_slug ); ?>">
								<?php echo esc_html( $cat_label ); ?>
							</button>
						<?php endforeach; ?>
					</div>

					<div class="wst-pill-group wst-pill-group--status" role="group" aria-label="Filtrer par état">
						<button type="button" class="wst-status-pill is-active" data-filter-status="all">Tous</button>
						<button type="button" class="wst-status-pill" data-filter-status="enabled">Actives</button>
						<button type="button" class="wst-status-pill" data-filter-status="disabled">Désactivées</button>
					</div>
				</div>
			</section>

			<!-- Features List Form -->
			<form method="post" action="" id="wst-features-form">
				<?php wp_nonce_field( 'woo_soyoo_tools_save_settings', 'woo_soyoo_tools_save_nonce' ); ?>

				<div class="wst-features-grid" id="wst-features-list">
					<?php foreach ( $features as $feature_id => $feature ) : ?>
						<?php
						$is_enabled    = ! empty( $feature['enabled'] );
						$category_slug = isset( $feature['category_slug'] ) ? $feature['category_slug'] : 'general';
						$search_blob   = strtolower(
							implode(
								' ',
								array(
									$feature['id'],
									$feature['title'],
									$feature['category'],
									$feature['summary'],
									isset( $feature['problem'] ) ? $feature['problem'] : '',
									isset( $feature['solution'] ) ? $feature['solution'] : '',
									isset( $feature['hooks'] ) && is_array( $feature['hooks'] ) ? implode( ' ', $feature['hooks'] ) : '',
								)
							)
						);
						?>
						<article
							class="wst-feature-card <?php echo $is_enabled ? 'is-enabled' : 'is-disabled'; ?>"
							data-feature-id="<?php echo esc_attr( $feature_id ); ?>"
							data-category="<?php echo esc_attr( $category_slug ); ?>"
							data-status="<?php echo $is_enabled ? 'enabled' : 'disabled'; ?>"
							data-search="<?php echo esc_attr( $search_blob ); ?>"
						>
							<div class="wst-feature-card__main">
								<div class="wst-feature-card__content">
									<div class="wst-feature-card__meta">
										<span class="wst-meta-category"><?php echo esc_html( $feature['category'] ); ?></span>
										<?php if ( ! empty( $feature['badge'] ) ) : ?>
											<span class="wst-meta-badge"><?php echo esc_html( $feature['badge'] ); ?></span>
										<?php endif; ?>
										<?php if ( ! empty( $feature['since'] ) ) : ?>
											<span class="wst-meta-version">Depuis v<?php echo esc_html( $feature['since'] ); ?></span>
										<?php endif; ?>
										<code class="wst-meta-slug"><?php echo esc_html( $feature_id ); ?></code>
									</div>

									<h2 class="wst-feature-card__title">
										<label for="wst-toggle-<?php echo esc_attr( $feature_id ); ?>">
											<?php echo esc_html( $feature['title'] ); ?>
										</label>
									</h2>

									<p class="wst-feature-card__summary">
										<?php echo esc_html( $feature['summary'] ); ?>
									</p>

									<?php if ( 'force_french_transactional_emails' === $feature_id ) : ?>
										<div class="wst-feature-card__live-info">
											<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
											<span>Locale cible verrouillée sur ce site : <strong><?php echo esc_html( $target_locale ); ?></strong></span>
										</div>
									<?php endif; ?>

									<div class="wst-feature-card__actions">
										<button type="button" class="wst-details-toggle" aria-expanded="false">
											<svg class="wst-details-toggle__chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
											<span>Afficher le contexte technique &amp; les hooks</span>
										</button>
									</div>
								</div>

								<div class="wst-feature-card__control">
									<label class="wst-switch" for="wst-toggle-<?php echo esc_attr( $feature_id ); ?>">
										<input
											type="checkbox"
											id="wst-toggle-<?php echo esc_attr( $feature_id ); ?>"
											name="enabled_features[]"
											value="<?php echo esc_attr( $feature_id ); ?>"
											class="wst-switch__input"
											data-feature-id="<?php echo esc_attr( $feature_id ); ?>"
											<?php checked( $is_enabled ); ?>
										>
										<span class="wst-switch__slider"></span>
									</label>
									<span class="wst-switch-state-label" data-state-label="<?php echo esc_attr( $feature_id ); ?>">
										<?php echo $is_enabled ? 'Active' : 'Désactivée'; ?>
									</span>
								</div>
							</div>

							<!-- Expandable Technical Context Drawer -->
							<div class="wst-feature-card__drawer" hidden>
								<div class="wst-drawer-grid">
									<?php if ( ! empty( $feature['problem'] ) ) : ?>
										<div class="wst-drawer-block">
											<h3 class="wst-drawer-block__title">Problème &amp; Cause racine</h3>
											<p class="wst-drawer-block__text"><?php echo esc_html( $feature['problem'] ); ?></p>
										</div>
									<?php endif; ?>

									<?php if ( ! empty( $feature['solution'] ) ) : ?>
										<div class="wst-drawer-block">
											<h3 class="wst-drawer-block__title">Architecture du correctif SOYOO</h3>
											<p class="wst-drawer-block__text"><?php echo esc_html( $feature['solution'] ); ?></p>
										</div>
									<?php endif; ?>
								</div>

								<?php if ( ! empty( $feature['hooks'] ) && is_array( $feature['hooks'] ) ) : ?>
									<div class="wst-drawer-hooks">
										<span class="wst-drawer-hooks__label">Hooks &amp; Filtres branchés :</span>
										<div class="wst-drawer-hooks__list">
											<?php foreach ( $feature['hooks'] as $hook_item ) : ?>
												<code class="wst-hook-tag"><?php echo esc_html( $hook_item ); ?></code>
											<?php endforeach; ?>
										</div>
									</div>
								<?php endif; ?>
							</div>
						</article>
					<?php endforeach; ?>
				</div>

				<!-- Empty state when filter matches nothing -->
				<div class="wst-empty-state" id="wst-empty-state" hidden>
					<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
					<p>Aucune fonctionnalité ne correspond aux critères de recherche ou de filtre sélectionnés.</p>
					<button type="button" class="wst-btn wst-btn--secondary" id="wst-reset-filters">Réinitialiser les filtres</button>
				</div>

				<!-- Footer Save Bar -->
				<footer class="wst-footer-bar">
					<div class="wst-footer-bar__info">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
						<span>Chaque bascule d'interrupteur est sauvegardée instantanément. Les nouvelles fonctionnalités ajoutées aux futures versions du plugin s'activent par défaut sauf si vous les décochez sur ce site.</span>
					</div>
					<button type="submit" class="wst-btn wst-btn--primary">
						Enregistrer les modifications
					</button>
				</footer>
			</form>

			<!-- Toast Notification for AJAX saves -->
			<div id="wst-toast" class="wst-toast" role="status" aria-live="polite" hidden>
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
				<span id="wst-toast-message">Modifications enregistrées</span>
			</div>
		</div>
		<?php
	}
}
