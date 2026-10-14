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
