<?php
/**
 * Order fulfillment queue — admin page listing orders ready to pack.
 *
 * Registers the top-level "Fulfillment" admin menu and the "Order Queue"
 * submenu page. Displays orders with status "processing" or BFO_STATUS_PACKING
 * in a sortable table, with a one-click "Start Packing" button.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BFO_Order_Queue
 *
 * @since 1.0.0
 */
class BFO_Order_Queue {

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var BFO_Order_Queue|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @since  1.0.0
	 * @return BFO_Order_Queue
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
		add_action( 'admin_menu',           array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_bfo_queue_data',            array( $this, 'ajax_queue_data' ) );
		add_action( 'wp_ajax_bfo_start_packing_session', array( $this, 'ajax_start_session' ) );
		add_action( 'wp_ajax_bfo_queue_barcode_search',  array( $this, 'ajax_barcode_search' ) );
	}

	// -------------------------------------------------------------------------
	// Menu registration
	// -------------------------------------------------------------------------

	/**
	 * Registers the top-level "Fulfillment" menu and its subpages.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			esc_html__( 'Fulfillment', 'barcode-fulfillment-orders' ),
			esc_html__( 'Fulfillment', 'barcode-fulfillment-orders' ),
			BFO_CAPABILITY_QUEUE,
			'bfo-queue',
			array( $this, 'render_queue_page' ),
			'dashicons-archive',
			56
		);

		add_submenu_page(
			'bfo-queue',
			esc_html__( 'Order Queue', 'barcode-fulfillment-orders' ),
			esc_html__( 'Order Queue', 'barcode-fulfillment-orders' ),
			BFO_CAPABILITY_QUEUE,
			'bfo-queue',
			array( $this, 'render_queue_page' )
		);
	}

	// -------------------------------------------------------------------------
	// Queue page rendering
	// -------------------------------------------------------------------------

	/**
	 * Renders the Order Queue admin page.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render_queue_page() {
		if ( ! current_user_can( BFO_CAPABILITY_QUEUE ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'barcode-fulfillment-orders' ) );
		}

		$refresh = absint( get_option( BFO_OPTION_QUEUE_REFRESH, 30 ) );
		$orders  = $this->get_queue_orders();
		?>
		<div class="wrap bfo-queue-wrap">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Order Queue', 'barcode-fulfillment-orders' ); ?>
			</h1>
			<span class="bfo-live-badge"><?php esc_html_e( 'Live', 'barcode-fulfillment-orders' ); ?></span>
			<p class="description">
				<?php
				/* translators: %d: refresh interval in seconds */
				printf( esc_html__( 'Auto-refreshes every %d seconds. Scan an order barcode or click Start Packing.', 'barcode-fulfillment-orders' ), absint( $refresh ) );
				?>
			</p>

			<!-- Order barcode scanner input (hidden by default, shown on mobile) -->
			<div class="bfo-queue-scan-area">
				<label for="bfo-queue-barcode-search"><?php esc_html_e( 'Scan order barcode:', 'barcode-fulfillment-orders' ); ?></label>
				<input type="text" id="bfo-queue-barcode-search" class="regular-text" placeholder="<?php esc_attr_e( 'Scan or type order barcode…', 'barcode-fulfillment-orders' ); ?>" autocomplete="off">
				<span id="bfo-queue-search-msg" class="description" style="display:none;"></span>
			</div>

			<div id="bfo-queue-container">
				<?php $this->render_queue_table( $orders ); ?>
			</div>
		</div>

		<script>
		var bfoQueueConfig = {
			ajaxUrl : <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
			nonces  : {
				refresh: <?php echo wp_json_encode( wp_create_nonce( 'bfo_queue' ) ); ?>
			},
			refresh : <?php echo absint( $refresh ); ?>,
			packUrl : <?php echo wp_json_encode( admin_url( 'admin.php?page=bfo-pack-order' ) ); ?>,
			i18n    : {
				starting    : <?php echo wp_json_encode( __( 'Starting…', 'barcode-fulfillment-orders' ) ); ?>,
				startFailed : <?php echo wp_json_encode( __( 'Could not start packing session.', 'barcode-fulfillment-orders' ) ); ?>,
				networkError: <?php echo wp_json_encode( __( 'Network error — please try again.', 'barcode-fulfillment-orders' ) ); ?>,
				notFound    : <?php echo wp_json_encode( __( 'Order not found.', 'barcode-fulfillment-orders' ) ); ?>
			}
		};
		</script>
		<?php
	}

	/**
	 * Renders the HTML table of orders in the queue.
	 *
	 * @since  1.0.0
	 * @param  array $orders  Array of WC_Order objects.
	 * @return void
	 */
	private function render_queue_table( $orders ) {
		if ( empty( $orders ) ) {
			echo '<div class="bfo-empty-queue"><p>' .
				esc_html__( 'No orders in the queue. Great work!', 'barcode-fulfillment-orders' ) .
				'</p></div>';
			return;
		}

		$generator = BFO_Barcode_Generator::get_instance();
		$format    = get_option( BFO_OPTION_ORDER_BARCODE_FORMAT, 'code128' );
		?>
		<table class="wp-list-table widefat fixed striped bfo-queue-table">
			<thead>
			<tr>
				<th><?php esc_html_e( 'Order', 'barcode-fulfillment-orders' ); ?></th>
				<th><?php esc_html_e( 'Barcode', 'barcode-fulfillment-orders' ); ?></th>
				<th><?php esc_html_e( 'Customer', 'barcode-fulfillment-orders' ); ?></th>
				<th><?php esc_html_e( 'Items', 'barcode-fulfillment-orders' ); ?></th>
				<th><?php esc_html_e( 'Date', 'barcode-fulfillment-orders' ); ?></th>
				<th><?php esc_html_e( 'Status', 'barcode-fulfillment-orders' ); ?></th>
				<th><?php esc_html_e( 'Shipping', 'barcode-fulfillment-orders' ); ?></th>
				<th><?php esc_html_e( 'Label', 'barcode-fulfillment-orders' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'barcode-fulfillment-orders' ); ?></th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $orders as $order ) :
				$barcode    = bfo_get_order_barcode( $order->get_id() );
				$session    = bfo_get_session_for_order( $order->get_id() );
				$is_active  = $session && BFO_SESSION_ACTIVE === $session->status;
				$is_paused  = $session && BFO_SESSION_PAUSED === $session->status;
				$is_mine    = $session && (int) $session->worker_id === get_current_user_id();
				$worker     = $is_active ? get_userdata( (int) $session->worker_id ) : null;
				$item_count = $order->get_item_count();
				$shipping   = '';
				foreach ( $order->get_items( 'shipping' ) as $s_item ) {
					$shipping = $s_item->get_method_title();
					break;
				}
				$tracking_num = $order->get_meta( BFO_META_TRACKING_NUMBER, true );
				$tracking_url = $order->get_meta( BFO_META_TRACKING_URL,    true );
				$provider     = get_option( BFO_OPTION_SHIPPING_PROVIDER, 'none' );
				$is_packed    = $order->has_status( BFO_STATUS_PACKED );
			?>
			<tr data-order-id="<?php echo absint( $order->get_id() ); ?>" class="<?php echo $is_active ? 'bfo-row-active' : ''; ?>">
				<td>
					<strong>
						<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>" target="_blank">
							#<?php echo absint( $order->get_id() ); ?>
						</a>
					</strong>
				</td>
				<td class="bfo-barcode-cell">
					<?php if ( $barcode ) : ?>
						<code><?php echo esc_html( $barcode ); ?></code>
						<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo $generator->render_inline( $barcode, $format, 30, 1 ); ?>
					<?php else : ?>
						<span class="description"><?php esc_html_e( '—', 'barcode-fulfillment-orders' ); ?></span>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></td>
				<td>
					<?php
					/* translators: %d: number of order items */
					echo esc_html( sprintf( _n( '%d item', '%d items', $item_count, 'barcode-fulfillment-orders' ), $item_count ) );
					?>
				</td>
				<td><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></td>
				<td>
					<span class="order-status status-<?php echo esc_attr( $order->get_status() ); ?>">
						<?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
					</span>
					<?php if ( $is_active && $worker ) : ?>
						<br><small class="description">
							<?php
							/* translators: %s: worker name */
							echo esc_html( sprintf( __( 'Packing: %s', 'barcode-fulfillment-orders' ), $worker->display_name ) );
							?>
						</small>				<?php elseif ( $is_paused ) : ?>
					<br><small class="description"><?php esc_html_e( 'Paused', 'barcode-fulfillment-orders' ); ?></small>					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $shipping ?: '—' ); ?></td>
				<td>
					<?php if ( $tracking_num ) : ?>
						<?php if ( $tracking_url ) : ?>
							<a href="<?php echo esc_url( $tracking_url ); ?>" target="_blank" rel="noopener">
								<?php echo esc_html( $tracking_num ); ?> ↗
							</a>
						<?php else : ?>
							<code><?php echo esc_html( $tracking_num ); ?></code>
						<?php endif; ?>
					<?php elseif ( $is_packed && 'none' !== $provider ) : ?>
						<?php $ship_nonce = wp_create_nonce( 'bfo_shipping_' . $order->get_id() ); ?>
						<button type="button"
							class="button button-small bfo-get-rates-btn"
							data-order-id="<?php echo absint( $order->get_id() ); ?>"
							data-nonce="<?php echo esc_attr( $ship_nonce ); ?>">
							<?php esc_html_e( 'Get Rates', 'barcode-fulfillment-orders' ); ?>
						</button>
					<?php else : ?>
						<span class="description">—</span>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $is_active && $is_mine ) : ?>
						<?php
						$continue_url = esc_url( bfo_fulfillment_url( $order->get_id() ) . '&session_id=' . absint( $session->id ) );
						?>
						<a href="<?php echo $continue_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" class="button button-primary">
							<?php esc_html_e( 'Continue Packing', 'barcode-fulfillment-orders' ); ?>
						</a>
					<?php elseif ( $is_active && ! $is_mine ) : ?>
						<span class="description"><?php esc_html_e( 'In progress', 'barcode-fulfillment-orders' ); ?></span>
					<?php elseif ( $is_paused ) : ?>
						<?php
						$nonce = wp_create_nonce( 'bfo_start_session_' . $order->get_id() );
						?>
						<button type="button"
						class="button button-primary bfo-start-packing-btn"
						data-order-id="<?php echo absint( $order->get_id() ); ?>"
						data-nonce="<?php echo esc_attr( $nonce ); ?>">
						<?php esc_html_e( 'Resume Packing', 'barcode-fulfillment-orders' ); ?>
					</button>
					<?php elseif ( $is_packed ) : ?>
						<span class="bfo-packed-badge">&#10003; <?php esc_html_e( 'Packed', 'barcode-fulfillment-orders' ); ?></span>
					<?php else : ?>
						<?php
						$nonce = wp_create_nonce( 'bfo_start_session_' . $order->get_id() );
						?>
						<button type="button"
							class="button button-primary bfo-start-packing-btn"
							data-order-id="<?php echo absint( $order->get_id() ); ?>"
							data-nonce="<?php echo esc_attr( $nonce ); ?>">
							<?php esc_html_e( 'Start Packing', 'barcode-fulfillment-orders' ); ?>
						</button>
					<?php endif; ?>
				</td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// -------------------------------------------------------------------------
	// Data retrieval
	// -------------------------------------------------------------------------

	/**
	 * Returns orders that need to be packed.
	 *
	 * @since  1.0.0
	 * @return WC_Order[]
	 */
	public function get_queue_orders() {
		return wc_get_orders(
			array(
				'limit'   => BFO_MAX_QUEUE_ORDERS,
				'status'  => array(
					'processing',
					'wc-' . BFO_STATUS_PACKING,
					'wc-' . BFO_STATUS_PACKED,
				),
				'orderby' => 'date',
				'order'   => 'ASC',
			)
		);
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	/**
	 * Enqueues scripts and styles for the queue page.
	 *
	 * @since  1.0.0
	 * @param  string $hook  Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_bfo-queue' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'bfo-queue',
			BFO_PLUGIN_URL . 'assets/js/bfo-queue.js',
			array( 'jquery' ),
			BFO_VERSION,
			true
		);

		wp_enqueue_style(
			'bfo-admin',
			BFO_PLUGIN_URL . 'assets/css/bfo-admin.css',
			array(),
			BFO_VERSION
		);
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: returns refreshed queue HTML for auto-update.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_queue_data() {
		check_ajax_referer( 'bfo_queue', 'security' );

		if ( ! current_user_can( BFO_CAPABILITY_QUEUE ) ) {
			wp_send_json_error( null, 403 );
		}

		ob_start();
		$this->render_queue_table( $this->get_queue_orders() );
		$html = ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * AJAX: creates a packing session and redirects to the fulfillment screen.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_start_session() {
		$order_id = absint( $_POST['order_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		check_ajax_referer( 'bfo_start_session_' . $order_id, 'security' );

		if ( ! current_user_can( BFO_CAPABILITY_PACK ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'barcode-fulfillment-orders' ) ), 403 );
		}

		$result = BFO_Packing_Session::get_instance()->start( $order_id, get_current_user_id() );

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}

		wp_send_json_success(
			array(
				'session_id'  => $result['session_id'],
				'redirect'    => esc_url( bfo_fulfillment_url( $order_id ) . '&session_id=' . absint( $result['session_id'] ) ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Queue barcode-search AJAX handler
	// -------------------------------------------------------------------------

	/**
	 * Handles the queue barcode search: looks up an order by its barcode value
	 * and returns the start-packing URL so the JS can redirect the worker.
	 *
	 * @since  1.1.0
	 * @return void  Terminates via wp_send_json_*.
	 */
	public function ajax_barcode_search() {
		check_ajax_referer( 'bfo_queue', 'security' );

		if ( ! current_user_can( BFO_CAPABILITY_QUEUE ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'barcode-fulfillment-orders' ) ), 403 );
		}

		$barcode  = isset( $_POST['barcode'] ) ? sanitize_text_field( wp_unslash( $_POST['barcode'] ) ) : '';
		$order_id = bfo_lookup_order_by_barcode( $barcode );

		if ( ! $order_id ) {
			// Also try treating the raw value as a numeric order ID (manual entry).
			if ( ctype_digit( $barcode ) ) {
				$order = wc_get_order( absint( $barcode ) );
				$order_id = $order ? $order->get_id() : 0;
			}
		}

		if ( ! $order_id ) {
			wp_send_json_error(
				array( 'message' => __( 'No order found for that barcode.', 'barcode-fulfillment-orders' ) )
			);
		}

		$start_nonce = wp_create_nonce( 'bfo_start_session_' . $order_id );

		wp_send_json_success(
			array(
				'order_id' => $order_id,
				'nonce'    => $start_nonce,
				'redirect' => esc_url( admin_url( 'admin.php?page=bfo-pack-order&order_id=' . $order_id ) ),
			)
		);
	}
}
