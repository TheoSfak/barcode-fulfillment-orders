<?php
/**
 * Missing Items admin notification email class.
 *
 * Sent to the store admin/shop manager when a packing session is completed with
 * one or more items marked as missing.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BFO_Email_Missing_Items_Admin
 *
 * @since 1.0.0
 */
class BFO_Email_Missing_Items_Admin extends WC_Email {

	/** @var array Missing item details [product_id, name, qty_missing, reason, notes] */
	protected array $missing_items = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id             = 'bfo_missing_items_admin';
		$this->customer_email = false;
		$this->title          = __( 'Missing Items Report (admin)', 'barcode-fulfillment-orders' );
		$this->description    = __( 'Sent to the shop manager when a packed order has missing items.', 'barcode-fulfillment-orders' );
		$this->heading        = __( 'Missing Items Report', 'barcode-fulfillment-orders' );
		$this->subject        = __( '[{site_title}] Order #{order_number} packed with missing items', 'barcode-fulfillment-orders' );

		$this->template_html  = 'emails/missing-items-report.php';
		$this->template_plain = 'emails/plain/missing-items-report.php';
		$this->template_base  = BFO_PLUGIN_DIR . 'templates/';

		$this->placeholders = array(
			'{site_title}'   => $this->get_blogname(),
			'{order_date}'   => '',
			'{order_number}' => '',
		);

		parent::__construct();
	}

	// -------------------------------------------------------------------------
	// Trigger
	// -------------------------------------------------------------------------

	/**
	 * Triggers the admin email.
	 *
	 * @since  1.0.0
	 * @param  int      $order_id      Order ID.
	 * @param  array    $missing_items Array of missing item data.
	 * @param  WC_Order $order         Optional order object.
	 * @return void
	 */
	public function trigger( $order_id, array $missing_items = array(), $order = null ) {
		$this->setup_locale();

		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( absint( $order_id ) );
		}

		if ( is_a( $order, 'WC_Order' ) ) {
			$this->object                         = $order;
			$this->missing_items                  = $missing_items;
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

	/**
	 * Returns the default recipient (shop manager / admin).
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public function get_default_recipient() {
		return get_option( 'admin_email' );
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
				'missing_items' => $this->missing_items,
				'email_heading' => $this->get_heading(),
				'sent_to_admin' => true,
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
				'missing_items' => $this->missing_items,
				'email_heading' => $this->get_heading(),
				'sent_to_admin' => true,
				'plain_text'    => true,
				'email'         => $this,
			),
			'',
			$this->template_base
		);
	}

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	/**
	 * Initialises form fields in WC Email settings.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function init_form_fields() {
		parent::init_form_fields();

		// Remove default recipient label and add custom description.
		if ( isset( $this->form_fields['recipient'] ) ) {
			$this->form_fields['recipient']['description'] = __( 'Comma-separated list of email addresses. Defaults to the admin email.', 'barcode-fulfillment-orders' );
		}
	}
}
