<?php
/**
 * WooCommerce email: Pick List.
 *
 * Admin email that sends the warehouse pick list (all selected pending orders,
 * their barcodes, and line items) to a configurable recipient.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Email' ) ) {
	return;
}

/**
 * Class BFO_Email_Pick_List
 *
 * @since 1.2.0
 */
class BFO_Email_Pick_List extends WC_Email {

	/** @var WC_Order[] */
	public array $orders = array();

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	public function __construct() {
		$this->id             = 'bfo_pick_list';
		$this->title          = __( 'Pick List', 'barcode-fulfillment-orders' );
		$this->description    = __( 'Sends a pick list of pending orders to the warehouse picker.', 'barcode-fulfillment-orders' );
		$this->template_html  = 'emails/bfo-pick-list.php';   // theme-overridable (unused — we generate inline)
		$this->template_plain = '';
		$this->placeholders   = array(
			'{date}'         => '',
			'{orders_count}' => '',
		);

		// Admin-only email — no customer recipient.
		$this->customer_email = false;

		parent::__construct();

		// Override default recipient.
		$this->recipient = get_option( BFO_OPTION_PICK_LIST_RECIPIENT, get_option( 'admin_email', '' ) );
	}

	// -------------------------------------------------------------------------
	// Trigger
	// -------------------------------------------------------------------------

	/**
	 * Trigger the email.
	 *
	 * @since  1.2.0
	 * @param  WC_Order[] $orders  Orders to include.
	 * @param  string     $to      Recipient address (overrides setting).
	 * @return void
	 */
	public function trigger( array $orders, string $to = '' ): void {
		$this->setup_locale();

		$this->orders = $orders;

		$this->placeholders['{date}']         = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		$this->placeholders['{orders_count}'] = count( $orders );

		if ( $to ) {
			$this->recipient = $to;
		}

		if ( $this->recipient && ! empty( $this->orders ) ) {
			$this->send( $this->recipient, $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	// -------------------------------------------------------------------------
	// Subject / heading
	// -------------------------------------------------------------------------

	public function get_default_subject(): string {
		/* translators: {orders_count}: number of orders, {date}: date/time */
		return __( 'Pick List — {orders_count} orders — {date}', 'barcode-fulfillment-orders' );
	}

	public function get_default_heading(): string {
		/* translators: {orders_count}: number of orders */
		return __( 'Warehouse Pick List ({orders_count} orders)', 'barcode-fulfillment-orders' );
	}

	// -------------------------------------------------------------------------
	// Content
	// -------------------------------------------------------------------------

	/**
	 * Generates the HTML email content inline (no separate template file required).
	 *
	 * @since  1.2.0
	 * @return string
	 */
	public function get_content_html(): string {
		$generator = BFO_Barcode_Generator::get_instance();
		$format    = get_option( BFO_OPTION_ORDER_BARCODE_FORMAT, 'code128' );
		$site_name = get_bloginfo( 'name' );

		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width,initial-scale=1">
			<style>
				body { font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; font-size: 13px; color: #1e1e1e; background: #f7f7f7; margin: 0; padding: 20px; }
				.email-wrap { max-width: 680px; margin: 0 auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
				.email-header { background: #1d6fa5; color: #fff; padding: 24px 28px; }
				.email-header h1 { margin: 0; font-size: 20px; font-weight: 700; }
				.email-header p { margin: 4px 0 0; opacity: .8; font-size: 13px; }
				.email-body { padding: 24px 28px; }
				.order-block { border: 1px solid #e0e0e0; border-radius: 6px; margin-bottom: 20px; overflow: hidden; }
				.order-head { background: #f5f5f5; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e0e0e0; }
				.order-head-left h2 { margin: 0; font-size: 15px; color: #1e1e1e; }
				.order-head-left p  { margin: 2px 0 0; font-size: 12px; color: #666; }
				.order-barcode { text-align: right; }
				.order-barcode svg, .order-barcode img { height: 40px; width: auto; }
				.order-barcode small { display: block; font-size: 10px; color: #888; margin-top: 2px; }
				.items-table { width: 100%; border-collapse: collapse; font-size: 12px; }
				.items-table th { background: #fafafa; padding: 8px 12px; text-align: left; color: #666; font-weight: 600; border-bottom: 1px solid #eee; }
				.items-table td { padding: 9px 12px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
				.items-table tr:last-child td { border-bottom: none; }
				.items-table .col-bc svg, .items-table .col-bc img { height: 24px; width: auto; display: block; }
				.qty-badge { display: inline-block; background: #1d6fa5; color: #fff; border-radius: 20px; padding: 1px 8px; font-weight: 700; font-size: 11px; }
				.email-footer { padding: 16px 28px; background: #f5f5f5; text-align: center; font-size: 11px; color: #999; border-top: 1px solid #e0e0e0; }
				.pick-checkbox { display: inline-block; width: 14px; height: 14px; border: 1.5px solid #666; border-radius: 2px; }
			</style>
		</head>
		<body>
		<div class="email-wrap">
			<div class="email-header">
				<h1><?php echo esc_html( $this->format_string( $this->get_heading() ) ); ?></h1>
				<p><?php echo esc_html( sprintf(
					/* translators: %s: site name */
					__( 'Generated by %s', 'barcode-fulfillment-orders' ),
					$site_name
				) ); ?> &mdash; <?php echo esc_html( $this->placeholders['{date}'] ); ?></p>
			</div>
			<div class="email-body">
				<?php foreach ( $this->orders as $order ) :
					$order_barcode = bfo_get_order_barcode( $order->get_id() );
					$barcode_svg   = $order_barcode ? $generator->render_inline( $order_barcode, $format, 40, 1 ) : '';
					$shipping_method = '';
					foreach ( $order->get_items( 'shipping' ) as $s_item ) {
						$shipping_method = $s_item->get_method_title();
						break;
					}
				?>
				<div class="order-block">
					<div class="order-head">
						<div class="order-head-left">
							<h2>
								<?php
								/* translators: %d: order ID */
								echo esc_html( sprintf( __( 'Order #%d', 'barcode-fulfillment-orders' ), $order->get_id() ) );
								?>
							</h2>
							<p>
								<?php echo esc_html( $order->get_formatted_billing_full_name() ); ?>
								<?php if ( $shipping_method ) : ?>
									&bull; <?php echo esc_html( $shipping_method ); ?>
								<?php endif; ?>
							</p>
						</div>
						<?php if ( $barcode_svg ) : ?>
						<div class="order-barcode">
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							echo $barcode_svg; ?>
							<small><?php echo esc_html( $order_barcode ); ?></small>
						</div>
						<?php endif; ?>
					</div>
					<table class="items-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Product', 'barcode-fulfillment-orders' ); ?></th>
								<th><?php esc_html_e( 'SKU', 'barcode-fulfillment-orders' ); ?></th>
								<th class="col-bc"><?php esc_html_e( 'Barcode', 'barcode-fulfillment-orders' ); ?></th>
								<th><?php esc_html_e( 'Qty', 'barcode-fulfillment-orders' ); ?></th>
								<th><?php esc_html_e( '✓', 'barcode-fulfillment-orders' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $order->get_items() as $item ) :
								/** @var WC_Order_Item_Product $item */
								$product    = $item->get_product();
								$sku        = $product ? $product->get_sku() : '';
								$pbc        = $product ? bfo_get_product_barcode( $product->get_id() ) : '';
								$bc_svg     = $pbc ? $generator->render_inline( $pbc, get_option( BFO_OPTION_PRODUCT_BARCODE_FORMAT, 'code128' ), 24, 1 ) : '';
							?>
							<tr>
								<td><?php echo esc_html( $item->get_name() ); ?></td>
								<td><?php echo esc_html( $sku ?: '—' ); ?></td>
								<td class="col-bc">
									<?php if ( $bc_svg ) :
										// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										echo $bc_svg;
									else : ?>
										<span style="color:#999;">—</span>
									<?php endif; ?>
								</td>
								<td><span class="qty-badge"><?php echo absint( $item->get_quantity() ); ?></span></td>
								<td><span class="pick-checkbox"></span></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endforeach; ?>
			</div>
			<div class="email-footer">
				<?php echo esc_html( sprintf(
					/* translators: %s: site name */
					__( 'Barcode Fulfillment Orders — %s', 'barcode-fulfillment-orders' ),
					$site_name
				) ); ?>
			</div>
		</div>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	public function get_content_plain(): string {
		$lines = array();
		foreach ( $this->orders as $order ) {
			$lines[] = sprintf( 'Order #%d — %s', $order->get_id(), $order->get_formatted_billing_full_name() );
			foreach ( $order->get_items() as $item ) {
				$lines[] = sprintf( '  • %s × %d', $item->get_name(), $item->get_quantity() );
			}
			$lines[] = '';
		}
		return implode( "\n", $lines );
	}
}
