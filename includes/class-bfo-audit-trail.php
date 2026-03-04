<?php
/**
 * Packing audit trail — searchable history of all packing sessions.
 *
 * Registers a "Packing History" sub-page under the Fulfillment menu and renders
 * a filterable table of sessions with per-session detail views and CSV export.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BFO_Audit_Trail
 *
 * @since 1.0.0
 */
class BFO_Audit_Trail {

	/** @var BFO_Audit_Trail|null Singleton instance. */
	private static ?BFO_Audit_Trail $instance = null;

	// -------------------------------------------------------------------------
	// Singleton
	// -------------------------------------------------------------------------

	/**
	 * Returns or creates the singleton instance.
	 *
	 * @since  1.0.0
	 * @return BFO_Audit_Trail
	 */
	public static function instance(): BFO_Audit_Trail {
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
		add_action( 'admin_menu', array( $this, 'register_menu' ), 25 );
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	/**
	 * Registers the Packing History sub-page.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'bfo-queue',
			__( 'Packing History', 'barcode-fulfillment-orders' ),
			__( 'Packing History', 'barcode-fulfillment-orders' ),
			BFO_CAPABILITY_DASHBOARD,
			'bfo-packing-history',
			array( $this, 'render_page' )
		);
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/**
	 * Routes to list or detail view based on query args.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( BFO_CAPABILITY_DASHBOARD ) ) {
			wp_die( esc_html__( 'You do not have permission to view packing history.', 'barcode-fulfillment-orders' ) );
		}

		// Nonceless reads are intentional for GET navigation — no state changes occur.
		if ( isset( $_GET['session_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$this->render_detail_view( absint( $_GET['session_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} else {
			$this->render_list_view();
		}
	}

	// -------------------------------------------------------------------------
	// List View
	// -------------------------------------------------------------------------

	/**
	 * Renders the paginated session list.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function render_list_view(): void {
		global $wpdb;

		// Filters — no nonce needed for read-only GET params.
		$search_worker  = isset( $_GET['worker_id'] ) ? absint( $_GET['worker_id'] ) : 0; // phpcs:ignore
		$search_status  = isset( $_GET['status'] )    ? sanitize_key( $_GET['status'] ) : ''; // phpcs:ignore
		$search_order   = isset( $_GET['order_id'] )  ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore
		$paged          = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore
		$per_page       = 25;
		$offset         = ( $paged - 1 ) * $per_page;

		$sessions_table = $wpdb->prefix . 'bfo_packing_sessions';

		// Build WHERE clauses.
		$where  = array( '1=1' );
		$params = array();

		if ( $search_worker ) {
			$where[]  = 'worker_id = %d';
			$params[] = $search_worker;
		}
		if ( $search_status ) {
			$where[]  = 'status = %s';
			$params[] = $search_status;
		}
		if ( $search_order ) {
			$where[]  = 'order_id = %d';
			$params[] = $search_order;
		}

		$where_sql = implode( ' AND ', $where );

		// Total count.
		if ( $params ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$sessions_table}` WHERE {$where_sql}", ...$params ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$sessions_table}` WHERE {$where_sql}" );
		}

		// Fetch page.
		$sql = "SELECT * FROM `{$sessions_table}` WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$all_params = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$sessions = $wpdb->get_results( $wpdb->prepare( $sql, ...$all_params ) );

		$total_pages = (int) ceil( $total / $per_page );

		// Export CSV link.
		$export_url = add_query_arg(
			array(
				'action'     => 'bfo_export_csv',
				'worker_id'  => $search_worker,
				'status'     => $search_status,
				'order_id'   => $search_order,
				'_wpnonce'   => wp_create_nonce( 'bfo_export_csv' ),
			),
			admin_url( 'admin-post.php' )
		);

		?>
		<div class="wrap bfo-audit-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Packing History', 'barcode-fulfillment-orders' ); ?></h1>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'barcode-fulfillment-orders' ); ?></a>
			<hr class="wp-header-end">

			<!-- Filters -->
			<form method="get" class="bfo-audit-filters">
				<input type="hidden" name="page" value="bfo-packing-history">
				<label>
					<?php esc_html_e( 'Order ID:', 'barcode-fulfillment-orders' ); ?>
					<input type="number" name="order_id" value="<?php echo esc_attr( $search_order ?: '' ); ?>" class="small-text" min="1">
				</label>
				&nbsp;
				<label>
					<?php esc_html_e( 'Status:', 'barcode-fulfillment-orders' ); ?>
					<select name="status">
						<option value=""><?php esc_html_e( '— All —', 'barcode-fulfillment-orders' ); ?></option>
						<?php foreach ( $this->session_statuses() as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $search_status, $slug ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				&nbsp;
				<?php submit_button( __( 'Filter', 'barcode-fulfillment-orders' ), 'secondary', 'submit', false ); ?>
				<?php if ( $search_worker || $search_status || $search_order ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=bfo-packing-history' ) ); ?>"><?php esc_html_e( 'Reset', 'barcode-fulfillment-orders' ); ?></a>
				<?php endif; ?>
			</form>

			<!-- Table -->
			<table class="wp-list-table widefat fixed striped bfo-audit-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Session', 'barcode-fulfillment-orders' ); ?></th>
						<th><?php esc_html_e( 'Order', 'barcode-fulfillment-orders' ); ?></th>
						<th><?php esc_html_e( 'Worker', 'barcode-fulfillment-orders' ); ?></th>
						<th><?php esc_html_e( 'Status', 'barcode-fulfillment-orders' ); ?></th>
						<th><?php esc_html_e( 'Started', 'barcode-fulfillment-orders' ); ?></th>
						<th><?php esc_html_e( 'Duration', 'barcode-fulfillment-orders' ); ?></th>
						<th><?php esc_html_e( 'Scans', 'barcode-fulfillment-orders' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $sessions ) ) : ?>
						<tr>
							<td colspan="7"><?php esc_html_e( 'No sessions found.', 'barcode-fulfillment-orders' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $sessions as $s ) : ?>
							<?php
							$detail_url = add_query_arg( array( 'page' => 'bfo-packing-history', 'session_id' => $s->id ), admin_url( 'admin.php' ) );
							$order_url  = $s->order_id ? get_edit_post_link( $s->order_id ) : '#';
							$worker     = $s->worker_id ? get_userdata( $s->worker_id ) : null;
							$duration   = ( $s->completed_at && $s->started_at ) ? ( strtotime( $s->completed_at ) - strtotime( $s->started_at ) ) : 0;

							// Scan count.
							$scan_table = $wpdb->prefix . 'bfo_scan_logs';
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
							$scan_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$scan_table}` WHERE session_id = %d", $s->id ) );
							?>
							<tr>
								<td><a href="<?php echo esc_url( $detail_url ); ?>">#<?php echo esc_html( $s->id ); ?></a></td>
								<td>
									<?php if ( $s->order_id ) : ?>
										<a href="<?php echo esc_url( $order_url ); ?>">#<?php echo esc_html( $s->order_id ); ?></a>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $worker ? $worker->display_name : __( 'Unknown', 'barcode-fulfillment-orders' ) ); ?></td>
								<td><?php echo esc_html( bfo_session_status_label( $s->status ) ); ?></td>
								<td><?php echo esc_html( $s->started_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $s->started_at ) ) : '—' ); ?></td>
								<td><?php echo esc_html( $duration ? bfo_format_duration( $duration ) : '—' ); ?></td>
								<td><?php echo esc_html( $scan_count ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- Pagination -->
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput
							array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => $paged,
								'total'   => $total_pages,
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Detail View
	// -------------------------------------------------------------------------

	/**
	 * Renders the detailed scan log for one session.
	 *
	 * @since  1.0.0
	 * @param  int $session_id Session ID.
	 * @return void
	 */
	private function render_detail_view( int $session_id ): void {
		$db      = BFO_Database::instance();
		$session = $db->get_session( $session_id );

		if ( ! $session ) {
			wp_die( esc_html__( 'Session not found.', 'barcode-fulfillment-orders' ) );
		}

		$scan_logs = $db->get_scan_logs_for_session( $session_id );
		$order     = $session->order_id ? wc_get_order( (int) $session->order_id ) : null;
		$worker    = $session->worker_id ? get_userdata( (int) $session->worker_id ) : null;
		$back_url  = admin_url( 'admin.php?page=bfo-packing-history' );

		?>
		<div class="wrap bfo-audit-detail-wrap">
			<h1>
				<?php
				/* translators: %d: session ID */
				printf( esc_html__( 'Packing Session #%d', 'barcode-fulfillment-orders' ), (int) $session->id );
				?>
			</h1>
			<a href="<?php echo esc_url( $back_url ); ?>" class="button">&larr; <?php esc_html_e( 'Back to History', 'barcode-fulfillment-orders' ); ?></a>

			<hr>

			<table class="form-table bfo-audit-detail-meta" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Order', 'barcode-fulfillment-orders' ); ?></th>
					<td>
						<?php if ( $order ) : ?>
							<a href="<?php echo esc_url( get_edit_post_link( $order->get_id() ) ); ?>">
								#<?php echo esc_html( $order->get_order_number() ); ?>
							</a>
						<?php else : ?>
							#<?php echo esc_html( $session->order_id ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Worker', 'barcode-fulfillment-orders' ); ?></th>
					<td><?php echo esc_html( $worker ? $worker->display_name : __( 'Unknown', 'barcode-fulfillment-orders' ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Status', 'barcode-fulfillment-orders' ); ?></th>
					<td><?php echo esc_html( bfo_session_status_label( $session->status ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Started', 'barcode-fulfillment-orders' ); ?></th>
					<td><?php echo esc_html( $session->started_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $session->started_at ) ) : '—' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Ended', 'barcode-fulfillment-orders' ); ?></th>
				<td><?php echo esc_html( $session->completed_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $session->completed_at ) ) : '—' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Duration', 'barcode-fulfillment-orders' ); ?></th>
					<td>
						<?php
						if ( $session->started_at && $session->completed_at ) {
							$dur = strtotime( $session->completed_at ) - strtotime( $session->started_at );
							echo esc_html( bfo_format_duration( $dur ) );
						} else {
							echo '—';
						}
						?>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Scan Log', 'barcode-fulfillment-orders' ); ?></h2>
			<table class="wp-list-table widefat fixed striped bfo-scan-log-table">
				<thead>
					<tr>
						<th><?php esc_html_e( '#', 'barcode-fulfillment-orders' ); ?></th>
						<th><?php esc_html_e( 'Time', 'barcode-fulfillment-orders' ); ?></th>
						<th><?php esc_html_e( 'Barcode Scanned', 'barcode-fulfillment-orders' ); ?></th>
						<th><?php esc_html_e( 'Product', 'barcode-fulfillment-orders' ); ?></th>
						<th><?php esc_html_e( 'Action', 'barcode-fulfillment-orders' ); ?></th>
						<th><?php esc_html_e( 'Notes', 'barcode-fulfillment-orders' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $scan_logs ) ) : ?>
						<tr>
							<td colspan="6"><?php esc_html_e( 'No scans recorded.', 'barcode-fulfillment-orders' ); ?></td>
						</tr>
					<?php else : ?>
						<?php $row_num = 1; ?>
						<?php foreach ( $scan_logs as $log ) : ?>
							<?php
							$product_name = '';
							if ( $log->product_id ) {
								$product = wc_get_product( (int) $log->product_id );
								$product_name = $product ? $product->get_name() : '#' . $log->product_id;
							}
							?>
							<tr>
								<td><?php echo esc_html( $row_num++ ); ?></td>
								<td><?php echo esc_html( $log->scanned_at ? wp_date( get_option( 'time_format' ), strtotime( $log->scanned_at ) ) : '—' ); ?></td>
								<td><code><?php echo esc_html( $log->barcode_scanned ); ?></code></td>
								<td><?php echo esc_html( $product_name ?: '—' ); ?></td>
								<td>
									<span class="bfo-scan-action bfo-scan-action--<?php echo esc_attr( $log->action ); ?>">
										<?php echo esc_html( bfo_scan_action_label( $log->action ) ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $log->missing_reason ?: '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns an array of displayable session statuses for filter dropdown.
	 *
	 * @since  1.0.0
	 * @return array<string,string>
	 */
	private function session_statuses(): array {
		return array(
			BFO_SESSION_STATUS_ACTIVE    => __( 'Active', 'barcode-fulfillment-orders' ),
			BFO_SESSION_STATUS_PAUSED    => __( 'Paused', 'barcode-fulfillment-orders' ),
			BFO_SESSION_STATUS_COMPLETED => __( 'Completed', 'barcode-fulfillment-orders' ),
			BFO_SESSION_STATUS_CANCELLED => __( 'Cancelled', 'barcode-fulfillment-orders' ),
		);
	}
}
