<?php
/**
 * Order Packed — plain text email template.
 *
 * Override in theme: barcode-fulfillment-orders/emails/plain/order-packed.php
 *
 * @var WC_Order $order
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
	/* translators: %s: customer first name */
	esc_html__( "Hi %s,\n\n", 'barcode-fulfillment-orders' ),
	esc_html( $order->get_billing_first_name() )
);

echo esc_html__( "Your order has been packed and is ready to be handed to your chosen shipping carrier.\n\n", 'barcode-fulfillment-orders' );

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n\n" . esc_html__( "Thank you for shopping with us!", 'barcode-fulfillment-orders' ) . "\n\n";

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
