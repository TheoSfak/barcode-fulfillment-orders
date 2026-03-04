<?php
/**
 * Product-level barcode management.
 *
 * - Adds a barcode input field to the product Inventory tab (simple + variable).
 * - Auto-generates a barcode on product creation when the setting is enabled.
 * - Adds a "Barcode" column to the products admin list with bulk generation.
 * - Enforces barcode uniqueness across all products and variations.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BFO_Product_Barcode
 *
 * @since 1.0.0
 */
class BFO_Product_Barcode {

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var BFO_Product_Barcode|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @since  1.0.0
	 * @return BFO_Product_Barcode
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
		// Simple product inventory tab.
		add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'render_product_field' ) );
		add_action( 'woocommerce_process_product_meta',                   array( $this, 'save_product_field' ) );

		// Variable product — each variation.
		add_action( 'woocommerce_product_after_variable_attributes',  array( $this, 'render_variation_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation',             array( $this, 'save_variation_field' ),   10, 2 );

		// Auto-generate on product creation.
		add_action( 'wp_insert_post', array( $this, 'maybe_auto_generate' ), 10, 3 );

		// Admin products list column.
		add_filter( 'manage_product_posts_columns',       array( $this, 'add_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_column' ), 10, 2 );

		// Bulk action: generate missing barcodes.
		add_filter( 'bulk_actions-edit-product',             array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-product',      array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices',                         array( $this, 'bulk_action_notice' ) );

		// AJAX: generate a single barcode on demand.
		add_action( 'wp_ajax_bfo_generate_product_barcode', array( $this, 'ajax_generate_barcode' ) );

		// Admin column CSS + inline JS.
		add_action( 'admin_head', array( $this, 'admin_column_styles' ) );
		add_action( 'admin_footer-edit.php', array( $this, 'admin_column_script' ) );
	}

	// -------------------------------------------------------------------------
	// Product field — simple
	// -------------------------------------------------------------------------

	/**
	 * Renders the barcode input field in the Inventory tab of a simple product.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render_product_field() {
		global $post;

		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return;
		}

		$barcode = $product->get_meta( BFO_META_PRODUCT_BARCODE, true );
		$nonce   = wp_create_nonce( 'bfo_generate_product_barcode_' . $post->ID );

		echo '<div class="options_group bfo-barcode-group">';

		woocommerce_wp_text_input(
			array(
				'id'          => '_bfo_barcode',
				'label'       => esc_html__( 'Barcode', 'barcode-fulfillment-orders' ),
				'desc_tip'    => true,
				'description' => esc_html__( 'Unique barcode for this product. Enter an existing barcode or click Generate.', 'barcode-fulfillment-orders' ),
				'value'       => esc_attr( $barcode ),
			)
		);

		printf(
			'<p class="form-field"><label>&nbsp;</label>' .
			'<button type="button" class="button bfo-generate-barcode" ' .
			'data-product-id="%d" data-nonce="%s">%s</button>' .
			'<span class="bfo-barcode-preview">%s</span></p>',
			absint( $post->ID ),
			esc_attr( $nonce ),
			esc_html__( 'Auto-Generate', 'barcode-fulfillment-orders' ),
			$barcode ? BFO_Barcode_Generator::get_instance()->render_inline( $barcode, get_option( BFO_OPTION_PRODUCT_BARCODE_FORMAT, 'code128' ), 40, 1 ) : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		echo '</div>';
	}

	/**
	 * Saves the barcode meta from the simple product form.
	 *
	 * @since  1.0.0
	 * @param  int $post_id  Product post ID.
	 * @return void
	 */
	public function save_product_field( $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['_bfo_barcode'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by WC
			return;
		}

		$barcode = bfo_sanitize_barcode( wp_unslash( $_POST['_bfo_barcode'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}

		if ( empty( $barcode ) ) {
			$product->delete_meta_data( BFO_META_PRODUCT_BARCODE );
		} else {
			$product->update_meta_data( BFO_META_PRODUCT_BARCODE, $barcode );
		}

		$product->save_meta_data();
	}

	// -------------------------------------------------------------------------
	// Variation field
	// -------------------------------------------------------------------------

	/**
	 * Renders the barcode field for a product variation.
	 *
	 * @since  1.0.0
	 * @param  int     $loop           Variation loop index.
	 * @param  array   $variation_data Variation data.
	 * @param  WP_Post $variation      Variation post object.
	 * @return void
	 */
	public function render_variation_field( $loop, $variation_data, $variation ) {
		$barcode = get_post_meta( $variation->ID, BFO_META_PRODUCT_BARCODE, true );
		$nonce   = wp_create_nonce( 'bfo_generate_product_barcode_' . $variation->ID );
		?>
		<div class="form-row form-row-full bfo-variation-barcode">
			<label><?php esc_html_e( 'Barcode', 'barcode-fulfillment-orders' ); ?></label>
			<input type="text"
				name="bfo_variation_barcode[<?php echo absint( $loop ); ?>]"
				value="<?php echo esc_attr( $barcode ); ?>"
				placeholder="<?php esc_attr_e( 'Leave blank to auto-generate on save', 'barcode-fulfillment-orders' ); ?>"
				class="short">
			<button type="button" class="button bfo-generate-barcode"
				data-product-id="<?php echo absint( $variation->ID ); ?>"
				data-input-name="bfo_variation_barcode[<?php echo absint( $loop ); ?>]"
				data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Generate', 'barcode-fulfillment-orders' ); ?>
			</button>
		</div>
		<input type="hidden" name="bfo_variation_id[<?php echo absint( $loop ); ?>]" value="<?php echo absint( $variation->ID ); ?>">
		<?php
	}

	/**
	 * Saves the barcode for a variation.
	 *
	 * @since  1.0.0
	 * @param  int $variation_id  Variation post ID.
	 * @param  int $loop          Loop index.
	 * @return void
	 */
	public function save_variation_field( $variation_id, $loop ) {
		if ( ! isset( $_POST['bfo_variation_barcode'][ $loop ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$barcode = bfo_sanitize_barcode( wp_unslash( $_POST['bfo_variation_barcode'][ $loop ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( empty( $barcode ) ) {
			delete_post_meta( $variation_id, BFO_META_PRODUCT_BARCODE );
		} else {
			update_post_meta( $variation_id, BFO_META_PRODUCT_BARCODE, $barcode );
		}
	}

	// -------------------------------------------------------------------------
	// Auto-generate on creation
	// -------------------------------------------------------------------------

	/**
	 * Auto-generates a barcode when a new product is inserted if the setting is on.
	 *
	 * @since  1.0.0
	 * @param  int     $post_id  Post ID.
	 * @param  WP_Post $post     Post object.
	 * @param  bool    $update   True if updating an existing post.
	 * @return void
	 */
	public function maybe_auto_generate( $post_id, $post, $update ) {
		if ( $update ) {
			return;
		}
		if ( 'product' !== $post->post_type ) {
			return;
		}
		if ( 'yes' !== get_option( BFO_OPTION_AUTO_GENERATE, 'no' ) ) {
			return;
		}

		$existing = get_post_meta( $post_id, BFO_META_PRODUCT_BARCODE, true );
		if ( ! empty( $existing ) ) {
			return;
		}

		update_post_meta( $post_id, BFO_META_PRODUCT_BARCODE, bfo_generate_unique_barcode( 'product' ) );
	}

	// -------------------------------------------------------------------------
	// Products list column
	// -------------------------------------------------------------------------

	/**
	 * Adds the "Barcode" column to the products list table.
	 *
	 * @since  1.0.0
	 * @param  array $columns  Existing columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'sku' === $key ) {
				$new['bfo_barcode'] = esc_html__( 'Barcode', 'barcode-fulfillment-orders' );
			}
		}
		// Fallback: append if sku column not found.
		if ( ! isset( $new['bfo_barcode'] ) ) {
			$new['bfo_barcode'] = esc_html__( 'Barcode', 'barcode-fulfillment-orders' );
		}
		return $new;
	}

	/**
	 * Renders the barcode column content.
	 *
	 * @since  1.0.0
	 * @param  string $column   Column key.
	 * @param  int    $post_id  Product post ID.
	 * @return void
	 */
	public function render_column( $column, $post_id ) {
		if ( 'bfo_barcode' !== $column ) {
			return;
		}

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			echo '—';
			return;
		}

		$barcode = $product->get_meta( BFO_META_PRODUCT_BARCODE, true );

		if ( $barcode ) {
			echo '<code>' . esc_html( $barcode ) . '</code>';
		} else {
			$nonce = wp_create_nonce( 'bfo_generate_product_barcode_' . $post_id );
			printf(
				'<span class="bfo-no-barcode">%s</span> ' .
				'<button type="button" class="button-link bfo-generate-barcode-quick" ' .
				'data-product-id="%d" data-nonce="%s">%s</button>',
				esc_html__( '—', 'barcode-fulfillment-orders' ),
				absint( $post_id ),
				esc_attr( $nonce ),
				esc_html__( 'Generate', 'barcode-fulfillment-orders' )
			);
		}
	}

	// -------------------------------------------------------------------------
	// Bulk action
	// -------------------------------------------------------------------------

	/**
	 * Registers the bulk action to generate missing barcodes.
	 *
	 * @since  1.0.0
	 * @param  array $actions  Existing bulk actions.
	 * @return array
	 */
	public function add_bulk_action( $actions ) {
		$actions['bfo_generate_barcodes'] = esc_html__( 'Generate missing barcodes', 'barcode-fulfillment-orders' );
		return $actions;
	}

	/**
	 * Processes the bulk "generate missing barcodes" action.
	 *
	 * @since  1.0.0
	 * @param  string $redirect_to  Redirect URL.
	 * @param  string $action       Bulk action slug.
	 * @param  int[]  $post_ids     Selected product IDs.
	 * @return string               Modified redirect URL.
	 */
	public function handle_bulk_action( $redirect_to, $action, $post_ids ) {
		if ( 'bfo_generate_barcodes' !== $action ) {
			return $redirect_to;
		}

		if ( ! current_user_can( 'edit_products' ) ) {
			return $redirect_to;
		}

		$generated = 0;

		foreach ( $post_ids as $id ) {
			$product = wc_get_product( absint( $id ) );
			if ( ! $product ) {
				continue;
			}
			if ( empty( $product->get_meta( BFO_META_PRODUCT_BARCODE, true ) ) ) {
				$product->update_meta_data( BFO_META_PRODUCT_BARCODE, bfo_generate_unique_barcode( 'product' ) );
				$product->save_meta_data();
				$generated++;
			}
		}

		return add_query_arg( 'bfo_generated', $generated, $redirect_to );
	}

	/**
	 * Displays a notice after the bulk action completes.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function bulk_action_notice() {
		if ( ! isset( $_GET['bfo_generated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$count = absint( $_GET['bfo_generated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			/* translators: %d: number of barcodes generated */
			esc_html( sprintf( _n( 'Generated %d barcode.', 'Generated %d barcodes.', $count, 'barcode-fulfillment-orders' ), $count ) )
		);
	}

	// -------------------------------------------------------------------------
	// AJAX handler
	// -------------------------------------------------------------------------

	/**
	 * AJAX: generates and saves a barcode for a product, returns it in JSON.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_generate_barcode() {
		$product_id = absint( $_POST['product_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		check_ajax_referer( 'bfo_generate_product_barcode_' . $product_id, 'nonce' );

		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'barcode-fulfillment-orders' ) ), 403 );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Product not found.', 'barcode-fulfillment-orders' ) ), 404 );
		}

		$barcode = bfo_generate_unique_barcode( 'product' );
		$product->update_meta_data( BFO_META_PRODUCT_BARCODE, $barcode );
		$product->save_meta_data();

		$format = get_option( BFO_OPTION_PRODUCT_BARCODE_FORMAT, 'code128' );
		$svg    = BFO_Barcode_Generator::get_instance()->render_inline( $barcode, $format, 40, 1 );

		wp_send_json_success(
			array(
				'barcode' => $barcode,
				'svg'     => $svg,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Admin styles / scripts
	// -------------------------------------------------------------------------

	/**
	 * Inline CSS for the barcode column and field.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function admin_column_styles() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		if ( ! in_array( $screen->id, array( 'edit-product', 'product' ), true ) ) {
			return;
		}
		?>
		<style>
		.column-bfo_barcode { width: 130px; }
		.bfo-barcode-group  { border-top: 1px solid #e0e0e0; }
		.bfo-barcode-preview svg { display: block; margin-top: 6px; }
		.bfo-variation-barcode { padding: 5px 0; }
		</style>
		<?php
	}

	/**
	 * Inline JS for the "Generate" button in the products list and product edit.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function admin_column_script() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		if ( ! in_array( $screen->id, array( 'edit-product', 'product' ), true ) ) {
			return;
		}
		?>
		<script>
		(function($){
			$(document).on('click', '.bfo-generate-barcode, .bfo-generate-barcode-quick', function(){
				var btn = $(this);
				var productId = btn.data('product-id');
				var nonce     = btn.data('nonce');
				var inputName = btn.data('input-name');

				btn.prop('disabled', true).text('<?php echo esc_js( __( 'Generating…', 'barcode-fulfillment-orders' ) ); ?>');

				$.post(ajaxurl, {
					action     : 'bfo_generate_product_barcode',
					product_id : productId,
					nonce      : nonce
				}, function(res){
					if ( res.success ) {
						// Update text inputs by name or by #_bfo_barcode.
						if ( inputName ) {
							$('[name="' + inputName + '"]').val( res.data.barcode );
						} else {
							$('#_bfo_barcode').val( res.data.barcode );
						}
						// Update preview.
						var preview = btn.siblings('.bfo-barcode-preview');
						if ( preview.length ) preview.html( res.data.svg );
						// Update list table cell.
						var cell = btn.closest('td.bfo_barcode');
						if ( cell.length ) cell.html('<code>' + res.data.barcode + '</code>');
					} else {
						alert( res.data.message );
					}
				}).always(function(){
					btn.prop('disabled', false).text('<?php echo esc_js( __( 'Auto-Generate', 'barcode-fulfillment-orders' ) ); ?>');
				});
			});
		}(jQuery));
		</script>
		<?php
	}
}
