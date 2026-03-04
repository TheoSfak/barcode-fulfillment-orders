<?php
/**
 * WooCommerce email integration.
 *
 * Registers two custom emails:
 *   1. bfo_order_packed        — Customer notification that the order is packed and ready to ship.
 *   2. bfo_missing_items_admin — Admin notification when an order is closed with missing items.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BFO_Emails
 *
 * @since 1.0.0
 */
class BFO_Emails {

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var BFO_Emails|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @since  1.0.0
	 * @return BFO_Emails
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Alias for get_instance() — called by the main plugin bootstrapper.
	 *
	 * @since  1.0.0
	 * @return static
	 */
	public static function instance() {
		return self::get_instance();
	}

	/**
	 * Constructor — registers hooks.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		add_filter( 'woocommerce_email_classes', array( $this, 'register_emails' ) );
		add_action( 'woocommerce_order_status_' . BFO_STATUS_PACKED, array( $this, 'trigger_packed_email' ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Email class registration
	// -------------------------------------------------------------------------

	/**
	 * Adds the custom email classes to WooCommerce.
	 *
	 * @since  1.0.0
	 * @param  array $email_class_instances  Existing WC email classes.
	 * @return array
	 */
	public function register_emails( $email_class_instances ) {
		require_once BFO_PLUGIN_DIR . 'includes/emails/class-bfo-email-order-packed.php';
		require_once BFO_PLUGIN_DIR . 'includes/emails/class-bfo-email-missing-items-admin.php';

		$email_class_instances['BFO_Email_Order_Packed']          = new BFO_Email_Order_Packed();
		$email_class_instances['BFO_Email_Missing_Items_Admin']    = new BFO_Email_Missing_Items_Admin();

		return $email_class_instances;
	}

	// -------------------------------------------------------------------------
	// Trigger helpers
	// -------------------------------------------------------------------------

	/**
	 * Fires the "Order Packed" email when an order moves to BFO_STATUS_PACKED.
	 *
	 * @since  1.0.0
	 * @param  int      $order_id  Order ID.
	 * @param  WC_Order $order     Order object.
	 * @return void
	 */
	public function trigger_packed_email( $order_id, $order ) {
		if ( 'yes' !== get_option( BFO_OPTION_EMAIL_PACKED, 'yes' ) ) {
			return;
		}

		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();

		if ( isset( $emails['BFO_Email_Order_Packed'] ) ) {
			$emails['BFO_Email_Order_Packed']->trigger( $order_id, $order );
		}
	}

	/**
	 * Sends the admin "Missing Items" notification email.
	 *
	 * @since  1.0.0
	 * @param  int      $order_id       Order ID.
	 * @param  WC_Order $order          Order object.
	 * @param  array    $missing_items  Array of missing item data (name, qty, reason).
	 * @return void
	 */
	public static function send_missing_items_email( $order_id, $order, $missing_items ) {
		if ( 'yes' !== get_option( BFO_OPTION_EMAIL_MISSING, 'yes' ) ) {
			return;
		}

		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();

		if ( isset( $emails['BFO_Email_Missing_Items_Admin'] ) ) {
			$emails['BFO_Email_Missing_Items_Admin']->trigger( $order_id, $order, $missing_items );
		}
	}
}
