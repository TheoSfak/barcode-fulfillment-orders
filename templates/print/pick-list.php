<?php
/**
 * Pick List print template.
 *
 * Variables available:
 *   $orders  WC_Order[]   Orders selected for picking.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$generator      = BFO_Barcode_Generator::get_instance();
$order_format   = get_option( BFO_OPTION_ORDER_BARCODE_FORMAT, 'code128' );
$product_format = get_option( BFO_OPTION_PRODUCT_BARCODE_FORMAT, 'code128' );
$site_name      = get_bloginfo( 'name' );
$generated_at   = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php
		echo esc_html( sprintf(
			/* translators: %s: site name */
			__( 'Pick List — %s', 'barcode-fulfillment-orders' ),
			$site_name
		) );
	?></title>
	<link rel="stylesheet" href="<?php echo esc_url( BFO_PLUGIN_URL . 'assets/css/bfo-print.css' ); ?>">
	<style>
		/* Pick list specific overrides */
		.bfo-pick-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 8mm; padding-bottom: 4mm; border-bottom: 2px solid #000; }
		.bfo-pick-header-left h1 { margin: 0; font-size: 18pt; font-weight: 700; }
		.bfo-pick-header-left p  { margin: 1mm 0 0; font-size: 9pt; color: #555; }
		.bfo-pick-header-right   { text-align: right; font-size: 9pt; color: #444; }
		.bfo-pick-order          { break-inside: avoid; page-break-inside: avoid; margin-bottom: 10mm; border: 1px solid #ccc; border-radius: 3mm; overflow: hidden; }
		.bfo-pick-order-head     { background: #f5f5f5; padding: 4mm 5mm; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #ddd; }
		.bfo-pick-order-info h2  { margin: 0; font-size: 12pt; font-weight: 700; }
		.bfo-pick-order-info p   { margin: 1mm 0 0; font-size: 9pt; color: #555; }
		.bfo-pick-order-barcode  { text-align: right; }
		.bfo-pick-order-barcode svg,
		.bfo-pick-order-barcode img { height: 14mm; width: auto; display: block; }
		.bfo-pick-order-barcode small { font-size: 7pt; color: #666; display: block; margin-top: 1mm; }
		.bfo-pick-items          { width: 100%; border-collapse: collapse; font-size: 9pt; }
		.bfo-pick-items th       { background: #fafafa; padding: 2.5mm 3mm; text-align: left; font-size: 8pt; text-transform: uppercase; color: #444; letter-spacing: .3px; border-bottom: 1px solid #ddd; }
		.bfo-pick-items td       { padding: 3mm 3mm; border-bottom: 0.5px solid #eee; vertical-align: middle; }
		.bfo-pick-items tr:last-child td { border-bottom: none; }
		.bfo-pick-items .col-bc svg,
		.bfo-pick-items .col-bc img { height: 10mm; width: auto; display: block; }
		.bfo-pick-items .col-qty  { text-align: center; font-weight: 700; font-size: 11pt; }
		.bfo-pick-items .col-tick { text-align: center; }
		.bfo-tick-box { display: inline-block; width: 6mm; height: 6mm; border: 1.5px solid #555; border-radius: 1mm; }
		.bfo-pick-footer { margin-top: 8mm; text-align: center; font-size: 8pt; color: #999; }
		@media print {
			.bfo-print-controls { display: none !important; }
			.bfo-pick-order { page-break-after: always; }
			.bfo-pick-order:last-child { page-break-after: auto; }
		}
	</style>
</head>
<body class="bfo-print-body">

	<!-- Print controls bar (hidden when printing) -->
	<div class="bfo-print-controls">
		<button onclick="window.print()">
			<?php esc_html_e( 'Print', 'barcode-fulfillment-orders' ); ?>
		</button>
		<span style="margin-left:12px; font-size:0.9rem; color:#555;">
			<?php
			echo esc_html( sprintf(
				/* translators: %d: number of orders */
				_n( '%d order', '%d orders', count( $orders ), 'barcode-fulfillment-orders' ),
				count( $orders )
			) );
			?>
		</span>
	</div>

	<div style="padding: 10mm 14mm;">

		<!-- Document header -->
		<div class="bfo-pick-header">
			<div class="bfo-pick-header-left">
				<h1><?php esc_html_e( 'PICK LIST', 'barcode-fulfillment-orders' ); ?></h1>
				<p><?php echo esc_html( $site_name ); ?> &mdash; <?php echo esc_html( $generated_at ); ?></p>
			</div>
			<div class="bfo-pick-header-right">
				<strong><?php
					echo esc_html( sprintf(
						/* translators: %d: number of orders */
						_n( '%d order', '%d orders', count( $orders ), 'barcode-fulfillment-orders' ),
						count( $orders )
					) );
				?></strong>
			</div>
		</div>

		<?php foreach ( $orders as $order ) :
			$order_barcode   = bfo_get_order_barcode( $order->get_id() );
			$order_bc_svg    = $order_barcode ? $generator->render_inline( $order_barcode, $order_format, 56, 1 ) : '';
			$shipping_method = '';
			foreach ( $order->get_items( 'shipping' ) as $s_item ) {
				$shipping_method = $s_item->get_method_title();
				break;
			}
			$date_str = $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '—';
		?>
		<div class="bfo-pick-order">
			<div class="bfo-pick-order-head">
				<div class="bfo-pick-order-info">
					<h2>
						<?php
						echo esc_html( sprintf(
							/* translators: %d: order number */
							__( 'Order #%d', 'barcode-fulfillment-orders' ),
							$order->get_id()
						) );
						?>
					</h2>
					<p>
						<?php echo esc_html( $order->get_formatted_billing_full_name() ); ?>
						<?php if ( $shipping_method ) : ?>
							&bull; <?php echo esc_html( $shipping_method ); ?>
						<?php endif; ?>
						&bull; <?php echo esc_html( $date_str ); ?>
					</p>
				</div>
				<?php if ( $order_bc_svg ) : ?>
				<div class="bfo-pick-order-barcode">
					<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $order_bc_svg; ?>
					<small><?php echo esc_html( $order_barcode ); ?></small>
				</div>
				<?php endif; ?>
			</div>

			<table class="bfo-pick-items">
				<thead>
					<tr>
						<th style="width:38%"><?php esc_html_e( 'Product', 'barcode-fulfillment-orders' ); ?></th>
						<th style="width:14%"><?php esc_html_e( 'SKU', 'barcode-fulfillment-orders' ); ?></th>
						<th class="col-bc" style="width:22%"><?php esc_html_e( 'Barcode', 'barcode-fulfillment-orders' ); ?></th>
						<th class="col-qty" style="width:10%"><?php esc_html_e( 'Qty', 'barcode-fulfillment-orders' ); ?></th>
						<th class="col-tick" style="width:8%"><?php esc_html_e( 'Pick', 'barcode-fulfillment-orders' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $order->get_items() as $item ) :
						/** @var WC_Order_Item_Product $item */
						$product = $item->get_product();
						$sku     = $product ? $product->get_sku() : '';
						$pbc     = $product ? bfo_get_product_barcode( $product->get_id() ) : '';
						$bc_svg  = $pbc ? $generator->render_inline( $pbc, $product_format, 40, 1 ) : '';
					?>
					<tr>
						<td><?php echo esc_html( $item->get_name() ); ?></td>
						<td><?php echo esc_html( $sku ?: '—' ); ?></td>
						<td class="col-bc">
							<?php if ( $bc_svg ) :
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								echo $bc_svg;
							else : ?>
								<span style="color:#aaa;">—</span>
							<?php endif; ?>
						</td>
						<td class="col-qty"><?php echo absint( $item->get_quantity() ); ?></td>
						<td class="col-tick"><span class="bfo-tick-box"></span></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endforeach; ?>

		<div class="bfo-pick-footer">
			<?php echo esc_html( sprintf(
				/* translators: 1: site name, 2: plugin name */
				__( '%1$s &mdash; Barcode Fulfillment Orders', 'barcode-fulfillment-orders' ),
				$site_name
			) ); ?>
		</div>

	</div>

</body>
</html>
