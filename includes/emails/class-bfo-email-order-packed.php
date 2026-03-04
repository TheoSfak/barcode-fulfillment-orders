<?php
/**
 * Order Packed customer email class.
 *
 * Sent to the customer when their order status changes to BFO_STATUS_PACKED.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BFO_Email_Order_Packed
 *
 * @since 1.0.0
 */
class BFO_Email_Order_Packed extends WC_Email {

	/**
	 * Constructor — sets email properties and hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id             = 'bfo_order_packed';
		$this->customer_email = true;
		$this->title          = __( 'Order Packed', 'barcode-fulfillment-orders' );
		$this->description    = __( 'Sent to customers when their order is fully packed and ready to ship.', 'barcode-fulfillment-orders' );
		$this->heading        = __( 'Your order is packed and ready to ship!',  'barcode-fulfillment-orders' );
		$this->subject        = __( 'Your order #{order_number} is packed', 'barcode-fulfillment-orders' );

		$this->template_html  = 'emails/order-packed.php';
		$this->template_plain = 'emails/plain/order-packed.php';
		$this->template_base  = BFO_PLUGIN_DIR . 'templates/';

		// Hook into the packed status transition.
		$this->placeholders = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);

		parent::__construct();
	}

	// -------------------------------------------------------------------------
	// Trigger
	// -------------------------------------------------------------------------

	/**
	 * Triggers the email send.
	 *
	 * @since  1.0.0
	 * @param  int           $order_id  Order ID.
	 * @param  WC_Order|null $order     Optional order object.
	 * @return void
	 */
	public function trigger( $order_id, $order = null ) {
		$this->setup_locale();

		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( absint( $order_id ) );
		}

		if ( is_a( $order, 'WC_Order' ) ) {
			$this->object                         = $order;
			$this->recipient                      = $order->get_billing_email();
			$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
			$this->placeholders['{order_number}'] = $order->get_order_number();
		}

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send(
				$this->get_recipient(),
				$this->get_subject(),
				$this->get_content(),
				$this->get_headers(),
				$this->get_attachments()
			);
		}

		$this->restore_locale();
	}

	// -------------------------------------------------------------------------
	// Content
	// -------------------------------------------------------------------------

	/**
	 * Returns the HTML email content.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'order'         => $this->object,
				'email_heading' => $this->get_heading(),
				'sent_to_admin' => false,
				'plain_text'    => false,
				'email'         => $this,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * Returns the plain-text email content.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'order'         => $this->object,
				'email_heading' => $this->get_heading(),
				'sent_to_admin' => false,
				'plain_text'    => true,
				'email'         => $this,
			),
			'',
			$this->template_base
		);
	}
}
