<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width">
<title><?php
/* translators: %s: order number */
printf( esc_html__( 'Packing Slip #%s', 'barcode-fulfillment-orders' ), esc_html( $order->get_order_number() ) );
?></title>
<link rel="stylesheet" href="<?php echo esc_url( BFO_PLUGIN_URL . 'assets/css/bfo-print.css' ); ?>">
</head>
<body class="bfo-print-body">

<?php
/**
 * Packing slip print template.
 *
 * Override in theme: barcode-fulfillment-orders/print/packing-slip.php
 *
 * @var WC_Order $order  Order object.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$generator     = BFO_Barcode_Generator::instance();
$format        = get_option( BFO_OPTION_BARCODE_FORMAT, 'code128' );
$order_barcode = $order->get_meta( BFO_META_ORDER_BARCODE );
$site_logo_id  = get_theme_mod( 'custom_logo' );
$site_logo_url = $site_logo_id ? wp_get_attachment_image_url( $site_logo_id, 'medium' ) : '';
?>

<div class="bfo-print-controls">
	<button onclick="window.print();"><?php esc_html_e( 'Print Packing Slip', 'barcode-fulfillment-orders' ); ?></button>
	<button onclick="window.close();" style="margin-left:8px;"><?php esc_html_e( 'Close', 'barcode-fulfillment-orders' ); ?></button>
</div>

<div class="bfo-packing-slip">

	<!-- Header -->
	<div class="bfo-slip-header">
		<div class="bfo-slip-store">
			<?php if ( $site_logo_url ) : ?>
				<img src="<?php echo esc_url( $site_logo_url ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" class="bfo-slip-logo">
			<?php else : ?>
				<strong style="font-size:14pt;"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong>
			<?php endif; ?>
		</div>

		<div class="bfo-slip-order-info">
			<h2><?php esc_html_e( 'Packing Slip', 'barcode-fulfillment-orders' ); ?></h2>
			<p>
				<strong><?php esc_html_e( 'Order:', 'barcode-fulfillment-orders' ); ?></strong>
				#<?php echo esc_html( $order->get_order_number() ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Date:', 'barcode-fulfillment-orders' ); ?></strong>
				<?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?>
			</p>
			<?php if ( $order->get_meta( '_bfo_box_count' ) > 1 ) : ?>
				<p>
					<strong><?php esc_html_e( 'Boxes:', 'barcode-fulfillment-orders' ); ?></strong>
					<?php echo esc_html( $order->get_meta( '_bfo_box_count' ) ); ?>
				</p>
			<?php endif; ?>
		</div>
	</div>

	<!-- Addresses -->
	<div class="bfo-slip-addresses">
		<div class="bfo-slip-address">
			<h3><?php esc_html_e( 'Ship To', 'barcode-fulfillment-orders' ); ?></h3>
			<address>
				<?php echo wp_kses_post( $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address() ); ?>
			</address>
		</div>

		<div class="bfo-slip-address">
			<h3><?php esc_html_e( 'Bill To', 'barcode-fulfillment-orders' ); ?></h3>
			<address>
				<?php echo wp_kses_post( $order->get_formatted_billing_address() ); ?>
			</address>
		</div>
	</div>

	<!-- Items Table -->
	<table class="bfo-slip-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Product', 'barcode-fulfillment-orders' ); ?></th>
				<th class="col-sku"><?php esc_html_e( 'SKU', 'barcode-fulfillment-orders' ); ?></th>
				<th class="col-qty"><?php esc_html_e( 'Qty', 'barcode-fulfillment-orders' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $order->get_items() as $item_id => $item ) : ?>
				<?php
				/** @var WC_Order_Item_Product $item */
				$product = $item->get_product();
				$sku     = $product ? $product->get_sku() : '';
				?>
				<tr>
					<td><?php echo esc_html( $item->get_name() ); ?></td>
					<td class="col-sku"><?php echo esc_html( $sku ?: '—' ); ?></td>
					<td class="col-qty"><?php echo esc_html( $item->get_quantity() ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<!-- Shipping method -->
	<?php foreach ( $order->get_items( 'shipping' ) as $shipping ) : ?>
		<p style="font-size:8.5pt;margin-top:4mm;">
			<strong><?php esc_html_e( 'Shipping Method:', 'barcode-fulfillment-orders' ); ?></strong>
			<?php echo esc_html( $shipping->get_method_title() ); ?>
		</p>
	<?php endforeach; ?>

	<!-- Order Notes -->
	<?php $note = $order->get_customer_note(); ?>
	<?php if ( $note ) : ?>
		<p style="font-size:8.5pt;margin-top:4mm;padding:3mm;border:1px solid #ddd;border-radius:2mm;">
			<strong><?php esc_html_e( 'Customer Note:', 'barcode-fulfillment-orders' ); ?></strong><br>
			<?php echo esc_html( $note ); ?>
		</p>
	<?php endif; ?>

	<!-- Order Barcode -->
	<?php if ( $order_barcode ) : ?>
		<div class="bfo-slip-barcode">
			<?php echo $generator->render_inline( $order_barcode, $format, 50, 1.4 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p><?php echo esc_html( $order_barcode ); ?></p>
		</div>
	<?php endif; ?>

	<!-- Footer -->
	<div class="bfo-slip-footer">
		<?php printf(
			/* translators: %s: site name */
			esc_html__( 'Thank you for your order — %s', 'barcode-fulfillment-orders' ),
			esc_html( get_bloginfo( 'name' ) )
		); ?>
	</div>

</div>

<script>
	setTimeout( function () { window.print(); }, 600 );
</script>

</body>
</html>
