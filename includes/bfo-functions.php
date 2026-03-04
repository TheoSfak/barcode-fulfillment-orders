<?php
/**
 * Procedural helper functions for Barcode Fulfillment Orders.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// -------------------------------------------------------------------------
// Product barcode helpers
// -------------------------------------------------------------------------

/**
 * Returns the barcode for a product (or product variation).
 *
 * @since  1.0.0
 * @param  int    $product_id  Product or variation post ID.
 * @return string              Barcode string, or empty string if not set.
 */
function bfo_get_product_barcode( $product_id ) {
	$product = wc_get_product( absint( $product_id ) );
	if ( ! $product ) {
		return '';
	}
	return (string) $product->get_meta( BFO_META_PRODUCT_BARCODE, true );
}

/**
 * Finds a product ID by barcode value.
 * Searches both simple products and product variations.
 *
 * @since  1.0.0
 * @param  string   $barcode  Barcode string to look up.
 * @return int|false          Product/variation ID on success, false if not found.
 */
function bfo_lookup_product_by_barcode( $barcode ) {
	if ( empty( $barcode ) ) {
		return false;
	}

	$barcode = sanitize_text_field( $barcode );

	add_filter( 'woocommerce_product_data_store_cpt_get_products_query', 'bfo_lookup_product_barcode_meta_query', 10, 2 );

	$products = wc_get_products(
		array(
			'limit'          => 1,
			'status'         => array( 'publish', 'private' ),
			'return'         => 'ids',
			'_bfo_barcode'   => $barcode, // Custom handler below adds this as meta_query.
		)
	);

	remove_filter( 'woocommerce_product_data_store_cpt_get_products_query', 'bfo_lookup_product_barcode_meta_query', 10 );

	if ( ! empty( $products ) ) {
		return (int) reset( $products );
	}

	// Also search variations (they share the same meta table).
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$variation_id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT `post_id`
			   FROM `{$wpdb->postmeta}`
			  WHERE `meta_key` = %s
			    AND `meta_value` = %s
			  LIMIT 1",
			BFO_META_PRODUCT_BARCODE,
			$barcode
		)
	);

	return $variation_id ? (int) $variation_id : false;
}

/**
 * Hooks into wc_get_products() to add _bfo_barcode as a meta_query filter.
 *
 * @since  1.0.0
 * @param  array $wp_query_args  Args passed to WP_Query.
 * @param  array $query_vars     wc_get_products query vars.
 * @return array
 */
function bfo_lookup_product_barcode_meta_query( $wp_query_args, $query_vars ) {
	if ( isset( $query_vars['_bfo_barcode'] ) ) {
		$wp_query_args['meta_query'][] = array(
			'key'   => BFO_META_PRODUCT_BARCODE,
			'value' => sanitize_text_field( $query_vars['_bfo_barcode'] ),
		);
	}
	return $wp_query_args;
}

// -------------------------------------------------------------------------
// Order barcode helpers
// -------------------------------------------------------------------------

/**
 * Returns the barcode for a WooCommerce order.
 *
 * @since  1.0.0
 * @param  int    $order_id  WooCommerce order ID.
 * @return string            Barcode string, or empty string if not set.
 */
function bfo_get_order_barcode( $order_id ) {
	$order = wc_get_order( absint( $order_id ) );
	if ( ! $order ) {
		return '';
	}
	return (string) $order->get_meta( BFO_META_ORDER_BARCODE, true );
}

/**
 * Finds an order ID by its barcode value.
 *
 * @since  1.0.0
 * @param  string   $barcode  Barcode string.
 * @return int|false          Order ID or false.
 */
function bfo_lookup_order_by_barcode( $barcode ) {
	if ( empty( $barcode ) ) {
		return false;
	}

	$barcode = sanitize_text_field( $barcode );

	// Use WC orders data store to support both HPOS and legacy.
	$orders = wc_get_orders(
		array(
			'limit'      => 1,
			'return'     => 'ids',
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Lookup by barcode requires meta query
				array(
					'key'   => BFO_META_ORDER_BARCODE,
					'value' => $barcode,
				),
			),
		)
	);

	return ! empty( $orders ) ? (int) reset( $orders ) : false;
}

/**
 * Generates a unique barcode string.
 *
 * Format: {PREFIX}{YYYYMMDDHHIISS}{RANDOM}
 *
 * @since  1.0.0
 * @param  string $type  'order' or 'product' — determines which prefix option to use.
 * @return string
 */
function bfo_generate_unique_barcode( $type = 'order' ) {
	if ( 'order' === $type ) {
		$prefix = sanitize_text_field( get_option( BFO_OPTION_ORDER_BARCODE_PREFIX, 'ORD-' ) );
	} else {
		$prefix = 'PRD-';
	}

	do {
		$barcode = strtoupper( $prefix . date( 'YmdHis' ) . wp_rand( 100, 999 ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		// Ensure uniqueness.
		if ( 'order' === $type ) {
			$exists = bfo_lookup_order_by_barcode( $barcode );
		} else {
			$exists = bfo_lookup_product_by_barcode( $barcode );
		}
	} while ( $exists );

	return $barcode;
}

// -------------------------------------------------------------------------
// Session helpers
// -------------------------------------------------------------------------

/**
 * Returns the active (or paused) packing session for an order, if any.
 *
 * @since  1.0.0
 * @param  int        $order_id  Order ID.
 * @return object|null
 */
function bfo_get_session_for_order( $order_id ) {
	return BFO_Database::get_instance()->get_active_session_for_order( absint( $order_id ) );
}

// -------------------------------------------------------------------------
// Sanitisation / formatting helpers
// -------------------------------------------------------------------------

/**
 * Sanitises a barcode string for storage: strips whitespace, converts to uppercase.
 *
 * @since  1.0.0
 * @param  string $barcode  Raw barcode input.
 * @return string
 */
function bfo_sanitize_barcode( $barcode ) {
	return strtoupper( trim( sanitize_text_field( $barcode ) ) );
}

/**
 * Returns the human-readable label for a barcode format slug.
 *
 * @since  1.0.0
 * @param  string $format  Format slug (code128, ean13, upca, qr).
 * @return string
 */
function bfo_format_barcode_type( $format ) {
	$labels = array(
		'code128' => __( 'Code 128', 'barcode-fulfillment-orders' ),
		'ean13'   => __( 'EAN-13', 'barcode-fulfillment-orders' ),
		'upca'    => __( 'UPC-A', 'barcode-fulfillment-orders' ),
		'qr'      => __( 'QR Code', 'barcode-fulfillment-orders' ),
	);

	return isset( $labels[ $format ] ) ? $labels[ $format ] : esc_html( $format );
}

/**
 * Returns a human-readable label for a packing session status slug.
 *
 * @since  1.0.0
 * @param  string $status  Status slug.
 * @return string
 */
function bfo_session_status_label( $status ) {
	$labels = array(
		BFO_SESSION_ACTIVE    => __( 'Active', 'barcode-fulfillment-orders' ),
		BFO_SESSION_PAUSED    => __( 'Paused', 'barcode-fulfillment-orders' ),
		BFO_SESSION_COMPLETED => __( 'Completed', 'barcode-fulfillment-orders' ),
		BFO_SESSION_CANCELLED => __( 'Cancelled', 'barcode-fulfillment-orders' ),
	);

	return isset( $labels[ $status ] ) ? $labels[ $status ] : esc_html( $status );
}

/**
 * Returns a human-readable label for a scan log action.
 *
 * @since  1.0.0
 * @param  string $action  Action slug.
 * @return string
 */
function bfo_scan_action_label( $action ) {
	$labels = array(
		BFO_SCAN_ACTION_SCANNED   => __( 'Scanned', 'barcode-fulfillment-orders' ),
		BFO_SCAN_ACTION_MISSING   => __( 'Missing', 'barcode-fulfillment-orders' ),
		BFO_SCAN_ACTION_WRONG     => __( 'Wrong Product', 'barcode-fulfillment-orders' ),
		BFO_SCAN_ACTION_DUPLICATE => __( 'Duplicate Scan', 'barcode-fulfillment-orders' ),
		BFO_SCAN_ACTION_OVER_SCAN => __( 'Over-scanned', 'barcode-fulfillment-orders' ),
	);

	return isset( $labels[ $action ] ) ? $labels[ $action ] : esc_html( $action );
}

/**
 * Formats seconds into a human-readable duration (e.g. "2h 15m 30s").
 *
 * @since  1.0.0
 * @param  int    $seconds  Duration in seconds.
 * @return string
 */
function bfo_format_duration( $seconds ) {
	$seconds = absint( $seconds );
	$hours   = intdiv( $seconds, 3600 );
	$minutes = intdiv( $seconds % 3600, 60 );
	$secs    = $seconds % 60;

	$parts = array();
	if ( $hours ) {
		/* translators: %d: number of hours */
		$parts[] = sprintf( _n( '%dh', '%dh', $hours, 'barcode-fulfillment-orders' ), $hours );
	}
	if ( $minutes ) {
		/* translators: %d: number of minutes */
		$parts[] = sprintf( _n( '%dm', '%dm', $minutes, 'barcode-fulfillment-orders' ), $minutes );
	}
	$parts[] = sprintf( '%ds', $secs );

	return implode( ' ', $parts );
}

/**
 * Returns the fulfillment screen URL (optionally for a specific order).
 *
 * @since  1.0.0
 * @param  int $order_id  Optional order ID.
 * @return string
 */
function bfo_fulfillment_url( $order_id = 0 ) {
	$url = admin_url( 'admin.php?page=bfo-pack-order' );
	if ( $order_id ) {
		$url = add_query_arg( 'order_id', absint( $order_id ), $url );
	}
	return $url;
}
