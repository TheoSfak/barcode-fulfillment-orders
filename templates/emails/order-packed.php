<?php
/**
 * Order Packed — HTML email template.
 *
 * Override this in your theme: barcode-fulfillment-orders/emails/order-packed.php
 *
 * @var WC_Order $order          Order object.
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

/**
 * @hooked WC_Emails::email_header — 10
 */
do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php
	printf(
		/* translators: %s: customer first name */
		esc_html__( 'Hi %s,', 'barcode-fulfillment-orders' ),
		esc_html( $order->get_billing_first_name() )
	);
?></p>

<p><?php esc_html_e( 'Great news! Your order has been packed and is ready to be handed to your chosen shipping carrier. You will receive a separate tracking notification once it has been collected.', 'barcode-fulfillment-orders' ); ?></p>

<?php
/**
 * @hooked WC_Emails::order_details — 10
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * @hooked WC_Emails::order_meta — 10
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/**
 * @hooked WC_Emails::customer_details — 10
 * @hooked WC_Emails::email_address    — 20
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );
?>

<p><?php esc_html_e( 'Thank you for shopping with us!', 'barcode-fulfillment-orders' ); ?></p>

<?php
/**
 * @hooked WC_Emails::email_footer — 10
 */
do_action( 'woocommerce_email_footer', $email );
