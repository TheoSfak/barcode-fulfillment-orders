<?php
/**
 * Pick List — print and email pending orders for warehouse pickers.
 *
 * Adds a "Pick List" sub-page under the Fulfillment menu. The admin can:
 *   • Select any subset of pending orders.
 *   • Open a print-ready page (new tab) showing each order's barcode, items,
 *     and product barcodes so a picker can walk the warehouse.
 *   • Email the same pick list to a configurable address.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BFO_Pick_List
 *
 * @since 1.2.0
 */
class BFO_Pick_List {

	/** @var BFO_Pick_List|null */
	private static ?BFO_Pick_List $instance = null;

	// -------------------------------------------------------------------------
	// Singleton
	// -------------------------------------------------------------------------

	public static function instance(): BFO_Pick_List {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu',            array( $this, 'register_menu' ), 25 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_bfo_print_pick_list', array( $this, 'ajax_print' ) );
		add_action( 'wp_ajax_bfo_email_pick_list', array( $this, 'ajax_email' ) );
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	public function register_menu(): void {
		add_submenu_page(
			'bfo-queue',
			__( 'Pick List', 'barcode-fulfillment-orders' ),
			__( 'Pick List', 'barcode-fulfillment-orders' ),
			BFO_CAPABILITY_PACK,
			'bfo-pick-list',
			array( $this, 'render_page' )
		);
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public function enqueue_assets( string $hook ): void {
		if ( 'fulfillment_page_bfo-pick-list' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'bfo-admin',
			BFO_PLUGIN_URL . 'assets/css/bfo-admin.css',
			array(),
			BFO_VERSION
		);
	}

	// -------------------------------------------------------------------------
	// Page render
	// -------------------------------------------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( BFO_CAPABILITY_PACK ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'barcode-fulfillment-orders' ) );
		}

		$orders        = $this->get_pickable_orders();
		$recipient     = sanitize_email( get_option( BFO_OPTION_PICK_LIST_RECIPIENT, get_option( 'admin_email', '' ) ) );
		$print_nonce   = wp_create_nonce( 'bfo_print_pick_list' );
		$email_nonce   = wp_create_nonce( 'bfo_email_pick_list' );
		$ajax_url      = admin_url( 'admin-ajax.php' );
		?>
		<div class="wrap bfo-picklist-wrap">
			<h1><?php esc_html_e( 'Pick List', 'barcode-fulfillment-orders' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Select orders to include in the pick list. Print it for your warehouse picker or send it by email.', 'barcode-fulfillment-orders' ); ?>
			</p>

			<?php if ( empty( $orders ) ) : ?>
				<div class="bfo-picklist-empty">
					<p><?php esc_html_e( 'No pending orders to pick right now. Great work!', 'barcode-fulfillment-orders' ); ?></p>
				</div>
			<?php else : ?>

			<form id="bfo-picklist-form">
				<!-- Toolbar -->
				<div class="bfo-picklist-toolbar">
					<label class="bfo-picklist-select-all">
						<input type="checkbox" id="bfo-picklist-check-all">
						<?php esc_html_e( 'Select all', 'barcode-fulfillment-orders' ); ?>
					</label>
					<span class="bfo-picklist-selected-count">
						<span id="bfo-picklist-count">0</span> <?php esc_html_e( 'selected', 'barcode-fulfillment-orders' ); ?>
					</span>
					<button type="button" id="bfo-print-picklist-btn" class="button button-primary" disabled>
						<span class="dashicons dashicons-printer" style="margin-top:3px;"></span>
						<?php esc_html_e( 'Print Pick List', 'barcode-fulfillment-orders' ); ?>
					</button>
					<button type="button" id="bfo-email-picklist-btn" class="button" disabled>
						<span class="dashicons dashicons-email-alt" style="margin-top:3px;"></span>
						<?php esc_html_e( 'Email Pick List', 'barcode-fulfillment-orders' ); ?>
					</button>
				</div>

				<!-- Email form (hidden until button clicked) -->
				<div id="bfo-picklist-email-form" class="bfo-picklist-email-form" style="display:none;">
					<label for="bfo-picklist-email">
						<?php esc_html_e( 'Send to:', 'barcode-fulfillment-orders' ); ?>
					</label>
					<input type="email" id="bfo-picklist-email" value="<?php echo esc_attr( $recipient ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'picker@warehouse.com', 'barcode-fulfillment-orders' ); ?>">
					<button type="button" id="bfo-picklist-send-btn" class="button button-primary">
						<?php esc_html_e( 'Send', 'barcode-fulfillment-orders' ); ?>
					</button>
					<span id="bfo-picklist-email-msg" class="description" style="margin-left:8px;"></span>
				</div>

				<!-- Orders table -->
				<table class="wp-list-table widefat fixed striped bfo-picklist-table">
					<thead>
						<tr>
							<th class="bfo-col-check"><span class="screen-reader-text"><?php esc_html_e( 'Select', 'barcode-fulfillment-orders' ); ?></span></th>
							<th><?php esc_html_e( 'Order', 'barcode-fulfillment-orders' ); ?></th>
							<th><?php esc_html_e( 'Customer', 'barcode-fulfillment-orders' ); ?></th>
							<th><?php esc_html_e( 'Status', 'barcode-fulfillment-orders' ); ?></th>
							<th><?php esc_html_e( 'Items', 'barcode-fulfillment-orders' ); ?></th>
							<th><?php esc_html_e( 'Date', 'barcode-fulfillment-orders' ); ?></th>
							<th><?php esc_html_e( 'Shipping Method', 'barcode-fulfillment-orders' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $orders as $order ) :
							$shipping_method = '';
							foreach ( $order->get_items( 'shipping' ) as $s_item ) {
								$shipping_method = $s_item->get_method_title();
								break;
							}
						?>
						<tr>
							<td class="bfo-col-check">
								<input type="checkbox" class="bfo-picklist-order-check" value="<?php echo absint( $order->get_id() ); ?>">
							</td>
							<td>
								<strong>
									<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>" target="_blank">
										#<?php echo absint( $order->get_id() ); ?>
									</a>
								</strong>
							</td>
							<td><?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></td>
							<td>
								<span class="order-status status-<?php echo esc_attr( $order->get_status() ); ?>">
									<?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
								</span>
							</td>
							<td>
								<?php
								$item_count = $order->get_item_count();
								/* translators: %d: number of items */
								echo esc_html( sprintf( _n( '%d item', '%d items', $item_count, 'barcode-fulfillment-orders' ), $item_count ) );
								?>
							</td>
							<td><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></td>
							<td><?php echo esc_html( $shipping_method ?: '—' ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</form>

			<?php endif; ?>
		</div>

		<script>
		( function() {
			const checkAll  = document.getElementById( 'bfo-picklist-check-all' );
			const checks    = document.querySelectorAll( '.bfo-picklist-order-check' );
			const countEl   = document.getElementById( 'bfo-picklist-count' );
			const printBtn  = document.getElementById( 'bfo-print-picklist-btn' );
			const emailBtn  = document.getElementById( 'bfo-email-picklist-btn' );
			const emailForm = document.getElementById( 'bfo-picklist-email-form' );
			const sendBtn   = document.getElementById( 'bfo-picklist-send-btn' );
			const emailIn   = document.getElementById( 'bfo-picklist-email' );
			const emailMsg  = document.getElementById( 'bfo-picklist-email-msg' );
			const ajaxUrl   = <?php echo wp_json_encode( $ajax_url ); ?>;
			const printNonce = <?php echo wp_json_encode( $print_nonce ); ?>;
			const emailNonce = <?php echo wp_json_encode( $email_nonce ); ?>;

			function getSelected() {
				return Array.from( checks ).filter( c => c.checked ).map( c => c.value );
			}

			function updateUI() {
				const sel = getSelected();
				countEl.textContent = sel.length;
				const hasAny = sel.length > 0;
				if ( printBtn ) printBtn.disabled = ! hasAny;
				if ( emailBtn ) emailBtn.disabled = ! hasAny;
			}

			if ( checkAll ) {
				checkAll.addEventListener( 'change', function() {
					checks.forEach( c => c.checked = checkAll.checked );
					updateUI();
				} );
			}
			checks.forEach( c => c.addEventListener( 'change', function() {
				if ( checkAll ) checkAll.checked = Array.from( checks ).every( x => x.checked );
				updateUI();
			} ) );

			if ( printBtn ) {
				printBtn.addEventListener( 'click', function() {
					const ids = getSelected();
					if ( ! ids.length ) return;
					const url = ajaxUrl + '?action=bfo_print_pick_list&_wpnonce=' + encodeURIComponent( printNonce ) + '&order_ids=' + encodeURIComponent( ids.join( ',' ) );
					window.open( url, '_blank' );
				} );
			}

			if ( emailBtn ) {
				emailBtn.addEventListener( 'click', function() {
					if ( emailForm ) {
						emailForm.style.display = emailForm.style.display === 'none' ? 'flex' : 'none';
					}
				} );
			}

			if ( sendBtn ) {
				sendBtn.addEventListener( 'click', function() {
					const ids = getSelected();
					const to  = emailIn ? emailIn.value.trim() : '';
					if ( ! ids.length ) { emailMsg.textContent = <?php echo wp_json_encode( __( 'No orders selected.', 'barcode-fulfillment-orders' ) ); ?>; return; }
					if ( ! to )         { emailMsg.textContent = <?php echo wp_json_encode( __( 'Please enter an email address.', 'barcode-fulfillment-orders' ) ); ?>; return; }

					sendBtn.disabled = true;
					emailMsg.textContent = <?php echo wp_json_encode( __( 'Sending…', 'barcode-fulfillment-orders' ) ); ?>;

					fetch( ajaxUrl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: new URLSearchParams( {
							action:    'bfo_email_pick_list',
							security:  emailNonce,
							order_ids: ids.join( ',' ),
							to:        to,
						} ),
					} )
					.then( r => r.json() )
					.then( data => {
						emailMsg.textContent = data.success
							? <?php echo wp_json_encode( __( '✓ Sent!', 'barcode-fulfillment-orders' ) ); ?>
							: ( data.data && data.data.message ? data.data.message : <?php echo wp_json_encode( __( 'Send failed.', 'barcode-fulfillment-orders' ) ); ?> );
					} )
					.catch( () => { emailMsg.textContent = <?php echo wp_json_encode( __( 'Network error.', 'barcode-fulfillment-orders' ) ); ?>; } )
					.finally( () => { sendBtn.disabled = false; } );
				} );
			}
		} )();
		</script>
		<?php
	}

	// -------------------------------------------------------------------------
	// Data helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns orders eligible for picking (processing, packing).
	 *
	 * @since  1.2.0
	 * @return WC_Order[]
	 */
	private function get_pickable_orders(): array {
		return wc_get_orders( array(
			'limit'   => BFO_MAX_QUEUE_ORDERS,
			'status'  => array( 'processing', 'wc-' . BFO_STATUS_PACKING ),
			'orderby' => 'date',
			'order'   => 'ASC',
		) );
	}

	/**
	 * Resolves an array of order IDs to WC_Order objects (validates ownership).
	 *
	 * @since  1.2.0
	 * @param  int[] $ids
	 * @return WC_Order[]
	 */
	private function get_orders_by_ids( array $ids ): array {
		$orders = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( absint( $id ) );
			if ( $order instanceof WC_Order ) {
				$orders[] = $order;
			}
		}
		return $orders;
	}

	// -------------------------------------------------------------------------
	// AJAX: Print
	// -------------------------------------------------------------------------

	/**
	 * Streams the pick list print template to the browser.
	 *
	 * @since  1.2.0
	 * @return void
	 */
	public function ajax_print(): void {
		if ( ! check_ajax_referer( 'bfo_print_pick_list', '_wpnonce', false ) || ! current_user_can( BFO_CAPABILITY_PACK ) ) {
			wp_die( esc_html__( 'Security check failed.', 'barcode-fulfillment-orders' ) );
		}

		$raw_ids = isset( $_GET['order_ids'] ) ? sanitize_text_field( wp_unslash( $_GET['order_ids'] ) ) : '';
		$ids     = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );

		if ( empty( $ids ) ) {
			wp_die( esc_html__( 'No orders selected.', 'barcode-fulfillment-orders' ) );
		}

		$orders = $this->get_orders_by_ids( $ids );

		if ( empty( $orders ) ) {
			wp_die( esc_html__( 'No valid orders found.', 'barcode-fulfillment-orders' ) );
		}

		$this->stream_template( 'print/pick-list.php', array( 'orders' => $orders ) );
	}

	// -------------------------------------------------------------------------
	// AJAX: Email
	// -------------------------------------------------------------------------

	/**
	 * Sends the pick list by email.
	 *
	 * @since  1.2.0
	 * @return void
	 */
	public function ajax_email(): void {
		check_ajax_referer( 'bfo_email_pick_list', 'security' );

		if ( ! current_user_can( BFO_CAPABILITY_PACK ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'barcode-fulfillment-orders' ) ), 403 );
		}

		$raw_ids = isset( $_POST['order_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['order_ids'] ) ) : '';
		$ids     = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );
		$to      = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No orders selected.', 'barcode-fulfillment-orders' ) ) );
		}

		if ( ! is_email( $to ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid email address.', 'barcode-fulfillment-orders' ) ) );
		}

		$orders = $this->get_orders_by_ids( $ids );

		if ( empty( $orders ) ) {
			wp_send_json_error( array( 'message' => __( 'No valid orders found.', 'barcode-fulfillment-orders' ) ) );
		}

		// Persist recipient as default for next time.
		update_option( BFO_OPTION_PICK_LIST_RECIPIENT, $to );

		BFO_Emails::send_pick_list_email( $orders, $to );

		wp_send_json_success( array( 'message' => __( 'Pick list sent.', 'barcode-fulfillment-orders' ) ) );
	}

	// -------------------------------------------------------------------------
	// Template streaming helper
	// -------------------------------------------------------------------------

	/**
	 * Loads and outputs a print template, then exits.
	 *
	 * Checks the active theme for overrides before falling back to the plugin.
	 *
	 * @since  1.2.0
	 * @param  string $template_name  Relative path from templates/ or the theme override dir.
	 * @param  array  $args           Variables to extract into the template scope.
	 * @return void
	 */
	private function stream_template( string $template_name, array $args = array() ): void {
		$theme_file  = get_stylesheet_directory() . '/barcode-fulfillment-orders/' . $template_name;
		$plugin_file = BFO_PLUGIN_DIR . 'templates/' . $template_name;
		$template    = file_exists( $theme_file ) ? $theme_file : $plugin_file;

		if ( ! file_exists( $template ) ) {
			wp_die( esc_html__( 'Pick list template not found.', 'barcode-fulfillment-orders' ) );
		}

		// phpcs:ignore WordPress.PHP.DontExtract
		extract( $args, EXTR_SKIP );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store, no-cache' );
		include $template;
		exit;
	}
}
