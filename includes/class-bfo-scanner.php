<?php
/**
 * AJAX barcode scan processor.
 *
 * Receives a scanned barcode, validates it against the active packing session,
 * increments the counter, logs the event, and returns a JSON response with the
 * updated state for the fulfillment screen UI.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BFO_Scanner
 *
 * @since 1.0.0
 */
class BFO_Scanner {

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var BFO_Scanner|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @since  1.0.0
	 * @return BFO_Scanner
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
	 * Constructor — registers AJAX hooks.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		add_action( 'wp_ajax_bfo_process_scan',   array( $this, 'ajax_process_scan' ) );
		add_action( 'wp_ajax_bfo_complete_order', array( $this, 'ajax_complete_order' ) );
	}

	// -------------------------------------------------------------------------
	// AJAX: process a scanned barcode
	// -------------------------------------------------------------------------

	/**
	 * Handles wp_ajax_bfo_process_scan.
	 *
	 * Expected POST fields:
	 *   nonce       — bfo_scan_{session_id}
	 *   session_id  — int
	 *   barcode     — string
	 *   box_number  — int (optional, defaults to 1)
	 *
	 * @since  1.0.0
	 * @return void  Sends JSON and exits.
	 */
	public function ajax_process_scan() {
		$session_id = absint( $_POST['session_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		check_ajax_referer( 'bfo_scan_' . $session_id, 'security' );

		if ( ! current_user_can( BFO_CAPABILITY_PACK ) ) {
			wp_send_json_error( array( 'type' => 'error', 'message' => __( 'Permission denied.', 'barcode-fulfillment-orders' ) ), 403 );
		}

		$barcode    = bfo_sanitize_barcode( wp_unslash( $_POST['barcode'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$box_number = absint( $_POST['box_number'] ?? 1 );
		$worker_id  = get_current_user_id();

		if ( empty( $barcode ) ) {
			wp_send_json_error( array( 'type' => 'error', 'message' => __( 'Empty barcode.', 'barcode-fulfillment-orders' ) ) );
		}

		// Load session.
		$session = BFO_Database::get_instance()->get_session( $session_id );
		if ( ! $session || BFO_SESSION_ACTIVE !== $session->status ) {
			wp_send_json_error( array( 'type' => 'error', 'message' => __( 'No active packing session.', 'barcode-fulfillment-orders' ) ) );
		}

		$order = wc_get_order( (int) $session->order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'type' => 'error', 'message' => __( 'Order not found.', 'barcode-fulfillment-orders' ) ) );
		}

		// Check if this is the ORDER barcode being scanned from the queue screen.
		$order_barcode = bfo_get_order_barcode( $order->get_id() );
		if ( $order_barcode && $barcode === $order_barcode ) {
			wp_send_json_success(
				array(
					'type'    => 'order_barcode',
					'message' => __( 'Order barcode scanned. Please scan products.', 'barcode-fulfillment-orders' ),
				)
			);
		}

		// Update heartbeat timestamp.
		BFO_Database::get_instance()->update_session( $session_id, array( 'last_ping' => current_time( 'mysql' ) ) );

		// Find product by barcode.
		$scanned_product_id = bfo_lookup_product_by_barcode( $barcode );
		if ( ! $scanned_product_id ) {
			// Unknown barcode — not a product in the system.
			BFO_Database::get_instance()->insert_scan_log(
				array(
					'session_id'      => $session_id,
					'order_id'        => $order->get_id(),
					'product_id'      => 0,
					'barcode_scanned' => $barcode,
					'box_number'      => $box_number,
					'action'          => BFO_SCAN_ACTION_WRONG,
					'worker_id'       => $worker_id,
					'quantity'        => 1,
				)
			);
			wp_send_json_success(
				array(
					'type'    => 'unknown',
					/* translators: %s: scanned barcode */
					'message' => sprintf( __( 'Unknown barcode: %s', 'barcode-fulfillment-orders' ), esc_html( $barcode ) ),
					'summary' => $this->build_summary( $session_id, $order ),
				)
			);
		}

		// Does this product belong to this order?
		$matched_item = $this->find_order_item( $order, $scanned_product_id );

		if ( ! $matched_item ) {
			// Product is in the system but NOT in this order.
			$product = wc_get_product( $scanned_product_id );
			BFO_Database::get_instance()->insert_scan_log(
				array(
					'session_id'      => $session_id,
					'order_id'        => $order->get_id(),
					'product_id'      => $scanned_product_id,
					'barcode_scanned' => $barcode,
					'box_number'      => $box_number,
					'action'          => BFO_SCAN_ACTION_WRONG,
					'worker_id'       => $worker_id,
					'quantity'        => 1,
				)
			);
			wp_send_json_success(
				array(
					'type'         => 'wrong_product',
					/* translators: %s: product name */
					'message'      => sprintf(
						__( 'Wrong product: %s does not belong to this order.', 'barcode-fulfillment-orders' ),
						$product ? $product->get_name() : __( 'Unknown', 'barcode-fulfillment-orders' )
					),
					'product_name' => $product ? $product->get_name() : '',
					'summary'      => $this->build_summary( $session_id, $order ),
				)
			);
		}

		// Product belongs to this order — check quantities.
		$product_id   = (int) $matched_item->get_product_id();
		$variation_id = (int) $matched_item->get_variation_id();
		$ordered_qty  = (int) $matched_item->get_quantity();

		// Count scans so far for this item.
		$summary_rows = BFO_Database::get_instance()->get_scan_summary_for_session( $session_id );
		$key          = $product_id . '_' . $variation_id;
		$already_scanned = 0;
		foreach ( $summary_rows as $row ) {
			if ( (int) $row['product_id'] === $product_id && (int) $row['variation_id'] === $variation_id ) {
				$already_scanned = (int) $row['scanned'];
				break;
			}
		}

		if ( $already_scanned >= $ordered_qty ) {
			// Over-scan.
			BFO_Database::get_instance()->insert_scan_log(
				array(
					'session_id'      => $session_id,
					'order_id'        => $order->get_id(),
					'product_id'      => $product_id,
					'variation_id'    => $variation_id,
					'barcode_scanned' => $barcode,
					'box_number'      => $box_number,
					'action'          => BFO_SCAN_ACTION_OVER_SCAN,
					'worker_id'       => $worker_id,
					'quantity'        => 1,
				)
			);
			wp_send_json_success(
				array(
					'type'    => 'over_scan',
					'message' => __( 'Already scanned the required quantity for this product!', 'barcode-fulfillment-orders' ),
					'summary' => $this->build_summary( $session_id, $order ),
				)
			);
		}

		// Success: log the scan.
		BFO_Database::get_instance()->insert_scan_log(
			array(
				'session_id'      => $session_id,
				'order_id'        => $order->get_id(),
				'product_id'      => $product_id,
				'variation_id'    => $variation_id,
				'barcode_scanned' => $barcode,
				'box_number'      => $box_number,
				'action'          => BFO_SCAN_ACTION_SCANNED,
				'worker_id'       => $worker_id,
				'quantity'        => 1,
			)
		);

		$new_total   = $already_scanned + 1;
		$all_done    = $this->all_items_accounted( $session_id, $order );

		wp_send_json_success(
			array(
				'type'         => 'success',
				/* translators: 1: current count, 2: total required */
				'message'      => sprintf( __( 'Scanned (%1$d/%2$d)', 'barcode-fulfillment-orders' ), $new_total, $ordered_qty ),
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'scanned'      => $new_total,
				'ordered'      => $ordered_qty,
				'all_done'     => $all_done,
				'summary'      => $this->build_summary( $session_id, $order ),
			)
		);
	}

	/**
	 * Handles wp_ajax_bfo_complete_order.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_complete_order() {
		$session_id = absint( $_POST['session_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		check_ajax_referer( 'bfo_scan_' . $session_id, 'security' );

		if ( ! current_user_can( BFO_CAPABILITY_PACK ) ) {
			wp_send_json_error( array( 'type' => 'error', 'message' => __( 'Permission denied.', 'barcode-fulfillment-orders' ) ), 403 );
		}

		$result = BFO_Packing_Session::get_instance()->complete( $session_id, get_current_user_id() );

		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => __( 'Order packed and ready to ship!', 'barcode-fulfillment-orders' ) ) );
		} else {
			wp_send_json_error(
				array(
					'message'     => $result['message'],
					'unaccounted' => $result['unaccounted'],
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Finds the first order item that matches a given product/variation ID.
	 *
	 * @since  1.0.0
	 * @param  WC_Order $order       The order.
	 * @param  int      $product_id  Product or variation ID to look for.
	 * @return WC_Order_Item_Product|null
	 */
	private function find_order_item( WC_Order $order, int $product_id ) {
		foreach ( $order->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			if ( (int) $item->get_variation_id() === $product_id ) {
				return $item;
			}
			if ( (int) $item->get_product_id() === $product_id ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Checks if every order item has been fully scanned or marked missing.
	 *
	 * @since  1.0.0
	 * @param  int      $session_id  Session ID.
	 * @param  WC_Order $order       The order.
	 * @return bool
	 */
	private function all_items_accounted( int $session_id, WC_Order $order ) {
		$unaccounted = BFO_Packing_Session::get_instance()->get_unaccounted_items( $session_id, $order );
		return empty( $unaccounted );
	}

	/**
	 * Builds a summary array of all line items with their scan status.
	 *
	 * Returned to the client on every scan response so the UI stays in sync.
	 *
	 * @since  1.0.0
	 * @param  int      $session_id  Session ID.
	 * @param  WC_Order $order       The order.
	 * @return array
	 */
	public function build_summary( int $session_id, WC_Order $order ) {
		$scan_rows = BFO_Database::get_instance()->get_scan_summary_for_session( $session_id );

		// Index by "productId_variationId".
		$indexed = array();
		foreach ( $scan_rows as $row ) {
			$indexed[ $row['product_id'] . '_' . $row['variation_id'] ] = $row;
		}

		$items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			/** @var WC_Order_Item_Product $item */
			$pid  = (int) $item->get_product_id();
			$vid  = (int) $item->get_variation_id();
			$qty  = (int) $item->get_quantity();
			$key  = $pid . '_' . $vid;

			$scanned = isset( $indexed[ $key ] ) ? (int) $indexed[ $key ]['scanned'] : 0;
			$missing = isset( $indexed[ $key ] ) ? (int) $indexed[ $key ]['missing'] : 0;

			$status = 'pending';
			if ( $scanned >= $qty ) {
				$status = 'complete';
			} elseif ( $scanned > 0 ) {
				$status = 'partial';
			} elseif ( $missing > 0 ) {
				$status = 'missing';
			}

			$product = $item->get_product();
			$items[] = array(
				'item_id'      => $item_id,
				'product_id'   => $pid,
				'variation_id' => $vid,
				'name'         => $item->get_name(),
				'sku'          => $product ? $product->get_sku() : '',
				'barcode'      => $product ? (string) $product->get_meta( BFO_META_PRODUCT_BARCODE, true ) : '',
				'ordered'      => $qty,
				'scanned'      => $scanned,
				'missing'      => $missing,
				'status'       => $status,
			);
		}

		return $items;
	}
}
