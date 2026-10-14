<?php
/**
 * Feature: Force French Locale on WooCommerce Transactional & Admin Emails.
 *
 * Prevents WooCommerce "New Order" (and other transactional/admin) emails from being
 * sent in English when deferred to Action Scheduler, triggered via payment gateway
 * webhooks (Stripe, PayPlug, Alma, PayPal), WP-Cron, REST API, or when an admin user
 * profile has a non-French locale.
 *
 * @package WooSoyooTools
 */

namespace SOYOO\Tools\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Feature_Force_French_Emails
 */
class Feature_Force_French_Emails {

	/**
	 * Track how many times we switched locale to restore cleanly without unbalancing WP's stack.
	 *
	 * @var int
	 */
	private static $switch_depth = 0;

	/**
	 * Boot the feature hooks.
	 */
	public static function register() {
		// 1. Action Scheduler / Queued transactional emails (Core WooCommerce hook is `woocommerce_deliver_queued_transactional_email` at prio 10).
		// Executing at priority 1 guarantees we switch locale BEFORE `WC_Emails::instance()` is instantiated!
		add_action( 'woocommerce_deliver_queued_transactional_email', array( __CLASS__, 'switch_to_site_french_locale' ), 1 );
		add_action( 'woocommerce_deliver_queued_transactional_email', array( __CLASS__, 'restore_locale_safely' ), 99 );

		// Also hook into `woocommerce_allow_send_queued_transactional_email` filter which runs right before `WC_Emails::instance()` inside `send_queued_transactional_email()`.
		add_filter( 'woocommerce_allow_send_queued_transactional_email', array( __CLASS__, 'filter_allow_queued_email_switch_locale' ), 1, 3 );

		// Compatibility hook in case a custom snippet dispatches `woocommerce_send_queued_transactional_email`.
		add_action( 'woocommerce_send_queued_transactional_email', array( __CLASS__, 'switch_to_site_french_locale' ), 1 );
		add_action( 'woocommerce_send_queued_transactional_email', array( __CLASS__, 'restore_locale_safely' ), 99 );

		// 2. Manual order email resends from Admin / HPOS Order screen.
		add_action( 'woocommerce_before_resend_order_emails', array( __CLASS__, 'switch_to_site_french_locale' ), 1 );
		add_action( 'woocommerce_after_resend_order_emails', array( __CLASS__, 'restore_locale_safely' ), 99 );

		// 3. Synchronous / Webhook / REST order status transitions (Stripe, PayPlug, Alma, etc.).
		// WooCommerce's `WC_Email::setup_locale()` only calls `wc_switch_to_site_locale()` when `$this->is_customer_email()` is true,
		// leaving Admin emails (`New Order`, `Cancelled Order`, `Failed Order`) in whatever locale the current webhook/cron/user has.
		add_filter( 'woocommerce_email_setup_locale', array( __CLASS__, 'on_wc_email_setup_locale' ), 10, 1 );
		add_filter( 'woocommerce_email_restore_locale', array( __CLASS__, 'on_wc_email_restore_locale' ), 10, 1 );

		// Wrap all registered WooCommerce transactional email notification hooks (`*_notification`).
		add_action( 'woocommerce_init', array( __CLASS__, 'wrap_transactional_notification_hooks' ), 99 );

		// 4. Guard against early `WC_Emails::__construct()` English caching of default Subject & Heading on Admin Emails.
		$admin_email_ids = array( 'new_order', 'cancelled_order', 'failed_order' );
		foreach ( $admin_email_ids as $email_id ) {
			add_filter( "woocommerce_email_subject_{$email_id}", array( __CLASS__, 'ensure_translated_admin_subject' ), 10, 3 );
			add_filter( "woocommerce_email_heading_{$email_id}", array( __CLASS__, 'ensure_translated_admin_heading' ), 10, 3 );
		}
	}

	/**
	 * Determine the target French / site locale.
	 *
	 * @return string
	 */
	public static function get_target_locale() {
		$wplang = get_option( 'WPLANG' );
		if ( ! empty( $wplang ) && is_string( $wplang ) && 0 !== strpos( $wplang, 'en_' ) ) {
			return $wplang;
		}
		return 'fr_FR';
	}

	/**
	 * Switch to the site's French locale and ensure the `woocommerce` textdomain is loaded in French.
	 */
	public static function switch_to_site_french_locale() {
		$target_locale = self::get_target_locale();

		if ( function_exists( 'switch_to_locale' ) ) {
			$switched = switch_to_locale( $target_locale );
			if ( $switched ) {
				self::$switch_depth++;
			}
		}

		self::ensure_woocommerce_textdomain_loaded( $target_locale );
	}

	/**
	 * Restore previous locale only if we previously switched it.
	 */
	public static function restore_locale_safely() {
		if ( self::$switch_depth > 0 && function_exists( 'restore_previous_locale' ) ) {
			restore_previous_locale();
			self::$switch_depth--;
		}
	}

	/**
	 * Ensure the `woocommerce` translations are loaded for the target locale during background/CLI/webhook execution.
	 *
	 * @param string $locale Target locale (e.g. 'fr_FR').
	 */
	private static function ensure_woocommerce_textdomain_loaded( $locale ) {
		// Fast check: if "New order" is already translated, nothing to reload.
		if ( 'New order' !== __( 'New order', 'woocommerce' ) ) {
			return;
		}

		// Try reloading via WooCommerce's native method or explicit .l10n.php / .mo file in WP_LANG_DIR.
		if ( function_exists( 'WC' ) && is_object( WC() ) && method_exists( WC(), 'load_plugin_textdomain' ) ) {
			unload_textdomain( 'woocommerce', true );
			WC()->load_plugin_textdomain();
		}

		if ( 'New order' === __( 'New order', 'woocommerce' ) && defined( 'WP_LANG_DIR' ) ) {
			$mofile = WP_LANG_DIR . '/plugins/woocommerce-' . $locale . '.mo';
			if ( file_exists( $mofile ) ) {
				load_textdomain( 'woocommerce', $mofile, $locale );
			}
		}
	}

	/**
	 * Filter `woocommerce_allow_send_queued_transactional_email` to ensure locale is switched before `WC_Emails::instance()`.
	 *
	 * @param bool   $allow  Whether to allow sending.
	 * @param string $filter Email action filter name.
	 * @param array  $args   Action arguments.
	 * @return bool
	 */
	public static function filter_allow_queued_email_switch_locale( $allow, $filter = '', $args = array() ) {
		if ( $allow ) {
			self::switch_to_site_french_locale();
		}
		return $allow;
	}

	/**
	 * Hook into `woocommerce_email_setup_locale` filter so every `WC_Email::setup_locale()` call also switches our locale.
	 *
	 * @param bool $setup Whether WC should setup locale.
	 * @return bool
	 */
	public static function on_wc_email_setup_locale( $setup ) {
		self::switch_to_site_french_locale();
		return $setup;
	}

	/**
	 * Hook into `woocommerce_email_restore_locale` filter to balance `on_wc_email_setup_locale`.
	 *
	 * @param bool $restore Whether WC should restore locale.
	 * @return bool
	 */
	public static function on_wc_email_restore_locale( $restore ) {
		self::restore_locale_safely();
		return $restore;
	}

	/**
	 * Wrap all `*_notification` hooks registered by WooCommerce transactional emails
	 * so that even Admin emails (`is_customer_email() === false`) run inside the French locale.
	 */
	public static function wrap_transactional_notification_hooks() {
		$email_actions = apply_filters(
			'woocommerce_email_actions',
			array(
				'woocommerce_low_stock',
				'woocommerce_no_stock',
				'woocommerce_product_on_backorder',
				'woocommerce_order_status_pending_to_processing',
				'woocommerce_order_status_pending_to_completed',
				'woocommerce_order_status_processing_to_cancelled',
				'woocommerce_order_status_pending_to_failed',
				'woocommerce_order_status_pending_to_on-hold',
				'woocommerce_order_status_failed_to_processing',
				'woocommerce_order_status_failed_to_completed',
				'woocommerce_order_status_failed_to_on-hold',
				'woocommerce_order_status_cancelled_to_processing',
				'woocommerce_order_status_cancelled_to_completed',
				'woocommerce_order_status_cancelled_to_on-hold',
				'woocommerce_order_status_on-hold_to_processing',
				'woocommerce_order_status_on-hold_to_cancelled',
				'woocommerce_order_status_on-hold_to_failed',
				'woocommerce_order_status_completed',
				'woocommerce_order_status_failed',
				'woocommerce_order_fully_refunded',
				'woocommerce_order_partially_refunded',
				'woocommerce_new_customer_note',
				'woocommerce_created_customer',
			)
		);

		if ( ! is_array( $email_actions ) ) {
			return;
		}

		foreach ( $email_actions as $action ) {
			add_action( $action . '_notification', array( __CLASS__, 'switch_to_site_french_locale' ), 1 );
			add_action( $action . '_notification', array( __CLASS__, 'restore_locale_safely' ), 999 );
		}
	}

	/**
	 * Re-translate English default subjects if `WC_Email` was constructed before the locale switch.
	 *
	 * @param string    $subject Formatted email subject.
	 * @param \WC_Order $order   Order object.
	 * @param \WC_Email $email   Email instance.
	 * @return string
	 */
	public static function ensure_translated_admin_subject( $subject, $order = null, $email = null ) {
		if ( ! is_string( $subject ) || ! is_object( $email ) || ! method_exists( $email, 'format_string' ) ) {
			return $subject;
		}

		self::ensure_woocommerce_textdomain_loaded( self::get_target_locale() );

		$english_patterns = array(
			'New order #'          => __( '[{site_title}]: New order #{order_number}', 'woocommerce' ),
			'Cancelled order #'    => __( '[{site_title}]: Cancelled order #{order_number}', 'woocommerce' ),
			'Order #'              => null,
			'has been cancelled'   => __( '[{site_title}]: Order #{order_number} has been cancelled', 'woocommerce' ),
			'Failed order #'       => __( '[{site_title}]: Failed order #{order_number}', 'woocommerce' ),
			'has failed'           => __( '[{site_title}]: Order #{order_number} has failed', 'woocommerce' ),
		);

		foreach ( $english_patterns as $needle => $translated_template ) {
			if ( null !== $translated_template && false !== stripos( $subject, $needle ) ) {
				return $email->format_string( $translated_template );
			}
		}

		return $subject;
	}

	/**
	 * Re-translate English default headings if `WC_Email` was constructed before the locale switch.
	 *
	 * @param string    $heading Formatted email heading.
	 * @param \WC_Order $order   Order object.
	 * @param \WC_Email $email   Email instance.
	 * @return string
	 */
	public static function ensure_translated_admin_heading( $heading, $order = null, $email = null ) {
		if ( ! is_string( $heading ) || ! is_object( $email ) || ! method_exists( $email, 'format_string' ) ) {
			return $heading;
		}

		self::ensure_woocommerce_textdomain_loaded( self::get_target_locale() );

		$english_headings = array(
			'New order:'       => __( 'New order: #{order_number}', 'woocommerce' ),
			'Cancelled order:' => __( 'Cancelled order: #{order_number}', 'woocommerce' ),
			'Order Cancelled:' => __( 'Order Cancelled: #{order_number}', 'woocommerce' ),
			'Failed order:'    => __( 'Failed order: #{order_number}', 'woocommerce' ),
			'Order Failed:'    => __( 'Order Failed: #{order_number}', 'woocommerce' ),
		);

		foreach ( $english_headings as $needle => $translated_template ) {
			if ( false !== stripos( $heading, $needle ) ) {
				return $email->format_string( $translated_template );
			}
		}

		return $heading;
	}
}
