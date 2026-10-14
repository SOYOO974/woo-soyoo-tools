<?php
/**
 * Feature: Asynchronous Geo-IP Security Alert on Administrator Logins.
 *
 * Detects successful logins to `administrator` accounts from unusual countries without
 * slowing down the login request and without trusting spoofable `X-Forwarded-For` headers.
 * Dispatches an asynchronous single event (`soyoo_tools_verify_admin_login_ip`) and alerts
 * `julien@soyoo.re` (filterable) with explicit site identification in both subject and body.
 *
 * @package WooSoyooTools
 */

namespace SOYOO\Tools\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Feature_Admin_Login_Alert
 */
class Feature_Admin_Login_Alert {

	/**
	 * Asynchronous single-event hook name.
	 */
	const ASYNC_HOOK = 'soyoo_tools_verify_admin_login_ip';

	/**
	 * Register feature hooks.
	 */
	public static function register() {
		add_action( 'wp_login', array( __CLASS__, 'on_admin_login' ), 10, 2 );
		add_action( self::ASYNC_HOOK, array( __CLASS__, 'verify_admin_login_ip' ), 10, 3 );
	}

	/**
	 * Intercept successful logins for administrator accounts and schedule an async Geo-IP check.
	 *
	 * @param string   $user_login Username.
	 * @param \WP_User $user       WP_User object.
	 */
	public static function on_admin_login( $user_login, $user ) {
		if ( ! ( $user instanceof \WP_User ) ) {
			return;
		}

		$roles = is_array( $user->roles ) ? $user->roles : array();
		if ( ! in_array( 'administrator', $roles, true ) ) {
			return;
		}

		$user_ip = self::get_trusted_client_ip();
		if ( empty( $user_ip ) || in_array( $user_ip, array( '127.0.0.1', '::1' ), true ) ) {
			return;
		}

		$cf_country = '';
		if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			$raw_country = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
			if ( preg_match( '/^[A-Z]{2}$/', $raw_country ) && ! in_array( $raw_country, array( 'XX', 'T1' ), true ) ) {
				$cf_country = $raw_country;
			}
		}

		// Avoid scheduling duplicate events if the 15-minute cooldown lock is already set for this IP + user.
		$lock_key = 'soyoo_login_alert_' . md5( $user_ip . '|' . $user_login );
		if ( false !== get_transient( $lock_key ) ) {
			return;
		}

		wp_schedule_single_event(
			time(),
			self::ASYNC_HOOK,
			array( (string) $user_login, (string) $user_ip, (string) $cf_country )
		);
	}

	/**
	 * Extract client IP securely: only trust `HTTP_CF_CONNECTING_IP` (Cloudflare / Rocket.net)
	 * or `REMOTE_ADDR`. Never trust `HTTP_X_FORWARDED_FOR` which can be spoofed by attackers.
	 *
	 * @return string Validated IP address or empty string.
	 */
	public static function get_trusted_client_ip() {
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$cf_ip = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) );
			if ( filter_var( $cf_ip, FILTER_VALIDATE_IP ) ) {
				return $cf_ip;
			}
		}

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$remote_ip = trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) );
			if ( filter_var( $remote_ip, FILTER_VALIDATE_IP ) ) {
				return $remote_ip;
			}
		}

		return '';
	}

	/**
	 * Asynchronous worker that resolves the country for the login IP and sends an alert email if unauthorized.
	 *
	 * @param string $user_login Username.
	 * @param string $user_ip    Client IP address.
	 * @param string $cf_country Optional ISO-2 country code from Cloudflare (`HTTP_CF_IPCOUNTRY`).
	 */
	public static function verify_admin_login_ip( $user_login, $user_ip, $cf_country = '' ) {
		$user_login = sanitize_user( (string) $user_login );
		$user_ip    = trim( (string) $user_ip );
		$cf_country = strtoupper( trim( (string) $cf_country ) );

		if ( empty( $user_login ) || empty( $user_ip ) || in_array( $user_ip, array( '127.0.0.1', '::1' ), true ) ) {
			return;
		}

		$lock_key = 'soyoo_login_alert_' . md5( $user_ip . '|' . $user_login );
		if ( false !== get_transient( $lock_key ) ) {
			return;
		}

		$allowed_countries = apply_filters(
			'woo_soyoo_tools_allowed_admin_countries',
			array( 'FR', 'RE', 'PK', 'MG', 'MU', 'YT' )
		);
		$allowed_countries = array_map( 'strtoupper', (array) $allowed_countries );

		$country_code = '';
		$country_name = '';

		if ( preg_match( '/^[A-Z]{2}$/', $cf_country ) && ! in_array( $cf_country, array( 'XX', 'T1' ), true ) ) {
			$country_code = $cf_country;
			// Fast path: if Cloudflare country is already in the allowed list, exit immediately without external HTTP calls.
			if ( in_array( $country_code, $allowed_countries, true ) ) {
				return;
			}
		}

		// Resolve full country details via ip-api.com with HTTPS fallback to ipwho.is.
		$geo = self::lookup_ip_geolocation( $user_ip );
		if ( ! empty( $geo['country_code'] ) ) {
			$country_code = strtoupper( $geo['country_code'] );
		}
		if ( ! empty( $geo['country_name'] ) ) {
			$country_name = $geo['country_name'];
		}

		if ( ! empty( $country_code ) && in_array( $country_code, $allowed_countries, true ) ) {
			return;
		}

		// Lock for 15 minutes to avoid duplicate email alerts for the same IP + user.
		set_transient( $lock_key, 1, 15 * MINUTE_IN_SECONDS );

		$alert_email = apply_filters( 'woo_soyoo_tools_security_alert_email', 'julien@soyoo.re' );
		if ( empty( $alert_email ) || ! is_email( $alert_email ) ) {
			return;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$site_url  = home_url( '/' );
		$host      = wp_parse_url( $site_url, PHP_URL_HOST );
		$country_label = ! empty( $country_name ) && ! empty( $country_code )
			? sprintf( '%s (%s)', $country_name, $country_code )
			: ( ! empty( $country_code ) ? $country_code : 'Inconnu / Non résolu' );

		$local_time = function_exists( 'wp_date' )
			? wp_date( 'd/m/Y à H:i:s' )
			: gmdate( 'd/m/Y à H:i:s' );
		$tz_string  = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC';

		$subject = sprintf(
			'[Alerte Sécurité SOYOO] Connexion Admin hors zone (%s) sur %s (%s)',
			$country_label,
			$site_name,
			$host ? $host : $site_url
		);

		$body_lines = array(
			sprintf( 'Une connexion réussie à un compte Administrateur a été détectée depuis un pays hors liste autorisée sur le site %s.', $site_name ),
			'',
			'--- DÉTAILS DE LA CONNEXION ---',
			sprintf( 'Site web        : %s (%s)', $site_name, $site_url ),
			sprintf( 'Compte Admin    : %s', $user_login ),
			sprintf( 'Adresse IP      : %s', $user_ip ),
			sprintf( 'Pays détecté    : %s', $country_label ),
			sprintf( 'Pays autorisés  : %s', implode( ', ', $allowed_countries ) ),
			sprintf( 'Horodatage      : %s (%s)', $local_time, $tz_string ),
			'',
			'Si cette connexion est légitime (nouveau collaborateur ou déplacement), aucune action n\'est requise. Dans le cas contraire, réinitialisez immédiatement les accès du compte sur ' . admin_url( 'users.php' ) . '.',
		);

		wp_mail( $alert_email, $subject, implode( "\n", $body_lines ) );
	}

	/**
	 * Query external Geo-IP APIs (`http://ip-api.com` then fallback `https://ipwho.is`).
	 *
	 * @param string $ip IP address.
	 * @return array{country_code: string, country_name: string}
	 */
	private static function lookup_ip_geolocation( $ip ) {
		$result = array(
			'country_code' => '',
			'country_name' => '',
		);

		// 1. Primary lookup: ip-api.com.
		$primary_url = 'http://ip-api.com/json/' . rawurlencode( $ip ) . '?fields=status,country,countryCode';
		$response    = wp_remote_get(
			$primary_url,
			array(
				'timeout'   => 5,
				'sslverify' => false,
			)
		);

		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) && isset( $data['status'] ) && 'success' === $data['status'] && ! empty( $data['countryCode'] ) ) {
				$result['country_code'] = sanitize_text_field( $data['countryCode'] );
				$result['country_name'] = isset( $data['country'] ) ? sanitize_text_field( $data['country'] ) : '';
				return $result;
			}
		}

		// 2. Fallback HTTPS lookup: ipwho.is.
		$fallback_url = 'https://ipwho.is/' . rawurlencode( $ip );
		$response_fb  = wp_remote_get(
			$fallback_url,
			array(
				'timeout' => 5,
			)
		);

		if ( ! is_wp_error( $response_fb ) && 200 === (int) wp_remote_retrieve_response_code( $response_fb ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response_fb ), true );
			if ( is_array( $data ) && ! empty( $data['success'] ) && ! empty( $data['country_code'] ) ) {
				$result['country_code'] = sanitize_text_field( $data['country_code'] );
				$result['country_name'] = isset( $data['country'] ) ? sanitize_text_field( $data['country'] ) : '';
				return $result;
			}
		}

		return $result;
	}
}
