<?php
/**
 * Plugin Name:       Barcode Fulfillment Orders
 * Plugin URI:        https://github.com/TheoSfak/barcode-fulfillment-orders
 * Description:       Barcode-based warehouse fulfillment for WooCommerce. Assign barcodes to products, scan orders to pack, and track every step from queue to shipment.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Theodore Sfakianakis
 * Author URI:        https://github.com/TheoSfak
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       barcode-fulfillment-orders
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:      9.9
 *
 * @package BarcodeFulfillmentOrders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// -------------------------------------------------------------------------
// Core constants
// -------------------------------------------------------------------------

/** Plugin version. */
define( 'BFO_VERSION', '1.0.0' );

/** Database schema version (increment when tables change). */
define( 'BFO_DB_VERSION', '1.0.0' );

/** Absolute path to plugin directory (with trailing slash). */
define( 'BFO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/** URL to plugin directory (with trailing slash). */
define( 'BFO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/** Plugin basename for use with hooks. */
define( 'BFO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// -------------------------------------------------------------------------
// Meta key constants
// -------------------------------------------------------------------------

/** Product meta key storing the barcode value. */
define( 'BFO_META_PRODUCT_BARCODE', '_bfo_barcode' );

/** Order meta key storing the order barcode value. */
define( 'BFO_META_ORDER_BARCODE', '_bfo_order_barcode' );

/** Order meta key storing the packing session ID. */
define( 'BFO_META_ORDER_SESSION_ID', '_bfo_packing_session_id' );

/** Order meta key storing box count. */
define( 'BFO_META_ORDER_BOX_COUNT', '_bfo_box_count' );

// -------------------------------------------------------------------------
// Option key constants
// -------------------------------------------------------------------------

/** Option: barcode format for products (code128 / ean13 / upca / qr). */
define( 'BFO_OPTION_PRODUCT_BARCODE_FORMAT', 'bfo_product_barcode_format' );
/** Alias used by settings class (unified single format option). */
define( 'BFO_OPTION_BARCODE_FORMAT',         'bfo_barcode_format' );

/** Option: barcode format for orders (code128 / qr). */
define( 'BFO_OPTION_ORDER_BARCODE_FORMAT', 'bfo_order_barcode_format' );

/** Option: prefix prepended to auto-generated order barcodes. */
define( 'BFO_OPTION_ORDER_BARCODE_PREFIX', 'bfo_order_barcode_prefix' );
/** Aliases used by settings / generator classes. */
define( 'BFO_OPTION_ORDER_PREFIX',   'bfo_order_prefix' );
define( 'BFO_OPTION_PRODUCT_PREFIX', 'bfo_product_prefix' );

/** Option: auto-generate barcode when a product is created (yes/no). */
define( 'BFO_OPTION_AUTO_GENERATE_PRODUCT', 'bfo_auto_generate_product' );
/** Alias used by settings class. */
define( 'BFO_OPTION_AUTO_GENERATE', 'bfo_auto_generate' );

/** Option: session idle timeout in minutes before auto-pause. */
define( 'BFO_OPTION_SESSION_TIMEOUT', 'bfo_session_timeout' );
/** Alias used by settings class. */
define( 'BFO_OPTION_IDLE_TIMEOUT',    'bfo_idle_timeout' );

/** Option: missing item policy (allow / approval / backorder). */
define( 'BFO_OPTION_MISSING_POLICY', 'bfo_missing_policy' );

/** Option: enable multi-box packing (yes/no). */
define( 'BFO_OPTION_MULTI_BOX', 'bfo_multi_box' );

/** Option: enable camera scanning on fulfillment screen (yes/no). */
define( 'BFO_OPTION_CAMERA_SCAN', 'bfo_camera_scan' );

/** Option: enable sound effects on fulfillment screen (yes/no). */
define( 'BFO_OPTION_SOUND_EFFECTS', 'bfo_sound_effects' );
/** Alias used by settings class. */
define( 'BFO_OPTION_SOUND_CUES',    'bfo_sound_cues' );

/** Option: order queue auto-refresh interval in seconds. */
define( 'BFO_OPTION_QUEUE_REFRESH', 'bfo_queue_refresh' );

/** Option: enable "Order Packed" customer email (yes/no). */
define( 'BFO_OPTION_EMAIL_PACKED', 'bfo_email_packed' );

/** Option: enable missing items admin notification email (yes/no). */
define( 'BFO_OPTION_EMAIL_MISSING', 'bfo_email_missing' );

/** Option: audit log retention in days (0 = keep forever). */
define( 'BFO_OPTION_LOG_RETENTION', 'bfo_log_retention' );

/** Option: stored DB schema version for migration checks. */
define( 'BFO_OPTION_DB_VERSION', 'bfo_db_version' );

// -------------------------------------------------------------------------
// Capability constants
// -------------------------------------------------------------------------

/** Capability required to access fulfillment settings and dashboard. */
define( 'BFO_CAPABILITY_MANAGE', 'bfo_manage_settings' );

/** Capability required to pack/scan orders. */
define( 'BFO_CAPABILITY_PACK', 'bfo_pack_orders' );

/** Capability required to view the order queue. */
define( 'BFO_CAPABILITY_QUEUE', 'bfo_view_queue' );

/** Capability required to view the analytics dashboard. */
define( 'BFO_CAPABILITY_DASHBOARD', 'bfo_view_dashboard' );

// -------------------------------------------------------------------------
// Custom order status constants
// -------------------------------------------------------------------------

/** Order status slug: actively being packed by a warehouse worker. */
define( 'BFO_STATUS_PACKING', 'bfo-packing' );

/** Order status slug: packed and ready to ship. */
define( 'BFO_STATUS_PACKED', 'bfo-packed' );

// -------------------------------------------------------------------------
// Packing session status constants
// -------------------------------------------------------------------------

define( 'BFO_SESSION_ACTIVE',    'active' );
define( 'BFO_SESSION_PAUSED',    'paused' );
define( 'BFO_SESSION_COMPLETED', 'completed' );
define( 'BFO_SESSION_CANCELLED', 'cancelled' );

// Aliased constants used by audit trail and dashboard.
define( 'BFO_SESSION_STATUS_ACTIVE',    BFO_SESSION_ACTIVE );
define( 'BFO_SESSION_STATUS_PAUSED',    BFO_SESSION_PAUSED );
define( 'BFO_SESSION_STATUS_COMPLETED', BFO_SESSION_COMPLETED );
define( 'BFO_SESSION_STATUS_CANCELLED', BFO_SESSION_CANCELLED );

// -------------------------------------------------------------------------
// Scan log action constants
// -------------------------------------------------------------------------

define( 'BFO_SCAN_ACTION_SCANNED',      'scanned' );
define( 'BFO_SCAN_ACTION_MISSING',      'missing' );
define( 'BFO_SCAN_ACTION_WRONG',        'wrong_product' );
define( 'BFO_SCAN_ACTION_DUPLICATE',    'duplicate' );
define( 'BFO_SCAN_ACTION_OVER_SCAN',    'over_scan' );

// Aliased scan action constants used by scanner / dashboard.
define( 'BFO_SCAN_ACTION_SUCCESS', BFO_SCAN_ACTION_SCANNED );

// -------------------------------------------------------------------------
// Query / performance limits
// -------------------------------------------------------------------------

/** Maximum number of orders to load in the queue at once. */
define( 'BFO_MAX_QUEUE_ORDERS', 100 );

/** Maximum number of log rows to display per page in audit trail. */
define( 'BFO_MAX_LOG_PER_PAGE', 50 );

// -------------------------------------------------------------------------
// WooCommerce active check
// -------------------------------------------------------------------------

/**
 * Checks whether WooCommerce is currently active on single-site or multisite.
 *
 * @since  1.0.0
 * @return bool
 */
function bfo_is_woocommerce_active() {
	$active_plugins = (array) get_option( 'active_plugins', array() );

	if ( is_multisite() ) {
		$active_plugins = array_merge(
			$active_plugins,
			array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) )
		);
	}

	return in_array( 'woocommerce/woocommerce.php', $active_plugins, true ) ||
		   array_key_exists( 'woocommerce/woocommerce.php', $active_plugins );
}

// -------------------------------------------------------------------------
// Initialisation
// -------------------------------------------------------------------------

/**
 * Loads all plugin class files and instantiates them.
 *
 * Hooked to plugins_loaded at priority 20 so WooCommerce is already
 * initialised before we run.
 *
 * @since 1.0.0
 * @return void
 */
function bfo_init() {
	if ( ! bfo_is_woocommerce_active() ) {
		add_action( 'admin_notices', 'bfo_woocommerce_missing_notice' );
		return;
	}

	load_plugin_textdomain(
		'barcode-fulfillment-orders',
		false,
		dirname( BFO_PLUGIN_BASENAME ) . '/languages'
	);

	// Run DB migrations if needed.
	require_once BFO_PLUGIN_DIR . 'includes/class-bfo-database.php';
	BFO_Database::instance()->maybe_upgrade();

	// Core includes.
	require_once BFO_PLUGIN_DIR . 'includes/bfo-functions.php';
	require_once BFO_PLUGIN_DIR . 'includes/class-bfo-roles.php';

	// Self-heal: persist capabilities if activation previously failed/was skipped.
	$admin_role = get_role( 'administrator' );
	if ( $admin_role && ! $admin_role->has_cap( BFO_CAPABILITY_PACK ) ) {
		BFO_Roles::instance()->add_roles();
	}

	// Dynamically grant BFO caps to administrators and shop managers on every
	// request, so the page access check works even before stored caps propagate
	// to the current user's already-constructed WP_User object.
	add_filter( 'user_has_cap', 'bfo_dynamic_grant_caps', 10, 4 );

	require_once BFO_PLUGIN_DIR . 'includes/class-bfo-barcode-generator.php';
	require_once BFO_PLUGIN_DIR . 'includes/class-bfo-order-status.php';

	// Barcode management.
	require_once BFO_PLUGIN_DIR . 'includes/class-bfo-product-barcode.php';
	require_once BFO_PLUGIN_DIR . 'includes/class-bfo-order-barcode.php';

	// Fulfillment.
	require_once BFO_PLUGIN_DIR . 'includes/class-bfo-packing-session.php';
	require_once BFO_PLUGIN_DIR . 'includes/class-bfo-scanner.php';
	require_once BFO_PLUGIN_DIR . 'includes/class-bfo-missing-products.php';
	require_once BFO_PLUGIN_DIR . 'includes/class-bfo-multi-box.php';
	require_once BFO_PLUGIN_DIR . 'includes/class-bfo-order-queue.php';
	require_once BFO_PLUGIN_DIR . 'includes/class-bfo-fulfillment-screen.php';

	// Admin.
	require_once BFO_PLUGIN_DIR . 'includes/class-bfo-settings.php';
	require_once BFO_PLUGIN_DIR . 'includes/class-bfo-audit-trail.php';
	require_once BFO_PLUGIN_DIR . 'includes/class-bfo-dashboard.php';
	require_once BFO_PLUGIN_DIR . 'includes/class-bfo-labels.php';

	// Emails.
	require_once BFO_PLUGIN_DIR . 'includes/class-bfo-emails.php';

	// Instantiate all classes.
	BFO_Roles::instance();
	BFO_Order_Status::instance();
	BFO_Product_Barcode::instance();
	BFO_Order_Barcode::instance();
	BFO_Packing_Session::instance();
	BFO_Scanner::instance();
	BFO_Missing_Products::instance();
	BFO_Multi_Box::instance();
	BFO_Order_Queue::instance();
	BFO_Fulfillment_Screen::instance();
	BFO_Settings::instance();
	BFO_Audit_Trail::instance();
	BFO_Dashboard::instance();
	BFO_Labels::instance();
	BFO_Emails::instance();
}
add_action( 'plugins_loaded', 'bfo_init', 20 );

/**
 * Outputs an admin notice when WooCommerce is not active.
 *
 * @since 1.0.0
 * @return void
 */
function bfo_woocommerce_missing_notice() {
	echo '<div class="notice notice-error"><p>' .
		esc_html__( 'Barcode Fulfillment Orders requires WooCommerce to be installed and active.', 'barcode-fulfillment-orders' ) .
		'</p></div>';
}

// -------------------------------------------------------------------------
// HPOS & Blocks compatibility declaration
// -------------------------------------------------------------------------

/**
 * Declares compatibility with WooCommerce HPOS and Cart/Checkout Blocks.
 *
 * @since 1.0.0
 * @return void
 */
function bfo_declare_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			__FILE__,
			true
		);
	}
}
add_action( 'before_woocommerce_init', 'bfo_declare_compatibility' );

// -------------------------------------------------------------------------
// Activation & deactivation hooks
// -------------------------------------------------------------------------

/**
 * Plugin activation: validate WooCommerce, create DB tables, add roles, set defaults.
 *
 * @since 1.0.0
 * @return void
 */
function bfo_activate() {
	if ( ! bfo_is_woocommerce_active() ) {
		deactivate_plugins( BFO_PLUGIN_BASENAME );
		wp_die(
			esc_html__( 'Barcode Fulfillment Orders requires WooCommerce to be installed and active.', 'barcode-fulfillment-orders' ),
			esc_html__( 'Plugin Activation Error', 'barcode-fulfillment-orders' ),
			array( 'back_link' => true )
		);
	}

	require_once BFO_PLUGIN_DIR . 'includes/class-bfo-database.php';
	require_once BFO_PLUGIN_DIR . 'includes/class-bfo-roles.php';

	BFO_Database::instance()->install();
	BFO_Roles::instance()->add_roles();

	// Set default options only on first activation.
	$defaults = array(
		BFO_OPTION_PRODUCT_BARCODE_FORMAT => 'code128',
		BFO_OPTION_ORDER_BARCODE_FORMAT   => 'code128',
		BFO_OPTION_ORDER_BARCODE_PREFIX   => 'ORD-',
		BFO_OPTION_AUTO_GENERATE_PRODUCT  => 'no',
		BFO_OPTION_SESSION_TIMEOUT        => 30,
		BFO_OPTION_MISSING_POLICY         => 'allow',
		BFO_OPTION_MULTI_BOX              => 'yes',
		BFO_OPTION_CAMERA_SCAN            => 'yes',
		BFO_OPTION_SOUND_EFFECTS          => 'yes',
		BFO_OPTION_QUEUE_REFRESH          => 30,
		BFO_OPTION_EMAIL_PACKED           => 'yes',
		BFO_OPTION_EMAIL_MISSING          => 'yes',
		BFO_OPTION_LOG_RETENTION          => 90,
	);

	foreach ( $defaults as $key => $value ) {
		if ( false === get_option( $key ) ) {
			add_option( $key, $value );
		}
	}
}
register_activation_hook( __FILE__, 'bfo_activate' );

/**
 * Plugin deactivation: clean up transients.
 *
 * @since 1.0.0
 * @return void
 */
function bfo_deactivate() {
	delete_transient( 'bfo_order_queue_cache' );
}
register_deactivation_hook( __FILE__, 'bfo_deactivate' );

// -------------------------------------------------------------------------
// Dynamic capability grants
// -------------------------------------------------------------------------

/**
 * Dynamically grants all BFO capabilities to administrators and shop managers
 * on every request.
 *
 * This is necessary because WordPress constructs the current WP_User object
 * before plugins_loaded fires, so any capabilities added via add_cap() during
 * that hook are not reflected in the current request's user object. Using
 * the user_has_cap filter ensures the check is always live.
 *
 * @since  1.0.0
 * @param  bool[]   $allcaps  All capabilities the user has.
 * @param  string[] $caps     Required primitive capabilities.
 * @param  array    $args     [0] requested cap, [1] user ID, ...
 * @param  WP_User  $user     The user object.
 * @return bool[]
 */
function bfo_dynamic_grant_caps( $allcaps, $caps, $args, $user ) {
	$bfo_caps = array(
		BFO_CAPABILITY_MANAGE,
		BFO_CAPABILITY_PACK,
		BFO_CAPABILITY_QUEUE,
		BFO_CAPABILITY_DASHBOARD,
	);

	// Only act when a BFO capability is being checked.
	if ( empty( array_intersect( $caps, $bfo_caps ) ) ) {
		return $allcaps;
	}

	// Grant to administrators and shop managers.
	if ( ! empty( $allcaps['manage_options'] ) || ! empty( $allcaps['manage_woocommerce'] ) ) {
		foreach ( $bfo_caps as $cap ) {
			$allcaps[ $cap ] = true;
		}
	}

	return $allcaps;
}
