<?php
/**
 * Feature: Automatically Link Guest Orders to Existing or Newly Created Customer Accounts.
 *
 * Ensures that customers who already own an account on the store but check out as a guest
 * (e.g. via Apple Pay, Google Pay, Express Checkout, or mobile) immediately have their order
 * linked to their user ID (`customer_id`), and links past guest orders when a customer registers.
 * Fully compatible with WooCommerce HPOS (`wp_wc_orders`).
 *
 * @package WooSoyooTools
 */

namespace SOYOO\Tools\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Feature_Link_Guest_Orders
 */
class Feature_Link_Guest_Orders {

	/**
	 * Guard against recursive order saves during fallback hooks.
	 *
	 * @var array<int, bool>
	 */
	private static $processing_orders = array();

	/**
	 * Register feature hooks.
	 */
	public static function register() {
		// 1. When a new customer account is created, link all past guest orders matching their billing email.
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'link_past_orders_on_registration' ), 10, 1 );

		// 2. During checkout order creation (before DB insert/save): zero extra SQL write query.
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'assign_user_on_checkout_create_order' ), 20, 1 );

		// 3. Safety net for orders created outside standard checkout (REST API, Apple Pay / Stripe Express, manual, webhooks).
		add_action( 'woocommerce_new_order', array( __CLASS__, 'assign_user_on_existing_order' ), 20, 2 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'assign_user_on_existing_order' ), 20, 2 );
	}

	/**
	 * Retroactively link past guest orders when a customer creates an account.
	 *
	 * @param int $customer_id Newly created WordPress user ID.
	 */
	public static function link_past_orders_on_registration( $customer_id ) {
		$customer_id = (int) $customer_id;
		if ( $customer_id <= 0 ) {
			return;
		}

		if ( function_exists( 'wc_update_new_customer_past_orders' ) ) {
			wc_update_new_customer_past_orders( $customer_id );
		}
	}

	/**
	 * Assign existing customer ID directly on the order object before WooCommerce saves it at checkout.
	 *
	 * @param \WC_Order $order Order instance being created.
	 */
	public static function assign_user_on_checkout_create_order( $order ) {
		if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		if ( (int) $order->get_customer_id() !== 0 ) {
			return;
		}

		$billing_email = sanitize_email( (string) $order->get_billing_email() );
		if ( empty( $billing_email ) || ! is_email( $billing_email ) ) {
			return;
		}

		$user = get_user_by( 'email', $billing_email );
		if ( $user instanceof \WP_User && $user->ID > 0 ) {
			$order->set_customer_id( (int) $user->ID );
		}
	}

	/**
	 * Fallback safety net for guest orders created outside standard checkout or transitioning to processing via webhook.
	 *
	 * @param int            $order_id Order ID.
	 * @param \WC_Order|null $order    Optional order object.
	 */
	public static function assign_user_on_existing_order( $order_id, $order = null ) {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 || ! empty( self::$processing_orders[ $order_id ] ) ) {
			return;
		}

		if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order' ) ) {
			if ( ! function_exists( 'wc_get_order' ) ) {
				return;
			}
			$order = wc_get_order( $order_id );
		}

		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		if ( (int) $order->get_customer_id() !== 0 ) {
			return;
		}

		$billing_email = sanitize_email( (string) $order->get_billing_email() );
		if ( empty( $billing_email ) || ! is_email( $billing_email ) ) {
			return;
		}

		$user = get_user_by( 'email', $billing_email );
		if ( $user instanceof \WP_User && $user->ID > 0 ) {
			self::$processing_orders[ $order_id ] = true;
			$order->set_customer_id( (int) $user->ID );
			$order->save();
			unset( self::$processing_orders[ $order_id ] );
		}
	}
}
