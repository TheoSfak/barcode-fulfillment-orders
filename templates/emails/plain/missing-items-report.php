<?php
/**
 * Missing Items Report — plain text admin email template.
 *
 * Override in theme: barcode-fulfillment-orders/emails/plain/missing-items-report.php
 *
 * @var WC_Order $order
 * @var array    $missing_items
 * @var string   $email_heading
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

echo "= " . esc_html( $email_heading ) . " =\n\n";

printf(
	/* translators: %s: order number */
	esc_html__( "Order #%s has been packed with one or more missing items:\n\n", 'barcode-fulfillment-orders' ),
	esc_html( $order->get_order_number() )
);

if ( ! empty( $missing_items ) ) {
	foreach ( $missing_items as $item ) {
		$reason = bfo_format_missing_reason( $item['reason'] ?? '' );
		printf(
			"- %s (Qty missing: %d, Reason: %s)\n",
			esc_html( $item['name'] ?? '' ),
			(int) ( $item['qty_missing'] ?? 1 ),
			esc_html( $reason )
		);
		if ( ! empty( $item['notes'] ) ) {
			echo "  " . esc_html( $item['notes'] ) . "\n";
		}
	}
}

echo "\n" . esc_html__( 'View the order:', 'barcode-fulfillment-orders' ) . ' ' . esc_url( get_edit_post_link( $order->get_id() ) ) . "\n\n";

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
