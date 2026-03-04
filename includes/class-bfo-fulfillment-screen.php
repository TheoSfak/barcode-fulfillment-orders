<?php
/**
 * Fulfillment / packing screen.
 *
 * Renders the interactive packing UI where warehouse workers:
 *   1. Scan products into the active order
 *   2. Mark items as missing
 *   3. Manage boxes
 *   4. Close the order
 *
 * Accessible at: admin.php?page=bfo-pack-order&order_id=X&session_id=Y
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BFO_Fulfillment_Screen
 *
 * @since 1.0.0
 */
class BFO_Fulfillment_Screen {

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var BFO_Fulfillment_Screen|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @since  1.0.0
	 * @return BFO_Fulfillment_Screen
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
		add_action( 'admin_menu',            array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	// -------------------------------------------------------------------------
	// Menu registration
	// -------------------------------------------------------------------------

	/**
	 * Registers the "Pack Order" sub-page (hidden from the navigation).
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_page() {
		// Using null as parent registers the page without adding it to any menu,
		// so it's accessible by URL but never appears in the sidebar.
		add_submenu_page(
			null,
			esc_html__( 'Pack Order', 'barcode-fulfillment-orders' ),
			esc_html__( 'Pack Order', 'barcode-fulfillment-orders' ),
			BFO_CAPABILITY_PACK,
			'bfo-pack-order',
			array( $this, 'render_page' )
		);
	}

	// -------------------------------------------------------------------------
	// Page rendering
	// -------------------------------------------------------------------------

	/**
	 * Renders the packing screen.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( BFO_CAPABILITY_PACK ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'barcode-fulfillment-orders' ) );
		}

		$order_id   = absint( $_GET['order_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$session_id = absint( $_GET['session_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $order_id ) {
			echo '<div class="wrap"><p>' . esc_html__( 'No order specified.', 'barcode-fulfillment-orders' ) . '</p></div>';
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Order not found.', 'barcode-fulfillment-orders' ) . '</p></div>';
			return;
		}

		// Load or validate session.
		$session = null;
		if ( $session_id ) {
			$session = BFO_Database::get_instance()->get_session( $session_id );
			if ( $session && (int) $session->order_id !== $order_id ) {
				$session = null;
			}
		}
		if ( ! $session ) {
			$session = bfo_get_session_for_order( $order_id );
		}

		if ( ! $session ) {
			// Auto-start if coming directly without a session ID.
			$result = BFO_Packing_Session::get_instance()->start( $order_id, get_current_user_id() );
			if ( $result['success'] ) {
				$session = BFO_Database::get_instance()->get_session( $result['session_id'] );
			}
		}

		if ( ! $session ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Could not start packing session. The order may already be locked by another worker.', 'barcode-fulfillment-orders' ) . '</p></div>';
			return;
		}

		$session_id  = (int) $session->id;
		$scan_nonce  = wp_create_nonce( 'bfo_scan_' . $session_id );
		$session_nonce = wp_create_nonce( 'bfo_session_' . $session_id );
		$format      = get_option( BFO_OPTION_ORDER_BARCODE_FORMAT, 'code128' );
		$barcode     = bfo_get_order_barcode( $order_id );
		$generator   = BFO_Barcode_Generator::get_instance();
		$boxes       = BFO_Multi_Box::get_instance()->get_boxes( $session_id );
		$scanner     = BFO_Scanner::get_instance();
		$items       = $scanner->build_summary( $session_id, $order );
		$multi_box   = 'yes' === get_option( BFO_OPTION_MULTI_BOX, 'yes' );
		$camera_on   = 'yes' === get_option( BFO_OPTION_CAMERA_SCAN, 'yes' );
		$sound_on    = 'yes' === get_option( BFO_OPTION_SOUND_EFFECTS, 'yes' );
		$reasons     = BFO_Missing_Products::get_reasons();

		// Progress.
		$total_ordered  = array_sum( array_column( $items, 'ordered' ) );
		$total_scanned  = array_sum( array_column( $items, 'scanned' ) );
		$total_missing  = array_sum( array_column( $items, 'missing' ) );
		$total_accounted = $total_scanned + $total_missing;
		$progress_pct   = $total_ordered > 0 ? round( ( $total_accounted / $total_ordered ) * 100 ) : 0;

		?>
		<div class="wrap bfo-fulfillment-wrap" id="bfo-fulfillment-screen">

			<!-- ===== TOP BAR ===== -->
			<div class="bfo-topbar">
				<div class="bfo-topbar-left">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=bfo-queue' ) ); ?>" class="bfo-back-link">
						&larr; <?php esc_html_e( 'Queue', 'barcode-fulfillment-orders' ); ?>
					</a>
					<h1>
						<?php
						/* translators: %d: order number */
						printf( esc_html__( 'Order #%d', 'barcode-fulfillment-orders' ), absint( $order_id ) );
						?>
					</h1>
					<span class="bfo-customer-name"><?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></span>
				</div>
				<div class="bfo-topbar-right">
					<?php if ( $barcode ) : ?>
						<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo $generator->render_inline( $barcode, $format, 40, 1 ); ?>
						<code><?php echo esc_html( $barcode ); ?></code>
					<?php endif; ?>
				</div>
			</div>

			<!-- ===== PROGRESS BAR ===== -->
			<div class="bfo-progress-wrap">
				<div class="bfo-progress-bar">
					<div id="bfo-progress-fill" class="bfo-progress-fill" style="width:<?php echo absint( $progress_pct ); ?>%"></div>
				</div>
				<span id="bfo-progress-label" class="bfo-progress-label">
					<?php
					/* translators: 1: accounted count 2: total count 3: percentage */
					printf( esc_html__( '%1$d / %2$d items (%3$d%%)', 'barcode-fulfillment-orders' ), absint( $total_accounted ), absint( $total_ordered ), absint( $progress_pct ) );
					?>
				</span>
			</div>

			<!-- ===== ALERT AREA ===== -->
			<div class="bfo-alert-area" id="bfo-alert-area" role="alert" aria-live="assertive"></div>

			<!-- ===== SCANNER INPUT ===== -->
			<div class="bfo-scanner-area">
				<div class="bfo-scanner-input-wrap">
					<input type="text"
						id="bfo-scan-input"
						class="bfo-scan-input"
						placeholder="<?php esc_attr_e( 'Scan product barcode…', 'barcode-fulfillment-orders' ); ?>"
						autocomplete="off"
						autofocus>
					<?php if ( $camera_on ) : ?>
					<button type="button" class="button bfo-camera-toggle" id="bfo-camera-toggle">
						📷 <?php esc_html_e( 'Camera', 'barcode-fulfillment-orders' ); ?>
					</button>
					<?php endif; ?>
				</div>
				<?php if ( $camera_on ) : ?>
				<div id="bfo-camera-container" class="bfo-camera-container" style="display:none;">
					<div id="bfo-camera-reader"></div>
					<button type="button" class="button bfo-camera-stop" id="bfo-camera-stop">
						<?php esc_html_e( 'Stop Camera', 'barcode-fulfillment-orders' ); ?>
					</button>
				</div>
				<?php endif; ?>
			</div>

			<div class="bfo-columns">

				<!-- ===== PRODUCT LIST ===== -->
				<div class="bfo-product-list-wrap">
					<h2><?php esc_html_e( 'Products', 'barcode-fulfillment-orders' ); ?></h2>
					<table class="widefat bfo-product-list" id="bfo-product-list">
						<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'barcode-fulfillment-orders' ); ?></th>
							<th><?php esc_html_e( 'SKU', 'barcode-fulfillment-orders' ); ?></th>
							<th><?php esc_html_e( 'Barcode', 'barcode-fulfillment-orders' ); ?></th>
							<th><?php esc_html_e( 'Qty', 'barcode-fulfillment-orders' ); ?></th>
							<th><?php esc_html_e( 'Scanned', 'barcode-fulfillment-orders' ); ?></th>
							<th><?php esc_html_e( 'Status', 'barcode-fulfillment-orders' ); ?></th>
							<th><?php esc_html_e( 'Action', 'barcode-fulfillment-orders' ); ?></th>
						</tr>
						</thead>
						<tbody>
						<?php foreach ( $items as $item ) : ?>
						<tr id="bfo-item-<?php echo absint( $item['product_id'] ); ?>-<?php echo absint( $item['variation_id'] ); ?>"						data-product-id="<?php echo absint( $item['product_id'] ); ?>"							class="bfo-item bfo-status-<?php echo esc_attr( $item['status'] ); ?>">
							<td class="bfo-item-name">
								<?php echo esc_html( $item['name'] ); ?>
							</td>
							<td><code><?php echo esc_html( $item['sku'] ?: '—' ); ?></code></td>
							<td>
								<?php if ( $item['barcode'] ) : ?>
									<code><?php echo esc_html( $item['barcode'] ); ?></code>
								<?php else : ?>
									<span class="description"><?php esc_html_e( 'No barcode', 'barcode-fulfillment-orders' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="bfo-item-ordered"><?php echo absint( $item['ordered'] ); ?></td>
						<td class="bfo-cell-scanned">
							<span class="bfo-qty-badge bfo-qty-badge--<?php echo esc_attr( $item['status'] ); ?>"><?php echo absint( $item['scanned'] ); ?>/<?php echo absint( $item['ordered'] ); ?></span>
						</td>
						<td class="bfo-cell-status">
								<?php echo esc_html( $this->status_icon( $item['status'] ) ); ?>
							</td>
							<td>
								<button type="button"
								class="button-link bfo-missing-btn"
								data-product-id="<?php echo absint( $item['product_id'] ); ?>"
								data-variation-id="<?php echo absint( $item['variation_id'] ); ?>"
								data-remaining="<?php echo absint( max( 0, $item['ordered'] - $item['scanned'] ) ); ?>"
									data-name="<?php echo esc_attr( $item['name'] ); ?>"
									<?php echo ( 'complete' === $item['status'] ) ? 'disabled' : ''; ?>>
									<?php esc_html_e( 'Missing', 'barcode-fulfillment-orders' ); ?>
								</button>
							</td>
						</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<!-- ===== BOX PANEL ===== -->
				<?php if ( $multi_box ) : ?>
				<div class="bfo-box-panel-wrap">
					<h2><?php esc_html_e( 'Boxes', 'barcode-fulfillment-orders' ); ?></h2>
				<div class="bfo-box-tabs" id="bfo-box-tabs">
					<?php foreach ( $boxes as $box ) : ?>
					<button type="button"
						class="button bfo-box-tab <?php echo ( 1 === count( $boxes ) ) ? 'bfo-box-tab--active' : ''; ?>"
						data-box-id="<?php echo absint( $box['id'] ); ?>"
						data-box-number="<?php echo absint( $box['box_number'] ); ?>">
						<?php echo esc_html( $box['label'] ); ?>
					</button>
					<?php endforeach; ?>
				</div>
				<button type="button" class="button" id="bfo-add-box-btn">
						+ <?php esc_html_e( 'New Box', 'barcode-fulfillment-orders' ); ?>
					</button>
				</div>
				<?php endif; ?>
			</div>

			<!-- ===== ACTION BUTTONS ===== -->
			<div class="bfo-action-buttons">
				<button type="button" class="button button-primary button-large bfo-complete-btn" id="bfo-complete-btn">
					✓ <?php esc_html_e( 'Complete Order', 'barcode-fulfillment-orders' ); ?>
				</button>
				<button type="button" class="button button-large bfo-pause-btn" id="bfo-pause-btn">
					⏸ <?php esc_html_e( 'Pause', 'barcode-fulfillment-orders' ); ?>
				</button>
				<button type="button" class="button bfo-cancel-btn" id="bfo-cancel-btn">
					✕ <?php esc_html_e( 'Cancel Session', 'barcode-fulfillment-orders' ); ?>
				</button>
			</div>

		</div><!-- .bfo-fulfillment-wrap -->

		<!-- ===== MISSING ITEM MODAL ===== -->
		<div id="bfo-missing-modal" class="bfo-modal" style="display:none;" role="dialog" aria-modal="true">
			<div class="bfo-modal-inner">
				<h2 id="bfo-missing-modal-title"><?php esc_html_e( 'Mark Item as Missing', 'barcode-fulfillment-orders' ); ?></h2>
				<p id="bfo-missing-product-name"></p>
				<form id="bfo-missing-form">
					<input type="hidden" id="bfo-missing-product-id" name="product_id" value="">
					<label for="bfo-missing-reason"><?php esc_html_e( 'Reason:', 'barcode-fulfillment-orders' ); ?></label>
					<select id="bfo-missing-reason" name="reason">
						<?php foreach ( $reasons as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<div id="bfo-missing-notes-wrap">
						<label for="bfo-missing-notes"><?php esc_html_e( 'Additional notes:', 'barcode-fulfillment-orders' ); ?></label>
						<input type="text" id="bfo-missing-notes" name="notes" class="regular-text">
					</div>
					<label for="bfo-missing-qty"><?php esc_html_e( 'Missing quantity:', 'barcode-fulfillment-orders' ); ?></label>
					<input type="number" id="bfo-missing-qty" name="qty" min="1" value="1" class="small-text">
					<div class="bfo-modal-buttons">
						<button type="submit" class="button button-primary" id="bfo-missing-confirm">
							<?php esc_html_e( 'Confirm Missing', 'barcode-fulfillment-orders' ); ?>
						</button>
						<button type="button" class="button" id="bfo-missing-modal-close">
							<?php esc_html_e( 'Cancel', 'barcode-fulfillment-orders' ); ?>
						</button>
					</div>
				</form>
			</div>
		</div>
		<div class="bfo-modal-backdrop" id="bfo-modal-backdrop" style="display:none;"></div>

		<!-- ===== UNACCOUNTED MODAL ===== -->
		<div id="bfo-unaccounted-modal" class="bfo-modal" style="display:none;" role="dialog" aria-modal="true">
			<div class="bfo-modal-inner">
				<h2><?php esc_html_e( 'Cannot Complete Order', 'barcode-fulfillment-orders' ); ?></h2>
				<p><?php esc_html_e( 'The following products have not been scanned or marked missing:', 'barcode-fulfillment-orders' ); ?></p>
				<ul id="bfo-unaccounted-list"></ul>
				<div class="bfo-modal-buttons">
					<button type="button" class="button button-primary" id="bfo-unaccounted-close">
						<?php esc_html_e( 'Go Back', 'barcode-fulfillment-orders' ); ?>
					</button>
				</div>
			</div>
		</div>

		<!-- Config for JS -->
		<?php
		$items_for_js = array_map(
			function ( $it ) {
				return array(
					'product_id' => (int) $it['product_id'],
					'needed'     => (int) $it['ordered'],
					'scanned'    => (int) $it['scanned'],
					'status'     => $it['status'],
				);
			},
			$items
		);
		$first_box_id = ! empty( $boxes ) ? (int) $boxes[0]['id'] : 0;
		?>
		<script>
		var bfoPackConfig = {
			ajaxUrl   : <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
			sessionId : <?php echo absint( $session_id ); ?>,
			orderId   : <?php echo absint( $order_id ); ?>,
			nonces    : {
				scan     : <?php echo wp_json_encode( $scan_nonce ); ?>,
				pause    : <?php echo wp_json_encode( $session_nonce ); ?>,
				cancel   : <?php echo wp_json_encode( $session_nonce ); ?>,
				complete : <?php echo wp_json_encode( $scan_nonce ); ?>,
				missing  : <?php echo wp_json_encode( $scan_nonce ); ?>,
				box      : <?php echo wp_json_encode( $scan_nonce ); ?>,
				heartbeat: <?php echo wp_json_encode( $session_nonce ); ?>,
			},
			firstBoxId: <?php echo absint( $first_box_id ); ?>,
			items     : <?php echo wp_json_encode( $items_for_js ); ?>,
			queueUrl  : <?php echo wp_json_encode( admin_url( 'admin.php?page=bfo-queue' ) ); ?>,
			heartbeat : 30,
			soundOn   : <?php echo $sound_on ? 'true' : 'false'; ?>,
			cameraOn  : <?php echo $camera_on ? 'true' : 'false'; ?>,
			multiBox  : <?php echo $multi_box ? 'true' : 'false'; ?>,
			i18n: {
				scanSuccess        : <?php echo wp_json_encode( __( 'Scanned!', 'barcode-fulfillment-orders' ) ); ?>,
				orderBarcodeScanned: <?php echo wp_json_encode( __( 'Order barcode scanned.', 'barcode-fulfillment-orders' ) ); ?>,
				overScan           : <?php echo wp_json_encode( __( 'Item already fully scanned!', 'barcode-fulfillment-orders' ) ); ?>,
				wrongProduct       : <?php echo wp_json_encode( __( 'This product is not in this order.', 'barcode-fulfillment-orders' ) ); ?>,
				unknownBarcode     : <?php echo wp_json_encode( __( 'Barcode not recognised.', 'barcode-fulfillment-orders' ) ); ?>,
				unknownError       : <?php echo wp_json_encode( __( 'An unknown error occurred.', 'barcode-fulfillment-orders' ) ); ?>,
				networkError       : <?php echo wp_json_encode( __( 'Network error — please try again.', 'barcode-fulfillment-orders' ) ); ?>,
				completeFailed     : <?php echo wp_json_encode( __( 'Could not complete order.', 'barcode-fulfillment-orders' ) ); ?>,
				actionFailed       : <?php echo wp_json_encode( __( 'Action failed — please try again.', 'barcode-fulfillment-orders' ) ); ?>,
				confirmCancel      : <?php echo wp_json_encode( __( 'Cancel this session? Scans will be lost.', 'barcode-fulfillment-orders' ) ); ?>,
				markedMissing      : <?php echo wp_json_encode( __( 'Item marked as missing.', 'barcode-fulfillment-orders' ) ); ?>,
				needed             : <?php echo wp_json_encode( __( 'needed', 'barcode-fulfillment-orders' ) ); ?>,
				scanned            : <?php echo wp_json_encode( __( 'scanned', 'barcode-fulfillment-orders' ) ); ?>,
				remaining          : <?php echo wp_json_encode( __( 'remaining', 'barcode-fulfillment-orders' ) ); ?>,
			},
		};
		</script>
		<?php
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	/**
	 * Enqueues scripts and styles for the packing screen.
	 *
	 * @since  1.0.0
	 * @param  string $hook  Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'bfo-pack-order' ) ) {
			return;
		}

		wp_enqueue_style(
			'bfo-fulfillment',
			BFO_PLUGIN_URL . 'assets/css/bfo-fulfillment.css',
			array(),
			BFO_VERSION
		);

		// Camera scanning library.
		if ( 'yes' === get_option( BFO_OPTION_CAMERA_SCAN, 'yes' ) ) {
			wp_enqueue_script(
				'html5-qrcode',
				BFO_PLUGIN_URL . 'assets/js/vendor/html5-qrcode.min.js',
				array(),
				'2.3.8',
				true
			);
			wp_enqueue_script(
				'bfo-camera-scanner',
				BFO_PLUGIN_URL . 'assets/js/bfo-camera-scanner.js',
				array( 'html5-qrcode' ),
				BFO_VERSION,
				true
			);
		}

		wp_enqueue_script(
			'bfo-packing',
			BFO_PLUGIN_URL . 'assets/js/bfo-packing.js',
			array( 'jquery' ),
			BFO_VERSION,
			true
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns a text icon for the given item status.
	 *
	 * @since  1.0.0
	 * @param  string $status  Status slug.
	 * @return string
	 */
	private function status_icon( $status ) {
		switch ( $status ) {
			case 'complete':
				return '✅ ' . __( 'Complete', 'barcode-fulfillment-orders' );
			case 'partial':
				return '⏳ ' . __( 'Partial', 'barcode-fulfillment-orders' );
			case 'missing':
				return '❌ ' . __( 'Missing', 'barcode-fulfillment-orders' );
			default:
				return '⚪ ' . __( 'Pending', 'barcode-fulfillment-orders' );
		}
	}
}
