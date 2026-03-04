<?php
/**
 * Registers custom WooCommerce order statuses for the fulfillment workflow.
 *
 * Statuses added:
 *   wc-bfo-packing  →  "Packing"        (worker actively packing the order)
 *   wc-bfo-packed   →  "Packed"         (ready to ship)
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BFO_Order_Status
 *
 * @since 1.0.0
 */
class BFO_Order_Status {

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var BFO_Order_Status|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @since  1.0.0
	 * @return BFO_Order_Status
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
	 * Constructor — registers all hooks.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		add_action( 'init',                        array( $this, 'register_statuses' ) );
		add_filter( 'wc_order_statuses',           array( $this, 'add_to_wc_statuses' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_payment', array( $this, 'add_to_payment_statuses' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_bulk_actions' ) );
		add_action( 'admin_head',                  array( $this, 'status_colors' ) );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'friendly_status_label' ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Status registration
	// -------------------------------------------------------------------------

	/**
	 * Registers the custom order statuses with WordPress.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_statuses() {
		register_post_status(
			'wc-' . BFO_STATUS_PACKING,
			array(
				'label'                     => _x( 'Packing', 'Order status', 'barcode-fulfillment-orders' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop( 'Packing <span class="count">(%s)</span>', 'Packing <span class="count">(%s)</span>', 'barcode-fulfillment-orders' ),
			)
		);

		register_post_status(
			'wc-' . BFO_STATUS_PACKED,
			array(
				'label'                     => _x( 'Packed', 'Order status', 'barcode-fulfillment-orders' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop( 'Packed <span class="count">(%s)</span>', 'Packed <span class="count">(%s)</span>', 'barcode-fulfillment-orders' ),
			)
		);

		register_post_status(
			'wc-' . BFO_STATUS_SHIPPED,
			array(
				'label'                     => _x( 'Shipped', 'Order status', 'barcode-fulfillment-orders' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop( 'Shipped <span class="count">(%s)</span>', 'Shipped <span class="count">(%s)</span>', 'barcode-fulfillment-orders' ),
			)
		);
	}

	/**
	 * Adds the custom statuses to WooCommerce's known status list.
	 *
	 * @since  1.0.0
	 * @param  array $statuses  Existing WC statuses.
	 * @return array
	 */
	public function add_to_wc_statuses( $statuses ) {
		$new = array();
		foreach ( $statuses as $key => $label ) {
			$new[ $key ] = $label;
			// Insert after 'processing'.
			if ( 'wc-processing' === $key ) {
				$new[ 'wc-' . BFO_STATUS_PACKING ] = _x( 'Packing', 'Order status', 'barcode-fulfillment-orders' );
				$new[ 'wc-' . BFO_STATUS_PACKED ]  = _x( 'Packed',  'Order status', 'barcode-fulfillment-orders' );
				$new[ 'wc-' . BFO_STATUS_SHIPPED ] = _x( 'Shipped', 'Order status', 'barcode-fulfillment-orders' );
			}
		}
		return $new;
	}

	/**
	 * Allows payment on custom statuses (if order is somehow re-processed).
	 *
	 * @since  1.0.0
	 * @param  array $statuses  Valid payment statuses.
	 * @return array
	 */
	public function add_to_payment_statuses( $statuses ) {
		$statuses[] = BFO_STATUS_PACKING;
		$statuses[] = BFO_STATUS_PACKED;
		return $statuses;
	}

	/**
	 * Adds custom statuses to the admin order list bulk actions.
	 *
	 * @since  1.0.0
	 * @param  array $actions  Existing bulk actions.
	 * @return array
	 */
	public function add_bulk_actions( $actions ) {
		$actions[ 'mark_' . BFO_STATUS_PACKED ]   = __( 'Change status to Packed',  'barcode-fulfillment-orders' );
		$actions[ 'mark_' . BFO_STATUS_SHIPPED ]  = __( 'Change status to Shipped', 'barcode-fulfillment-orders' );
		return $actions;
	}

	/**
	 * Injects status badge colours into the admin head.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function status_colors() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}
		?>
		<style>
		.order-status.status-<?php echo esc_attr( BFO_STATUS_PACKING ); ?> {
			background: #f0a30a;
			color: #fff;
		}
		.order-status.status-<?php echo esc_attr( BFO_STATUS_PACKED ); ?> {
			background: #2e7d32;
			color: #fff;
		}
		.order-status.status-<?php echo esc_attr( BFO_STATUS_SHIPPED ); ?> {
			background: #1565c0;
			color: #fff;
		}
		</style>
		<?php
	}

	/**
	 * Replaces the raw status slug with a friendly label on the My Account page.
	 *
	 * @since  1.0.0
	 * @param  array    $actions  Account actions.
	 * @param  WC_Order $order    The order.
	 * @return array
	 */
	public function friendly_status_label( $actions, $order ) {
		if ( $order->has_status( BFO_STATUS_PACKING ) ) {
			// No specific action needed — WC reads the label from register_post_status.
		}
		return $actions;
	}

	// -------------------------------------------------------------------------
	// Static helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns the customer-facing label for a given order status slug.
	 *
	 * @since  1.0.0
	 * @param  string $status  Status slug (without wc- prefix).
	 * @return string
	 */
	public static function customer_label( $status ) {
		$map = array(
			BFO_STATUS_PACKING => __( 'Your order is being prepared', 'barcode-fulfillment-orders' ),
			BFO_STATUS_PACKED  => __( 'Your order is ready to ship',  'barcode-fulfillment-orders' ),
			BFO_STATUS_SHIPPED => __( 'Your order has been shipped',   'barcode-fulfillment-orders' ),
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : wc_get_order_status_name( $status );
	}
}
