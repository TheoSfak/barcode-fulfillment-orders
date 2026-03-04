<?php
/**
 * Packing session state management.
 *
 * Handles the lifecycle of a packing session:
 *   start → (active) → pause / complete / cancel
 *
 * Also runs a WP-Cron task to auto-pause idle sessions.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BFO_Packing_Session
 *
 * @since 1.0.0
 */
class BFO_Packing_Session {

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var BFO_Packing_Session|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @since  1.0.0
	 * @return BFO_Packing_Session
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
	 * Constructor — registers hooks.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		// Heartbeat AJAX to keep session alive.
		add_action( 'wp_ajax_bfo_session_heartbeat',   array( $this, 'ajax_heartbeat' ) );
		// Pause a session.
		add_action( 'wp_ajax_bfo_pause_session',       array( $this, 'ajax_pause' ) );
		// Resume a paused session.
		add_action( 'wp_ajax_bfo_resume_session',      array( $this, 'ajax_resume' ) );
		// Cancel a session.
		add_action( 'wp_ajax_bfo_cancel_session',      array( $this, 'ajax_cancel' ) );

		// WP-Cron: auto-pause idle sessions.
		add_action( 'bfo_check_idle_sessions', array( $this, 'auto_pause_idle_sessions' ) );
		if ( ! wp_next_scheduled( 'bfo_check_idle_sessions' ) ) {
			wp_schedule_event( time(), 'bfo_five_minutes', 'bfo_check_idle_sessions' );
		}
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
	}

	// -------------------------------------------------------------------------
	// Session lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Starts a new packing session for an order.
	 *
	 * Acquires an exclusive lock: if another active session exists for this
	 * order, returns an error array instead.
	 *
	 * @since  1.0.0
	 * @param  int $order_id   WooCommerce order ID.
	 * @param  int $worker_id  WordPress user ID.
	 * @return array {success:bool, session_id:int|null, message:string}
	 */
	public function start( $order_id, $worker_id ) {
		$order_id  = absint( $order_id );
		$worker_id = absint( $worker_id );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'success' => false, 'message' => __( 'Order not found.', 'barcode-fulfillment-orders' ) );
		}

		// Check for an existing active lock on this order.
		$existing = BFO_Database::get_instance()->get_active_session_for_order( $order_id );
		if ( $existing && BFO_SESSION_ACTIVE === $existing->status ) {
			$worker = get_userdata( (int) $existing->worker_id );
			return array(
				'success' => false,
				/* translators: %s: worker display name */
				'message' => sprintf(
					__( 'This order is already being packed by %s.', 'barcode-fulfillment-orders' ),
					$worker ? $worker->display_name : __( 'another worker', 'barcode-fulfillment-orders' )
				),
			);
		}

		// Create session row.
		$session_id = BFO_Database::get_instance()->insert_session( $order_id, $worker_id );
		if ( ! $session_id ) {
			return array( 'success' => false, 'message' => __( 'Could not create packing session.', 'barcode-fulfillment-orders' ) );
		}

		// Save session ID on the order.
		$order->update_meta_data( BFO_META_ORDER_SESSION_ID, $session_id );

		// Transition order status to "Packing".
		if ( ! $order->has_status( BFO_STATUS_PACKING ) ) {
			$order->update_status(
				BFO_STATUS_PACKING,
				__( 'Packing session started by warehouse worker.', 'barcode-fulfillment-orders' )
			);
		} else {
			$order->save_meta_data();
		}

		// Create the first box.
		BFO_Database::get_instance()->insert_box( $session_id, $order_id, 1 );

		return array( 'success' => true, 'session_id' => $session_id, 'message' => '' );
	}

	/**
	 * Pauses an active session.
	 *
	 * @since  1.0.0
	 * @param  int $session_id  Session primary key.
	 * @param  int $worker_id   Must own the session.
	 * @return bool
	 */
	public function pause( $session_id, $worker_id ) {
		$session = BFO_Database::get_instance()->get_session( absint( $session_id ) );
		if ( ! $session || (int) $session->worker_id !== absint( $worker_id ) ) {
			return false;
		}
		if ( BFO_SESSION_ACTIVE !== $session->status ) {
			return false;
		}
		return BFO_Database::get_instance()->update_session( $session_id, array( 'status' => BFO_SESSION_PAUSED ) );
	}

	/**
	 * Resumes a paused session.
	 *
	 * @since  1.0.0
	 * @param  int $session_id  Session primary key.
	 * @param  int $worker_id   User resuming.
	 * @return array {success:bool, message:string}
	 */
	public function resume( $session_id, $worker_id ) {
		$session = BFO_Database::get_instance()->get_session( absint( $session_id ) );
		if ( ! $session ) {
			return array( 'success' => false, 'message' => __( 'Session not found.', 'barcode-fulfillment-orders' ) );
		}
		if ( BFO_SESSION_PAUSED !== $session->status ) {
			return array( 'success' => false, 'message' => __( 'Session is not paused.', 'barcode-fulfillment-orders' ) );
		}

		BFO_Database::get_instance()->update_session(
			$session_id,
			array(
				'status'     => BFO_SESSION_ACTIVE,
				'worker_id'  => absint( $worker_id ),
				'last_ping'  => current_time( 'mysql' ),
			)
		);

		return array( 'success' => true, 'message' => '' );
	}

	/**
	 * Marks a session as complete and transitions the order status to "Packed".
	 *
	 * Validates that all line items are either scanned or marked missing.
	 *
	 * @since  1.0.0
	 * @param  int $session_id  Session primary key.
	 * @param  int $worker_id   Worker closing the session.
	 * @return array {success:bool, message:string, unaccounted:array}
	 */
	public function complete( $session_id, $worker_id ) {
		$session = BFO_Database::get_instance()->get_session( absint( $session_id ) );
		if ( ! $session || (int) $session->worker_id !== absint( $worker_id ) ) {
			return array( 'success' => false, 'message' => __( 'Session not found or permission denied.', 'barcode-fulfillment-orders' ), 'unaccounted' => array() );
		}

		$order = wc_get_order( (int) $session->order_id );
		if ( ! $order ) {
			return array( 'success' => false, 'message' => __( 'Order not found.', 'barcode-fulfillment-orders' ), 'unaccounted' => array() );
		}

		// Validate: all items accounted for.
		$unaccounted = $this->get_unaccounted_items( $session_id, $order );

		if ( ! empty( $unaccounted ) ) {
			return array(
				'success'      => false,
				'message'      => __( 'Some products have not been scanned or marked missing.', 'barcode-fulfillment-orders' ),
				'unaccounted'  => $unaccounted,
			);
		}

		// All good — mark session completed.
		BFO_Database::get_instance()->update_session(
			$session_id,
			array(
				'status'       => BFO_SESSION_COMPLETED,
				'completed_at' => current_time( 'mysql' ),
			)
		);

		// Transition order to Packed.
		$note = __( 'All items packed. Order ready to ship.', 'barcode-fulfillment-orders' );
		$order->update_status( BFO_STATUS_PACKED, $note );

		return array( 'success' => true, 'message' => '', 'unaccounted' => array() );
	}

	/**
	 * Cancels a session and reverts the order to "Processing".
	 *
	 * @since  1.0.0
	 * @param  int $session_id  Session primary key.
	 * @param  int $worker_id   Worker or manager cancelling.
	 * @return bool
	 */
	public function cancel( $session_id, $worker_id ) {
		$session = BFO_Database::get_instance()->get_session( absint( $session_id ) );
		if ( ! $session ) {
			return false;
		}

		// Allow the session owner or admins/managers to cancel.
		$user = get_userdata( absint( $worker_id ) );
		$is_manager = $user && ( $user->has_cap( 'manage_woocommerce' ) || $user->has_cap( BFO_CAPABILITY_MANAGE ) );
		if ( (int) $session->worker_id !== absint( $worker_id ) && ! $is_manager ) {
			return false;
		}

		BFO_Database::get_instance()->update_session( $session_id, array( 'status' => BFO_SESSION_CANCELLED ) );

		$order = wc_get_order( (int) $session->order_id );
		if ( $order && $order->has_status( BFO_STATUS_PACKING ) ) {
			$order->update_status( 'processing', __( 'Packing session cancelled.', 'barcode-fulfillment-orders' ) );
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Item accounting
	// -------------------------------------------------------------------------

	/**
	 * Returns order line items that have neither been scanned nor marked missing.
	 *
	 * @since  1.0.0
	 * @param  int      $session_id  Session primary key.
	 * @param  WC_Order $order       The order.
	 * @return array  Each entry: ['name'=>string, 'quantity'=>int, 'remaining'=>int]
	 */
	public function get_unaccounted_items( $session_id, $order ) {
		$summary = BFO_Database::get_instance()->get_scan_summary_for_session( $session_id );

		// Build: product_id => ['scanned'=>int, 'missing'=>int].
		$scanned_map = array();
		foreach ( $summary as $row ) {
			$key = (int) $row['product_id'] . '_' . (int) $row['variation_id'];
			$scanned_map[ $key ] = array(
				'scanned' => (int) $row['scanned'],
				'missing' => (int) $row['missing'],
			);
		}

		$unaccounted = array();

		foreach ( $order->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			$product_id   = (int) $item->get_product_id();
			$variation_id = (int) $item->get_variation_id();
			$qty          = (int) $item->get_quantity();
			$key          = $product_id . '_' . $variation_id;

			$scanned = isset( $scanned_map[ $key ] ) ? $scanned_map[ $key ]['scanned'] : 0;
			$missing = isset( $scanned_map[ $key ] ) ? $scanned_map[ $key ]['missing'] : 0;
			$accounted = $scanned + $missing;

			if ( $accounted < $qty ) {
				$unaccounted[] = array(
					'name'      => $item->get_name(),
					'quantity'  => $qty,
					'remaining' => $qty - $accounted,
				);
			}
		}

		return $unaccounted;
	}

	// -------------------------------------------------------------------------
	// Idle session auto-pause (WP-Cron)
	// -------------------------------------------------------------------------

	/**
	 * Registers a 5-minute cron interval.
	 *
	 * @since  1.0.0
	 * @param  array $schedules  Existing cron schedules.
	 * @return array
	 */
	public function add_cron_interval( $schedules ) {
		$schedules['bfo_five_minutes'] = array(
			'interval' => 300,
			'display'  => esc_html__( 'Every Five Minutes', 'barcode-fulfillment-orders' ),
		);
		return $schedules;
	}

	/**
	 * WP-Cron callback: pauses sessions that have been idle longer than the timeout.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function auto_pause_idle_sessions() {
		global $wpdb;

		$timeout_minutes = absint( get_option( BFO_OPTION_SESSION_TIMEOUT, 30 ) );
		if ( 0 === $timeout_minutes ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$idle_sessions = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT `id` FROM `{$wpdb->prefix}bfo_packing_sessions`
				  WHERE `status` = 'active'
				    AND `last_ping` < DATE_SUB( NOW(), INTERVAL %d MINUTE )",
				$timeout_minutes
			)
		);

		foreach ( $idle_sessions as $session_id ) {
			BFO_Database::get_instance()->update_session(
				(int) $session_id,
				array( 'status' => BFO_SESSION_PAUSED )
			);
		}
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: updates last_ping to keep the session alive.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_heartbeat() {
		$session_id = absint( $_POST['session_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		check_ajax_referer( 'bfo_session_' . $session_id, 'security' );

		if ( ! current_user_can( BFO_CAPABILITY_PACK ) ) {
			wp_send_json_error( null, 403 );
		}

		BFO_Database::get_instance()->update_session(
			$session_id,
			array( 'last_ping' => current_time( 'mysql' ) )
		);

		wp_send_json_success();
	}

	/**
	 * AJAX: pauses the current session.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_pause() {
		$session_id = absint( $_POST['session_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		check_ajax_referer( 'bfo_session_' . $session_id, 'security' );

		if ( ! current_user_can( BFO_CAPABILITY_PACK ) ) {
			wp_send_json_error( null, 403 );
		}

		$ok = $this->pause( $session_id, get_current_user_id() );
		$ok ? wp_send_json_success() : wp_send_json_error( array( 'message' => __( 'Could not pause session.', 'barcode-fulfillment-orders' ) ) );
	}

	/**
	 * AJAX: resumes a paused session.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_resume() {
		$session_id = absint( $_POST['session_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		check_ajax_referer( 'bfo_resume_session_' . $session_id, 'security' );

		if ( ! current_user_can( BFO_CAPABILITY_PACK ) ) {
			wp_send_json_error( null, 403 );
		}

		$result = $this->resume( $session_id, get_current_user_id() );
		$result['success'] ? wp_send_json_success() : wp_send_json_error( array( 'message' => $result['message'] ) );
	}

	/**
	 * AJAX: cancels a session.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_cancel() {
		$session_id = absint( $_POST['session_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		check_ajax_referer( 'bfo_session_' . $session_id, 'security' );

		if ( ! current_user_can( BFO_CAPABILITY_PACK ) ) {
			wp_send_json_error( null, 403 );
		}

		$ok = $this->cancel( $session_id, get_current_user_id() );
		$ok ? wp_send_json_success() : wp_send_json_error( array( 'message' => __( 'Could not cancel session.', 'barcode-fulfillment-orders' ) ) );
	}
}
