<?php
/**
 * Central Feature Registry & State Manager for Woo SOYOO Tools.
 *
 * Manages registration, opt-out state persistence, and execution of all
 * WooCommerce enhancements. When a new feature is added in a plugin update
 * with `default_enabled => true`, it automatically activates on all client
 * sites unless explicitly unchecked in that site's settings.
 *
 * @package WooSoyooTools
 */

namespace SOYOO\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Feature_Manager
 */
class Feature_Manager {

	/**
	 * Option name storing explicit feature states (`['feature_id' => 1|0]`).
	 */
	const OPTION_NAME = 'woo_soyoo_tools_features_state';

	/**
	 * Singleton instance.
	 *
	 * @var Feature_Manager|null
	 */
	private static $instance = null;

	/**
	 * Registered features dictionary.
	 *
	 * @var array
	 */
	private $features = array();

	/**
	 * Retrieve singleton instance.
	 *
	 * @return Feature_Manager
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->register_built_in_features();
	}

	/**
	 * Register all built-in SOYOO enhancements.
	 */
	private function register_built_in_features() {
		$this->features = array(
			'force_french_transactional_emails' => array(
				'id'              => 'force_french_transactional_emails',
				'title'           => 'Verrouillage de la langue française des e-mails de commande (Action Scheduler & Webhooks)',
				'category'        => 'E-mails & Notifications',
				'category_slug'   => 'emails',
				'badge'           => 'Critique',
				'default_enabled' => true,
				'since'           => '1.0.0',
				'summary'         => 'Empêche l\'envoi en anglais (en_US) des e-mails de notification de nouvelle commande aux gestionnaires de boutique lorsque l\'envoi est différé dans Action Scheduler ou déclenché par un webhook de paiement.',
				'problem'         => 'Lorsque les e-mails transactionnels WooCommerce sont différés via Action Scheduler (`woocommerce_defer_transactional_emails`) ou déclenchés par un webhook de passerelle de paiement (Stripe, PayPlug, Alma, PayPal), le processus s\'exécute souvent sans contexte utilisateur ou avec une locale anglaise. De plus, la méthode native `WC_Email::setup_locale()` de WooCommerce ne bascule la langue du site que pour les e-mails clients (`is_customer_email() === true`), ignorant les e-mails administrateurs (`New Order`, `Cancelled Order`, `Failed Order`). Enfin, si `WC_Emails::instance()` est instancié avant le changement de locale, l\'objet et l\'en-tête par défaut restent figés en anglais.',
				'solution'        => 'Verrouillage à 3 niveaux : (1) Bascule sur la locale française du site (`WPLANG` ou `fr_FR`) à priorité 1 sur `woocommerce_deliver_queued_transactional_email` AVANT l\'instanciation de `WC_Emails::instance()` ; (2) Enveloppement de tous les hooks `*_notification` et filtres `woocommerce_email_setup_locale` pour couvrir aussi les e-mails destinés aux gestionnaires de boutique ; (3) Vérification et rechargement forcé du domaine de traduction `woocommerce` (`WP_LANG_DIR`) et ré-évaluation des sujets/en-têtes admin (`New order #`, `Cancelled order #`, `Failed order #`) s\'ils ont été mis en cache en anglais.',
				'hooks'           => array(
					'woocommerce_deliver_queued_transactional_email (prio 1 & 99)',
					'woocommerce_allow_send_queued_transactional_email (prio 1)',
					'woocommerce_send_queued_transactional_email (prio 1 & 99)',
					'woocommerce_email_setup_locale / woocommerce_email_restore_locale',
					'woocommerce_before_resend_order_emails / woocommerce_after_resend_order_emails',
					'woocommerce_email_subject_new_order / woocommerce_email_heading_new_order',
				),
				'callback'        => array( '\SOYOO\Tools\Features\Feature_Force_French_Emails', 'register' ),
			),
			'link_guest_orders_to_customers' => array(
				'id'              => 'link_guest_orders_to_customers',
				'title'           => 'Rattachement automatique des commandes invités aux comptes clients (HPOS & Temps réel)',
				'category'        => 'Commandes & Clients',
				'category_slug'   => 'orders',
				'badge'           => 'Essentiel',
				'default_enabled' => true,
				'since'           => '1.1.0',
				'summary'         => 'Associe automatiquement toute commande passée en mode invité (Apple Pay, Google Pay, mobile) au compte client existant correspondant à l\'e-mail de facturation, et rattache l\'historique passé lors de la création d\'un compte.',
				'problem'         => 'Les clients possédant déjà un compte sur la boutique mais commandant rapidement sans s\'authentifier (Apple Pay, Express Checkout, mobile) ont leurs commandes enregistrées avec `customer_id = 0`. Ces commandes deviennent invisibles dans leur espace « Mon compte » et faussent la LTV client. De même, un client qui crée son compte après avoir commandé en invité ne retrouve pas systématiquement son historique.',
				'solution'        => 'Triple verrou compatible HPOS (`wp_wc_orders`) : (1) Sur `woocommerce_created_customer`, appelle `wc_update_new_customer_past_orders( $customer_id )` pour rattacher rétroactivement toutes les commandes invités passées ; (2) Sur `woocommerce_checkout_create_order` (prio 20), injecte directement `$order->set_customer_id( $user->ID )` avant l\'insertion en base (0 requête SQL additionnelle) si `customer_id === 0` et que l\'e-mail de facturation correspond à un compte existant ; (3) Filet de sécurité sur `woocommerce_new_order` et `woocommerce_order_status_processing` (prio 20) pour les commandes créées hors checkout classique ou validées par webhook.',
				'hooks'           => array(
					'woocommerce_created_customer (prio 10)',
					'woocommerce_checkout_create_order (prio 20)',
					'woocommerce_new_order (prio 20)',
					'woocommerce_order_status_processing (prio 20)',
				),
				'callback'        => array( '\SOYOO\Tools\Features\Feature_Link_Guest_Orders', 'register' ),
			),
			'database_and_scheduler_hygiene' => array(
				'id'              => 'database_and_scheduler_hygiene',
				'title'           => 'Hygiène Base de Données, Action Scheduler, Transients & Options Autoload',
				'category'        => 'Performance & Base de données',
				'category_slug'   => 'performance',
				'badge'           => 'Performance',
				'default_enabled' => true,
				'since'           => '1.1.0',
				'summary'         => 'Maintient les tables Action Scheduler, les transients expirés, les événements Independent Analytics (> 1 an) et le cache `alloptions` (WP 6.6+) propres et légers sans impacter le TTFB.',
				'problem'         => 'Sur les boutiques WooCommerce actives, les tables `actionscheduler_actions` et `actionscheduler_logs` gonflent rapidement (rétention native de 31 jours et petits lots de 20), des hooks orphelins sans callback (`fetch_patterns`, `sfn_*`) tournent dans le vide, les transients expirés s\'accumulent dans `wp_options`, et plusieurs extensions chargent de volumineuses options en `autoload` (`yes`/`on`/`auto`/`auto-on`) à chaque requête PHP.',
				'solution'        => 'Architecture d\'hygiène en 7 volets : (a) Rétention Action Scheduler réduite à 7 jours et batch size porté à 1000 ; (b) Neutralisation des hooks AS fantômes sans callback sur `init` ; (c) Purge active bi-quotidienne par lots (`LIMIT 5000`, max 25s, `wp_cache_flush_runtime()`) des logs AS > 7j, actions `complete`/`canceled` > 7j et `failed` > 14j ; (d) Purge quotidienne douce de 500 transients expirés ; (e) Rétention Independent Analytics Pro forcée à 365j et purge nocturne de `analytics_wp_events` > 365j ; (f) Dé-autoload non-destructif (tous les 3j en admin) des options lourdes connues compatible WP 6.6+ ; (g) Bouton de déclenchement manuel sécurisé en 1 clic.',
				'hooks'           => array(
					'action_scheduler_retention_period / action_scheduler_cleanup_batch_size (prio 99)',
					'init (prio 20 & 30)',
					'soyoo_tools_as_purge_cron (twicedaily)',
					'soyoo_tools_transients_cleanup_cron (daily)',
					'option_ia_data_retention_period',
					'admin_init (de-autoload & déclenchement manuel)',
				),
				'callback'        => array( '\SOYOO\Tools\Features\Feature_Database_Hygiene', 'register' ),
			),
			'abandoned_orders_cleanup_1y' => array(
				'id'              => 'abandoned_orders_cleanup_1y',
				'title'           => 'Purge nocturne des commandes abandonnées ou échouées de plus d\'un an (HPOS-Safe)',
				'category'        => 'Performance & Base de données',
				'category_slug'   => 'performance',
				'badge'           => 'Hygiène HPOS',
				'default_enabled' => true,
				'since'           => '1.1.0',
				'summary'         => 'Supprime chaque nuit à 03h30 (heure locale) par petits lots de 30 les commandes au statut `cancelled` ou `failed` de plus de 365 jours n\'ayant jamais donné lieu à un paiement.',
				'problem'         => 'Au fil des années, des milliers de paniers abandonnés au paiement ou de tentatives de règlement échouées (`cancelled`, `failed`) s\'accumulent dans `wp_wc_orders` (HPOS) et `wp_posts`, alourdissant les index SQL et les sauvegardes alors qu\'elles n\'ont aucune valeur comptable (aucun paiement ni numéro de transaction).',
				'solution'        => 'Tâche quotidienne planifiée à 03h30 heure locale (`wp_timezone()`) récupérant via `wc_get_orders()` jusqu\'à 30 commandes `cancelled` ou `failed` créées il y a plus de 365 jours. Applique 4 verrous de sécurité stricts avant suppression (`in_array($status, [\'cancelled\', \'failed\'], true)`, `null === $order->get_date_paid()`, `empty($order->get_transaction_id())`, `date_created < time() - 365 * DAY_IN_SECONDS`), exécute `$order->delete( true )` (compatible HPOS & Legacy) et journalise dans `wc_get_logger()` (`source: woo-soyoo-tools`).',
				'hooks'           => array(
					'init (planification 03h30 wp_timezone)',
					'soyoo_tools_abandoned_orders_cleanup_cron (daily)',
				),
				'callback'        => array( '\SOYOO\Tools\Features\Feature_Abandoned_Orders_Cleanup', 'register' ),
			),
			'admin_login_geo_alert' => array(
				'id'              => 'admin_login_geo_alert',
				'title'           => 'Alerte de sécurité Geo-IP asynchrone sur les connexions Administrateur',
				'category'        => 'Sécurité & Surveillance',
				'category_slug'   => 'security',
				'badge'           => 'Sécurité',
				'default_enabled' => true,
				'since'           => '1.1.0',
				'summary'         => 'Surveille en tâche de fond les connexions réussies aux comptes `administrator` et envoie une alerte e-mail immédiate si la connexion provient d\'un pays hors zone autorisée (FR, RE, PK, MG, MU, YT).',
				'problem'         => 'Une compromission d\'identifiants administrateur (phishing, fuite de mot de passe, session volée) depuis un pays étranger peut passer inaperçue. Les implémentations classiques ralentissent le login par un appel HTTP synchrone ou font confiance à l\'en-tête `X-Forwarded-For` falsifiable par un attaquant.',
				'solution'        => 'Sur `wp_login`, extrait l\'IP réelle de manière non falsifiable (`HTTP_CF_CONNECTING_IP` ou `REMOTE_ADDR`, sans jamais utiliser la fin de `HTTP_X_FORWARDED_FOR`) ainsi que `HTTP_CF_IPCOUNTRY`, puis planifie un événement unique asynchrone `soyoo_tools_verify_admin_login_ip`. Le worker vérifie un verrou anti-spam de 15 min, résout le pays via Cloudflare ou `ip-api.com` (avec fallback HTTPS `ipwho.is`), et alerte `julien@soyoo.re` avec le nom et l\'URL du site si le pays n\'est pas dans la liste autorisée.',
				'hooks'           => array(
					'wp_login (prio 10)',
					'soyoo_tools_verify_admin_login_ip (async single event)',
					'woo_soyoo_tools_allowed_admin_countries (filter)',
					'woo_soyoo_tools_security_alert_email (filter)',
				),
				'callback'        => array( '\SOYOO\Tools\Features\Feature_Admin_Login_Alert', 'register' ),
			),
			'safe_admin_svg_uploads' => array(
				'id'              => 'safe_admin_svg_uploads',
				'title'           => 'Téléversement SVG sécurisé pour Administrateurs & Gestionnaires de boutique',
				'category'        => 'Back-Office & Médias',
				'category_slug'   => 'admin',
				'badge'           => 'Confort Admin',
				'default_enabled' => true,
				'since'           => '1.1.0',
				'summary'         => 'Autorise l\'upload de fichiers vectoriels `.svg` uniquement aux comptes privilégiés (`manage_woocommerce` / `manage_options`) avec inspection anti-XSS à la volée et aperçu corrigé dans la médiathèque.',
				'problem'         => 'WordPress bloque nativement les fichiers `.svg` par crainte d\'injections XSS, obligeant souvent à installer des extensions tierces lourdes ou permissives qui ouvrent l\'upload SVG à tous les rôles utilisateurs sans filtrage strict.',
				'solution'        => 'Autorise `image/svg+xml` dans `upload_mimes` et corrige la détection `wp_check_filetype_and_ext` sur WordPress 6.x exclusivement pour les utilisateurs disposant de `manage_woocommerce` ou `manage_options`. Inspecte le contenu brut dans `wp_handle_upload_prefilter` pour exiger une balise `<svg` valide et bloquer tout vecteur XSS (`<script`, `onload=`, `onerror=`, `javascript:`, `<iframe`, `<foreignObject`). Corrige le rendu des miniatures SVG dans `admin_head`.',
				'hooks'           => array(
					'upload_mimes (prio 10)',
					'wp_check_filetype_and_ext (prio 10)',
					'wp_handle_upload_prefilter (prio 10)',
					'admin_head (miniatures SVG médiathèque)',
				),
				'callback'        => array( '\SOYOO\Tools\Features\Feature_Safe_Svg_Uploads', 'register' ),
			),
		);

		/**
		 * Allow registering additional features programmatically if needed.
		 *
		 * @param array $features Registered features.
		 */
		$this->features = apply_filters( 'woo_soyoo_tools_registered_features', $this->features );
	}

	/**
	 * Boot all currently enabled features.
	 */
	public function boot() {
		foreach ( $this->features as $feature_id => $feature ) {
			if ( $this->is_enabled( $feature_id ) && ! empty( $feature['callback'] ) && is_callable( $feature['callback'] ) ) {
				call_user_func( $feature['callback'] );
			}
		}
	}

	/**
	 * Return all registered features with their live `enabled` boolean status.
	 *
	 * @return array
	 */
	public function get_features() {
		$result = array();
		foreach ( $this->features as $feature_id => $feature ) {
			$feature['enabled'] = $this->is_enabled( $feature_id );
			$result[ $feature_id ] = $feature;
		}
		return $result;
	}

	/**
	 * Return distinct categories present in registered features.
	 *
	 * @return array Associative array of `category_slug => category_label`.
	 */
	public function get_categories() {
		$categories = array();
		foreach ( $this->features as $feature ) {
			$slug  = isset( $feature['category_slug'] ) ? $feature['category_slug'] : 'general';
			$label = isset( $feature['category'] ) ? $feature['category'] : 'Général';
			$categories[ $slug ] = $label;
		}
		return $categories;
	}

	/**
	 * Check whether a specific feature is enabled.
	 * Falls back to the feature's `default_enabled` value if not explicitly saved in DB yet.
	 *
	 * @param string $feature_id Feature identifier.
	 * @return bool
	 */
	public function is_enabled( $feature_id ) {
		if ( ! isset( $this->features[ $feature_id ] ) ) {
			return false;
		}

		$saved_states = get_option( self::OPTION_NAME, array() );
		if ( is_array( $saved_states ) && array_key_exists( $feature_id, $saved_states ) ) {
			return (bool) $saved_states[ $feature_id ];
		}

		return ! empty( $this->features[ $feature_id ]['default_enabled'] );
	}

	/**
	 * Update the state of a single feature.
	 *
	 * @param string $feature_id Feature identifier.
	 * @param bool   $enabled    Target state.
	 * @return bool True on valid feature, false otherwise.
	 */
	public function set_feature_state( $feature_id, $enabled ) {
		if ( ! isset( $this->features[ $feature_id ] ) ) {
			return false;
		}

		$saved_states = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $saved_states ) ) {
			$saved_states = array();
		}

		$saved_states[ $feature_id ] = $enabled ? 1 : 0;
		update_option( self::OPTION_NAME, $saved_states, true );

		return true;
	}

	/**
	 * Save states for all registered features at once (from form submission).
	 *
	 * @param array $enabled_ids List of feature IDs that are checked.
	 */
	public function save_all_states( $enabled_ids ) {
		$enabled_map  = array_fill_keys( (array) $enabled_ids, true );
		$saved_states = array();

		foreach ( $this->features as $feature_id => $feature ) {
			$saved_states[ $feature_id ] = isset( $enabled_map[ $feature_id ] ) ? 1 : 0;
		}

		update_option( self::OPTION_NAME, $saved_states, true );
	}
}
