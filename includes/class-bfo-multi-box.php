<?php
/**
 * Multi-box packing support.
 *
 * Allows warehouse workers to split an order across multiple physical boxes.
 * Each box is tracked in {prefix}bfo_boxes with optional dimensions and weight.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BFO_Multi_Box
 *
 * @since 1.0.0
 */
class BFO_Multi_Box {

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var BFO_Multi_Box|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @since  1.0.0
	 * @return BFO_Multi_Box
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
		add_action( 'wp_ajax_bfo_add_box',    array( $this, 'ajax_add_box' ) );
		add_action( 'wp_ajax_bfo_update_box', array( $this, 'ajax_update_box' ) );
		add_action( 'wp_ajax_bfo_get_boxes',  array( $this, 'ajax_get_boxes' ) );
	}

	// -------------------------------------------------------------------------
	// Core API
	// -------------------------------------------------------------------------

	/**
	 * Creates a new box for a session and returns its data.
	 *
	 * @since  1.0.0
	 * @param  int $session_id  Session primary key.
	 * @return array  {success:bool, box:object|null, message:string}
	 */
	public function add_box( $session_id ) {
		if ( 'yes' !== get_option( BFO_OPTION_MULTI_BOX, 'yes' ) ) {
			return array( 'success' => false, 'message' => __( 'Multi-box packing is disabled.', 'barcode-fulfillment-orders' ) );
		}

		$session = BFO_Database::get_instance()->get_session( absint( $session_id ) );
		if ( ! $session || BFO_SESSION_ACTIVE !== $session->status ) {
			return array( 'success' => false, 'message' => __( 'No active session.', 'barcode-fulfillment-orders' ) );
		}

		// Determine next box number.
		$existing = BFO_Database::get_instance()->get_boxes_for_session( $session_id );
		$next_num = count( $existing ) + 1;

		$box_id = BFO_Database::get_instance()->insert_box( $session_id, (int) $session->order_id, $next_num );
		if ( ! $box_id ) {
			return array( 'success' => false, 'message' => __( 'Could not create box.', 'barcode-fulfillment-orders' ) );
		}

		// Update box count on the order.
		$order = wc_get_order( (int) $session->order_id );
		if ( $order ) {
			$order->update_meta_data( BFO_META_ORDER_BOX_COUNT, $next_num );
			$order->save_meta_data();
			$order->add_order_note(
				/* translators: %d: box number */
				sprintf( __( 'Box #%d created during packing.', 'barcode-fulfillment-orders' ), $next_num )
			);
		}

		return array(
			'success'    => true,
			'box_id'     => $box_id,
			'box_number' => $next_num,
			/* translators: %d: box number */
			'label'      => sprintf( __( 'Box %d', 'barcode-fulfillment-orders' ), $next_num ),
			'message'    => '',
		);
	}

	/**
	 * Updates weight/dimensions on a box.
	 *
	 * @since  1.0.0
	 * @param  int   $box_id   Box primary key.
	 * @param  array $data     Associative array with optional keys: weight, length, width, height, label.
	 * @return bool
	 */
	public function update_box( $box_id, $data ) {
		$allowed = array( 'weight', 'length', 'width', 'height', 'label' );
		$clean   = array();

		foreach ( $allowed as $field ) {
			if ( isset( $data[ $field ] ) ) {
				if ( 'label' === $field ) {
					$clean[ $field ] = sanitize_text_field( $data[ $field ] );
				} else {
					$val = str_replace( ',', '.', $data[ $field ] );
					$clean[ $field ] = is_numeric( $val ) ? floatval( $val ) : null;
				}
			}
		}

		if ( empty( $clean ) ) {
			return false;
		}

		return BFO_Database::get_instance()->update_box( absint( $box_id ), $clean );
	}

	/**
	 * Returns all boxes for a session, formatted for the client.
	 *
	 * @since  1.0.0
	 * @param  int $session_id  Session primary key.
	 * @return array
	 */
	public function get_boxes( $session_id ) {
		$rows  = BFO_Database::get_instance()->get_boxes_for_session( absint( $session_id ) );
		$boxes = array();

		foreach ( $rows as $box ) {
			$boxes[] = array(
				'id'         => (int) $box->id,
				'box_number' => (int) $box->box_number,
				/* translators: %d: box number */
				'label'      => $box->label ?: sprintf( __( 'Box %d', 'barcode-fulfillment-orders' ), $box->box_number ),
				'weight'     => $box->weight ? (float) $box->weight : null,
				'length'     => $box->length ? (float) $box->length : null,
				'width'      => $box->width  ? (float) $box->width  : null,
				'height'     => $box->height ? (float) $box->height : null,
			);
		}

		return $boxes;
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: creates a new box for the active session.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_add_box() {
		$session_id = absint( $_POST['session_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		check_ajax_referer( 'bfo_scan_' . $session_id, 'nonce' );

		if ( ! current_user_can( BFO_CAPABILITY_PACK ) ) {
			wp_send_json_error( null, 403 );
		}

		$result = $this->add_box( $session_id );
		$result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}

	/**
	 * AJAX: updates dimensions/weight on a box.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_update_box() {
		$session_id = absint( $_POST['session_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		check_ajax_referer( 'bfo_scan_' . $session_id, 'nonce' );

		if ( ! current_user_can( BFO_CAPABILITY_PACK ) ) {
			wp_send_json_error( null, 403 );
		}

		$box_id = absint( $_POST['box_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$data   = array_intersect_key(
			array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $_POST['data'] ?? array() ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			array_flip( array( 'weight', 'length', 'width', 'height', 'label' ) )
		);

		$ok = $this->update_box( $box_id, $data );
		$ok ? wp_send_json_success() : wp_send_json_error();
	}

	/**
	 * AJAX: returns all boxes for a session.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_get_boxes() {
		$session_id = absint( $_GET['session_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_ajax_referer( 'bfo_scan_' . $session_id, 'nonce' );

		if ( ! current_user_can( BFO_CAPABILITY_PACK ) ) {
			wp_send_json_error( null, 403 );
		}

		wp_send_json_success( array( 'boxes' => $this->get_boxes( $session_id ) ) );
	}
}
