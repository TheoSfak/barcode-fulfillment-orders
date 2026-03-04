<?php
/**
 * Missing Items Report — HTML admin email template.
 *
 * Override this in your theme: barcode-fulfillment-orders/emails/missing-items-report.php
 *
 * @var WC_Order $order          Order object.
 * @var array    $missing_items  Array of missing item data.
 * @var string   $email_heading  Email heading text.
 * @var bool     $sent_to_admin  Whether this is an admin email.
 * @var bool     $plain_text     Whether this is a plain text email.
 * @var WC_Email $email          Email object.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php
	printf(
		/* translators: %s: order number */
		esc_html__( 'The following items were marked as missing during packing for order %s.', 'barcode-fulfillment-orders' ),
		'<strong>#' . esc_html( $order->get_order_number() ) . '</strong>'
	);
?></p>

<?php if ( ! empty( $missing_items ) ) : ?>
	<table cellspacing="0" cellpadding="6" style="width:100%;border:1px solid #eee;" border="1">
		<thead>
			<tr>
				<th style="text-align:left;padding:8px;background:#f5f5f5;"><?php esc_html_e( 'Product', 'barcode-fulfillment-orders' ); ?></th>
				<th style="text-align:center;padding:8px;background:#f5f5f5;"><?php esc_html_e( 'Qty Missing', 'barcode-fulfillment-orders' ); ?></th>
				<th style="text-align:left;padding:8px;background:#f5f5f5;"><?php esc_html_e( 'Reason', 'barcode-fulfillment-orders' ); ?></th>
				<th style="text-align:left;padding:8px;background:#f5f5f5;"><?php esc_html_e( 'Notes', 'barcode-fulfillment-orders' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $missing_items as $item ) : ?>
				<tr>
					<td style="padding:8px;border-top:1px solid #eee;"><?php echo esc_html( $item['name'] ?? '' ); ?></td>
					<td style="padding:8px;text-align:center;border-top:1px solid #eee;"><?php echo esc_html( $item['qty_missing'] ?? 1 ); ?></td>
					<td style="padding:8px;border-top:1px solid #eee;"><?php echo esc_html( bfo_format_missing_reason( $item['reason'] ?? '' ) ); ?></td>
					<td style="padding:8px;border-top:1px solid #eee;"><?php echo esc_html( $item['notes'] ?? '' ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<p style="margin-top:16px;">
	<a href="<?php echo esc_url( get_edit_post_link( $order->get_id() ) ); ?>"
	   style="background:#2271b1;color:#fff;padding:8px 16px;text-decoration:none;border-radius:4px;">
		<?php esc_html_e( 'View Order', 'barcode-fulfillment-orders' ); ?>
	</a>
</p>

<?php
/**
 * @hooked WC_Emails::order_details — 10
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_footer', $email );
