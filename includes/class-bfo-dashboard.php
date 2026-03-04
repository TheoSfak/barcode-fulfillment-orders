<?php
/**
 * Fulfillment dashboard — analytics overview.
 *
 * Renders today's stats, a weekly throughput bar chart (CSS/SVG only), a worker
 * leaderboard and a pending-alerts panel. No external charting library required.
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BFO_Dashboard
 *
 * @since 1.0.0
 */
class BFO_Dashboard {

	/** @var BFO_Dashboard|null Singleton instance. */
	private static ?BFO_Dashboard $instance = null;

	// -------------------------------------------------------------------------
	// Singleton
	// -------------------------------------------------------------------------

	/**
	 * Returns or creates the singleton instance.
	 *
	 * @since  1.0.0
	 * @return BFO_Dashboard
	 */
	public static function instance(): BFO_Dashboard {
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
		add_action( 'admin_menu', array( $this, 'register_menu' ), 30 );
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	/**
	 * Registers the Dashboard sub-page.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'bfo-queue',
			__( 'Fulfillment Dashboard', 'barcode-fulfillment-orders' ),
			__( 'Dashboard', 'barcode-fulfillment-orders' ),
			BFO_CAPABILITY_DASHBOARD,
			'bfo-dashboard',
			array( $this, 'render_page' )
		);
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/**
	 * Renders the dashboard page.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( BFO_CAPABILITY_DASHBOARD ) ) {
			wp_die( esc_html__( 'You do not have permission to view the fulfillment dashboard.', 'barcode-fulfillment-orders' ) );
		}

		$today_stats   = $this->get_today_stats();
		$weekly_data   = $this->get_weekly_throughput();
		$leaderboard   = $this->get_worker_leaderboard();
		$alerts        = $this->get_alerts();

		?>
		<div class="wrap bfo-dashboard-wrap">
			<h1><?php esc_html_e( 'Fulfillment Dashboard', 'barcode-fulfillment-orders' ); ?></h1>

			<!-- Today's Stats Cards -->
			<div class="bfo-stat-cards">
				<?php $this->render_stat_card( __( 'Orders Packed Today', 'barcode-fulfillment-orders' ), $today_stats['orders_packed'] ); ?>
				<?php $this->render_stat_card( __( 'Active Sessions', 'barcode-fulfillment-orders' ), $today_stats['active_sessions'] ); ?>
				<?php $this->render_stat_card( __( 'Items Scanned Today', 'barcode-fulfillment-orders' ), $today_stats['items_scanned'] ); ?>
				<?php $this->render_stat_card( __( 'Missing Items Today', 'barcode-fulfillment-orders' ), $today_stats['missing_items'], 'bfo-stat-card--alert' ); ?>
			</div>

			<?php if ( $alerts ) : ?>
			<!-- Alerts Panel -->
			<div class="bfo-alerts-panel">
				<h2><?php esc_html_e( 'Alerts', 'barcode-fulfillment-orders' ); ?></h2>
				<ul class="bfo-alerts-list">
					<?php foreach ( $alerts as $alert ) : ?>
						<li class="bfo-alert bfo-alert--<?php echo esc_attr( $alert['type'] ); ?>">
							<?php echo wp_kses_post( $alert['message'] ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>

			<div class="bfo-dashboard-columns">

				<!-- Weekly Throughput Bar Chart (CSS-only) -->
				<div class="bfo-chart-wrap">
					<h2><?php esc_html_e( 'Orders Packed — Last 7 Days', 'barcode-fulfillment-orders' ); ?></h2>
					<?php $this->render_bar_chart( $weekly_data ); ?>
				</div>

				<!-- Worker Leaderboard -->
				<div class="bfo-leaderboard-wrap">
					<h2><?php esc_html_e( 'Top Workers This Week', 'barcode-fulfillment-orders' ); ?></h2>
					<?php $this->render_leaderboard( $leaderboard ); ?>
				</div>

			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Sub-renderers
	// -------------------------------------------------------------------------

	/**
	 * Renders a single stat card.
	 *
	 * @since  1.0.0
	 * @param  string $label      Card label.
	 * @param  int    $value      Card value.
	 * @param  string $extra_class Optional extra CSS class.
	 * @return void
	 */
	private function render_stat_card( string $label, int $value, string $extra_class = '' ): void {
		?>
		<div class="bfo-stat-card <?php echo esc_attr( $extra_class ); ?>">
			<span class="bfo-stat-value"><?php echo esc_html( $value ); ?></span>
			<span class="bfo-stat-label"><?php echo esc_html( $label ); ?></span>
		</div>
		<?php
	}

	/**
	 * Renders a CSS bar chart from weekly throughput data.
	 *
	 * @since  1.0.0
	 * @param  array $data Array of {label, count} rows, oldest first.
	 * @return void
	 */
	private function render_bar_chart( array $data ): void {
		if ( empty( $data ) ) {
			echo '<p>' . esc_html__( 'No data yet.', 'barcode-fulfillment-orders' ) . '</p>';
			return;
		}

		$max = max( array_column( $data, 'count' ) );
		$max = max( $max, 1 ); // Avoid division by zero.

		echo '<div class="bfo-bar-chart">';
		foreach ( $data as $row ) {
			$height_pct = round( ( $row['count'] / $max ) * 100 );
			?>
			<div class="bfo-bar-chart__item">
				<div class="bfo-bar-chart__bar" style="height:<?php echo esc_attr( $height_pct ); ?>%">
					<span class="bfo-bar-chart__value"><?php echo esc_html( $row['count'] ); ?></span>
				</div>
				<div class="bfo-bar-chart__label"><?php echo esc_html( $row['label'] ); ?></div>
			</div>
			<?php
		}
		echo '</div>';
	}

	/**
	 * Renders the worker leaderboard table.
	 *
	 * @since  1.0.0
	 * @param  array $rows Array of {display_name, orders_completed, avg_duration}.
	 * @return void
	 */
	private function render_leaderboard( array $rows ): void {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No data yet.', 'barcode-fulfillment-orders' ) . '</p>';
			return;
		}
		?>
		<table class="wp-list-table widefat fixed striped bfo-leaderboard-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Worker', 'barcode-fulfillment-orders' ); ?></th>
					<th><?php esc_html_e( 'Orders', 'barcode-fulfillment-orders' ); ?></th>
					<th><?php esc_html_e( 'Avg. Time', 'barcode-fulfillment-orders' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['display_name'] ); ?></td>
						<td><?php echo esc_html( $row['orders_completed'] ); ?></td>
						<td><?php echo esc_html( $row['avg_duration'] ? bfo_format_duration( (int) $row['avg_duration'] ) : '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// -------------------------------------------------------------------------
	// Data Queries
	// -------------------------------------------------------------------------

	/**
	 * Returns today's summary stats.
	 *
	 * @since  1.0.0
	 * @return array{orders_packed:int, active_sessions:int, items_scanned:int, missing_items:int}
	 */
	private function get_today_stats(): array {
		global $wpdb;

		$sessions_table = $wpdb->prefix . 'bfo_packing_sessions';
		$scans_table    = $wpdb->prefix . 'bfo_scan_logs';
		$today          = current_time( 'Y-m-d' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$orders_packed = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$sessions_table}` WHERE status = %s AND DATE(ended_at) = %s",
			BFO_SESSION_STATUS_COMPLETED,
			$today
		) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$active_sessions = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$sessions_table}` WHERE status = %s",
			BFO_SESSION_STATUS_ACTIVE
		) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$items_scanned = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$scans_table}` WHERE action = %s AND DATE(scanned_at) = %s",
			BFO_SCAN_ACTION_SUCCESS,
			$today
		) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$missing_items = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$scans_table}` WHERE action = %s AND DATE(scanned_at) = %s",
			BFO_SCAN_ACTION_MISSING,
			$today
		) );

		return compact( 'orders_packed', 'active_sessions', 'items_scanned', 'missing_items' );
	}

	/**
	 * Returns completed-order counts for the last 7 days.
	 *
	 * @since  1.0.0
	 * @return array<int,array{label:string,count:int}>
	 */
	private function get_weekly_throughput(): array {
		global $wpdb;

		$sessions_table = $wpdb->prefix . 'bfo_packing_sessions';
		$result         = array();

		for ( $i = 6; $i >= 0; $i-- ) {
			$date  = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
			$label = gmdate( 'D', strtotime( $date ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM `{$sessions_table}` WHERE status = %s AND DATE(ended_at) = %s",
				BFO_SESSION_STATUS_COMPLETED,
				$date
			) );

			$result[] = array( 'label' => $label, 'count' => $count );
		}

		return $result;
	}

	/**
	 * Returns worker leaderboard for the current week.
	 *
	 * @since  1.0.0
	 * @return array<int,array{display_name:string,orders_completed:int,avg_duration:float|null}>
	 */
	private function get_worker_leaderboard(): array {
		global $wpdb;

		$sessions_table = $wpdb->prefix . 'bfo_packing_sessions';
		$monday         = gmdate( 'Y-m-d', strtotime( 'monday this week' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT worker_id,
			        COUNT(*) AS orders_completed,
			        AVG(TIMESTAMPDIFF(SECOND, started_at, ended_at)) AS avg_duration
			 FROM `{$sessions_table}`
			 WHERE status = %s
			   AND ended_at >= %s
			 GROUP BY worker_id
			 ORDER BY orders_completed DESC
			 LIMIT 10",
			BFO_SESSION_STATUS_COMPLETED,
			$monday
		) );

		$leaderboard = array();
		foreach ( $rows as $row ) {
			$user = $row->worker_id ? get_userdata( (int) $row->worker_id ) : null;
			$leaderboard[] = array(
				'display_name'     => $user ? $user->display_name : __( 'Unknown', 'barcode-fulfillment-orders' ),
				'orders_completed' => (int) $row->orders_completed,
				'avg_duration'     => $row->avg_duration ? (float) $row->avg_duration : null,
			);
		}

		return $leaderboard;
	}

	/**
	 * Returns an array of displayable alerts.
	 *
	 * @since  1.0.0
	 * @return array<int,array{type:string,message:string}>
	 */
	private function get_alerts(): array {
		global $wpdb;

		$alerts         = array();
		$sessions_table = $wpdb->prefix . 'bfo_packing_sessions';
		$idle_limit     = (int) get_option( BFO_OPTION_IDLE_TIMEOUT, 30 );

		// Stale active sessions.
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $idle_limit * 60 * 2 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$stale  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$sessions_table}` WHERE status = %s AND last_ping < %s",
			BFO_SESSION_STATUS_ACTIVE,
			$cutoff
		) );

		if ( $stale ) {
			$alerts[] = array(
				'type'    => 'warning',
				/* translators: %d: number of stale sessions */
				'message' => sprintf( _n( '%d active packing session appears to be stalled.', '%d active packing sessions appear to be stalled.', $stale, 'barcode-fulfillment-orders' ), $stale ),
			);
		}

		// Orders sitting in "packing" status for too long.
		$wc_packing_status = 'wc-' . BFO_STATUS_PACKING;
		$old_threshold     = gmdate( 'Y-m-d H:i:s', time() - ( 4 * HOUR_IN_SECONDS ) );

		$old_packing_orders = wc_get_orders( array(
			'status'       => array( $wc_packing_status ),
			'limit'        => 1,
			'date_before'  => $old_threshold,
			'return'       => 'ids',
		) );

		if ( ! empty( $old_packing_orders ) ) {
			$alerts[] = array(
				'type'    => 'error',
				'message' => sprintf(
					/* translators: %s: queue page URL */
					__( 'Some orders have been in "Packing" status for more than 4 hours. <a href="%s">View queue</a>.', 'barcode-fulfillment-orders' ),
					esc_url( admin_url( 'admin.php?page=bfo-queue' ) )
				),
			);
		}

		return $alerts;
	}
}
