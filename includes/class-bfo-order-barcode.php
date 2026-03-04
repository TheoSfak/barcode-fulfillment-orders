<?php
/**
 * Order barcode auto-generation and display.
 *
 * - Generates a unique barcode on order creation (checkout or admin).
 * - Displays the barcode as a meta box in the WC admin order detail screen.
 * - Adds a "Barcode" column to the admin orders list.
 * - Includes the barcode in the admin New Order email.
 * - HPOS-compatible via WC CRUD methods.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BFO_Order_Barcode
 *
 * @since 1.0.0
 */
class BFO_Order_Barcode {

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var BFO_Order_Barcode|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @since  1.0.0
	 * @return BFO_Order_Barcode
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
		// Generate barcode on order creation.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'generate_on_checkout' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'generate_on_api_checkout' ) );
		add_action( 'woocommerce_new_order', array( $this, 'generate_on_admin_create' ) );

		// Admin order detail meta box.
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );

		// Admin orders list column.
		add_filter( 'manage_edit-shop_order_columns',            array( $this, 'add_column' ) );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column',     array( $this, 'render_column' ), 10, 2 );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_column_hpos' ), 10, 2 );

		// Include barcode in New Order admin email.
		add_action( 'woocommerce_email_order_meta', array( $this, 'add_to_email' ), 10, 4 );
	}

	// -------------------------------------------------------------------------
	// Barcode generation
	// -------------------------------------------------------------------------

	/**
	 * Generates and stores a barcode after a standard checkout order.
	 *
	 * @since  1.0.0
	 * @param  int       $order_id       Order ID.
	 * @param  array     $posted_data    Posted checkout data.
	 * @param  WC_Order  $order          The new order object.
	 * @return void
	 */
	public function generate_on_checkout( $order_id, $posted_data, $order ) {
		$this->assign_barcode( $order );
	}

	/**
	 * Generates a barcode after a Store API (Blocks checkout) order.
	 *
	 * @since  1.0.0
	 * @param  WC_Order $order  The new order.
	 * @return void
	 */
	public function generate_on_api_checkout( $order ) {
		$this->assign_barcode( $order );
	}

	/**
	 * Generates a barcode when an order is manually created in the admin.
	 *
	 * @since  1.0.0
	 * @param  int $order_id  Order ID.
	 * @return void
	 */
	public function generate_on_admin_create( $order_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( $order ) {
			$this->assign_barcode( $order );
		}
	}

	/**
	 * Assigns a unique barcode to an order if it does not already have one.
	 *
	 * @since  1.0.0
	 * @param  WC_Order $order  The order.
	 * @return void
	 */
	private function assign_barcode( WC_Order $order ) {
		if ( $order->get_meta( BFO_META_ORDER_BARCODE, true ) ) {
			return; // Already assigned.
		}

		$barcode = bfo_generate_unique_barcode( 'order' );
		$order->update_meta_data( BFO_META_ORDER_BARCODE, $barcode );
		$order->save_meta_data();
	}

	// -------------------------------------------------------------------------
	// Admin order detail meta box
	// -------------------------------------------------------------------------

	/**
	 * Registers the order barcode meta box for both legacy and HPOS screens.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_meta_box() {
		$screens = array( 'shop_order', 'woocommerce_page_wc-orders' );

		foreach ( $screens as $screen ) {
			add_meta_box(
				'bfo_order_barcode',
				esc_html__( 'Order Barcode', 'barcode-fulfillment-orders' ),
				array( $this, 'render_meta_box' ),
				$screen,
				'side',
				'high'
			);
		}
	}

	/**
	 * Renders the order barcode meta box content.
	 *
	 * @since  1.0.0
	 * @param  WP_Post|WC_Order $post_or_order  Post or order object depending on screen.
	 * @return void
	 */
	public function render_meta_box( $post_or_order ) {
		$order = ( $post_or_order instanceof WC_Order )
			? $post_or_order
			: wc_get_order( $post_or_order->ID );

		if ( ! $order ) {
			return;
		}

		$barcode = $order->get_meta( BFO_META_ORDER_BARCODE, true );
		$format  = get_option( BFO_OPTION_ORDER_BARCODE_FORMAT, 'code128' );

		if ( empty( $barcode ) ) {
			echo '<p>' . esc_html__( 'No barcode assigned yet.', 'barcode-fulfillment-orders' ) . '</p>';

			$nonce = wp_create_nonce( 'bfo_regenerate_order_barcode_' . $order->get_id() );
			printf(
				'<button type="button" class="button bfo-regen-order-barcode" data-order-id="%d" data-nonce="%s">%s</button>',
				absint( $order->get_id() ),
				esc_attr( $nonce ),
				esc_html__( 'Generate Barcode', 'barcode-fulfillment-orders' )
			);
			return;
		}

		echo '<p style="text-align:center;"><code>' . esc_html( $barcode ) . '</code></p>';
		echo BFO_Barcode_Generator::get_instance()->render_inline( $barcode, $format, 50, 2 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG generated internally

		// Pack Order link / status indicator.
		$session = bfo_get_session_for_order( $order->get_id() );
		if ( $session && BFO_SESSION_ACTIVE === $session->status ) {
			$worker = get_userdata( (int) $session->worker_id );
			echo '<p class="description">' .
				/* translators: %s: worker display name */
				esc_html( sprintf( __( 'Being packed by: %s', 'barcode-fulfillment-orders' ), $worker ? $worker->display_name : __( 'Unknown', 'barcode-fulfillment-orders' ) ) ) .
				'</p>';
		} elseif ( $order->has_status( BFO_STATUS_PACKED ) ) {
			echo '<p style="text-align:center;color:#2e7d32;font-weight:600;">&#10003; ' .
				esc_html__( 'Order Packed', 'barcode-fulfillment-orders' ) .
				'</p>';
		} elseif ( $order->has_status( BFO_STATUS_SHIPPED ) ) {
			echo '<p style="text-align:center;color:#1565c0;font-weight:600;">&#10003; ' .
				esc_html__( 'Order Shipped', 'barcode-fulfillment-orders' ) .
				'</p>';
		} else {
			printf(
				'<p><a href="%s" class="button button-primary" style="width:100%%;text-align:center;">%s</a></p>',
				esc_url( bfo_fulfillment_url( $order->get_id() ) ),
				esc_html__( 'Pack Order', 'barcode-fulfillment-orders' )
			);
		}
	}

	// -------------------------------------------------------------------------
	// Orders list column
	// -------------------------------------------------------------------------

	/**
	 * Adds the "Barcode" column to the admin orders list.
	 *
	 * @since  1.0.0
	 * @param  array $columns  Existing columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new['bfo_order_barcode'] = esc_html__( 'Barcode', 'barcode-fulfillment-orders' );
			}
		}
		return $new;
	}

	/**
	 * Renders the barcode column for legacy orders list.
	 *
	 * @since  1.0.0
	 * @param  string $column   Column key.
	 * @param  int    $post_id  Order post ID.
	 * @return void
	 */
	public function render_column( $column, $post_id ) {
		if ( 'bfo_order_barcode' !== $column ) {
			return;
		}
		$order = wc_get_order( absint( $post_id ) );
		$this->render_column_content( $order );
	}

	/**
	 * Renders the barcode column for HPOS orders list.
	 *
	 * @since  1.0.0
	 * @param  string   $column  Column key.
	 * @param  WC_Order $order   Order object.
	 * @return void
	 */
	public function render_column_hpos( $column, $order ) {
		if ( 'bfo_order_barcode' !== $column ) {
			return;
		}
		$this->render_column_content( $order );
	}

	/**
	 * Shared column output logic.
	 *
	 * @since  1.0.0
	 * @param  WC_Order|false $order  Order object.
	 * @return void
	 */
	private function render_column_content( $order ) {
		if ( ! $order ) {
			echo '—';
			return;
		}
		$barcode = $order->get_meta( BFO_META_ORDER_BARCODE, true );
		if ( $barcode ) {
			echo '<code style="font-size:11px;">' . esc_html( $barcode ) . '</code>';
		} else {
			echo '—';
		}
	}

	// -------------------------------------------------------------------------
	// Email barcode
	// -------------------------------------------------------------------------

	/**
	 * Appends the order barcode to the admin "New Order" email.
	 *
	 * @since  1.0.0
	 * @param  WC_Order $order          The order.
	 * @param  bool     $sent_to_admin  True when sending to admin.
	 * @param  bool     $plain_text     True for plain-text email.
	 * @param  object   $email          The email object.
	 * @return void
	 */
	public function add_to_email( $order, $sent_to_admin, $plain_text, $email ) {
		if ( ! $sent_to_admin ) {
			return;
		}

		// Only new_order and new_order_notification emails.
		if ( ! in_array( $email->id, array( 'new_order', 'new_renewal_order' ), true ) ) {
			return;
		}

		$barcode = $order->get_meta( BFO_META_ORDER_BARCODE, true );
		if ( empty( $barcode ) ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Order Barcode:', 'barcode-fulfillment-orders' ) . ' ' . esc_html( $barcode ) . "\n";
			return;
		}

		$format = get_option( BFO_OPTION_ORDER_BARCODE_FORMAT, 'code128' );
		$uri    = BFO_Barcode_Generator::get_instance()->generate_data_uri( $barcode, $format, 50, 2 );

		printf(
			'<h2>%s</h2><p><code>%s</code></p><p><img src="%s" alt="%s" style="max-width:250px;"></p>',
			esc_html__( 'Order Barcode', 'barcode-fulfillment-orders' ),
			esc_html( $barcode ),
			esc_attr( $uri ),
			esc_attr( $barcode )
		);
	}
}
