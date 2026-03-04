<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width">
<title><?php
/* translators: %s: order number */
printf( esc_html__( 'Order Label #%s', 'barcode-fulfillment-orders' ), esc_html( $order->get_order_number() ) );
?></title>
<link rel="stylesheet" href="<?php echo esc_url( BFO_PLUGIN_URL . 'assets/css/bfo-print.css' ); ?>">
</head>
<body class="bfo-print-body">

<?php
/**
 * Order label print template.
 *
 * Override in theme: barcode-fulfillment-orders/print/order-label.php
 *
 * @var WC_Order $order  Order object.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$generator = BFO_Barcode_Generator::instance();
$format    = get_option( BFO_OPTION_BARCODE_FORMAT, 'code128' );
$barcode   = $order->get_meta( BFO_META_ORDER_BARCODE );
$svg       = $barcode ? $generator->render_inline( $barcode, $format, 70, 1.8 ) : '';

// Build shipping address.
$ship_name    = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
$ship_company = $order->get_shipping_company();
$ship_address = $order->get_formatted_shipping_address();
if ( ! $ship_name && ! $ship_address ) {
	$ship_name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	$ship_address = $order->get_formatted_billing_address();
}
?>

<div class="bfo-print-controls">
	<button onclick="window.print();"><?php esc_html_e( 'Print Label', 'barcode-fulfillment-orders' ); ?></button>
	<button onclick="window.close();" style="margin-left:8px;"><?php esc_html_e( 'Close', 'barcode-fulfillment-orders' ); ?></button>
</div>

<div class="bfo-label-sheet">
	<div class="bfo-label-grid">
		<div class="bfo-label bfo-label--order">

			<!-- Store / From -->
			<div class="bfo-label__from" style="font-size:7pt;color:#666;margin-bottom:4mm;align-self:flex-start;">
				<strong><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong>
			</div>

			<!-- Ship To -->
			<div class="bfo-label__customer" style="align-self:flex-start;margin-bottom:4mm;">
				<div style="font-size:7pt;text-transform:uppercase;color:#888;letter-spacing:0.5px;margin-bottom:1mm;">
					<?php esc_html_e( 'Ship To', 'barcode-fulfillment-orders' ); ?>
				</div>
				<strong style="font-size:10pt;"><?php echo esc_html( $ship_name ); ?></strong>
				<?php if ( $ship_company ) : ?>
					<div style="font-size:8pt;"><?php echo esc_html( $ship_company ); ?></div>
				<?php endif; ?>
				<div class="bfo-label__address" style="white-space:pre-line;">
					<?php echo wp_kses_post( $ship_address ); ?>
				</div>
			</div>

			<!-- Order number and barcode -->
			<div class="bfo-label__order-number" style="font-size:12pt;font-weight:700;margin-bottom:2mm;">
				<?php
				/* translators: %s: order number */
				printf( esc_html__( 'Order #%s', 'barcode-fulfillment-orders' ), esc_html( $order->get_order_number() ) );
				?>
			</div>

			<?php if ( $svg ) : ?>
				<div class="bfo-label__barcode">
					<?php echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>
				<div class="bfo-label__code"><?php echo esc_html( $barcode ); ?></div>
			<?php endif; ?>

		</div>
	</div>
</div>

<script>
	setTimeout( function () { window.print(); }, 600 );
</script>

</body>
</html>
