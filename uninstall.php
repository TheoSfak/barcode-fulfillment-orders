<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes all plugin data: custom tables, order/product meta, plugin options,
 * the warehouse worker role and its capabilities.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// -------------------------------------------------------------------------
// Drop custom tables
// -------------------------------------------------------------------------

$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}bfo_scan_logs`" );       // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}bfo_boxes`" );           // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}bfo_packing_sessions`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// -------------------------------------------------------------------------
// Remove product meta
// -------------------------------------------------------------------------

$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_bfo_barcode' ) );           // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_bfo_order_barcode' ) );     // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_bfo_packing_session_id' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_bfo_box_count' ) );         // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// -------------------------------------------------------------------------
// Remove HPOS order meta (if HPOS active)
// -------------------------------------------------------------------------

if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wc_orders_meta'" ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( "{$wpdb->prefix}wc_orders_meta", array( 'meta_key' => '_bfo_order_barcode' ) );      // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( "{$wpdb->prefix}wc_orders_meta", array( 'meta_key' => '_bfo_packing_session_id' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( "{$wpdb->prefix}wc_orders_meta", array( 'meta_key' => '_bfo_box_count' ) );          // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

// -------------------------------------------------------------------------
// Remove plugin options
// -------------------------------------------------------------------------

$options = array(
	'bfo_product_barcode_format',
	'bfo_order_barcode_format',
	'bfo_order_barcode_prefix',
	'bfo_auto_generate_product',
	'bfo_session_timeout',
	'bfo_missing_policy',
	'bfo_multi_box',
	'bfo_camera_scan',
	'bfo_sound_effects',
	'bfo_queue_refresh',
	'bfo_email_packed',
	'bfo_email_missing',
	'bfo_log_retention',
	'bfo_db_version',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// -------------------------------------------------------------------------
// Remove transients
// -------------------------------------------------------------------------

delete_transient( 'bfo_order_queue_cache' );

// -------------------------------------------------------------------------
// Remove the warehouse_worker role and custom capabilities
// -------------------------------------------------------------------------

remove_role( 'warehouse_worker' );

$capabilities_to_remove = array(
	'bfo_manage_settings',
	'bfo_pack_orders',
	'bfo_view_queue',
	'bfo_view_dashboard',
);

$roles_to_clean = array( 'administrator', 'shop_manager' );

foreach ( $roles_to_clean as $role_name ) {
	$role = get_role( $role_name );
	if ( $role ) {
		foreach ( $capabilities_to_remove as $cap ) {
			$role->remove_cap( $cap );
		}
	}
}
