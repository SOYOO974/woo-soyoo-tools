<?php
/**
 * Feature: Guest Customer Onboarding on My Account, HPOS Order Linking & Anti-Account-Takeover Security.
 *
 * Solves two structural WooCommerce issues:
 * 1. UX / Support friction: Customers who previously bought as guests (`customer_id = 0`) try to log in
 *    or reset their password on `/mon-compte/` and hit "Unknown email address" (`authenticate` &
 *    `lostpassword_post` inside `WC_Shortcode_My_Account::retrieve_password()`). Replaces the dead-end
 *    error with an actionable, emoji-free onboarding card and client-side `#reg_email` pre-fill
 *    (zero server-side PII on GET for Cloudflare Edge Cache compatibility).
 * 2. Security vulnerability: When `woocommerce_registration_generate_password = yes`,
 *    `WC_Form_Handler::process_registration()` logs the browser in immediately via cookie BEFORE email
 *    ownership is verified. Combined with automatic guest order linking on `woocommerce_created_customer`,
 *    any third party typing a customer's email would gain instant access to their billing/shipping
 *    addresses, invoices, and loyalty coupons. Blocks immediate session creation via
 *    `woocommerce_registration_auth_new_customer` whenever prior store history exists.
 *
 * Includes an automatic anti-duplicate guard if the active theme already implements
 * `jd_authenticate_guest_customer_check` (Jardin Naturel).
 *
 * @package WooSoyooTools
 */

namespace SOYOO\Tools\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Feature_Guest_Account_Onboarding
 */
class Feature_Guest_Account_Onboarding {

	/**
	 * Daily cron hook for lightweight retroactive linking of `customer_id = 0` orders.
	 */
	const DAILY_CRON_HOOK = 'soyoo_tools_guest_orders_link_cron';

	/**
	 * 24-hour witness transient to avoid calling `wp_next_scheduled()` on every request.
	 */
	const SCHEDULE_TRANSIENT = 'soyoo_tools_guest_onboarding_cron_checked';

	/**
	 * Non-autoloaded option storing the current pagination cursor for the daily 200-order batch.
	 */
	const CURSOR_OPTION = 'soyoo_tools_guest_orders_sync_page';

	/**
	 * Maximum guest-order lookups per IP within a 15-minute window.
	 */
	const RATE_LIMIT_MAX = 8;

	/**
	 * Daily batch size for `customer_id = 0` order reconciliation.
	 */
	const DAILY_BATCH_LIMIT = 200;

	/**
	 * Holds the latest guest login notice HTML during the current request so security plugins
	 * filtering `login_errors` cannot overwrite it with a generic error string.
	 *
	 * @var string
	 */
	private static $current_guest_login_notice = '';

	/**
	 * Register feature bootstrap.
	 *
	 * Defers hook attachment to `after_setup_theme` (priority 20) so the active theme's
	 * `functions.php` has already loaded before checking `jd_authenticate_guest_customer_check`.
	 */
	public static function register() {
		if ( did_action( 'after_setup_theme' ) ) {
			self::boot_after_theme();
		} else {
			add_action( 'after_setup_theme', array( __CLASS__, 'boot_after_theme' ), 20 );
		}
	}

	/**
	 * Check whether the active theme already provides the Jardin Naturel implementation.
	 *
	 * @return bool
	 */
	public static function is_superseded_by_theme() {
		return function_exists( 'jd_authenticate_guest_customer_check' )
			|| false !== has_filter( 'authenticate', 'jd_authenticate_guest_customer_check' );
	}

	/**
	 * Attach runtime hooks if not already implemented by the active theme.
	 */
	public static function boot_after_theme() {
		if ( self::is_superseded_by_theme() ) {
			return;
		}

		// 1. HPOS retroactive guest order linking upon registration + lightweight daily batch of 200 orders.
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'link_past_guest_orders_on_registration' ), 20, 1 );
		add_action( 'init', array( __CLASS__, 'maybe_schedule_daily_batch_cron' ), 35 );
		add_action( self::DAILY_CRON_HOOK, array( __CLASS__, 'run_daily_guest_orders_link_batch' ) );

		// 2. Guest history detection on Login (`authenticate` prio 30) & Lost Password (`lostpassword_post` prio 10).
		add_filter( 'authenticate', array( __CLASS__, 'intercept_login_guest_check' ), 30, 3 );
		add_filter( 'login_errors', array( __CLASS__, 'preserve_guest_login_notice' ), 999, 1 );
		add_action( 'lostpassword_post', array( __CLASS__, 'intercept_lostpassword_guest_check' ), 10, 2 );
		add_filter( 'woocommerce_lost_password_message', array( __CLASS__, 'append_lost_password_guest_hint' ), 10, 1 );

		// 3. Anti-account-takeover gate on unverified My Account registrations.
		add_filter( 'woocommerce_registration_auth_new_customer', array( __CLASS__, 'protect_existing_history_registration_autologin' ), 20, 2 );

		// 4. Emoji-free notice styling & client-side `#reg_email` pre-fill (zero PII on GET).
		add_action( 'wp_head', array( __CLASS__, 'render_account_onboarding_styles' ), 30 );
		add_action( 'wp_footer', array( __CLASS__, 'render_account_onboarding_script' ), 20 );
	}

	/**
	 * Extract visitor IP safely from Cloudflare Enterprise / Rocket.net (`HTTP_CF_CONNECTING_IP`)
	 * or `REMOTE_ADDR` (never trusting spoofable `HTTP_X_FORWARDED_FOR`).
	 *
	 * @return string
	 */
	public static function get_trusted_client_ip() {
		$headers = array( 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR' );
		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = trim( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Enforce lightweight per-IP rate limiting (max 8 guest lookups / 15 min) via transient
	 * to prevent automated email enumeration without impacting legitimate shoppers.
	 *
	 * @return bool True if lookup is permitted, false if rate-limited.
	 */
	public static function allow_guest_lookup_rate_limit() {
		$key   = 'soyoo_guest_chk_' . md5( self::get_trusted_client_ip() );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			return false;
		}

		set_transient( $key, $count + 1, 15 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Check whether an email address has placed at least one guest order while owning no WP user yet.
	 *
	 * @param string $email Email address.
	 * @return bool
	 */
	public static function email_has_guest_orders( $email ) {
		if ( ! is_string( $email ) || ! is_email( $email ) || ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}

		$clean_email = sanitize_email( strtolower( trim( $email ) ) );
		if ( empty( $clean_email ) || email_exists( $clean_email ) ) {
			return false;
		}

		$orders = wc_get_orders(
			array(
				'type'          => 'shop_order',
				'billing_email' => $clean_email,
				'limit'         => 1,
				'return'        => 'ids',
			)
		);

		return ! empty( $orders );
	}

	/**
	 * Check whether an email address already holds prior store history (at least 1 order
	 * or 1 nominative `shop_coupon`), regardless of whether the WP user was just created.
	 *
	 * @param string $email Customer email address.
	 * @return bool
	 */
	public static function email_has_existing_store_history( $email ) {
		if ( ! is_string( $email ) || ! is_email( $email ) || ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}

		$clean_email = sanitize_email( strtolower( trim( $email ) ) );
		if ( empty( $clean_email ) ) {
			return false;
		}

		$orders = wc_get_orders(
			array(
				'type'          => 'shop_order',
				'billing_email' => $clean_email,
				'limit'         => 1,
				'return'        => 'ids',
			)
		);

		if ( ! empty( $orders ) ) {
			return true;
		}

		$coupon_ids = get_posts(
			array(
				'post_type'              => 'shop_coupon',
				'post_status'            => 'publish',
				'posts_per_page'         => 10,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => 'customer_email',
						'value'   => $clean_email,
						'compare' => 'LIKE',
					),
				),
			)
		);

		foreach ( $coupon_ids as $coupon_id ) {
			$raw_meta = get_post_meta( (int) $coupon_id, 'customer_email', true );
			$emails   = is_array( $raw_meta )
				? $raw_meta
				: array_map( 'trim', explode( ',', (string) $raw_meta ) );
			$emails   = array_map( 'strtolower', array_filter( array_map( 'strval', $emails ) ) );

			if ( in_array( $clean_email, $emails, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Link any unlinked past guest orders (`customer_id = 0`) matching the newly registered customer's email.
	 * Fully HPOS-compatible (`wc_get_orders`) and idempotent alongside `wc_update_new_customer_past_orders()`.
	 *
	 * @param int $customer_id Newly created WordPress user ID.
	 * @return int Number of orders linked.
	 */
	public static function link_past_guest_orders_on_registration( $customer_id ) {
		$customer_id = absint( $customer_id );
		if ( ! $customer_id || ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$user = get_userdata( $customer_id );
		if ( ! ( $user instanceof \WP_User ) || empty( $user->user_email ) ) {
			return 0;
		}

		$clean_email = sanitize_email( strtolower( trim( $user->user_email ) ) );
		if ( empty( $clean_email ) ) {
			return 0;
		}

		$guest_orders = wc_get_orders(
			array(
				'type'          => 'shop_order',
				'billing_email' => $clean_email,
				'customer_id'   => 0,
				'limit'         => -1,
				'return'        => 'objects',
			)
		);

		$linked = 0;
		if ( is_array( $guest_orders ) ) {
			foreach ( $guest_orders as $order ) {
				if ( is_object( $order ) && is_a( $order, 'WC_Order' ) && 0 === (int) $order->get_customer_id() ) {
					$order->set_customer_id( $customer_id );
					$order->save();
					++$linked;
				}
			}
		}

		return $linked;
	}

	/**
	 * Schedule the lightweight daily cron at 04:00 site local time (`wp_timezone()`).
	 */
	public static function maybe_schedule_daily_batch_cron() {
		if ( false !== get_transient( self::SCHEDULE_TRANSIENT ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::DAILY_CRON_HOOK ) ) {
			$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
			$now      = new \DateTimeImmutable( 'now', $timezone );
			$next_run = $now->setTime( 4, 0, 0 );

			if ( $next_run <= $now ) {
				$next_run = $next_run->modify( '+1 day' );
			}

			wp_schedule_event( $next_run->getTimestamp(), 'daily', self::DAILY_CRON_HOOK );
		}

		set_transient( self::SCHEDULE_TRANSIENT, 1, DAY_IN_SECONDS );
	}

	/**
	 * Daily lightweight reconciliation batch: inspects up to 200 orders with `customer_id = 0`
	 * using a rotating page cursor so stores with > 200 genuine guest orders sweep their entire
	 * history across days without getting stuck on the first 200 unregistered guests.
	 *
	 * @return int Number of orders linked during this batch.
	 */
	public static function run_daily_guest_orders_link_batch() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$start_time = microtime( true );
		$page       = max( 1, (int) get_option( self::CURSOR_OPTION, 1 ) );

		$orders = wc_get_orders(
			array(
				'type'        => 'shop_order',
				'customer_id' => 0,
				'limit'       => self::DAILY_BATCH_LIMIT,
				'paged'       => $page,
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'return'      => 'objects',
			)
		);

		if ( empty( $orders ) || ! is_array( $orders ) ) {
			if ( 1 !== $page ) {
				update_option( self::CURSOR_OPTION, 1, false );
			}
			return 0;
		}

		$email_to_user_id = array();
		$linked_count     = 0;

		foreach ( $orders as $order ) {
			if ( ( microtime( true ) - $start_time ) > 20 ) {
				break;
			}

			if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order' ) || 0 !== (int) $order->get_customer_id() ) {
				continue;
			}

			$email = sanitize_email( strtolower( trim( (string) $order->get_billing_email() ) ) );
			if ( empty( $email ) || ! is_email( $email ) ) {
				continue;
			}

			if ( ! array_key_exists( $email, $email_to_user_id ) ) {
				$user                       = get_user_by( 'email', $email );
				$email_to_user_id[ $email ] = ( $user instanceof \WP_User && $user->ID > 0 ) ? (int) $user->ID : 0;
			}

			$matched_user_id = $email_to_user_id[ $email ];
			if ( $matched_user_id > 0 ) {
				$order->set_customer_id( $matched_user_id );
				$order->save();
				++$linked_count;
			}
		}

		// Advance or wrap around the pagination cursor so every `customer_id = 0` order is visited across runs.
		$next_page = ( count( $orders ) < self::DAILY_BATCH_LIMIT ) ? 1 : ( $page + 1 );
		update_option( self::CURSOR_OPTION, $next_page, false );

		if ( $linked_count > 0 && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info(
				sprintf(
					'Rattachement quotidien HPOS : %d commande(s) invitée(s) associée(s) à un compte client existant (lot page %d).',
					$linked_count,
					$page
				),
				array( 'source' => 'woo-soyoo-tools' )
			);
		}

		return $linked_count;
	}

	/**
	 * Anti-Account-Takeover Security Lock (`woocommerce_registration_auth_new_customer`):
	 * Outside checkout (`! is_checkout()`), if the newly registered email ALREADY owns at least
	 * 1 past order or 1 nominative coupon, block immediate cookie auto-login (`return false`) and
	 * instruct the customer to click the verification / password setup link sent to their inbox.
	 *
	 * Brand-new customers (0 past orders, 0 nominative coupons) continue to auto-login seamlessly.
	 *
	 * @param bool $auth         Default auto-login decision.
	 * @param int  $new_customer Newly created user ID.
	 * @return bool
	 */
	public static function protect_existing_history_registration_autologin( $auth, $new_customer ) {
		if ( ! $auth ) {
			return false;
		}

		// Never interrupt active checkout order placement (standard page or `?wc-ajax=checkout`).
		if ( ( function_exists( 'is_checkout' ) && is_checkout() ) || ( defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT ) ) {
			return $auth;
		}

		$user = get_userdata( absint( $new_customer ) );
		if ( ! ( $user instanceof \WP_User ) || empty( $user->user_email ) ) {
			return $auth;
		}

		if ( ! self::email_has_existing_store_history( $user->user_email ) ) {
			return $auth;
		}

		// Hardening when `woocommerce_registration_generate_password` is disabled on a store:
		// invalidate any unverified user-supplied password and send a genuine WooCommerce reset link
		// so an attacker cannot immediately sign in with a password they just typed.
		if ( 'yes' !== get_option( 'woocommerce_registration_generate_password' ) ) {
			wp_set_password( wp_generate_password( 32, true, true ), $user->ID );
			$reset_key = get_password_reset_key( $user );
			if ( ! is_wp_error( $reset_key ) && function_exists( 'WC' ) ) {
				WC()->mailer();
				do_action( 'woocommerce_reset_password_notification', $user->user_login, $reset_key );
			}
		}

		if ( function_exists( 'wc_clear_notices' ) && function_exists( 'wc_add_notice' ) ) {
			wc_clear_notices();

			$notice_html = sprintf(
				'<div class="soyoo-guest-verify-notice">' .
					'<span class="soyoo-guest-notice__eyebrow">%s</span>' .
					'<strong class="soyoo-guest-notice__title">%s</strong>' .
					'<p class="soyoo-guest-notice__desc">%s</p>' .
				'</div>',
				esc_html__( 'Compte créé — vérification requise', 'woo-soyoo-tools' ),
				esc_html__( 'Vos anciennes commandes ont bien été rattachées à votre compte.', 'woo-soyoo-tools' ),
				sprintf(
					/* translators: %s: customer email address */
					wp_kses_post( __( 'Pour protéger vos données personnelles, cliquez sur le lien envoyé par e-mail à <strong>%s</strong> afin de définir votre mot de passe et d’accéder à votre espace client (pensez à vérifier vos courriers indésirables).', 'woo-soyoo-tools' ) ),
					esc_html( $user->user_email )
				)
			);

			/**
			 * Allow customizing the anti-takeover verification notice HTML.
			 *
			 * @param string   $notice_html Rendered HTML notice.
			 * @param \WP_User $user        Newly created customer object.
			 */
			$notice_html = (string) apply_filters( 'woo_soyoo_tools_guest_verify_notice_html', $notice_html, $user );

			wc_add_notice( $notice_html, 'success' );
		}

		return false;
	}

	/**
	 * Build the emoji-free onboarding card HTML (`wc_kses_notice()` safe) displayed when a guest
	 * shopper tries to sign in or reset a non-existent password.
	 *
	 * @param string $email   Sanitized customer email.
	 * @param string $context 'login' or 'lost_password'.
	 * @return string
	 */
	public static function get_guest_onboarding_notice_html( $email, $context = 'login' ) {
		$clean_email          = sanitize_email( $email );
		$registration_enabled = ( 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) );
		$myaccount_url        = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/mon-compte/' );

		if ( 'login' === $context ) {
			$lead_text = sprintf(
				/* translators: %s: customer email address */
				__( 'Vos commandes précédentes avec %s ont été passées en mode invité : aucun mot de passe n’est encore associé à cette adresse.', 'woo-soyoo-tools' ),
				'<strong>' . esc_html( $clean_email ) . '</strong>'
			);
		} else {
			$lead_text = sprintf(
				/* translators: %s: customer email address */
				__( 'L’adresse %s a bien été utilisée pour commander en mode invité, mais aucun compte avec mot de passe n’a encore été activé.', 'woo-soyoo-tools' ),
				'<strong>' . esc_html( $clean_email ) . '</strong>'
			);
		}

		if ( $registration_enabled ) {
			$on_account_login = ( 'login' === $context && function_exists( 'is_account_page' ) && is_account_page() );
			$target_url       = $on_account_login
				? '#soyoo-register'
				: add_query_arg( array( 'soyoo_register_email' => rawurlencode( $clean_email ) ), $myaccount_url ) . '#soyoo-register';

			$action_text = ( 'login' === $context )
				? __( 'Activez votre compte dans le formulaire d’inscription avec cette même adresse pour retrouver automatiquement vos commandes et vos factures.', 'woo-soyoo-tools' )
				: __( 'Cliquez ci-dessous pour activer votre compte avec cette même adresse et retrouver automatiquement vos commandes et vos factures.', 'woo-soyoo-tools' );

			$html = sprintf(
				'<div class="soyoo-guest-notice">' .
					'<span class="soyoo-guest-notice__eyebrow">%s</span>' .
					'<strong class="soyoo-guest-notice__title">%s</strong>' .
					'<p class="soyoo-guest-notice__desc">%s %s</p>' .
					'<a href="%s" class="soyoo-guest-notice__btn" data-guest-email="%s">%s</a>' .
				'</div>',
				esc_html__( 'Commande passée en mode invité', 'woo-soyoo-tools' ),
				esc_html__( 'Il vous suffit d’activer votre espace client', 'woo-soyoo-tools' ),
				$lead_text,
				esc_html( $action_text ),
				esc_url( $target_url ),
				esc_attr( $clean_email ),
				esc_html__( 'Activer mon compte avec cet e-mail', 'woo-soyoo-tools' )
			);
		} else {
			$fallback_text = ( 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout' ) )
				? __( 'Vous pourrez activer votre espace client en cochant « Créer un compte » lors de votre prochaine commande avec cette même adresse e-mail.', 'woo-soyoo-tools' )
				: __( 'Vos confirmations et détails de commande restent consultables directement dans les e-mails reçus lors de votre achat.', 'woo-soyoo-tools' );

			$html = sprintf(
				'<div class="soyoo-guest-notice">' .
					'<span class="soyoo-guest-notice__eyebrow">%s</span>' .
					'<strong class="soyoo-guest-notice__title">%s</strong>' .
					'<p class="soyoo-guest-notice__desc">%s %s</p>' .
				'</div>',
				esc_html__( 'Commande passée en mode invité', 'woo-soyoo-tools' ),
				esc_html__( 'Aucun compte n’a encore été créé pour cette adresse', 'woo-soyoo-tools' ),
				$lead_text,
				esc_html( $fallback_text )
			);
		}

		/**
		 * Allow customizing the guest onboarding notice HTML.
		 *
		 * @param string $html        Rendered HTML notice.
		 * @param string $clean_email Sanitized guest email.
		 * @param string $context     'login' or 'lost_password'.
		 */
		return (string) apply_filters( 'woo_soyoo_tools_guest_onboarding_notice_html', $html, $clean_email, $context );
	}

	/**
	 * Intercept failed login attempts (`authenticate`, priority 30) for `invalid_email`,
	 * `invalid_username`, or `empty_password` when the submitted email owns past guest orders.
	 *
	 * @param \WP_User|\WP_Error|null $user     Authenticated user or error.
	 * @param string                  $username Submitted username or email.
	 * @param string                  $password Submitted password.
	 * @return \WP_User|\WP_Error|null
	 */
	public static function intercept_login_guest_check( $user, $username, $password ) {
		if ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || wp_doing_cron() ) {
			return $user;
		}

		if ( ! is_wp_error( $user ) ) {
			return $user;
		}

		$target_codes = array( 'invalid_email', 'invalid_username', 'empty_password' );
		if ( empty( array_intersect( $user->get_error_codes(), $target_codes ) ) ) {
			return $user;
		}

		$raw_login = is_string( $username ) ? trim( wp_unslash( $username ) ) : '';
		if ( empty( $raw_login ) || ! is_email( $raw_login ) ) {
			return $user;
		}

		$clean_email = sanitize_email( strtolower( $raw_login ) );
		if ( empty( $clean_email ) || email_exists( $clean_email ) || ! self::allow_guest_lookup_rate_limit() ) {
			return $user;
		}

		if ( self::email_has_guest_orders( $clean_email ) ) {
			$notice_html                      = self::get_guest_onboarding_notice_html( $clean_email, 'login' );
			self::$current_guest_login_notice = $notice_html;
			return new \WP_Error( 'soyoo_guest_account', $notice_html );
		}

		return $user;
	}

	/**
	 * Preserve our structured guest onboarding notice if a security plugin filters `login_errors`.
	 *
	 * @param string $error Filtered error message.
	 * @return string
	 */
	public static function preserve_guest_login_notice( $error ) {
		if ( ! empty( self::$current_guest_login_notice ) ) {
			$notice                           = self::$current_guest_login_notice;
			self::$current_guest_login_notice = '';
			return $notice;
		}

		return $error;
	}

	/**
	 * Intercept WooCommerce Lost Password submissions (`lostpassword_post`, priority 10).
	 * Note: `WC_Shortcode_My_Account::retrieve_password()` fires `lostpassword_post` and NOT `lostpassword_errors`.
	 *
	 * @param \WP_Error      $errors    Validation error object passed by reference.
	 * @param \WP_User|false $user_data Resolved user object or false when unknown.
	 */
	public static function intercept_lostpassword_guest_check( $errors, $user_data = false ) {
		if ( $user_data || ! ( $errors instanceof \WP_Error ) ) {
			return;
		}

		$raw_login = isset( $_POST['user_login'] ) && is_string( $_POST['user_login'] )
			? trim( sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified upstream in WC_Form_Handler::process_lost_password().
			: '';

		if ( empty( $raw_login ) || ! is_email( $raw_login ) ) {
			return;
		}

		$clean_email = sanitize_email( strtolower( $raw_login ) );
		if ( empty( $clean_email ) || email_exists( $clean_email ) || ! self::allow_guest_lookup_rate_limit() ) {
			return;
		}

		if ( self::email_has_guest_orders( $clean_email ) ) {
			$errors->add( 'soyoo_guest_account', self::get_guest_onboarding_notice_html( $clean_email, 'lost_password' ) );
		}
	}

	/**
	 * Add a discrete preventive hint on `/mon-compte/lost-password/` when My Account registration is enabled.
	 *
	 * @param string $message Default lost password intro message.
	 * @return string
	 */
	public static function append_lost_password_guest_hint( $message ) {
		if ( 'yes' !== get_option( 'woocommerce_enable_myaccount_registration' ) || ! function_exists( 'wc_get_page_permalink' ) ) {
			return $message;
		}

		$register_url = wc_get_page_permalink( 'myaccount' ) . '#soyoo-register';
		$hint         = sprintf(
			'<span class="soyoo-lostpw-guest-hint">%s <a href="%s">%s</a>.</span>',
			esc_html__( 'Vous aviez commandé sans créer de compte ?', 'woo-soyoo-tools' ),
			esc_url( $register_url ),
			esc_html__( 'Activez votre espace client dans « S’inscrire » avec la même adresse e-mail', 'woo-soyoo-tools' )
		);

		return $message . ' ' . $hint;
	}

	/**
	 * Determine whether lightweight inline styles should be output on the current logged-out page
	 * without ever initiating a WooCommerce session cookie on GET requests.
	 *
	 * @return bool
	 */
	private static function should_output_styles() {
		if ( is_user_logged_in() ) {
			return false;
		}

		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}

		if ( function_exists( 'WC' ) && isset( WC()->session ) && WC()->session && WC()->session->has_session() && function_exists( 'wc_notice_count' ) && wc_notice_count() > 0 ) {
			return true;
		}

		return false;
	}

	/**
	 * Print clean, theme-agnostic, emoji-free CSS for `.soyoo-guest-notice` and `.soyoo-guest-verify-notice`.
	 */
	public static function render_account_onboarding_styles() {
		if ( ! self::should_output_styles() ) {
			return;
		}
		?>
		<style id="woo-soyoo-guest-onboarding-css">
		.woocommerce-error:has(.soyoo-guest-notice),
		.woocommerce-message:has(.soyoo-guest-verify-notice),
		.wc-block-components-notice-banner:has(.soyoo-guest-notice),
		.wc-block-components-notice-banner:has(.soyoo-guest-verify-notice) {
			background: #f8fafc !important;
			color: #0f172a !important;
			border: 1px solid #cbd5e1 !important;
			border-left: 3px solid #0f172a !important;
			border-radius: 8px !important;
			padding: 1.125rem 1.375rem !important;
			box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05) !important;
			list-style: none !important;
		}
		.woocommerce-message:has(.soyoo-guest-verify-notice),
		.wc-block-components-notice-banner:has(.soyoo-guest-verify-notice) {
			border-left-color: #15803d !important;
		}
		.woocommerce-error:has(.soyoo-guest-notice)::before,
		.woocommerce-message:has(.soyoo-guest-verify-notice)::before,
		.wc-block-components-notice-banner:has(.soyoo-guest-notice) > svg,
		.wc-block-components-notice-banner:has(.soyoo-guest-verify-notice) > svg {
			content: none !important;
			display: none !important;
		}
		.woocommerce-error:has(.soyoo-guest-notice) li {
			margin: 0 !important;
			padding: 0 !important;
			list-style: none !important;
		}
		.soyoo-guest-notice,
		.soyoo-guest-verify-notice {
			display: flex;
			flex-direction: column;
			align-items: flex-start;
			gap: 0.375rem;
			text-align: left;
		}
		.soyoo-guest-notice__eyebrow {
			display: block;
			font-size: 0.7rem;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.14em;
			color: #475569;
			line-height: 1.3;
		}
		.soyoo-guest-verify-notice .soyoo-guest-notice__eyebrow {
			color: #15803d;
		}
		.soyoo-guest-notice__title {
			display: block;
			font-size: 1.05rem;
			font-weight: 600;
			color: #0f172a;
			line-height: 1.35;
		}
		.soyoo-guest-notice__desc {
			margin: 0.125rem 0 0 !important;
			font-size: 0.875rem;
			line-height: 1.55;
			color: #334155;
		}
		.soyoo-guest-notice__desc strong {
			color: #0f172a;
			font-weight: 600;
		}
		.soyoo-guest-notice__btn {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			margin-top: 0.5rem;
			padding: 0.625rem 1.15rem;
			border-radius: 6px;
			background: #0f172a !important;
			color: #ffffff !important;
			font-size: 0.8125rem;
			font-weight: 600;
			line-height: 1.25;
			text-decoration: none !important;
			transition: opacity 0.15s ease;
		}
		.soyoo-guest-notice__btn:hover,
		.soyoo-guest-notice__btn:focus {
			opacity: 0.9;
			color: #ffffff !important;
		}
		.soyoo-register-highlight {
			box-shadow: 0 0 0 2px #0f172a !important;
			border-radius: 8px;
			transition: box-shadow 0.25s ease;
		}
		</style>
		<?php
	}

	/**
	 * Print static client-side JS on logged-out My Account pages to pre-fill `#reg_email`
	 * from `[data-guest-email]` (POST response) or `?soyoo_register_email=` (lost-password redirect).
	 *
	 * Never echoes GET query parameters server-side, keeping HTML 100% Cloudflare Edge Cache safe.
	 */
	public static function render_account_onboarding_script() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || is_user_logged_in() ) {
			return;
		}
		?>
		<script id="woo-soyoo-guest-onboarding-js">
		(function () {
			var regInput = document.getElementById('reg_email') || document.querySelector('form.woocommerce-form-register input[name="email"], form.register input[name="email"]');
			if (!regInput) {
				return;
			}
			var regForm = regInput.closest('form') || regInput;
			var regCard = regInput.closest('.u-column2, .col-register') || regForm;

			function revealRegisterPanelIfHidden() {
				if (regInput.offsetParent !== null) {
					return;
				}
				var toggleBtn = document.querySelector('.wd-switch-to-register, [data-toggle="register"]');
				if (toggleBtn && typeof toggleBtn.click === 'function') {
					toggleBtn.click();
				}
			}

			function setEmailValue(email) {
				if (!email || email.indexOf('@') < 1 || email.length > 254) {
					return;
				}
				regInput.value = email;
				try {
					regInput.dispatchEvent(new Event('input', { bubbles: true }));
					regInput.dispatchEvent(new Event('change', { bubbles: true }));
				} catch (e) {}
			}

			function focusRegisterCard(email, shouldScroll) {
				revealRegisterPanelIfHidden();
				if (email) {
					setEmailValue(email);
				}
				if (regCard) {
					regCard.classList.add('soyoo-register-highlight');
				}
				if (shouldScroll && regCard) {
					regCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
					var passInput = regForm.querySelector('#reg_password, input[name="password"]');
					var submitBtn = regForm.querySelector('button[type="submit"], input[type="submit"]');
					setTimeout(function () {
						var target = (passInput && passInput.offsetParent !== null) ? passInput : (email && submitBtn ? submitBtn : regInput);
						if (target && typeof target.focus === 'function') {
							target.focus({ preventScroll: true });
						}
					}, 320);
				}
			}

			var triggerBtn = document.querySelector('.soyoo-guest-notice__btn[data-guest-email]');
			if (triggerBtn) {
				var noticeEmail = triggerBtn.getAttribute('data-guest-email') || '';
				if (noticeEmail && !regInput.value) {
					setEmailValue(noticeEmail);
				}
				triggerBtn.addEventListener('click', function (e) {
					e.preventDefault();
					focusRegisterCard(noticeEmail, true);
				});
			}

			try {
				var params = new URLSearchParams(window.location.search);
				var urlEmail = params.get('soyoo_register_email');
				if (urlEmail) {
					params.delete('soyoo_register_email');
					var cleanSearch = params.toString();
					var cleanHash = (window.location.hash === '#soyoo-register') ? '' : window.location.hash;
					var cleanUrl = window.location.pathname + (cleanSearch ? '?' + cleanSearch : '') + cleanHash;
					window.history.replaceState({}, '', cleanUrl);
					focusRegisterCard(urlEmail, true);
				} else if (window.location.hash === '#soyoo-register') {
					focusRegisterCard('', true);
				}
			} catch (err) {}
		})();
		</script>
		<?php
	}
}
