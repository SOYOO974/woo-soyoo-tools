<?php
/**
 * Feature: Abandoned / Cancelled / Failed Orders Cleanup (> 1 Year, HPOS-Safe).
 *
 * Runs a daily nocturnal cron at 03:30 site local time (`wp_timezone()`) that fetches up to
 * 30 `cancelled` or `failed` orders created more than 365 days ago via `wc_get_orders()`,
 * enforces 4 strict safety locks (status, null date_paid, empty transaction_id, age > 365d),
 * permanently deletes them (`$order->delete( true )`), and logs the operation to `wc_get_logger()`.
 *
 * @package WooSoyooTools
 */

namespace SOYOO\Tools\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Feature_Abandoned_Orders_Cleanup
 */
class Feature_Abandoned_Orders_Cleanup {

	/**
	 * Daily nocturnal cron hook.
	 */
	const CRON_HOOK = 'soyoo_tools_abandoned_orders_cleanup_cron';

	/**
	 * 24-hour witness transient to avoid querying `wp_next_scheduled()` on every request.
	 */
	const SCHEDULE_TRANSIENT = 'soyoo_tools_abandoned_orders_cron_checked';

	/**
	 * Register feature hooks.
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'maybe_schedule_nocturnal_cron' ), 35 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cleanup_batch' ) );
	}

	/**
	 * Schedule the daily cron at 03:30 local site time (`wp_timezone()`) if not already scheduled.
	 */
	public static function maybe_schedule_nocturnal_cron() {
		if ( false !== get_transient( self::SCHEDULE_TRANSIENT ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
			$now      = new \DateTimeImmutable( 'now', $timezone );
			$next_run = $now->setTime( 3, 30, 0 );

			if ( $next_run <= $now ) {
				$next_run = $next_run->modify( '+1 day' );
			}

			wp_schedule_event( $next_run->getTimestamp(), 'daily', self::CRON_HOOK );
		}

		set_transient( self::SCHEDULE_TRANSIENT, 1, DAY_IN_SECONDS );
	}

	/**
	 * Execute a safe batch cleanup of up to 30 `cancelled` or `failed` orders older than 365 days.
	 *
	 * @return int Number of orders permanently deleted.
	 */
	public static function run_cleanup_batch() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$cutoff_timestamp = time() - ( 365 * DAY_IN_SECONDS );

		$orders = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => array( 'cancelled', 'failed' ),
				'date_created' => '<' . $cutoff_timestamp,
				'limit'        => 30,
				'orderby'      => 'date',
				'order'        => 'ASC',
				'return'       => 'objects',
			)
		);

		if ( empty( $orders ) || ! is_array( $orders ) ) {
			return 0;
		}

		$deleted_ids = array();

		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order' ) ) {
				continue;
			}

			// Lock 1: Status must strictly be `cancelled` or `failed` (never refund, completed, processing, on-hold).
			$status = (string) $order->get_status();
			if ( ! in_array( $status, array( 'cancelled', 'failed' ), true ) ) {
				continue;
			}

			// Lock 2: Order must never have been paid (`date_paid` strictly null).
			if ( null !== $order->get_date_paid() ) {
				continue;
			}

			// Lock 3: Order must not hold any payment gateway transaction ID.
			$transaction_id = (string) $order->get_transaction_id();
			if ( ! empty( $transaction_id ) ) {
				continue;
			}

			// Lock 4: Creation date must strictly be older than 365 days.
			$date_created = $order->get_date_created();
			if ( ! $date_created || $date_created->getTimestamp() >= $cutoff_timestamp ) {
				continue;
			}

			$order_id = (int) $order->get_id();
			if ( $order->delete( true ) ) {
				$deleted_ids[] = $order_id;
			}
		}

		if ( ! empty( $deleted_ids ) && function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			$logger->info(
				sprintf(
					'Purge nocturne HPOS exécutée : %d commande(s) abandonnée(s)/échouée(s) (> 365 jours, non payées) supprimée(s) définitivement. IDs : %s',
					count( $deleted_ids ),
					implode( ', ', $deleted_ids )
				),
				array( 'source' => 'woo-soyoo-tools' )
			);
		}

		return count( $deleted_ids );
	}
}
