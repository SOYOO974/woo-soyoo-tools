<?php
/**
 * Feature: Database & Action Scheduler Hygiene.
 *
 * Keeps Action Scheduler tables (`actionscheduler_actions`, `actionscheduler_logs`),
 * expired transients in `wp_options`, Independent Analytics event logs, and heavy
 * autoloaded options lean and fast without impacting front-end TTFB.
 *
 * @package WooSoyooTools
 */

namespace SOYOO\Tools\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Feature_Database_Hygiene
 */
class Feature_Database_Hygiene {

	/**
	 * Twice-daily cron hook for Action Scheduler & Independent Analytics batch purge.
	 */
	const AS_PURGE_CRON_HOOK = 'soyoo_tools_as_purge_cron';

	/**
	 * Daily cron hook for expired transients & analytics cleanup.
	 */
	const TRANSIENTS_CRON_HOOK = 'soyoo_tools_transients_cleanup_cron';

	/**
	 * 24-hour witness transient to avoid calling `wp_next_scheduled()` on every front-end request.
	 */
	const CRON_CHECK_TRANSIENT = 'soyoo_tools_db_hygiene_cron_checked';

	/**
	 * 3-day witness transient for non-destructive heavy option de-autoloading in admin.
	 */
	const DEAUTOLOAD_TRANSIENT = 'soyoo_tools_deautoload_checked';

	/**
	 * Register feature hooks.
	 */
	public static function register() {
		// a) Action Scheduler retention period (7 days) & batch size (1000).
		add_filter( 'action_scheduler_retention_period', array( __CLASS__, 'filter_as_retention_period' ), 99 );
		add_filter( 'action_scheduler_cleanup_batch_size', array( __CLASS__, 'filter_as_cleanup_batch_size' ), 99 );

		// b) Neutralize orphan Action Scheduler hooks without callbacks so they complete cleanly.
		add_action( 'init', array( __CLASS__, 'neutralize_orphan_as_hooks' ), 20 );

		// c) & d) Schedule recurring background purges only when the 24h witness transient expires.
		add_action( 'init', array( __CLASS__, 'maybe_schedule_cron_jobs' ), 30 );
		add_action( self::AS_PURGE_CRON_HOOK, array( __CLASS__, 'run_action_scheduler_purge' ) );
		add_action( self::TRANSIENTS_CRON_HOOK, array( __CLASS__, 'run_transients_and_analytics_cleanup' ) );

		// e) Independent Analytics Pro retention safeguard (force 365 days if empty or 0).
		add_filter( 'option_ia_data_retention_period', array( __CLASS__, 'enforce_ia_retention_period' ) );
		add_filter( 'default_option_ia_data_retention_period', array( __CLASS__, 'enforce_ia_retention_period' ) );

		// f) & g) Admin-only non-destructive de-autoloading & manual optimization trigger.
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'maybe_deautoload_heavy_options' ), 20 );
			add_action( 'admin_init', array( __CLASS__, 'handle_manual_optimization_trigger' ), 10 );
			add_action( 'admin_notices', array( __CLASS__, 'render_manual_optimization_notice' ) );
		}
	}

	/**
	 * Set Action Scheduler retention period to 7 days.
	 *
	 * @return int
	 */
	public static function filter_as_retention_period() {
		return 7 * DAY_IN_SECONDS;
	}

	/**
	 * Set Action Scheduler cleanup batch size to 1000.
	 *
	 * @return int
	 */
	public static function filter_as_cleanup_batch_size() {
		return 1000;
	}

	/**
	 * Attach a no-op callback (`__return_true`) to known orphan Action Scheduler hooks
	 * that have no registered listener so Action Scheduler stops failing/retrying them.
	 */
	public static function neutralize_orphan_as_hooks() {
		$orphan_hooks = apply_filters(
			'woo_soyoo_tools_orphan_as_hooks',
			array(
				'fetch_patterns',
				'sfn_send_usage_report',
				'sfn_followup_emails',
				'action_scheduler/migration_hook',
			)
		);

		if ( ! is_array( $orphan_hooks ) ) {
			return;
		}

		foreach ( $orphan_hooks as $hook ) {
			if ( is_string( $hook ) && '' !== $hook && ! has_action( $hook ) ) {
				add_action( $hook, '__return_true' );
			}
		}
	}

	/**
	 * Schedule the twice-daily Action Scheduler purge and daily transients cleanup
	 * only when a 24h witness transient has expired.
	 */
	public static function maybe_schedule_cron_jobs() {
		if ( false !== get_transient( self::CRON_CHECK_TRANSIENT ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::AS_PURGE_CRON_HOOK ) ) {
			wp_schedule_event( time() + 120, 'twicedaily', self::AS_PURGE_CRON_HOOK );
		}

		if ( ! wp_next_scheduled( self::TRANSIENTS_CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'daily', self::TRANSIENTS_CRON_HOOK );
		}

		// Ensure Independent Analytics retention option is persisted as 365 if currently 0 or empty when IA table exists.
		self::maybe_persist_ia_retention_option();

		set_transient( self::CRON_CHECK_TRANSIENT, 1, DAY_IN_SECONDS );
	}

	/**
	 * Enforce 365-day retention for Independent Analytics Pro if empty or 0.
	 *
	 * @param mixed $value Current option value.
	 * @return int
	 */
	public static function enforce_ia_retention_period( $value ) {
		if ( empty( $value ) || (int) $value <= 0 ) {
			return 365;
		}
		return (int) $value;
	}

	/**
	 * Persist `ia_data_retention_period` = 365 in DB if Independent Analytics is installed and value is empty/0.
	 */
	private static function maybe_persist_ia_retention_option() {
		$raw_val = get_option( 'ia_data_retention_period', null );
		if ( null !== $raw_val && ( empty( $raw_val ) || (int) $raw_val <= 0 ) ) {
			update_option( 'ia_data_retention_period', 365, false );
		}
	}

	/**
	 * Execute bounded batch purge of Action Scheduler logs and completed/canceled/failed actions.
	 * Runs in chunks of 5000 rows up to a maximum wall-clock budget of 25 seconds.
	 *
	 * @return array{logs_deleted: int, actions_deleted: int}
	 */
	public static function run_action_scheduler_purge() {
		global $wpdb;

		$stats = array(
			'logs_deleted'    => 0,
			'actions_deleted' => 0,
		);

		$start_time   = microtime( true );
		$max_duration = 25.0;
		$batch_limit  = 5000;

		$logs_table    = $wpdb->prefix . 'actionscheduler_logs';
		$actions_table = $wpdb->prefix . 'actionscheduler_actions';

		// 1. Purge old rows in `actionscheduler_logs` (> 7 days).
		if ( self::table_exists( $logs_table ) ) {
			while ( ( microtime( true ) - $start_time ) < $max_duration ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$deleted = (int) $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM `{$logs_table}` WHERE log_date_gmt < (UTC_TIMESTAMP() - INTERVAL %d DAY) AND log_date_gmt != '0000-00-00 00:00:00' LIMIT %d",
						7,
						$batch_limit
					)
				);

				if ( $deleted > 0 ) {
					$stats['logs_deleted'] += $deleted;
				}

				if ( function_exists( 'wp_cache_flush_runtime' ) ) {
					wp_cache_flush_runtime();
				}

				if ( $deleted < $batch_limit ) {
					break;
				}
			}
		}

		// 2. Purge `complete` and `canceled` actions (> 7 days) and `failed` actions (> 14 days).
		if ( self::table_exists( $actions_table ) ) {
			while ( ( microtime( true ) - $start_time ) < $max_duration ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$deleted_completed = (int) $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM `{$actions_table}` WHERE status IN ('complete', 'canceled') AND scheduled_date_gmt < (UTC_TIMESTAMP() - INTERVAL %d DAY) AND scheduled_date_gmt != '0000-00-00 00:00:00' LIMIT %d",
						7,
						$batch_limit
					)
				);

				if ( $deleted_completed > 0 ) {
					$stats['actions_deleted'] += $deleted_completed;
				}

				if ( function_exists( 'wp_cache_flush_runtime' ) ) {
					wp_cache_flush_runtime();
				}

				if ( $deleted_completed < $batch_limit ) {
					break;
				}
			}

			while ( ( microtime( true ) - $start_time ) < $max_duration ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$deleted_failed = (int) $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM `{$actions_table}` WHERE status = 'failed' AND scheduled_date_gmt < (UTC_TIMESTAMP() - INTERVAL %d DAY) AND scheduled_date_gmt != '0000-00-00 00:00:00' LIMIT %d",
						14,
						$batch_limit
					)
				);

				if ( $deleted_failed > 0 ) {
					$stats['actions_deleted'] += $deleted_failed;
				}

				if ( function_exists( 'wp_cache_flush_runtime' ) ) {
					wp_cache_flush_runtime();
				}

				if ( $deleted_failed < $batch_limit ) {
					break;
				}
			}
		}

		return $stats;
	}

	/**
	 * Daily gentle cleanup of up to 500 expired transients in `wp_options`
	 * and Independent Analytics events older than 365 days.
	 *
	 * @return array{transients_deleted: int, ia_events_deleted: int}
	 */
	public static function run_transients_and_analytics_cleanup() {
		global $wpdb;

		$stats = array(
			'transients_deleted' => 0,
			'ia_events_deleted'  => 0,
		);

		$now = time();

		// 1. Fetch up to 500 expired transient timeout option names.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$expired_timeouts = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				WHERE option_name LIKE %s
				AND CAST(option_value AS UNSIGNED) > 0
				AND CAST(option_value AS UNSIGNED) < %d
				LIMIT 500",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$now
			)
		);

		if ( ! empty( $expired_timeouts ) && is_array( $expired_timeouts ) ) {
			$names_to_delete = array();
			foreach ( $expired_timeouts as $timeout_option ) {
				$transient_key     = substr( $timeout_option, strlen( '_transient_timeout_' ) );
				$names_to_delete[] = $timeout_option;
				$names_to_delete[] = '_transient_' . $transient_key;
			}

			$names_to_delete = array_unique( $names_to_delete );
			$placeholders    = implode( ', ', array_fill( 0, count( $names_to_delete ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$deleted_rows = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name IN ({$placeholders})",
					$names_to_delete
				)
			);

			$stats['transients_deleted'] = count( $expired_timeouts );

			if ( $deleted_rows > 0 && function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}

		// 2. Gentle nocturnal purge of Independent Analytics events > 365 days if table exists.
		$ia_candidates = array(
			$wpdb->prefix . 'analytics_wp_events',
			'wp_analytics_wp_events',
			$wpdb->prefix . 'independent_analytics_events',
		);
		$ia_candidates = array_unique( $ia_candidates );

		foreach ( $ia_candidates as $ia_table ) {
			if ( ! self::table_exists( $ia_table ) ) {
				continue;
			}

			$date_column = self::get_table_date_column( $ia_table, array( 'created_at', 'date', 'event_date' ) );
			if ( ! $date_column ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ia_deleted = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `{$ia_table}` WHERE `{$date_column}` < (UTC_TIMESTAMP() - INTERVAL %d DAY) LIMIT %d",
					365,
					5000
				)
			);

			if ( $ia_deleted > 0 ) {
				$stats['ia_events_deleted'] += $ia_deleted;
			}
		}

		return $stats;
	}

	/**
	 * Non-destructive de-autoloading of known heavy plugin options (checked once every 3 days in admin).
	 * Compatible with WordPress 6.6+ autoload values (`yes`, `on`, `auto`, `auto-on`).
	 *
	 * @param bool $force Whether to bypass the 3-day transient check.
	 * @return int Number of options switched to non-autoload.
	 */
	public static function maybe_deautoload_heavy_options( $force = false ) {
		if ( ! $force && false !== get_transient( self::DEAUTOLOAD_TRANSIENT ) ) {
			return 0;
		}

		global $wpdb;

		$heavy_options = apply_filters(
			'woo_soyoo_tools_heavy_autoload_options',
			array(
				'admin_site_enhancements_extra',
				'wpassetcleanup_global_data',
				'woocommerce_shipment_tracking_custom_providers',
				'rank_math_modules',
				'wf_crawlers_user_agents',
				'sib_push_app_xkeysib',
			)
		);

		$updated_count = 0;

		if ( ! empty( $heavy_options ) && is_array( $heavy_options ) ) {
			$heavy_options = array_values( array_filter( array_map( 'sanitize_text_field', $heavy_options ) ) );

			if ( ! empty( $heavy_options ) ) {
				if ( function_exists( 'wp_set_option_autoload' ) ) {
					foreach ( $heavy_options as $opt_name ) {
						if ( wp_set_option_autoload( $opt_name, false ) ) {
							$updated_count++;
						}
					}
				} else {
					$placeholders = implode( ', ', array_fill( 0, count( $heavy_options ), '%s' ) );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					$updated_count = (int) $wpdb->query(
						$wpdb->prepare(
							"UPDATE {$wpdb->options} SET autoload = 'no' WHERE option_name IN ({$placeholders}) AND autoload IN ('yes', 'on', 'auto', 'auto-on')",
							$heavy_options
						)
					);

					if ( $updated_count > 0 ) {
						wp_cache_delete( 'alloptions', 'options' );
					}
				}
			}
		}

		set_transient( self::DEAUTOLOAD_TRANSIENT, 1, 3 * DAY_IN_SECONDS );

		return $updated_count;
	}

	/**
	 * Handle manual optimization trigger from `?soyoo_run_db_opt=1&_wpnonce=...`.
	 */
	public static function handle_manual_optimization_trigger() {
		if ( ! isset( $_GET['soyoo_run_db_opt'] ) || '1' !== (string) $_GET['soyoo_run_db_opt'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'soyoo_run_db_opt_nonce' );

		$as_stats         = self::run_action_scheduler_purge();
		$transient_stats  = self::run_transients_and_analytics_cleanup();
		$deautoload_count = self::maybe_deautoload_heavy_options( true );

		$redirect_url = add_query_arg(
			array(
				'page'              => 'woo-soyoo-tools',
				'soyoo_db_opt_done' => '1',
				'as_actions'        => (int) $as_stats['actions_deleted'],
				'as_logs'           => (int) $as_stats['logs_deleted'],
				'transients'        => (int) $transient_stats['transients_deleted'],
				'deautoloaded'      => (int) $deautoload_count,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Build the secure admin URL for triggering manual database hygiene immediately.
	 *
	 * @return string
	 */
	public static function get_manual_trigger_url() {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'             => 'woo-soyoo-tools',
					'soyoo_run_db_opt' => '1',
				),
				admin_url( 'admin.php' )
			),
			'soyoo_run_db_opt_nonce'
		);
	}

	/**
	 * Render standard WordPress admin notice when manual optimization completes outside the SOYOO Tools screen.
	 */
	public static function render_manual_optimization_notice() {
		if ( ! isset( $_GET['soyoo_db_opt_done'] ) || '1' !== (string) $_GET['soyoo_db_opt_done'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$actions      = isset( $_GET['as_actions'] ) ? absint( $_GET['as_actions'] ) : 0;
		$logs         = isset( $_GET['as_logs'] ) ? absint( $_GET['as_logs'] ) : 0;
		$transients   = isset( $_GET['transients'] ) ? absint( $_GET['transients'] ) : 0;
		$deautoloaded = isset( $_GET['deautoloaded'] ) ? absint( $_GET['deautoloaded'] ) : 0;

		printf(
			'<div class="notice notice-success is-dismissible wst-banner-notice wst-banner-notice--success"><p><strong>SOYOO Tools — Optimisation BDD exécutée :</strong> %d action(s) et %d log(s) Action Scheduler purgés, %d transient(s) expiré(s) nettoyé(s), %d option(s) lourde(s) désautoloadée(s).</p></div>',
			(int) $actions,
			(int) $logs,
			(int) $transients,
			(int) $deautoloaded
		);
	}

	/**
	 * Check whether a database table exists.
	 *
	 * @param string $table_name Full table name.
	 * @return bool
	 */
	private static function table_exists( $table_name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		return $found === $table_name;
	}

	/**
	 * Find the first matching date column in a given table.
	 *
	 * @param string $table_name      Full table name.
	 * @param array  $candidate_names Candidate column names.
	 * @return string|null
	 */
	private static function get_table_date_column( $table_name, $candidate_names ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table_name}`", 0 );
		if ( ! is_array( $columns ) ) {
			return null;
		}

		foreach ( $candidate_names as $candidate ) {
			if ( in_array( $candidate, $columns, true ) ) {
				return $candidate;
			}
		}

		return null;
	}
}
