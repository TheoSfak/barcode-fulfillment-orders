<?php
/**
 * Database management: creates and upgrades custom tables.
 *
 * Tables:
 *   {prefix}bfo_packing_sessions  — one row per fulfillment session
 *   {prefix}bfo_scan_logs         — one row per scanned/missing/wrong event
 *   {prefix}bfo_boxes             — one row per physical shipping box
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BFO_Database
 *
 * @since 1.0.0
 */
class BFO_Database {

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var BFO_Database|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @since  1.0.0
	 * @return BFO_Database
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Alias for get_instance() — called by the main plugin bootstrapper.
	 *
	 * @since  1.0.0
	 * @return static
	 */
	public static function instance() {
		return self::get_instance();
	}

	/**
	 * Private constructor — use get_instance().
	 *
	 * @since 1.0.0
	 */
	private function __construct() {}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Creates or upgrades all custom tables.
	 *
	 * Safe to call on every activation — dbDelta() is idempotent.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function install() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// -- packing_sessions ------------------------------------------------
		$sql_sessions = "CREATE TABLE `{$wpdb->prefix}bfo_packing_sessions` (
			`id`           BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
			`order_id`     BIGINT(20)   UNSIGNED NOT NULL,
			`worker_id`    BIGINT(20)   UNSIGNED NOT NULL,
			`status`       VARCHAR(20)  NOT NULL DEFAULT 'active',
			`started_at`   DATETIME     NOT NULL,
			`completed_at` DATETIME              DEFAULT NULL,
			`last_ping`    DATETIME              DEFAULT NULL,
			`notes`        TEXT                  DEFAULT NULL,
			PRIMARY KEY (`id`),
			KEY `order_id`  (`order_id`),
			KEY `worker_id` (`worker_id`),
			KEY `status`    (`status`)
		) {$charset_collate};";

		// -- scan_logs -------------------------------------------------------
		$sql_logs = "CREATE TABLE `{$wpdb->prefix}bfo_scan_logs` (
			`id`              BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
			`session_id`      BIGINT(20)   UNSIGNED NOT NULL,
			`order_id`        BIGINT(20)   UNSIGNED NOT NULL,
			`product_id`      BIGINT(20)   UNSIGNED NOT NULL DEFAULT 0,
			`variation_id`    BIGINT(20)   UNSIGNED NOT NULL DEFAULT 0,
			`barcode_scanned` VARCHAR(255) NOT NULL DEFAULT '',
			`box_number`      SMALLINT(5)  UNSIGNED NOT NULL DEFAULT 1,
			`action`          VARCHAR(30)  NOT NULL DEFAULT 'scanned',
			`missing_reason`  VARCHAR(255)          DEFAULT NULL,
			`quantity`        SMALLINT(5)  UNSIGNED NOT NULL DEFAULT 1,
			`worker_id`       BIGINT(20)   UNSIGNED NOT NULL,
			`scanned_at`      DATETIME     NOT NULL,
			PRIMARY KEY (`id`),
			KEY `session_id` (`session_id`),
			KEY `order_id`   (`order_id`),
			KEY `worker_id`  (`worker_id`),
			KEY `product_id` (`product_id`),
			KEY `scanned_at` (`scanned_at`)
		) {$charset_collate};";

		// -- boxes -----------------------------------------------------------
		$sql_boxes = "CREATE TABLE `{$wpdb->prefix}bfo_boxes` (
			`id`         BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
			`session_id` BIGINT(20)   UNSIGNED NOT NULL,
			`order_id`   BIGINT(20)   UNSIGNED NOT NULL,
			`box_number` SMALLINT(5)  UNSIGNED NOT NULL DEFAULT 1,
			`label`      VARCHAR(100)          DEFAULT NULL,
			`weight`     DECIMAL(8,3)          DEFAULT NULL,
			`length`     DECIMAL(8,3)          DEFAULT NULL,
			`width`      DECIMAL(8,3)          DEFAULT NULL,
			`height`     DECIMAL(8,3)          DEFAULT NULL,
			`created_at` DATETIME     NOT NULL,
			PRIMARY KEY (`id`),
			KEY `session_id` (`session_id`),
			KEY `order_id`   (`order_id`)
		) {$charset_collate};";

		dbDelta( $sql_sessions );
		dbDelta( $sql_logs );
		dbDelta( $sql_boxes );

		update_option( BFO_OPTION_DB_VERSION, BFO_DB_VERSION );
	}

	/**
	 * Runs install() if the stored DB version is behind the current one.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function maybe_upgrade() {
		$stored = get_option( BFO_OPTION_DB_VERSION, '0' );

		if ( version_compare( $stored, BFO_DB_VERSION, '<' ) ) {
			$this->install();
		}
	}

	// -------------------------------------------------------------------------
	// Convenience query helpers (used by other classes)
	// -------------------------------------------------------------------------

	/**
	 * Inserts a new packing session row.
	 *
	 * @since  1.0.0
	 * @param  int    $order_id  WooCommerce order ID.
	 * @param  int    $worker_id WordPress user ID of the worker.
	 * @return int|false         Inserted row ID or false on failure.
	 */
	public function insert_session( $order_id, $worker_id ) {
		global $wpdb;

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"{$wpdb->prefix}bfo_packing_sessions",
			array(
				'order_id'   => absint( $order_id ),
				'worker_id'  => absint( $worker_id ),
				'status'     => BFO_SESSION_ACTIVE,
				'started_at' => current_time( 'mysql' ),
				'last_ping'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Fetches a single packing session by ID.
	 *
	 * @since  1.0.0
	 * @param  int        $session_id Session primary key.
	 * @return object|null
	 */
	public function get_session( $session_id ) {
		global $wpdb;

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}bfo_packing_sessions` WHERE `id` = %d",
				absint( $session_id )
			)
		);
	}

	/**
	 * Fetches the active or paused session for a given order.
	 *
	 * @since  1.0.0
	 * @param  int        $order_id Order ID.
	 * @return object|null
	 */
	public function get_active_session_for_order( $order_id ) {
		global $wpdb;

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}bfo_packing_sessions`
				  WHERE `order_id` = %d
				    AND `status` IN ('active','paused')
				  ORDER BY `id` DESC
				  LIMIT 1",
				absint( $order_id )
			)
		);
	}

	/**
	 * Updates a session row.
	 *
	 * @since  1.0.0
	 * @param  int   $session_id Session primary key.
	 * @param  array $data       Column => value pairs.
	 * @return bool
	 */
	public function update_session( $session_id, $data ) {
		global $wpdb;

		return (bool) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"{$wpdb->prefix}bfo_packing_sessions",
			$data,
			array( 'id' => absint( $session_id ) )
		);
	}

	/**
	 * Inserts a scan log row.
	 *
	 * @since  1.0.0
	 * @param  array $data  Associative array of column values.
	 * @return int|false    Inserted row ID or false.
	 */
	public function insert_scan_log( $data ) {
		global $wpdb;

		$data['scanned_at'] = current_time( 'mysql' );

		$result = $wpdb->insert( "{$wpdb->prefix}bfo_scan_logs", $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Returns all scan log rows for a given session.
	 *
	 * @since  1.0.0
	 * @param  int   $session_id Session ID.
	 * @return array
	 */
	public function get_scan_logs_for_session( $session_id ) {
		global $wpdb;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}bfo_scan_logs`
				  WHERE `session_id` = %d
				  ORDER BY `scanned_at` ASC",
				absint( $session_id )
			)
		);
	}

	/**
	 * Returns scan logs grouped by product_id (and variation_id) for a session.
	 * Used to build real-time scanned counts on the fulfillment screen.
	 *
	 * @since  1.0.0
	 * @param  int   $session_id  Session ID.
	 * @return array  Each row: product_id, variation_id, scanned (count), missing (count).
	 */
	public function get_scan_summary_for_session( $session_id ) {
		global $wpdb;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT
					`product_id`,
					`variation_id`,
					SUM( `action` = 'scanned' )  AS `scanned`,
					SUM( `action` = 'missing' )  AS `missing`
				 FROM `{$wpdb->prefix}bfo_scan_logs`
				 WHERE `session_id` = %d
				 GROUP BY `product_id`, `variation_id`",
				absint( $session_id )
			),
			ARRAY_A
		);
	}

	/**
	 * Inserts a new box record for a session.
	 *
	 * @since  1.0.0
	 * @param  int    $session_id Session ID.
	 * @param  int    $order_id   Order ID.
	 * @param  int    $box_number Sequential box number within this order.
	 * @return int|false
	 */
	public function insert_box( $session_id, $order_id, $box_number ) {
		global $wpdb;

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"{$wpdb->prefix}bfo_boxes",
			array(
				'session_id' => absint( $session_id ),
				'order_id'   => absint( $order_id ),
				'box_number' => absint( $box_number ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%s' )
		);

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Returns all boxes for a given session.
	 *
	 * @since  1.0.0
	 * @param  int   $session_id Session ID.
	 * @return array
	 */
	public function get_boxes_for_session( $session_id ) {
		global $wpdb;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}bfo_boxes`
				  WHERE `session_id` = %d
				  ORDER BY `box_number` ASC",
				absint( $session_id )
			)
		);
	}

	/**
	 * Updates a box record.
	 *
	 * @since  1.0.0
	 * @param  int   $box_id  Box primary key.
	 * @param  array $data    Column => value pairs.
	 * @return bool
	 */
	public function update_box( $box_id, $data ) {
		global $wpdb;

		return (bool) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"{$wpdb->prefix}bfo_boxes",
			$data,
			array( 'id' => absint( $box_id ) )
		);
	}

	/**
	 * Deletes audit log rows older than $days days.
	 *
	 * @since  1.0.0
	 * @param  int $days Retention period in days.
	 * @return int       Number of rows deleted.
	 */
	public function purge_old_logs( $days ) {
		global $wpdb;

		$days = absint( $days );
		if ( 0 === $days ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$wpdb->prefix}bfo_scan_logs`
				  WHERE `scanned_at` < DATE_SUB( NOW(), INTERVAL %d DAY )",
				$days
			)
		);
	}
}
