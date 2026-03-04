<?php
/**
 * Plugin settings page.
 *
 * Registers a "Settings" sub-page under the top-level Fulfillment menu and
 * provides four tabs: General, Fulfillment, Notifications, Data.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BFO_Settings
 *
 * @since 1.0.0
 */
class BFO_Settings {

	/** @var BFO_Settings|null Singleton instance. */
	private static ?BFO_Settings $instance = null;

	/** @var string Active tab slug. */
	private string $active_tab = 'general';

	/** @var string Settings-saved notice type. */
	private string $notice_type = '';

	// -------------------------------------------------------------------------
	// Singleton
	// -------------------------------------------------------------------------

	/**
	 * Returns or creates the singleton instance.
	 *
	 * @since  1.0.0
	 * @return BFO_Settings
	 */
	public static function instance(): BFO_Settings {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — registers hooks.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_post_bfo_save_settings', array( $this, 'handle_save' ) );
		add_action( 'admin_post_bfo_purge_logs', array( $this, 'handle_purge_logs' ) );
		add_action( 'admin_post_bfo_export_csv', array( $this, 'handle_export_csv' ) );
		add_action( 'admin_post_bfo_regenerate_barcodes', array( $this, 'handle_regenerate_barcodes' ) );
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	/**
	 * Registers the Settings sub-page.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'bfo-queue',
			__( 'BFO Settings', 'barcode-fulfillment-orders' ),
			__( 'Settings', 'barcode-fulfillment-orders' ),
			BFO_CAPABILITY_MANAGE,
			'bfo-settings',
			array( $this, 'render_page' )
		);
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/**
	 * Renders the settings page with tab navigation.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( BFO_CAPABILITY_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'barcode-fulfillment-orders' ) );
		}

		$this->active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification

		// Feedback notice.
		if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			add_settings_error( 'bfo_settings', 'saved', __( 'Settings saved.', 'barcode-fulfillment-orders' ), 'updated' );
		}
		if ( isset( $_GET['logs-purged'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			add_settings_error( 'bfo_settings', 'purged', __( 'Old scan logs purged.', 'barcode-fulfillment-orders' ), 'updated' );
		}
		if ( isset( $_GET['barcodes-generated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$count = absint( $_GET['barcodes-generated'] );
			/* translators: %d: number of barcodes generated */
			add_settings_error( 'bfo_settings', 'regen', sprintf( __( '%d missing barcodes generated.', 'barcode-fulfillment-orders' ), $count ), 'updated' );
		}

		$tabs = $this->get_tabs();
		?>
		<div class="wrap bfo-settings-wrap">
			<h1><?php esc_html_e( 'Barcode Fulfillment Orders — Settings', 'barcode-fulfillment-orders' ); ?></h1>
			<?php settings_errors( 'bfo_settings' ); ?>

			<nav class="nav-tab-wrapper woo-nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=bfo-settings&tab=' . $slug ) ); ?>"
					   class="nav-tab<?php echo $this->active_tab === $slug ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'bfo_save_settings_' . $this->active_tab, 'bfo_settings_nonce' ); ?>
				<input type="hidden" name="action" value="bfo_save_settings">
				<input type="hidden" name="bfo_tab" value="<?php echo esc_attr( $this->active_tab ); ?>">

				<table class="form-table" role="presentation">
					<?php $this->render_tab_fields( $this->active_tab ); ?>
				</table>

				<?php if ( 'data' !== $this->active_tab ) : ?>
					<?php submit_button( __( 'Save Settings', 'barcode-fulfillment-orders' ) ); ?>
				<?php endif; ?>
			</form>

			<?php if ( 'data' === $this->active_tab ) : ?>
				<?php $this->render_data_actions(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Tabs
	// -------------------------------------------------------------------------

	/**
	 * Returns ordered list of settings tabs.
	 *
	 * @since  1.0.0
	 * @return array<string,string>
	 */
	private function get_tabs(): array {
		return array(
			'general'       => __( 'General',       'barcode-fulfillment-orders' ),
			'fulfillment'   => __( 'Fulfillment',   'barcode-fulfillment-orders' ),
			'notifications' => __( 'Notifications', 'barcode-fulfillment-orders' ),
			'shipping'      => __( 'Shipping',      'barcode-fulfillment-orders' ),
			'data'          => __( 'Data',          'barcode-fulfillment-orders' ),
		);
	}

	// -------------------------------------------------------------------------
	// Field rendering
	// -------------------------------------------------------------------------

	/**
	 * Dispatches to the correct tab renderer.
	 *
	 * @since  1.0.0
	 * @param  string $tab Tab slug.
	 * @return void
	 */
	private function render_tab_fields( string $tab ): void {
		match ( $tab ) {
			'general'       => $this->render_general_tab(),
			'fulfillment'   => $this->render_fulfillment_tab(),
			'notifications' => $this->render_notifications_tab(),
			'shipping'      => $this->render_shipping_tab(),
			'data'          => null,
			default         => $this->render_general_tab(),
		};
	}

	/**
	 * Renders General tab fields.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function render_general_tab(): void {
		$barcode_format    = get_option( BFO_OPTION_BARCODE_FORMAT, 'code128' );
		$product_prefix    = get_option( BFO_OPTION_PRODUCT_PREFIX, 'PRD-' );
		$order_prefix      = get_option( BFO_OPTION_ORDER_PREFIX, 'ORD-' );
		$auto_gen_product  = get_option( BFO_OPTION_AUTO_GENERATE, 'yes' );
		?>
		<tr>
			<th scope="row">
				<label for="bfo_barcode_format"><?php esc_html_e( 'Barcode Format', 'barcode-fulfillment-orders' ); ?></label>
			</th>
			<td>
				<select name="bfo_barcode_format" id="bfo_barcode_format">
					<option value="code128" <?php selected( $barcode_format, 'code128' ); ?>><?php esc_html_e( 'Code 128', 'barcode-fulfillment-orders' ); ?></option>
					<option value="qr"      <?php selected( $barcode_format, 'qr' ); ?>><?php esc_html_e( 'QR Code', 'barcode-fulfillment-orders' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Barcode symbology used for products and orders.', 'barcode-fulfillment-orders' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="bfo_product_prefix"><?php esc_html_e( 'Product Barcode Prefix', 'barcode-fulfillment-orders' ); ?></label>
			</th>
			<td>
				<input type="text" name="bfo_product_prefix" id="bfo_product_prefix"
				       value="<?php echo esc_attr( $product_prefix ); ?>" class="regular-text">
				<p class="description"><?php esc_html_e( 'Prepended to auto-generated product barcodes.', 'barcode-fulfillment-orders' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="bfo_order_prefix"><?php esc_html_e( 'Order Barcode Prefix', 'barcode-fulfillment-orders' ); ?></label>
			</th>
			<td>
				<input type="text" name="bfo_order_prefix" id="bfo_order_prefix"
				       value="<?php echo esc_attr( $order_prefix ); ?>" class="regular-text">
				<p class="description"><?php esc_html_e( 'Prepended to auto-generated order barcodes.', 'barcode-fulfillment-orders' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Auto-Generate for New Products', 'barcode-fulfillment-orders' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="bfo_auto_generate" value="yes"
					       <?php checked( $auto_gen_product, 'yes' ); ?>>
					<?php esc_html_e( 'Automatically create a barcode when a new product is added.', 'barcode-fulfillment-orders' ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders Fulfillment tab fields.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function render_fulfillment_tab(): void {
		$idle_timeout   = (int) get_option( BFO_OPTION_IDLE_TIMEOUT, 30 );
		$missing_policy = get_option( BFO_OPTION_MISSING_POLICY, 'allow' );
		$multi_box      = get_option( BFO_OPTION_MULTI_BOX, 'yes' );
		$camera         = get_option( BFO_OPTION_CAMERA_SCAN, 'yes' );
		$sound          = get_option( BFO_OPTION_SOUND_CUES, 'yes' );
		$queue_refresh  = (int) get_option( BFO_OPTION_QUEUE_REFRESH, 60 );
		?>
		<tr>
			<th scope="row">
				<label for="bfo_idle_timeout"><?php esc_html_e( 'Idle Session Timeout (minutes)', 'barcode-fulfillment-orders' ); ?></label>
			</th>
			<td>
				<input type="number" name="bfo_idle_timeout" id="bfo_idle_timeout"
				       value="<?php echo esc_attr( $idle_timeout ); ?>" min="5" max="480" class="small-text">
				<p class="description"><?php esc_html_e( 'Packing sessions are auto-paused after this many minutes of inactivity.', 'barcode-fulfillment-orders' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="bfo_missing_policy"><?php esc_html_e( 'Missing Items Policy', 'barcode-fulfillment-orders' ); ?></label>
			</th>
			<td>
				<select name="bfo_missing_policy" id="bfo_missing_policy">
					<option value="allow"     <?php selected( $missing_policy, 'allow' ); ?>><?php esc_html_e( 'Allow completion with missing items', 'barcode-fulfillment-orders' ); ?></option>
					<option value="approval"  <?php selected( $missing_policy, 'approval' ); ?>><?php esc_html_e( 'Require manager approval (hold order)', 'barcode-fulfillment-orders' ); ?></option>
					<option value="backorder" <?php selected( $missing_policy, 'backorder' ); ?>><?php esc_html_e( 'Auto-backorder missing items', 'barcode-fulfillment-orders' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Multi-Box Support', 'barcode-fulfillment-orders' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="bfo_multi_box" value="yes" <?php checked( $multi_box, 'yes' ); ?>>
					<?php esc_html_e( 'Allow packing orders into multiple boxes.', 'barcode-fulfillment-orders' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Camera Scanning', 'barcode-fulfillment-orders' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="bfo_camera_scan" value="yes" <?php checked( $camera, 'yes' ); ?>>
					<?php esc_html_e( 'Enable camera/phone barcode scanning on the packing screen.', 'barcode-fulfillment-orders' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Sound Cues', 'barcode-fulfillment-orders' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="bfo_sound_cues" value="yes" <?php checked( $sound, 'yes' ); ?>>
					<?php esc_html_e( 'Play beep/error sounds on the packing screen.', 'barcode-fulfillment-orders' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="bfo_queue_refresh"><?php esc_html_e( 'Queue Auto-Refresh (seconds)', 'barcode-fulfillment-orders' ); ?></label>
			</th>
			<td>
				<input type="number" name="bfo_queue_refresh" id="bfo_queue_refresh"
				       value="<?php echo esc_attr( $queue_refresh ); ?>" min="10" max="600" class="small-text">
				<p class="description"><?php esc_html_e( 'How often the order queue page polls for new orders (0 to disable).', 'barcode-fulfillment-orders' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders Notifications tab fields.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function render_notifications_tab(): void {
		$email_packed  = get_option( BFO_OPTION_EMAIL_PACKED, 'yes' );
		$email_missing = get_option( BFO_OPTION_EMAIL_MISSING, 'yes' );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Order Packed Email to Customer', 'barcode-fulfillment-orders' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="bfo_email_packed" value="yes" <?php checked( $email_packed, 'yes' ); ?>>
					<?php esc_html_e( 'Send an email to the customer when their order is packed.', 'barcode-fulfillment-orders' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Missing Items Email to Admin', 'barcode-fulfillment-orders' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="bfo_email_missing" value="yes" <?php checked( $email_missing, 'yes' ); ?>>
					<?php esc_html_e( 'Send an email to the shop manager when items are marked missing.', 'barcode-fulfillment-orders' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"></th>
			<td>
				<p class="description">
					<?php
					printf(
						/* translators: %s: WooCommerce email settings URL */
						esc_html__( 'Email content and recipients can be customised in %s.', 'barcode-fulfillment-orders' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=email' ) ) . '">' . esc_html__( 'WooCommerce → Settings → Emails', 'barcode-fulfillment-orders' ) . '</a>'
					);
					?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders Data tab action buttons (outside the main form).
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function render_data_actions(): void {
		$retention = (int) get_option( BFO_OPTION_LOG_RETENTION, 90 );
		?>
		<h2><?php esc_html_e( 'Log Retention', 'barcode-fulfillment-orders' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'bfo_save_settings_data', 'bfo_settings_nonce' ); ?>
			<input type="hidden" name="action" value="bfo_save_settings">
			<input type="hidden" name="bfo_tab" value="data">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="bfo_log_retention"><?php esc_html_e( 'Retain scan logs for (days)', 'barcode-fulfillment-orders' ); ?></label>
					</th>
					<td>
						<input type="number" name="bfo_log_retention" id="bfo_log_retention"
						       value="<?php echo esc_attr( $retention ); ?>" min="7" max="730" class="small-text">
						<p class="description"><?php esc_html_e( 'Scan logs older than this number of days will be removed. Set to 0 to keep forever.', 'barcode-fulfillment-orders' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Retention Setting', 'barcode-fulfillment-orders' ) ); ?>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Maintenance Actions', 'barcode-fulfillment-orders' ); ?></h2>
		<p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<?php wp_nonce_field( 'bfo_purge_logs', 'bfo_purge_nonce' ); ?>
				<input type="hidden" name="action" value="bfo_purge_logs">
				<?php submit_button( __( 'Purge Old Scan Logs Now', 'barcode-fulfillment-orders' ), 'secondary', 'submit', false ); ?>
			</form>
			&nbsp;
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<?php wp_nonce_field( 'bfo_export_csv', 'bfo_export_nonce' ); ?>
				<input type="hidden" name="action" value="bfo_export_csv">
				<?php submit_button( __( 'Export Sessions CSV', 'barcode-fulfillment-orders' ), 'secondary', 'submit', false ); ?>
			</form>
			&nbsp;
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;"
			      onsubmit="return confirm('<?php esc_attr_e( 'Generate barcodes for all products that currently have none? This may take a while.', 'barcode-fulfillment-orders' ); ?>');">
				<?php wp_nonce_field( 'bfo_regenerate_barcodes', 'bfo_regen_nonce' ); ?>
				<input type="hidden" name="action" value="bfo_regenerate_barcodes">
				<?php submit_button( __( 'Generate Missing Product Barcodes', 'barcode-fulfillment-orders' ), 'secondary', 'submit', false ); ?>
			</form>
		</p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	/**
	 * Processes settings save for all tabs.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function handle_save(): void {
		$tab = isset( $_POST['bfo_tab'] ) ? sanitize_key( $_POST['bfo_tab'] ) : 'general';

		if ( ! check_admin_referer( 'bfo_save_settings_' . $tab, 'bfo_settings_nonce' )
			|| ! current_user_can( BFO_CAPABILITY_MANAGE ) ) {
			wp_die( esc_html__( 'Security check failed.', 'barcode-fulfillment-orders' ) );
		}

		match ( $tab ) {
			'general'       => $this->save_general(),
			'fulfillment'   => $this->save_fulfillment(),
			'notifications' => $this->save_notifications(),
			'shipping'      => $this->save_shipping(),
			'data'          => $this->save_data(),
			default         => null,
		};

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'bfo-settings', 'tab' => $tab, 'settings-updated' => '1' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** @since 1.0.0 */
	private function save_general(): void {
		$format = isset( $_POST['bfo_barcode_format'] ) ? sanitize_key( $_POST['bfo_barcode_format'] ) : 'code128';
		update_option( BFO_OPTION_BARCODE_FORMAT, in_array( $format, array( 'code128', 'qr' ), true ) ? $format : 'code128' );
		update_option( BFO_OPTION_PRODUCT_PREFIX, isset( $_POST['bfo_product_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['bfo_product_prefix'] ) ) : 'PRD-' );
		update_option( BFO_OPTION_ORDER_PREFIX,   isset( $_POST['bfo_order_prefix'] )   ? sanitize_text_field( wp_unslash( $_POST['bfo_order_prefix'] ) )   : 'ORD-' );
		update_option( BFO_OPTION_AUTO_GENERATE,  isset( $_POST['bfo_auto_generate'] )  ? 'yes' : 'no' );
	}

	/** @since 1.0.0 */
	private function save_fulfillment(): void {
		$timeout = isset( $_POST['bfo_idle_timeout'] ) ? absint( $_POST['bfo_idle_timeout'] ) : 30;
		update_option( BFO_OPTION_IDLE_TIMEOUT, max( 5, min( 480, $timeout ) ) );

		$policies = array( 'allow', 'approval', 'backorder' );
		$policy   = isset( $_POST['bfo_missing_policy'] ) ? sanitize_key( $_POST['bfo_missing_policy'] ) : 'allow';
		update_option( BFO_OPTION_MISSING_POLICY, in_array( $policy, $policies, true ) ? $policy : 'allow' );

		update_option( BFO_OPTION_MULTI_BOX,    isset( $_POST['bfo_multi_box'] )    ? 'yes' : 'no' );
		update_option( BFO_OPTION_CAMERA_SCAN,  isset( $_POST['bfo_camera_scan'] )  ? 'yes' : 'no' );
		update_option( BFO_OPTION_SOUND_CUES,   isset( $_POST['bfo_sound_cues'] )   ? 'yes' : 'no' );

		$refresh = isset( $_POST['bfo_queue_refresh'] ) ? absint( $_POST['bfo_queue_refresh'] ) : 60;
		update_option( BFO_OPTION_QUEUE_REFRESH, max( 0, min( 600, $refresh ) ) );
	}

	/** @since 1.0.0 */
	private function save_notifications(): void {
		update_option( BFO_OPTION_EMAIL_PACKED,  isset( $_POST['bfo_email_packed'] )  ? 'yes' : 'no' );
		update_option( BFO_OPTION_EMAIL_MISSING, isset( $_POST['bfo_email_missing'] ) ? 'yes' : 'no' );
	}

	/** @since 1.0.0 */
	private function save_data(): void {
		$days = isset( $_POST['bfo_log_retention'] ) ? absint( $_POST['bfo_log_retention'] ) : 90;
		update_option( BFO_OPTION_LOG_RETENTION, $days );
	}

	// =========================================================================
	// Shipping tab
	// =========================================================================

	/**
	 * Renders the Shipping settings tab.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	private function render_shipping_tab(): void {
		$provider   = get_option( BFO_OPTION_SHIPPING_PROVIDER, 'none' );
		$shippo_key = get_option( BFO_OPTION_SHIPPO_API_KEY, '' );
		$ep_key     = get_option( BFO_OPTION_EASYPOST_API_KEY, '' );
		$auto_ship  = get_option( BFO_OPTION_AUTO_SHIP_ON_PACK, 'no' );

		// From-address fields.
		$from = array(
			'name'    => get_option( BFO_OPTION_SHIPPING_FROM_NAME,    '' ),
			'company' => get_option( BFO_OPTION_SHIPPING_FROM_COMPANY, '' ),
			'street1' => get_option( BFO_OPTION_SHIPPING_FROM_STREET1, '' ),
			'street2' => get_option( BFO_OPTION_SHIPPING_FROM_STREET2, '' ),
			'city'    => get_option( BFO_OPTION_SHIPPING_FROM_CITY,    '' ),
			'state'   => get_option( BFO_OPTION_SHIPPING_FROM_STATE,   '' ),
			'zip'     => get_option( BFO_OPTION_SHIPPING_FROM_ZIP,     '' ),
			'country' => get_option( BFO_OPTION_SHIPPING_FROM_COUNTRY, 'US' ),
			'phone'   => get_option( BFO_OPTION_SHIPPING_FROM_PHONE,   '' ),
			'email'   => get_option( BFO_OPTION_SHIPPING_FROM_EMAIL,   get_option( 'admin_email' ) ),
		);

		// Default parcel.
		$length    = get_option( BFO_OPTION_DEFAULT_LENGTH,    10 );
		$width     = get_option( BFO_OPTION_DEFAULT_WIDTH,      8 );
		$height    = get_option( BFO_OPTION_DEFAULT_HEIGHT,     4 );
		$weight    = get_option( BFO_OPTION_DEFAULT_WEIGHT,    16 );
		$dist_unit = get_option( BFO_OPTION_DEFAULT_DIST_UNIT, 'in' );
		$mass_unit = get_option( BFO_OPTION_DEFAULT_MASS_UNIT, 'oz' );
		?>
		<!-- ---- Provider ---- -->
		<tr><th colspan="2"><h3 style="margin:0;"><?php esc_html_e( 'Provider', 'barcode-fulfillment-orders' ); ?></h3></th></tr>
		<tr>
			<th scope="row"><label for="bfo_shipping_provider"><?php esc_html_e( 'Shipping Provider', 'barcode-fulfillment-orders' ); ?></label></th>
			<td>
				<select name="bfo_shipping_provider" id="bfo_shipping_provider">
					<option value="none"     <?php selected( $provider, 'none' ); ?>><?php esc_html_e( '— None —',  'barcode-fulfillment-orders' ); ?></option>
					<option value="shippo"   <?php selected( $provider, 'shippo' ); ?>><?php esc_html_e( 'Shippo',    'barcode-fulfillment-orders' ); ?></option>
					<option value="easypost" <?php selected( $provider, 'easypost' ); ?>><?php esc_html_e( 'EasyPost', 'barcode-fulfillment-orders' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="bfo_shippo_api_key"><?php esc_html_e( 'Shippo API Key', 'barcode-fulfillment-orders' ); ?></label></th>
			<td>
				<input type="password" name="bfo_shippo_api_key" id="bfo_shippo_api_key"
				       value="<?php echo esc_attr( $shippo_key ); ?>" class="regular-text" autocomplete="new-password">
				<p class="description"><?php esc_html_e( 'Required when Provider is Shippo.', 'barcode-fulfillment-orders' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="bfo_easypost_api_key"><?php esc_html_e( 'EasyPost API Key', 'barcode-fulfillment-orders' ); ?></label></th>
			<td>
				<input type="password" name="bfo_easypost_api_key" id="bfo_easypost_api_key"
				       value="<?php echo esc_attr( $ep_key ); ?>" class="regular-text" autocomplete="new-password">
				<p class="description"><?php esc_html_e( 'Required when Provider is EasyPost.', 'barcode-fulfillment-orders' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Auto-Ship on Pack', 'barcode-fulfillment-orders' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="bfo_auto_ship_on_pack" value="yes" <?php checked( $auto_ship, 'yes' ); ?>>
					<?php esc_html_e( 'Automatically purchase the cheapest label when an order is marked Packed.', 'barcode-fulfillment-orders' ); ?>
				</label>
			</td>
		</tr>

		<!-- ---- From Address ---- -->
		<tr><th colspan="2"><h3 style="margin:16px 0 0;"><?php esc_html_e( 'Ship-From Address', 'barcode-fulfillment-orders' ); ?></h3></th></tr>
		<?php
		$addr_fields = array(
			'name'    => __( 'Sender Name',      'barcode-fulfillment-orders' ),
			'company' => __( 'Company',          'barcode-fulfillment-orders' ),
			'street1' => __( 'Street Address',   'barcode-fulfillment-orders' ),
			'street2' => __( 'Street Address 2', 'barcode-fulfillment-orders' ),
			'city'    => __( 'City',             'barcode-fulfillment-orders' ),
			'state'   => __( 'State / Province', 'barcode-fulfillment-orders' ),
			'zip'     => __( 'ZIP / Postal Code','barcode-fulfillment-orders' ),
			'country' => __( 'Country Code',     'barcode-fulfillment-orders' ),
			'phone'   => __( 'Phone',            'barcode-fulfillment-orders' ),
			'email'   => __( 'Email',            'barcode-fulfillment-orders' ),
		);
		foreach ( $addr_fields as $key => $label ) :
			$opt_name = 'bfo_shipping_from_' . $key;
			?>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( $opt_name ); ?>"><?php echo esc_html( $label ); ?></label></th>
				<td>
					<input type="text" name="<?php echo esc_attr( $opt_name ); ?>" id="<?php echo esc_attr( $opt_name ); ?>"
					       value="<?php echo esc_attr( $from[ $key ] ); ?>" class="regular-text">
					<?php if ( 'country' === $key ) : ?>
						<p class="description"><?php esc_html_e( 'ISO 3166-1 alpha-2 code, e.g. US, GB, DE.', 'barcode-fulfillment-orders' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>

		<!-- ---- Default Parcel ---- -->
		<tr><th colspan="2"><h3 style="margin:16px 0 0;"><?php esc_html_e( 'Default Package Dimensions', 'barcode-fulfillment-orders' ); ?></h3></th></tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Dimensions (L × W × H)', 'barcode-fulfillment-orders' ); ?></th>
			<td>
				<input type="number" name="bfo_default_length" value="<?php echo esc_attr( $length ); ?>" class="small-text" min="0" step="0.1">
				&times;
				<input type="number" name="bfo_default_width"  value="<?php echo esc_attr( $width ); ?>"  class="small-text" min="0" step="0.1">
				&times;
				<input type="number" name="bfo_default_height" value="<?php echo esc_attr( $height ); ?>" class="small-text" min="0" step="0.1">
				<select name="bfo_default_dist_unit">
					<option value="in" <?php selected( $dist_unit, 'in' ); ?>><?php esc_html_e( 'in', 'barcode-fulfillment-orders' ); ?></option>
					<option value="cm" <?php selected( $dist_unit, 'cm' ); ?>><?php esc_html_e( 'cm', 'barcode-fulfillment-orders' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="bfo_default_weight"><?php esc_html_e( 'Default Weight', 'barcode-fulfillment-orders' ); ?></label></th>
			<td>
				<input type="number" name="bfo_default_weight" id="bfo_default_weight"
				       value="<?php echo esc_attr( $weight ); ?>" class="small-text" min="0" step="0.1">
				<select name="bfo_default_mass_unit">
					<option value="oz" <?php selected( $mass_unit, 'oz' ); ?>><?php esc_html_e( 'oz', 'barcode-fulfillment-orders' ); ?></option>
					<option value="lb" <?php selected( $mass_unit, 'lb' ); ?>><?php esc_html_e( 'lb', 'barcode-fulfillment-orders' ); ?></option>
					<option value="g"  <?php selected( $mass_unit, 'g' );  ?>><?php esc_html_e( 'g',  'barcode-fulfillment-orders' ); ?></option>
					<option value="kg" <?php selected( $mass_unit, 'kg' ); ?>><?php esc_html_e( 'kg', 'barcode-fulfillment-orders' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Used for the entire order when no per-box weight is available.', 'barcode-fulfillment-orders' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Saves the Shipping settings tab.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	private function save_shipping(): void {
		$providers = array( 'none', 'shippo', 'easypost' );
		$provider  = isset( $_POST['bfo_shipping_provider'] ) ? sanitize_key( $_POST['bfo_shipping_provider'] ) : 'none';
		update_option( BFO_OPTION_SHIPPING_PROVIDER, in_array( $provider, $providers, true ) ? $provider : 'none' );

		update_option( BFO_OPTION_SHIPPO_API_KEY,   isset( $_POST['bfo_shippo_api_key'] )   ? sanitize_text_field( wp_unslash( $_POST['bfo_shippo_api_key'] ) )   : '' );
		update_option( BFO_OPTION_EASYPOST_API_KEY, isset( $_POST['bfo_easypost_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['bfo_easypost_api_key'] ) ) : '' );
		update_option( BFO_OPTION_AUTO_SHIP_ON_PACK, isset( $_POST['bfo_auto_ship_on_pack'] ) ? 'yes' : 'no' );

		// From address.
		$addr_keys = array( 'name', 'company', 'street1', 'street2', 'city', 'state', 'zip', 'country', 'phone', 'email' );
		foreach ( $addr_keys as $key ) {
			$opt  = 'bfo_shipping_from_' . $key;
			$post = 'bfo_shipping_from_' . $key;
			update_option( constant( 'BFO_OPTION_SHIPPING_FROM_' . strtoupper( $key ) ), isset( $_POST[ $post ] ) ? sanitize_text_field( wp_unslash( $_POST[ $post ] ) ) : '' );
		}

		// Default parcel.
		update_option( BFO_OPTION_DEFAULT_LENGTH, isset( $_POST['bfo_default_length'] ) ? (float) $_POST['bfo_default_length'] : 10 );
		update_option( BFO_OPTION_DEFAULT_WIDTH,  isset( $_POST['bfo_default_width'] )  ? (float) $_POST['bfo_default_width']  :  8 );
		update_option( BFO_OPTION_DEFAULT_HEIGHT, isset( $_POST['bfo_default_height'] ) ? (float) $_POST['bfo_default_height'] :  4 );
		update_option( BFO_OPTION_DEFAULT_WEIGHT, isset( $_POST['bfo_default_weight'] ) ? (float) $_POST['bfo_default_weight'] : 16 );

		$dist_units = array( 'in', 'cm' );
		$dist_unit  = isset( $_POST['bfo_default_dist_unit'] ) ? sanitize_key( $_POST['bfo_default_dist_unit'] ) : 'in';
		update_option( BFO_OPTION_DEFAULT_DIST_UNIT, in_array( $dist_unit, $dist_units, true ) ? $dist_unit : 'in' );

		$mass_units = array( 'oz', 'lb', 'g', 'kg' );
		$mass_unit  = isset( $_POST['bfo_default_mass_unit'] ) ? sanitize_key( $_POST['bfo_default_mass_unit'] ) : 'oz';
		update_option( BFO_OPTION_DEFAULT_MASS_UNIT, in_array( $mass_unit, $mass_units, true ) ? $mass_unit : 'oz' );
	}

	/**
	 * Manually purges old scan logs now.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function handle_purge_logs(): void {
		if ( ! check_admin_referer( 'bfo_purge_logs', 'bfo_purge_nonce' )
			|| ! current_user_can( BFO_CAPABILITY_MANAGE ) ) {
			wp_die( esc_html__( 'Security check failed.', 'barcode-fulfillment-orders' ) );
		}

		BFO_Database::instance()->purge_old_logs();

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'bfo-settings', 'tab' => 'data', 'logs-purged' => '1' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Exports all packing sessions as a CSV download.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function handle_export_csv(): void {
		if ( ! check_admin_referer( 'bfo_export_csv', 'bfo_export_nonce' )
			|| ! current_user_can( BFO_CAPABILITY_MANAGE ) ) {
			wp_die( esc_html__( 'Security check failed.', 'barcode-fulfillment-orders' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bfo_packing_sessions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY id DESC", ARRAY_A );

		$filename = 'bfo-sessions-' . gmdate( 'Ymd-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$output = fopen( 'php://output', 'w' );

		if ( ! empty( $rows ) ) {
			fputcsv( $output, array_keys( $rows[0] ) );
			foreach ( $rows as $row ) {
				fputcsv( $output, $row );
			}
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Generates barcodes for all products that currently have none.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function handle_regenerate_barcodes(): void {
		if ( ! check_admin_referer( 'bfo_regenerate_barcodes', 'bfo_regen_nonce' )
			|| ! current_user_can( BFO_CAPABILITY_MANAGE ) ) {
			wp_die( esc_html__( 'Security check failed.', 'barcode-fulfillment-orders' ) );
		}

		$count   = 0;
		$page    = 1;
		$per     = 50;

		do {
			$products = wc_get_products(
				array(
					'limit'  => $per,
					'page'   => $page,
					'status' => 'publish',
					'return' => 'objects',
				)
			);

			foreach ( $products as $product ) {
				if ( ! $product->get_meta( BFO_META_PRODUCT_BARCODE ) ) {
					$barcode = bfo_generate_unique_barcode( 'product' );
					$product->update_meta_data( BFO_META_PRODUCT_BARCODE, $barcode );
					$product->save_meta_data();
					$count++;
				}
			}

			$page++;
		} while ( count( $products ) === $per );

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'bfo-settings', 'tab' => 'data', 'barcodes-generated' => $count ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
