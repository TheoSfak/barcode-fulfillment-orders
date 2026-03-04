<?php
/**
 * Shipping integration: create shipments and purchase carrier labels.
 *
 * Supports Shippo (https://goshippo.com) and EasyPost (https://easypost.com).
 *
 * Flow:
 *   1. Admin clicks "Get Rates" → AJAX creates a shipment with the carrier API
 *      and returns normalised rate options.
 *   2. Admin picks a rate and clicks "Buy Label" → AJAX purchases the label.
 *   3. Tracking number + label URL are stored as order meta.
 *   4. Order transitions to "Shipped" status automatically.
 *   5. A tracking note is added to the order timeline.
 *
 * Auto-ship mode: if BFO_OPTION_AUTO_SHIP_ON_PACK = 'yes', the cheapest
 * available rate is purchased automatically when an order hits "Packed".
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BFO_Shipping
 *
 * @since 1.1.0
 */
class BFO_Shipping {

	/** @var BFO_Shipping|null */
	private static ?BFO_Shipping $instance = null;

	// -------------------------------------------------------------------------
	// Singleton
	// -------------------------------------------------------------------------

	/**
	 * @since  1.1.0
	 * @return BFO_Shipping
	 */
	public static function get_instance(): BFO_Shipping {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** @since 1.1.0 */
	public static function instance(): BFO_Shipping {
		return self::get_instance();
	}

	/**
	 * Constructor — registers hooks.
	 *
	 * @since 1.1.0
	 */
	private function __construct() {
		add_action( 'add_meta_boxes',       array( $this, 'register_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX handlers (admin-only).
		add_action( 'wp_ajax_bfo_get_shipping_rates',  array( $this, 'ajax_get_rates' ) );
		add_action( 'wp_ajax_bfo_buy_shipping_label',  array( $this, 'ajax_buy_label' ) );
		add_action( 'wp_ajax_bfo_void_shipping_label', array( $this, 'ajax_void_label' ) );

		// Auto-ship when order is marked Packed.
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_auto_ship' ), 20, 3 );
	}

	// -------------------------------------------------------------------------
	// Meta box
	// -------------------------------------------------------------------------

	/**
	 * Registers the shipping meta box on both classic and HPOS order screens.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	public function register_meta_box(): void {
		foreach ( array( 'shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
			add_meta_box(
				'bfo_shipping',
				esc_html__( 'BFO Shipping', 'barcode-fulfillment-orders' ),
				array( $this, 'render_meta_box' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * Renders the shipping meta box.
	 * Shows tracking info if the label has been purchased, or "Get Rates" otherwise.
	 *
	 * @since  1.1.0
	 * @param  WP_Post|WC_Order $post_or_order
	 * @return void
	 */
	public function render_meta_box( $post_or_order ): void {
		$order = ( $post_or_order instanceof WC_Order )
			? $post_or_order
			: wc_get_order( $post_or_order->ID );

		if ( ! $order ) {
			return;
		}

		$provider      = get_option( BFO_OPTION_SHIPPING_PROVIDER, 'none' );
		$tracking_num  = $order->get_meta( BFO_META_TRACKING_NUMBER, true );
		$tracking_url  = $order->get_meta( BFO_META_TRACKING_URL, true );
		$label_url     = $order->get_meta( BFO_META_SHIPPING_LABEL_URL, true );
		$carrier       = $order->get_meta( BFO_META_TRACKING_CARRIER, true );
		$nonce         = wp_create_nonce( 'bfo_shipping_' . $order->get_id() );

		echo '<div class="bfo-shipping-info">';

		if ( $tracking_num ) {
			// ---- Label already purchased — show tracking info. ----
			echo '<p><strong>' . esc_html__( 'Carrier:', 'barcode-fulfillment-orders' ) . '</strong> '
				. esc_html( $carrier ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Tracking #:', 'barcode-fulfillment-orders' ) . '</strong>'
				. '<br><code style="word-break:break-all;">' . esc_html( $tracking_num ) . '</code></p>';

			if ( $tracking_url ) {
				printf(
					'<p><a href="%s" target="_blank" rel="noopener" class="button button-small">%s ↗</a></p>',
					esc_url( $tracking_url ),
					esc_html__( 'Track Package', 'barcode-fulfillment-orders' )
				);
			}

			if ( $label_url ) {
				printf(
					'<p><a href="%s" target="_blank" rel="noopener" class="button button-small">%s</a></p>',
					esc_url( $label_url ),
					esc_html__( 'Download Label (PDF)', 'barcode-fulfillment-orders' )
				);
			}

			if ( 'none' !== $provider ) {
				printf(
					'<p><button type="button" class="button bfo-void-label-btn" data-order-id="%d" data-nonce="%s">%s</button></p>',
					absint( $order->get_id() ),
					esc_attr( $nonce ),
					esc_html__( 'Void Label', 'barcode-fulfillment-orders' )
				);
			}
		} elseif ( 'none' === $provider ) {
			// ---- No provider configured. ----
			echo '<p class="description">'
				. esc_html__( 'No shipping provider configured.', 'barcode-fulfillment-orders' )
				. ' <a href="'
				. esc_url( admin_url( 'admin.php?page=bfo-settings&tab=shipping' ) )
				. '">' . esc_html__( 'Configure now →', 'barcode-fulfillment-orders' ) . '</a></p>';
		} else {
			// ---- Provider configured, no label yet. ----
			$shippable = array( BFO_STATUS_PACKED, 'processing', 'on-hold' );
			if ( ! in_array( $order->get_status(), $shippable, true ) ) {
				echo '<p class="description">'
					. esc_html__( 'Rates available once the order is Packed.', 'barcode-fulfillment-orders' )
					. '</p>';
			} else {
				printf(
					'<button type="button" class="button button-primary bfo-get-rates-btn" style="width:100%%;margin-bottom:6px;" data-order-id="%d" data-nonce="%s">%s</button>',
					absint( $order->get_id() ),
					esc_attr( $nonce ),
					esc_html__( 'Get Shipping Rates', 'barcode-fulfillment-orders' )
				);
				printf(
					'<p class="description">%s</p>',
					/* translators: %s: shipping provider name */
					esc_html( sprintf( __( 'via %s', 'barcode-fulfillment-orders' ), ucfirst( $provider ) ) )
				);
			}
		}

		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	/**
	 * Enqueues the shipping JS and admin CSS on order edit screens.
	 *
	 * @since  1.1.0
	 * @param  string $hook  Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		$order_hooks = array( 'post.php', 'post-new.php', 'woocommerce_page_wc-orders', 'toplevel_page_bfo-queue' );
		if ( ! in_array( $hook, $order_hooks, true ) ) {
			return;
		}

		wp_enqueue_script(
			'bfo-shipping',
			BFO_PLUGIN_URL . 'assets/js/bfo-shipping.js',
			array( 'jquery' ),
			BFO_VERSION,
			true
		);

		wp_localize_script(
			'bfo-shipping',
			'bfoShipping',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'loading'         => __( 'Loading…', 'barcode-fulfillment-orders' ),
					'getRates'        => __( 'Get Shipping Rates', 'barcode-fulfillment-orders' ),
					'selectRate'      => __( 'Select a Shipping Rate', 'barcode-fulfillment-orders' ),
					'noRates'         => __( 'No rates available. Check your From Address and parcel dimensions in Settings → Shipping.', 'barcode-fulfillment-orders' ),
					'buyLabel'        => __( 'Buy Label', 'barcode-fulfillment-orders' ),
					'buying'          => __( 'Purchasing…', 'barcode-fulfillment-orders' ),
					'labelPurchased'  => __( 'Label purchased! Tracking:', 'barcode-fulfillment-orders' ),
					'selectRateFirst' => __( 'Please select a rate first.', 'barcode-fulfillment-orders' ),
					'voidConfirm'     => __( 'Void this label? This cannot be undone.', 'barcode-fulfillment-orders' ),
					'error'           => __( 'An error occurred. Please try again.', 'barcode-fulfillment-orders' ),
					'days'            => __( 'day(s)', 'barcode-fulfillment-orders' ),
					'close'           => __( 'Close', 'barcode-fulfillment-orders' ),
				),
			)
		);

		wp_enqueue_style(
			'bfo-admin',
			BFO_PLUGIN_URL . 'assets/css/bfo-admin.css',
			array(),
			BFO_VERSION
		);
	}

	// -------------------------------------------------------------------------
	// AJAX: Get rates
	// -------------------------------------------------------------------------

	/**
	 * Creates a shipment with the configured provider and returns available rates.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	public function ajax_get_rates(): void {
		$order_id = absint( $_POST['order_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! check_ajax_referer( 'bfo_shipping_' . $order_id, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'barcode-fulfillment-orders' ) ), 403 );
		}

		if ( ! current_user_can( BFO_CAPABILITY_MANAGE ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'barcode-fulfillment-orders' ) ), 403 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'barcode-fulfillment-orders' ) ) );
		}

		$result = $this->create_shipment( $order );

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}

		wp_send_json_success(
			array(
				'shipment_id' => $result['shipment_id'],
				'rates'       => $result['rates'],
			)
		);
	}

	// -------------------------------------------------------------------------
	// AJAX: Buy label
	// -------------------------------------------------------------------------

	/**
	 * Purchases a specific rate, stores tracking meta, and transitions the order to Shipped.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	public function ajax_buy_label(): void {
		$order_id    = absint( $_POST['order_id'] ?? 0 );                                  // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$rate_id     = sanitize_text_field( wp_unslash( $_POST['rate_id']     ?? '' ) );   // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$shipment_id = sanitize_text_field( wp_unslash( $_POST['shipment_id'] ?? '' ) );   // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! check_ajax_referer( 'bfo_shipping_' . $order_id, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'barcode-fulfillment-orders' ) ), 403 );
		}

		if ( ! current_user_can( BFO_CAPABILITY_MANAGE ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'barcode-fulfillment-orders' ) ), 403 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'barcode-fulfillment-orders' ) ) );
		}

		$result = $this->purchase_label( $shipment_id, $rate_id );

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}

		$this->store_tracking( $order, $result );

		// Remove the woocommerce_order_status_changed hook temporarily so we
		// don't recurse into maybe_auto_ship when we update the status here.
		remove_action( 'woocommerce_order_status_changed', array( $this, 'maybe_auto_ship' ), 20 );

		$order->update_status(
			BFO_STATUS_SHIPPED,
			/* translators: 1: carrier, 2: tracking number */
			sprintf( __( 'Shipped via %1$s — tracking: %2$s', 'barcode-fulfillment-orders' ), $result['carrier'], $result['tracking_number'] )
		);

		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_auto_ship' ), 20, 3 );

		// Rebuild meta box HTML for inline DOM update.
		ob_start();
		$this->render_meta_box( $order );
		$html = ob_get_clean();

		wp_send_json_success(
			array(
				'tracking_number' => $result['tracking_number'],
				'html'            => $html,
			)
		);
	}

	// -------------------------------------------------------------------------
	// AJAX: Void label
	// -------------------------------------------------------------------------

	/**
	 * Voids a previously purchased label and reverts the order to Packed.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	public function ajax_void_label(): void {
		$order_id = absint( $_POST['order_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! check_ajax_referer( 'bfo_shipping_' . $order_id, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'barcode-fulfillment-orders' ) ), 403 );
		}

		if ( ! current_user_can( BFO_CAPABILITY_MANAGE ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'barcode-fulfillment-orders' ) ), 403 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'barcode-fulfillment-orders' ) ) );
		}

		$provider       = get_option( BFO_OPTION_SHIPPING_PROVIDER, 'none' );
		$transaction_id = $order->get_meta( BFO_META_TRANSACTION_ID, true );
		$shipment_id    = $order->get_meta( BFO_META_SHIPMENT_ID, true );

		$voided = false;

		if ( $transaction_id || $shipment_id ) {
			if ( 'shippo' === $provider && $transaction_id ) {
				$voided = $this->void_shippo( $transaction_id );
			} elseif ( 'easypost' === $provider && $shipment_id ) {
				$voided = $this->void_easypost( $shipment_id );
			} else {
				$voided = true; // Nothing to void remotely.
			}
		} else {
			$voided = true; // No remote ID stored — just clear locally.
		}

		if ( ! $voided ) {
			wp_send_json_error(
				array( 'message' => __( 'Could not void label with the carrier. It may have already been used or is past the void window.', 'barcode-fulfillment-orders' ) )
			);
		}

		$this->clear_tracking( $order );

		remove_action( 'woocommerce_order_status_changed', array( $this, 'maybe_auto_ship' ), 20 );
		$order->update_status( BFO_STATUS_PACKED, __( 'Shipping label voided.', 'barcode-fulfillment-orders' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_auto_ship' ), 20, 3 );

		wp_send_json_success();
	}

	// -------------------------------------------------------------------------
	// Auto-ship on Packed
	// -------------------------------------------------------------------------

	/**
	 * Automatically purchases the cheapest rate when an order is marked Packed,
	 * if BFO_OPTION_AUTO_SHIP_ON_PACK is enabled and a provider is configured.
	 *
	 * @since  1.1.0
	 * @param  int    $order_id   WC order ID.
	 * @param  string $old_status Previous status slug (without wc- prefix).
	 * @param  string $new_status New status slug.
	 * @return void
	 */
	public function maybe_auto_ship( int $order_id, string $old_status, string $new_status ): void {
		if ( BFO_STATUS_PACKED !== $new_status ) {
			return;
		}
		if ( 'yes' !== get_option( BFO_OPTION_AUTO_SHIP_ON_PACK, 'no' ) ) {
			return;
		}
		if ( 'none' === get_option( BFO_OPTION_SHIPPING_PROVIDER, 'none' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( BFO_META_TRACKING_NUMBER, true ) ) {
			return; // Already has tracking — skip.
		}

		$shipment_result = $this->create_shipment( $order );

		if ( ! $shipment_result['success'] || empty( $shipment_result['rates'] ) ) {
			$order->add_order_note(
				__( 'BFO Auto-Ship: no rates returned. Please purchase the label manually.', 'barcode-fulfillment-orders' )
			);
			return;
		}

		// Buy cheapest (already sorted asc by price).
		$best   = $shipment_result['rates'][0];
		$result = $this->purchase_label( $shipment_result['shipment_id'], $best['id'] );

		if ( ! $result['success'] ) {
			/* translators: %s: error message */
			$order->add_order_note( sprintf( __( 'BFO Auto-Ship failed: %s', 'barcode-fulfillment-orders' ), $result['message'] ) );
			return;
		}

		$this->store_tracking( $order, $result );

		// Prevent recursion when transitioning to Shipped.
		remove_action( 'woocommerce_order_status_changed', array( $this, 'maybe_auto_ship' ), 20 );
		$order->update_status(
			BFO_STATUS_SHIPPED,
			/* translators: 1: carrier, 2: tracking number */
			sprintf( __( 'Auto-shipped via %1$s — tracking: %2$s', 'barcode-fulfillment-orders' ), $result['carrier'], $result['tracking_number'] )
		);
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_auto_ship' ), 20, 3 );
	}

	// -------------------------------------------------------------------------
	// Provider-agnostic wrappers
	// -------------------------------------------------------------------------

	/**
	 * Creates a shipment with whichever provider is currently active.
	 *
	 * @since  1.1.0
	 * @param  WC_Order $order
	 * @return array{success:bool, shipment_id:string, rates:array, message:string}
	 */
	private function create_shipment( WC_Order $order ): array {
		$provider = get_option( BFO_OPTION_SHIPPING_PROVIDER, 'none' );
		$from     = $this->get_from_address();
		$to       = $this->get_to_address( $order );
		$parcel   = $this->get_default_parcel();

		if ( 'shippo' === $provider ) {
			return $this->create_shipment_shippo( $from, $to, $parcel );
		}
		if ( 'easypost' === $provider ) {
			return $this->create_shipment_easypost( $from, $to, $parcel );
		}

		return array( 'success' => false, 'message' => __( 'No shipping provider configured.', 'barcode-fulfillment-orders' ) );
	}

	/**
	 * Purchases a label with whichever provider is currently active.
	 *
	 * @since  1.1.0
	 * @param  string $shipment_id  Provider shipment ID.
	 * @param  string $rate_id      Provider rate ID.
	 * @return array{success:bool, tracking_number:string, tracking_url:string, label_url:string, carrier:string, transaction_id:string, shipment_id:string, message:string}
	 */
	private function purchase_label( string $shipment_id, string $rate_id ): array {
		$provider = get_option( BFO_OPTION_SHIPPING_PROVIDER, 'none' );

		if ( 'shippo' === $provider ) {
			return $this->buy_label_shippo( $rate_id );
		}
		if ( 'easypost' === $provider ) {
			return $this->buy_label_easypost( $shipment_id, $rate_id );
		}

		return array( 'success' => false, 'message' => __( 'No shipping provider configured.', 'barcode-fulfillment-orders' ) );
	}

	// -------------------------------------------------------------------------
	// Shippo API
	// -------------------------------------------------------------------------

	/**
	 * Creates a Shippo shipment and returns normalised rates sorted cheapest-first.
	 *
	 * @since  1.1.0
	 * @param  array $from    From-address array.
	 * @param  array $to      To-address array.
	 * @param  array $parcel  Parcel dimensions/weight array.
	 * @return array
	 */
	private function create_shipment_shippo( array $from, array $to, array $parcel ): array {
		$api_key = get_option( BFO_OPTION_SHIPPO_API_KEY, '' );

		$response = wp_remote_post(
			'https://api.goshippo.com/shipments/',
			array(
				'headers' => array(
					'Authorization' => 'ShippoToken ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'address_from' => $from,
						'address_to'   => $to,
						'parcels'      => array( $parcel ),
						'async'        => false,
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 201 !== $code ) {
			$msg = $data['detail'] ?? ( $data['non_field_errors'][0] ?? __( 'Shippo API error.', 'barcode-fulfillment-orders' ) );
			return array( 'success' => false, 'message' => is_array( $msg ) ? implode( ' ', $msg ) : (string) $msg );
		}

		$rates = array();
		foreach ( $data['rates'] ?? array() as $r ) {
			if ( 'SUCCESS' !== ( $r['object_state'] ?? '' ) ) {
				continue;
			}
			$rates[] = array(
				'id'       => $r['object_id'],
				'carrier'  => $r['provider'],
				'service'  => $r['servicelevel']['name'] ?? ( $r['servicelevel']['token'] ?? '' ),
				'price'    => $r['amount'],
				'currency' => $r['currency'],
				'days'     => $r['estimated_days'] ?? null,
			);
		}

		usort( $rates, static fn( $a, $b ) => (float) $a['price'] <=> (float) $b['price'] );

		return array(
			'success'     => true,
			'shipment_id' => $data['object_id'],
			'rates'       => $rates,
		);
	}

	/**
	 * Buys a Shippo label for the given rate ID.
	 *
	 * @since  1.1.0
	 * @param  string $rate_id  Shippo rate object_id.
	 * @return array
	 */
	private function buy_label_shippo( string $rate_id ): array {
		$api_key = get_option( BFO_OPTION_SHIPPO_API_KEY, '' );

		$response = wp_remote_post(
			'https://api.goshippo.com/transactions/',
			array(
				'headers' => array(
					'Authorization' => 'ShippoToken ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'rate'            => $rate_id,
						'label_file_type' => 'PDF',
						'async'           => false,
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 201 !== $code || 'SUCCESS' !== ( $data['object_state'] ?? '' ) ) {
			$msg = $data['messages'][0]['text'] ?? ( $data['detail'] ?? __( 'Shippo transaction failed.', 'barcode-fulfillment-orders' ) );
			return array( 'success' => false, 'message' => (string) $msg );
		}

		return array(
			'success'         => true,
			'tracking_number' => $data['tracking_number'] ?? '',
			'tracking_url'    => $data['tracking_url_provider'] ?? '',
			'label_url'       => $data['label_url'] ?? '',
			'carrier'         => $data['rate']['provider'] ?? '',
			'transaction_id'  => $data['object_id'],
			'shipment_id'     => '',
		);
	}

	/**
	 * Requests a refund (void) for a Shippo transaction.
	 *
	 * @since  1.1.0
	 * @param  string $transaction_id  Shippo transaction object_id.
	 * @return bool
	 */
	private function void_shippo( string $transaction_id ): bool {
		$api_key = get_option( BFO_OPTION_SHIPPO_API_KEY, '' );

		$response = wp_remote_post(
			'https://api.goshippo.com/refunds/',
			array(
				'headers' => array(
					'Authorization' => 'ShippoToken ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'transaction' => $transaction_id ) ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return in_array( (int) wp_remote_retrieve_response_code( $response ), array( 200, 201 ), true );
	}

	// -------------------------------------------------------------------------
	// EasyPost API
	// -------------------------------------------------------------------------

	/**
	 * Creates an EasyPost shipment and returns normalised rates sorted cheapest-first.
	 *
	 * @since  1.1.0
	 * @param  array $from    From-address array.
	 * @param  array $to      To-address array.
	 * @param  array $parcel  Parcel dimensions/weight array.
	 * @return array
	 */
	private function create_shipment_easypost( array $from, array $to, array $parcel ): array {
		$api_key = get_option( BFO_OPTION_EASYPOST_API_KEY, '' );

		$response = wp_remote_post(
			'https://api.easypost.com/v2/shipments',
			array(
				'headers' => array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Authorization' => 'Basic ' . base64_encode( $api_key . ':' ),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'shipment' => array(
							'from_address' => $from,
							'to_address'   => $to,
							'parcel'       => $parcel,
						),
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( ! in_array( $code, array( 200, 201 ), true ) ) {
			$msg = $data['error']['message'] ?? __( 'EasyPost API error.', 'barcode-fulfillment-orders' );
			return array( 'success' => false, 'message' => (string) $msg );
		}

		$rates = array();
		foreach ( $data['rates'] ?? array() as $r ) {
			$rates[] = array(
				'id'       => $r['id'],
				'carrier'  => $r['carrier'],
				'service'  => $r['service'],
				'price'    => $r['rate'],
				'currency' => $r['currency'],
				'days'     => $r['delivery_days'] ?? null,
			);
		}

		usort( $rates, static fn( $a, $b ) => (float) $a['price'] <=> (float) $b['price'] );

		return array(
			'success'     => true,
			'shipment_id' => $data['id'],
			'rates'       => $rates,
		);
	}

	/**
	 * Buys a label for the given EasyPost shipment + rate.
	 *
	 * @since  1.1.0
	 * @param  string $shipment_id  EasyPost shipment ID.
	 * @param  string $rate_id      EasyPost rate ID.
	 * @return array
	 */
	private function buy_label_easypost( string $shipment_id, string $rate_id ): array {
		$api_key = get_option( BFO_OPTION_EASYPOST_API_KEY, '' );

		$response = wp_remote_post(
			'https://api.easypost.com/v2/shipments/' . rawurlencode( $shipment_id ) . '/buy',
			array(
				'headers' => array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Authorization' => 'Basic ' . base64_encode( $api_key . ':' ),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'rate' => array( 'id' => $rate_id ) ) ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => $response->get_error_message() );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( ! in_array( $code, array( 200, 201 ), true ) ) {
			$msg = $data['error']['message'] ?? __( 'EasyPost purchase failed.', 'barcode-fulfillment-orders' );
			return array( 'success' => false, 'message' => (string) $msg );
		}

		return array(
			'success'         => true,
			'tracking_number' => $data['tracking_code'] ?? '',
			'tracking_url'    => $data['tracker']['public_url'] ?? '',
			'label_url'       => $data['postage_label']['label_url'] ?? '',
			'carrier'         => $data['selected_rate']['carrier'] ?? '',
			'transaction_id'  => $data['id'],   // Reuse shipment ID for void.
			'shipment_id'     => $shipment_id,
		);
	}

	/**
	 * Requests a refund (void) for an EasyPost shipment.
	 *
	 * @since  1.1.0
	 * @param  string $shipment_id  EasyPost shipment ID.
	 * @return bool
	 */
	private function void_easypost( string $shipment_id ): bool {
		$api_key = get_option( BFO_OPTION_EASYPOST_API_KEY, '' );

		$response = wp_remote_post(
			'https://api.easypost.com/v2/shipments/' . rawurlencode( $shipment_id ) . '/refund',
			array(
				'headers' => array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Authorization' => 'Basic ' . base64_encode( $api_key . ':' ),
					'Content-Type'  => 'application/json',
				),
				'body'    => '{}',
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return in_array( (int) wp_remote_retrieve_response_code( $response ), array( 200, 201 ), true );
	}

	// -------------------------------------------------------------------------
	// Address / parcel helpers
	// -------------------------------------------------------------------------

	/**
	 * Builds the ship-from address array from plugin settings.
	 *
	 * @since  1.1.0
	 * @return array
	 */
	private function get_from_address(): array {
		return array(
			'name'    => get_option( BFO_OPTION_SHIPPING_FROM_NAME,    '' ),
			'company' => get_option( BFO_OPTION_SHIPPING_FROM_COMPANY, '' ),
			'street1' => get_option( BFO_OPTION_SHIPPING_FROM_STREET1, '' ),
			'street2' => get_option( BFO_OPTION_SHIPPING_FROM_STREET2, '' ),
			'city'    => get_option( BFO_OPTION_SHIPPING_FROM_CITY,    '' ),
			'state'   => get_option( BFO_OPTION_SHIPPING_FROM_STATE,   '' ),
			'zip'     => get_option( BFO_OPTION_SHIPPING_FROM_ZIP,     '' ),
			'country' => get_option( BFO_OPTION_SHIPPING_FROM_COUNTRY, 'US' ),
			'phone'   => get_option( BFO_OPTION_SHIPPING_FROM_PHONE,   '' ),
			'email'   => get_option( BFO_OPTION_SHIPPING_FROM_EMAIL,   get_option( 'admin_email', '' ) ),
		);
	}

	/**
	 * Builds the ship-to address from a WC_Order.
	 *
	 * @since  1.1.0
	 * @param  WC_Order $order
	 * @return array
	 */
	private function get_to_address( WC_Order $order ): array {
		return array(
			'name'    => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
			'company' => $order->get_shipping_company(),
			'street1' => $order->get_shipping_address_1(),
			'street2' => $order->get_shipping_address_2(),
			'city'    => $order->get_shipping_city(),
			'state'   => $order->get_shipping_state(),
			'zip'     => $order->get_shipping_postcode(),
			'country' => $order->get_shipping_country(),
			'phone'   => $order->get_billing_phone(),
			'email'   => $order->get_billing_email(),
		);
	}

	/**
	 * Builds the default parcel from plugin settings.
	 *
	 * @since  1.1.0
	 * @return array
	 */
	private function get_default_parcel(): array {
		return array(
			'length'        => (float) get_option( BFO_OPTION_DEFAULT_LENGTH,    10 ),
			'width'         => (float) get_option( BFO_OPTION_DEFAULT_WIDTH,      8 ),
			'height'        => (float) get_option( BFO_OPTION_DEFAULT_HEIGHT,     4 ),
			'distance_unit' => get_option( BFO_OPTION_DEFAULT_DIST_UNIT, 'in' ),
			'weight'        => (float) get_option( BFO_OPTION_DEFAULT_WEIGHT,    16 ),
			'mass_unit'     => get_option( BFO_OPTION_DEFAULT_MASS_UNIT, 'oz' ),
		);
	}

	// -------------------------------------------------------------------------
	// Order meta helpers
	// -------------------------------------------------------------------------

	/**
	 * Persists all tracking fields to the order.
	 *
	 * @since  1.1.0
	 * @param  WC_Order $order   The order.
	 * @param  array    $result  Normalised buy-label result array.
	 * @return void
	 */
	private function store_tracking( WC_Order $order, array $result ): void {
		$order->update_meta_data( BFO_META_TRACKING_NUMBER,    $result['tracking_number'] );
		$order->update_meta_data( BFO_META_TRACKING_CARRIER,   $result['carrier'] );
		$order->update_meta_data( BFO_META_TRACKING_URL,       $result['tracking_url'] );
		$order->update_meta_data( BFO_META_SHIPPING_LABEL_URL, $result['label_url'] );
		$order->update_meta_data( BFO_META_TRANSACTION_ID,     $result['transaction_id'] ?? '' );
		$order->update_meta_data( BFO_META_SHIPMENT_ID,        $result['shipment_id'] ?? '' );
		$order->save_meta_data();
	}

	/**
	 * Removes all tracking meta from the order (called when voiding a label).
	 *
	 * @since  1.1.0
	 * @param  WC_Order $order
	 * @return void
	 */
	private function clear_tracking( WC_Order $order ): void {
		foreach (
			array(
				BFO_META_TRACKING_NUMBER,
				BFO_META_TRACKING_CARRIER,
				BFO_META_TRACKING_URL,
				BFO_META_SHIPPING_LABEL_URL,
				BFO_META_TRANSACTION_ID,
				BFO_META_SHIPMENT_ID,
			) as $key
		) {
			$order->delete_meta_data( $key );
		}
		$order->save_meta_data();
	}
}
