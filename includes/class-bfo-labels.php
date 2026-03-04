<?php
/**
 * Barcode label & packing slip generation.
 *
 * Adds print-label buttons to the product and order edit pages, a bulk-print
 * action for the Products list, and an AJAX handler that streams the labels
 * print template to a dedicated browser tab.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BFO_Labels
 *
 * @since 1.0.0
 */
class BFO_Labels {

	/** @var BFO_Labels|null Singleton instance. */
	private static ?BFO_Labels $instance = null;

	// -------------------------------------------------------------------------
	// Singleton
	// -------------------------------------------------------------------------

	/**
	 * Returns or creates the singleton instance.
	 *
	 * @since  1.0.0
	 * @return BFO_Labels
	 */
	public static function instance(): BFO_Labels {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — registers all hooks.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		// Product edit screen button.
		add_action( 'woocommerce_product_options_sku', array( $this, 'product_label_button' ) );

		// Order edit screen meta box button.
		add_action( 'add_meta_boxes', array( $this, 'register_order_meta_box' ) );

		// Products list bulk action.
		add_filter( 'bulk_actions-edit-product', array( $this, 'register_bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_action_notice' ) );

		// AJAX print handlers.
		add_action( 'wp_ajax_bfo_print_product_label', array( $this, 'ajax_print_product_label' ) );
		add_action( 'wp_ajax_bfo_print_order_label',   array( $this, 'ajax_print_order_label' ) );
		add_action( 'wp_ajax_bfo_print_packing_slip',  array( $this, 'ajax_print_packing_slip' ) );
		add_action( 'wp_ajax_bfo_print_bulk_labels',   array( $this, 'ajax_print_bulk_labels' ) );
	}

	// -------------------------------------------------------------------------
	// Product Edit Screen
	// -------------------------------------------------------------------------

	/**
	 * Adds a "Print Label" button to the product SKU section.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function product_label_button(): void {
		global $post;

		if ( ! $post ) {
			return;
		}

		$nonce     = wp_create_nonce( 'bfo_print_product_label_' . $post->ID );
		$print_url = add_query_arg(
			array(
				'action'     => 'bfo_print_product_label',
				'product_id' => $post->ID,
				'_wpnonce'   => $nonce,
			),
			admin_url( 'admin-ajax.php' )
		);
		?>
		<p class="form-field">
			<label><?php esc_html_e( 'Barcode Label', 'barcode-fulfillment-orders' ); ?></label>
			<a href="<?php echo esc_url( $print_url ); ?>" target="_blank" class="button bfo-print-label-btn">
				<?php esc_html_e( 'Print Product Label', 'barcode-fulfillment-orders' ); ?>
			</a>
		</p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Order Edit Screen Meta Box
	// -------------------------------------------------------------------------

	/**
	 * Registers the label meta box on the order screens.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_order_meta_box(): void {
		$screens = array( 'shop_order', 'woocommerce_page_wc-orders' );
		foreach ( $screens as $screen ) {
			add_meta_box(
				'bfo-order-labels',
				__( 'Barcode Labels & Packing Slip', 'barcode-fulfillment-orders' ),
				array( $this, 'render_order_meta_box' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * Renders the order label meta box content.
	 *
	 * @since  1.0.0
	 * @param  WP_Post|WC_Order $post_or_order Post or order object.
	 * @return void
	 */
	public function render_order_meta_box( $post_or_order ): void {
		$order_id = $post_or_order instanceof WP_Post ? $post_or_order->ID : $post_or_order->get_id();

		$label_nonce  = wp_create_nonce( 'bfo_print_order_label_' . $order_id );
		$slip_nonce   = wp_create_nonce( 'bfo_print_packing_slip_' . $order_id );

		$label_url = add_query_arg(
			array( 'action' => 'bfo_print_order_label', 'order_id' => $order_id, '_wpnonce' => $label_nonce ),
			admin_url( 'admin-ajax.php' )
		);
		$slip_url = add_query_arg(
			array( 'action' => 'bfo_print_packing_slip', 'order_id' => $order_id, '_wpnonce' => $slip_nonce ),
			admin_url( 'admin-ajax.php' )
		);
		?>
		<p>
			<a href="<?php echo esc_url( $label_url ); ?>" target="_blank" class="button button-secondary" style="width:100%;text-align:center;margin-bottom:6px;">
				<?php esc_html_e( 'Print Order Label', 'barcode-fulfillment-orders' ); ?>
			</a>
		</p>
		<p>
			<a href="<?php echo esc_url( $slip_url ); ?>" target="_blank" class="button button-secondary" style="width:100%;text-align:center;">
				<?php esc_html_e( 'Print Packing Slip', 'barcode-fulfillment-orders' ); ?>
			</a>
		</p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Bulk Actions
	// -------------------------------------------------------------------------

	/**
	 * Registers a "Print barcode labels" entry in the Products list bulk actions.
	 *
	 * @since  1.0.0
	 * @param  array $actions Existing bulk actions.
	 * @return array
	 */
	public function register_bulk_action( array $actions ): array {
		$actions['bfo_bulk_print_labels'] = __( 'Print barcode labels', 'barcode-fulfillment-orders' );
		return $actions;
	}

	/**
	 * Handles the bulk label print request — redirects to the bulk print AJAX URL.
	 *
	 * @since  1.0.0
	 * @param  string $redirect_to Redirect URL.
	 * @param  string $action      Bulk action slug.
	 * @param  array  $post_ids    Selected post IDs.
	 * @return string
	 */
	public function handle_bulk_action( string $redirect_to, string $action, array $post_ids ): string {
		if ( 'bfo_bulk_print_labels' !== $action ) {
			return $redirect_to;
		}

		// Store IDs in a transient so the print page can retrieve them.
		$key = 'bfo_bulk_labels_' . md5( implode( ',', $post_ids ) . current_time( 'timestamp' ) );
		set_transient( $key, $post_ids, 5 * MINUTE_IN_SECONDS );

		$print_url = add_query_arg(
			array(
				'action'    => 'bfo_print_bulk_labels',
				'key'       => $key,
				'_wpnonce'  => wp_create_nonce( 'bfo_print_bulk_labels' ),
			),
			admin_url( 'admin-ajax.php' )
		);

		// Open in a new tab where possible — store the key for the notice then redirect.
		return add_query_arg( array( 'bfo_bulk_labels_key' => $key ), $redirect_to );
	}

	/**
	 * Shows a notice with a link to open the bulk print page.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function bulk_action_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( empty( $_GET['bfo_bulk_labels_key'] ) ) {
			return;
		}
		$key   = sanitize_key( $_GET['bfo_bulk_labels_key'] ); // phpcs:ignore
		$nonce = wp_create_nonce( 'bfo_print_bulk_labels' );
		$url   = add_query_arg( array( 'action' => 'bfo_print_bulk_labels', 'key' => $key, '_wpnonce' => $nonce ), admin_url( 'admin-ajax.php' ) );
		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<?php esc_html_e( 'Ready to print.', 'barcode-fulfillment-orders' ); ?>
				<a href="<?php echo esc_url( $url ); ?>" target="_blank" class="button button-primary">
					<?php esc_html_e( 'Open Print Page', 'barcode-fulfillment-orders' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX Print Handlers
	// -------------------------------------------------------------------------

	/**
	 * Streams the product label print template.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_print_product_label(): void {
		$product_id = absint( $_GET['product_id'] ?? 0 );

		if ( ! check_ajax_referer( 'bfo_print_product_label_' . $product_id, '_wpnonce', false )
			|| ! current_user_can( BFO_CAPABILITY_MANAGE ) ) {
			wp_die( esc_html__( 'Security check failed.', 'barcode-fulfillment-orders' ) );
		}

		$product = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			wp_die( esc_html__( 'Product not found.', 'barcode-fulfillment-orders' ) );
		}

		$this->stream_template(
			'print/product-label.php',
			array( 'product' => $product, 'products' => array( $product ) )
		);
	}

	/**
	 * Streams the order label print template.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_print_order_label(): void {
		$order_id = absint( $_GET['order_id'] ?? 0 );

		if ( ! check_ajax_referer( 'bfo_print_order_label_' . $order_id, '_wpnonce', false )
			|| ! current_user_can( BFO_CAPABILITY_PACK ) ) {
			wp_die( esc_html__( 'Security check failed.', 'barcode-fulfillment-orders' ) );
		}

		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'barcode-fulfillment-orders' ) );
		}

		$this->stream_template( 'print/order-label.php', array( 'order' => $order ) );
	}

	/**
	 * Streams the packing slip print template.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_print_packing_slip(): void {
		$order_id = absint( $_GET['order_id'] ?? 0 );

		if ( ! check_ajax_referer( 'bfo_print_packing_slip_' . $order_id, '_wpnonce', false )
			|| ! current_user_can( BFO_CAPABILITY_PACK ) ) {
			wp_die( esc_html__( 'Security check failed.', 'barcode-fulfillment-orders' ) );
		}

		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'barcode-fulfillment-orders' ) );
		}

		$this->stream_template( 'print/packing-slip.php', array( 'order' => $order ) );
	}

	/**
	 * Streams the bulk product label print template.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_print_bulk_labels(): void {
		if ( ! check_ajax_referer( 'bfo_print_bulk_labels', '_wpnonce', false )
			|| ! current_user_can( BFO_CAPABILITY_MANAGE ) ) {
			wp_die( esc_html__( 'Security check failed.', 'barcode-fulfillment-orders' ) );
		}

		$key     = sanitize_key( $_GET['key'] ?? '' );
		$ids     = $key ? get_transient( $key ) : array();
		$ids     = is_array( $ids ) ? array_map( 'absint', $ids ) : array();

		if ( empty( $ids ) ) {
			wp_die( esc_html__( 'No products found for bulk label print, or the request has expired.', 'barcode-fulfillment-orders' ) );
		}

		$products = array_filter( array_map( 'wc_get_product', $ids ) );

		$this->stream_template( 'print/product-label.php', array( 'products' => $products ) );

		delete_transient( $key );
	}

	// -------------------------------------------------------------------------
	// Template Helper
	// -------------------------------------------------------------------------

	/**
	 * Loads and renders a print template, then exits.
	 *
	 * Looks in the active theme's `barcode-fulfillment-orders/` folder first,
	 * then falls back to the plugin's `templates/` folder.
	 *
	 * @since  1.0.0
	 * @param  string $template_name Template file name relative to `templates/`.
	 * @param  array  $args          Variables to extract into the template scope.
	 * @return void
	 */
	private function stream_template( string $template_name, array $args = array() ): void {
		$theme_file  = get_stylesheet_directory() . '/barcode-fulfillment-orders/' . $template_name;
		$plugin_file = BFO_PLUGIN_DIR . 'templates/' . $template_name;

		$template = file_exists( $theme_file ) ? $theme_file : $plugin_file;

		if ( ! file_exists( $template ) ) {
			wp_die( esc_html__( 'Print template not found.', 'barcode-fulfillment-orders' ) );
		}

		// Suppress admin chrome.
		define( 'DOING_AJAX', true );

		// phpcs:ignore WordPress.PHP.DontExtract
		extract( $args, EXTR_SKIP );

		header( 'Content-Type: text/html; charset=utf-8' );
		include $template;
		exit;
	}
}
