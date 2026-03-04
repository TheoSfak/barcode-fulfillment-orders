<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width">
<title><?php esc_html_e( 'Product Labels', 'barcode-fulfillment-orders' ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( BFO_PLUGIN_URL . 'assets/css/bfo-print.css' ); ?>">
</head>
<body class="bfo-print-body">

<?php
/**
 * Product label print template.
 *
 * Override in theme: barcode-fulfillment-orders/print/product-label.php
 *
 * @var WC_Product[]  $products  Products to print labels for.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$generator = BFO_Barcode_Generator::instance();
$format    = get_option( BFO_OPTION_BARCODE_FORMAT, 'code128' );
?>

<div class="bfo-print-controls">
	<button onclick="window.print();"><?php esc_html_e( 'Print Labels', 'barcode-fulfillment-orders' ); ?></button>
	<button onclick="window.close();" style="margin-left:8px;"><?php esc_html_e( 'Close', 'barcode-fulfillment-orders' ); ?></button>
</div>

<div class="bfo-label-sheet">
	<div class="bfo-label-grid">
		<?php foreach ( $products as $product ) : ?>
			<?php
			$barcode = $product->get_meta( BFO_META_PRODUCT_BARCODE );
			if ( ! $barcode ) continue;
			$svg = $generator->render_inline( $barcode, $format, 60, 1.5 );
			?>
			<div class="bfo-label bfo-label--product">
				<div class="bfo-label__barcode"><?php echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				<div class="bfo-label__name"><?php echo esc_html( $product->get_name() ); ?></div>
				<?php if ( $product->get_sku() ) : ?>
					<div class="bfo-label__sku">SKU: <?php echo esc_html( $product->get_sku() ); ?></div>
				<?php endif; ?>
				<div class="bfo-label__code"><?php echo esc_html( $barcode ); ?></div>
			</div>
		<?php endforeach; ?>
	</div>
</div>

<script>
	// Auto-trigger print dialog after a short delay to allow fonts/SVGs to render.
	setTimeout( function () { window.print(); }, 600 );
</script>

</body>
</html>
