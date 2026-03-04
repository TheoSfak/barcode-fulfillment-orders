<?php
/**
 * Missing product handling during packing sessions.
 *
 * Allows warehouse workers to mark individual order line items as "missing"
 * (with a reason) so orders can still be closed when an item can't be found.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BFO_Missing_Products
 *
 * @since 1.0.0
 */
class BFO_Missing_Products {

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var BFO_Missing_Products|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @since  1.0.0
	 * @return BFO_Missing_Products
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
		add_action( 'wp_ajax_bfo_mark_missing', array( $this, 'ajax_mark_missing' ) );
	}

	// -------------------------------------------------------------------------
	// Reason labels
	// -------------------------------------------------------------------------

	/**
	 * Returns the available missing-reason options.
	 *
	 * @since  1.0.0
	 * @return array  slug => label
	 */
	public static function get_reasons() {
		return array(
			'out_of_stock' => __( 'Out of stock', 'barcode-fulfillment-orders' ),
			'damaged'      => __( 'Item damaged', 'barcode-fulfillment-orders' ),
			'cant_locate'  => __( 'Cannot locate', 'barcode-fulfillment-orders' ),
			'other'        => __( 'Other', 'barcode-fulfillment-orders' ),
		);
	}

	/**
	 * Returns the human-readable label for a missing reason slug.
	 *
	 * @since  1.0.0
	 * @param  string $reason  Reason slug.
	 * @return string
	 */
	public static function reason_label( $reason ) {
		$reasons = self::get_reasons();
		return isset( $reasons[ $reason ] ) ? $reasons[ $reason ] : esc_html( $reason );
	}

	// -------------------------------------------------------------------------
	// Core logic
	// -------------------------------------------------------------------------

	/**
	 * Marks a line item as missing within a packing session.
	 *
	 * @since  1.0.0
	 * @param  int    $session_id   Session primary key.
	 * @param  int    $product_id   Product (or variation) ID.
	 * @param  int    $variation_id Variation ID (0 if not a variation).
	 * @param  int    $quantity     Number of units missing.
	 * @param  string $reason       Reason slug (see get_reasons()).
	 * @param  string $notes        Free-text additional notes.
	 * @param  int    $worker_id    Worker user ID.
	 * @param  int    $box_number   Current box number.
	 * @return array  {success:bool, message:string, summary:array}
	 */
	public function mark_missing( $session_id, $product_id, $variation_id, $quantity, $reason, $notes, $worker_id, $box_number = 1 ) {
		$session = BFO_Database::get_instance()->get_session( absint( $session_id ) );
		if ( ! $session || BFO_SESSION_ACTIVE !== $session->status ) {
			return array( 'success' => false, 'message' => __( 'No active session.', 'barcode-fulfillment-orders' ) );
		}

		$order = wc_get_order( (int) $session->order_id );
		if ( ! $order ) {
			return array( 'success' => false, 'message' => __( 'Order not found.', 'barcode-fulfillment-orders' ) );
		}

		// Validate reason.
		$valid_reasons = array_keys( self::get_reasons() );
		if ( ! in_array( $reason, $valid_reasons, true ) ) {
			$reason = 'other';
		}

		$reason_text = self::reason_label( $reason );
		if ( 'other' === $reason && ! empty( $notes ) ) {
			$reason_text .= ': ' . sanitize_text_field( $notes );
		}

		BFO_Database::get_instance()->insert_scan_log(
			array(
				'session_id'      => absint( $session_id ),
				'order_id'        => $order->get_id(),
				'product_id'      => absint( $product_id ),
				'variation_id'    => absint( $variation_id ),
				'barcode_scanned' => '',
				'box_number'      => absint( $box_number ),
				'action'          => BFO_SCAN_ACTION_MISSING,
				'missing_reason'  => sanitize_text_field( $reason_text ),
				'quantity'        => max( 1, absint( $quantity ) ),
				'worker_id'       => absint( $worker_id ),
			)
		);

		// Add an order note.
		$product = wc_get_product( $variation_id ?: $product_id );
		$name    = $product ? $product->get_name() : __( 'Unknown product', 'barcode-fulfillment-orders' );

		$order->add_order_note(
			sprintf(
				/* translators: 1: product name, 2: quantity, 3: reason */
				__( 'Missing item: %1$s (qty: %2$d) — Reason: %3$s', 'barcode-fulfillment-orders' ),
				$name,
				max( 1, absint( $quantity ) ),
				$reason_text
			)
		);

		// Apply missing-item policy if configured.
		$policy = get_option( BFO_OPTION_MISSING_POLICY, 'allow' );
		if ( 'approval' === $policy ) {
			$order->update_status( 'on-hold', __( 'Missing item requires manager approval before shipping.', 'barcode-fulfillment-orders' ) );
		}

		$scanner = BFO_Scanner::get_instance();
		$summary = $scanner->build_summary( absint( $session_id ), $order );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: product name */
				__( 'Marked %s as missing.', 'barcode-fulfillment-orders' ),
				$name
			),
			'summary' => $summary,
		);
	}

	// -------------------------------------------------------------------------
	// AJAX handler
	// -------------------------------------------------------------------------

	/**
	 * Handles wp_ajax_bfo_mark_missing.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_mark_missing() {
		$session_id = absint( $_POST['session_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		check_ajax_referer( 'bfo_scan_' . $session_id, 'security' );

		if ( ! current_user_can( BFO_CAPABILITY_PACK ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'barcode-fulfillment-orders' ) ), 403 );
		}

		$product_id   = absint( $_POST['product_id'] ?? 0 );   // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$variation_id = absint( $_POST['variation_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$quantity     = absint( $_POST['qty'] ?? $_POST['quantity'] ?? 1 );      // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$reason       = sanitize_text_field( wp_unslash( $_POST['reason'] ?? 'other' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$notes        = sanitize_text_field( wp_unslash( $_POST['notes'] ?? '' ) );       // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$box_number   = absint( $_POST['box_number'] ?? 1 );    // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = $this->mark_missing(
			$session_id,
			$product_id,
			$variation_id,
			$quantity,
			$reason,
			$notes,
			get_current_user_id(),
			$box_number
		);

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}
}
