<?php
/**
 * Roles and capabilities: registers the Warehouse Worker role
 * and grants custom capabilities to administrator and shop_manager.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BFO_Roles
 *
 * @since 1.0.0
 */
class BFO_Roles {

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var BFO_Roles|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @since  1.0.0
	 * @return BFO_Roles
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
	 * Private constructor — use get_instance().
	 *
	 * @since 1.0.0
	 */
	private function __construct() {}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * All BFO-specific capabilities.
	 *
	 * @since  1.0.0
	 * @return string[]
	 */
	public function get_all_caps() {
		return array(
			BFO_CAPABILITY_MANAGE,    // bfo_manage_settings
			BFO_CAPABILITY_PACK,      // bfo_pack_orders
			BFO_CAPABILITY_QUEUE,     // bfo_view_queue
			BFO_CAPABILITY_DASHBOARD, // bfo_view_dashboard
		);
	}

	/**
	 * Creates the warehouse_worker role and grants caps to privileged roles.
	 *
	 * Called on plugin activation.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function add_roles() {
		// Warehouse Worker: can read WP admin, view queue, and pack orders.
		add_role(
			'warehouse_worker',
			__( 'Warehouse Worker', 'barcode-fulfillment-orders' ),
			array(
				'read'                  => true,
				BFO_CAPABILITY_PACK     => true,
				BFO_CAPABILITY_QUEUE    => true,
			)
		);

		// Grant all caps to administrator and shop_manager.
		$privileged = array( 'administrator', 'shop_manager' );

		foreach ( $privileged as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $this->get_all_caps() as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Removes BFO capabilities from all roles.
	 *
	 * Called on plugin uninstall (via uninstall.php).
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function remove_roles() {
		remove_role( 'warehouse_worker' );

		$privileged = array( 'administrator', 'shop_manager' );

		foreach ( $privileged as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $this->get_all_caps() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
