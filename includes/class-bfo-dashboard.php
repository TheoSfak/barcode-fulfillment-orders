<?php
/**
 * Fulfillment dashboard — enriched analytics overview.
 *
 * Renders:
 *  • 8 stat cards with trend comparison vs yesterday
 *  • Hourly scan activity bar chart (24 h)
 *  • Weekly / date-ranged throughput chart
 *  • Worker leaderboard with medals and relative progress bars
 *  • Recent session activity feed
 *  • Alerts panel
 *  • Auto-refresh via AJAX every 60 seconds
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

	public static function instance(): BFO_Dashboard {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu',                    array( $this, 'register_menu' ), 30 );
		add_action( 'wp_ajax_bfo_dashboard_refresh', array( $this, 'ajax_refresh' ) );
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
	// AJAX: live refresh
	// -------------------------------------------------------------------------

	/**
	 * Returns fresh dashboard data for the auto-refresh JS.
	 *
	 * @since  1.2.0
	 * @return void
	 */
	public function ajax_refresh(): void {
		check_ajax_referer( 'bfo_dashboard_refresh', 'security' );

		if ( ! current_user_can( BFO_CAPABILITY_DASHBOARD ) ) {
			wp_send_json_error( null, 403 );
		}

		$today     = $this->get_today_stats();
		$yesterday = $this->get_yesterday_stats();
		$hourly    = $this->get_hourly_scans_today();
		$pending   = $this->get_pending_count();
		$avg_time  = $this->get_avg_pack_time_today();
		$cancelled = $this->get_cancelled_today();
		$accuracy  = $this->get_scan_accuracy_today();
		$alerts    = $this->get_alerts();

		ob_start();
		if ( $alerts ) {
			?>
			<ul class="bfo-alerts-list">
				<?php foreach ( $alerts as $alert ) : ?>
					<li class="bfo-alert bfo-alert--<?php echo esc_attr( $alert['type'] ); ?>">
						<?php echo wp_kses_post( $alert['message'] ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php
		}
		$alerts_html = ob_get_clean();

		wp_send_json_success( array(
			'cards'       => array(
				'orders_packed'      => $today['orders_packed'],
				'items_scanned'      => $today['items_scanned'],
				'missing_items'      => $today['missing_items'],
				'active_sessions'    => $today['active_sessions'],
				'pending_orders'     => $pending,
				'avg_pack_time'      => $avg_time ? bfo_format_duration( (int) $avg_time ) : '—',
				'sessions_cancelled' => $cancelled,
				'scan_accuracy'      => $accuracy,
			),
			'trends'      => array(
				'orders_packed'  => $this->trend( $today['orders_packed'],  $yesterday['orders_packed'] ),
				'items_scanned'  => $this->trend( $today['items_scanned'],  $yesterday['items_scanned'] ),
				'missing_items'  => $this->trend( $today['missing_items'],  $yesterday['missing_items'], true ),
			),
			'hourly'      => $hourly,
			'alerts_html' => $alerts_html,
			'last_updated' => wp_date( get_option( 'time_format' ) ),
		) );
	}

	// -------------------------------------------------------------------------
	// Page render
	// -------------------------------------------------------------------------

	/**
	 * Renders the dashboard page.
	 *
	 * @since  1.0.0 (heavily revised 1.2.0)
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( BFO_CAPABILITY_DASHBOARD ) ) {
			wp_die( esc_html__( 'You do not have permission to view the fulfillment dashboard.', 'barcode-fulfillment-orders' ) );
		}

		// Date range from URL param.
		$range = in_array( $_GET['bfo_range'] ?? 'today', array( 'today', '7days', '30days' ), true ) // phpcs:ignore WordPress.Security.NonceVerification
			? sanitize_key( $_GET['bfo_range'] ?? 'today' ) // phpcs:ignore WordPress.Security.NonceVerification
			: 'today';

		$today       = $this->get_today_stats();
		$yesterday   = $this->get_yesterday_stats();
		$weekly      = $this->get_throughput_data( $range );
		$leaderboard = $this->get_worker_leaderboard( $range );
		$alerts      = $this->get_alerts();
		$hourly      = $this->get_hourly_scans_today();
		$recent      = $this->get_recent_sessions( 8 );
		$pending     = $this->get_pending_count();
		$avg_time    = $this->get_avg_pack_time_today();
		$cancelled   = $this->get_cancelled_today();
		$accuracy    = $this->get_scan_accuracy_today();
		$refresh_nonce = wp_create_nonce( 'bfo_dashboard_refresh' );

		$range_labels = array(
			'today'  => __( 'Today', 'barcode-fulfillment-orders' ),
			'7days'  => __( 'Last 7 Days', 'barcode-fulfillment-orders' ),
			'30days' => __( 'Last 30 Days', 'barcode-fulfillment-orders' ),
		);
		?>
		<div class="wrap bfo-dashboard-wrap">

			<!-- Header row -->
			<div class="bfo-dash-header">
				<h1><?php esc_html_e( 'Fulfillment Dashboard', 'barcode-fulfillment-orders' ); ?></h1>
				<div class="bfo-dash-header-right">
					<div class="bfo-range-tabs">
						<?php foreach ( $range_labels as $key => $label ) : ?>
							<a href="<?php echo esc_url( add_query_arg( 'bfo_range', $key ) ); ?>"
							   class="bfo-range-tab <?php echo $range === $key ? 'bfo-range-tab--active' : ''; ?>">
								<?php echo esc_html( $label ); ?>
							</a>
						<?php endforeach; ?>
					</div>
					<span class="bfo-live-badge"><?php esc_html_e( 'Live', 'barcode-fulfillment-orders' ); ?></span>
					<span class="bfo-last-updated">
						<?php esc_html_e( 'Updated', 'barcode-fulfillment-orders' ); ?>
						<span id="bfo-last-updated-time"><?php echo esc_html( wp_date( get_option( 'time_format' ) ) ); ?></span>
					</span>
				</div>
			</div>

			<!-- Alerts panel (also updated by AJAX) -->
			<div id="bfo-alerts-panel">
				<?php if ( $alerts ) : ?>
				<div class="bfo-alerts-panel-inner">
					<ul class="bfo-alerts-list">
						<?php foreach ( $alerts as $alert ) : ?>
							<li class="bfo-alert bfo-alert--<?php echo esc_attr( $alert['type'] ); ?>">
								<?php echo wp_kses_post( $alert['message'] ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php endif; ?>
			</div>

			<!-- 8 Stat cards -->
			<div class="bfo-stat-cards">
				<?php
				$this->render_stat_card( array(
					'id'          => 'orders_packed',
					'label'       => __( 'Orders Packed Today', 'barcode-fulfillment-orders' ),
					'value'       => $today['orders_packed'],
					'icon'        => '📦',
					'color'       => 'blue',
					'trend'       => $this->trend( $today['orders_packed'], $yesterday['orders_packed'] ),
					'trend_label' => __( 'vs yesterday', 'barcode-fulfillment-orders' ),
				) );
				$this->render_stat_card( array(
					'id'          => 'items_scanned',
					'label'       => __( 'Items Scanned Today', 'barcode-fulfillment-orders' ),
					'value'       => $today['items_scanned'],
					'icon'        => '🔍',
					'color'       => 'green',
					'trend'       => $this->trend( $today['items_scanned'], $yesterday['items_scanned'] ),
					'trend_label' => __( 'vs yesterday', 'barcode-fulfillment-orders' ),
				) );
				$this->render_stat_card( array(
					'id'          => 'missing_items',
					'label'       => __( 'Missing Items Today', 'barcode-fulfillment-orders' ),
					'value'       => $today['missing_items'],
					'icon'        => '⚠️',
					'color'       => $today['missing_items'] > 0 ? 'orange' : 'green',
					'trend'       => $this->trend( $today['missing_items'], $yesterday['missing_items'], true ),
					'trend_label' => __( 'vs yesterday', 'barcode-fulfillment-orders' ),
				) );
				$this->render_stat_card( array(
					'id'    => 'scan_accuracy',
					'label' => __( 'Scan Accuracy', 'barcode-fulfillment-orders' ),
					'value' => $accuracy . '%',
					'icon'  => '🎯',
					'color' => $accuracy >= 95 ? 'green' : ( $accuracy >= 80 ? 'yellow' : 'red' ),
				) );
				$this->render_stat_card( array(
					'id'    => 'active_sessions',
					'label' => __( 'Active Sessions', 'barcode-fulfillment-orders' ),
					'value' => $today['active_sessions'],
					'icon'  => '⚡',
					'color' => $today['active_sessions'] > 0 ? 'purple' : 'grey',
					'badge' => $today['active_sessions'] > 0 ? __( 'LIVE', 'barcode-fulfillment-orders' ) : '',
				) );
				$this->render_stat_card( array(
					'id'    => 'avg_pack_time',
					'label' => __( 'Avg Pack Time Today', 'barcode-fulfillment-orders' ),
					'value' => $avg_time ? bfo_format_duration( (int) $avg_time ) : '—',
					'icon'  => '⏱',
					'color' => 'blue',
				) );
				$this->render_stat_card( array(
					'id'         => 'pending_orders',
					'label'      => __( 'Pending Orders', 'barcode-fulfillment-orders' ),
					'value'      => $pending,
					'icon'       => '🗂',
					'color'      => $pending > 20 ? 'orange' : 'grey',
					'link'       => admin_url( 'admin.php?page=bfo-queue' ),
					'link_label' => __( 'View queue →', 'barcode-fulfillment-orders' ),
				) );
				$this->render_stat_card( array(
					'id'    => 'sessions_cancelled',
					'label' => __( 'Cancelled Today', 'barcode-fulfillment-orders' ),
					'value' => $cancelled,
					'icon'  => '✖',
					'color' => $cancelled > 5 ? 'red' : 'grey',
				) );
				?>
			</div>

			<!-- Hourly scan activity -->
			<div class="bfo-section-card bfo-hourly-section">
				<div class="bfo-section-card-header">
					<h2><?php esc_html_e( 'Scan Activity — Today (Hourly)', 'barcode-fulfillment-orders' ); ?></h2>
				</div>
				<?php $this->render_hourly_chart( $hourly ); ?>
			</div>

			<!-- Bottom three-column row -->
			<div class="bfo-dashboard-columns">

				<div class="bfo-section-card bfo-chart-wrap">
					<div class="bfo-section-card-header">
						<h2>
							<?php
							$chart_titles = array(
								'today'  => __( 'Orders Packed — Today', 'barcode-fulfillment-orders' ),
								'7days'  => __( 'Orders Packed — Last 7 Days', 'barcode-fulfillment-orders' ),
								'30days' => __( 'Orders Packed — Last 30 Days', 'barcode-fulfillment-orders' ),
							);
							echo esc_html( $chart_titles[ $range ] ?? $chart_titles['7days'] );
							?>
						</h2>
					</div>
					<?php $this->render_bar_chart( $weekly ); ?>
				</div>

				<div class="bfo-section-card bfo-leaderboard-wrap">
					<div class="bfo-section-card-header">
						<h2><?php esc_html_e( 'Top Workers', 'barcode-fulfillment-orders' ); ?></h2>
						<span class="bfo-section-subtitle"><?php echo esc_html( $range_labels[ $range ] ?? '' ); ?></span>
					</div>
					<?php $this->render_leaderboard( $leaderboard ); ?>
				</div>

				<div class="bfo-section-card bfo-activity-wrap">
					<div class="bfo-section-card-header">
						<h2><?php esc_html_e( 'Recent Sessions', 'barcode-fulfillment-orders' ); ?></h2>
					</div>
					<?php $this->render_activity_feed( $recent ); ?>
				</div>

			</div>
		</div>

		<script>
		( function () {
			var nonce    = <?php echo wp_json_encode( $refresh_nonce ); ?>;
			var ajaxUrl  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var INTERVAL = 60000;

			function animateNumber( el, to ) {
				var from = parseFloat( el.textContent ) || 0;
				if ( isNaN( from ) || isNaN( to ) || from === to ) { el.textContent = to; return; }
				var start = null, duration = 600;
				function step( ts ) {
					if ( ! start ) start = ts;
					var pct = Math.min( ( ts - start ) / duration, 1 );
					el.textContent = Math.round( from + ( to - from ) * pct );
					if ( pct < 1 ) requestAnimationFrame( step );
				}
				requestAnimationFrame( step );
			}

			function updateCard( id, value, trend ) {
				var valEl = document.getElementById( 'bfo-card-val-' + id );
				if ( valEl ) {
					var num = parseInt( value, 10 );
					if ( ! isNaN( num ) ) { animateNumber( valEl, num ); } else { valEl.textContent = value; }
				}
				if ( trend ) {
					var tEl = document.getElementById( 'bfo-card-trend-' + id );
					if ( tEl ) {
						tEl.className = 'bfo-trend bfo-trend--' + trend.dir;
						tEl.textContent = ( trend.dir === 'up' ? '▲' : ( trend.dir === 'down' ? '▼' : '—' ) ) + ' ' + Math.abs( trend.diff );
					}
				}
			}

			function updateHourly( data ) {
				if ( ! data ) return;
				var max = Math.max.apply( null, data.map( function ( b ) { return b.count; } ) ) || 1;
				var bars = document.querySelectorAll( '.bfo-hourly-bar' );
				bars.forEach( function ( bar, i ) {
					if ( ! data[ i ] ) return;
					var pct = Math.round( ( data[ i ].count / max ) * 100 );
					bar.style.height = ( pct || 2 ) + '%';
					var val = bar.querySelector( '.bfo-hourly-val' );
					if ( val ) val.textContent = data[ i ].count > 0 ? data[ i ].count : '';
				} );
			}

			function refresh() {
				fetch( ajaxUrl, {
					method : 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body   : new URLSearchParams( { action: 'bfo_dashboard_refresh', security: nonce } ),
				} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( resp ) {
					if ( ! resp.success ) return;
					var d = resp.data;
					Object.keys( d.cards ).forEach( function ( k ) {
						updateCard( k, d.cards[ k ], d.trends ? d.trends[ k ] : null );
					} );
					updateHourly( d.hourly );
					var alertsEl = document.getElementById( 'bfo-alerts-panel' );
					if ( alertsEl ) alertsEl.innerHTML = d.alerts_html;
					var tsEl = document.getElementById( 'bfo-last-updated-time' );
					if ( tsEl ) tsEl.textContent = d.last_updated;
				} )
				.catch( function () {} );
			}

			setInterval( refresh, INTERVAL );
		} )();
		</script>
		<?php
	}

	// -------------------------------------------------------------------------
	// Sub-renderers
	// -------------------------------------------------------------------------

	/**
	 * Renders a single stat card.
	 *
	 * @since  1.2.0 (replaces old 2-arg version)
	 * @param  array $cfg  Card configuration.
	 * @return void
	 */
	private function render_stat_card( array $cfg ): void {
		$id          = $cfg['id']          ?? '';
		$label       = $cfg['label']       ?? '';
		$value       = $cfg['value']       ?? 0;
		$icon        = $cfg['icon']        ?? '';
		$color       = $cfg['color']       ?? 'blue';
		$trend       = $cfg['trend']       ?? null;
		$trend_label = $cfg['trend_label'] ?? '';
		$badge       = $cfg['badge']       ?? '';
		$link        = $cfg['link']        ?? '';
		$link_label  = $cfg['link_label']  ?? '';
		?>
		<div class="bfo-stat-card bfo-stat-card--<?php echo esc_attr( $color ); ?>">
			<div class="bfo-stat-card-inner">
				<?php if ( $icon ) : ?>
					<div class="bfo-stat-icon"><?php echo esc_html( $icon ); ?></div>
				<?php endif; ?>
				<div class="bfo-stat-body">
					<span class="bfo-stat-value" id="bfo-card-val-<?php echo esc_attr( $id ); ?>">
						<?php echo esc_html( $value ); ?>
					</span>
					<?php if ( $badge ) : ?>
						<span class="bfo-live-badge bfo-live-badge--inline"><?php echo esc_html( $badge ); ?></span>
					<?php endif; ?>
					<span class="bfo-stat-label"><?php echo esc_html( $label ); ?></span>
					<?php if ( $trend ) : ?>
						<span class="bfo-trend bfo-trend--<?php echo esc_attr( $trend['dir'] ); ?>"
						      id="bfo-card-trend-<?php echo esc_attr( $id ); ?>">
							<?php
							$arrow = 'up' === $trend['dir'] ? '▲' : ( 'down' === $trend['dir'] ? '▼' : '—' );
							echo esc_html( $arrow . ' ' . abs( $trend['diff'] ) );
							if ( $trend_label ) {
								echo ' <span class="bfo-trend-label">' . esc_html( $trend_label ) . '</span>';
							}
							?>
						</span>
					<?php endif; ?>
					<?php if ( $link ) : ?>
						<a class="bfo-stat-link" href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $link_label ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the 24-hour scan activity bar chart.
	 *
	 * @since  1.2.0
	 * @param  array $hourly  24-element array of {hour, count}.
	 * @return void
	 */
	private function render_hourly_chart( array $hourly ): void {
		if ( empty( $hourly ) ) {
			echo '<p class="bfo-no-data">' . esc_html__( 'No scans today yet.', 'barcode-fulfillment-orders' ) . '</p>';
			return;
		}

		$max          = max( array_column( $hourly, 'count' ) );
		$max          = max( $max, 1 );
		$current_hour = (int) current_time( 'G' );

		echo '<div class="bfo-hourly-chart">';
		foreach ( $hourly as $row ) {
			$pct    = round( ( $row['count'] / $max ) * 100 );
			$is_now = (int) $row['hour'] === $current_hour;
			$cls    = $is_now ? 'bfo-hourly-item bfo-hourly-item--now' : 'bfo-hourly-item';
			printf(
				'<div class="%s"><div class="bfo-hourly-bar" style="height:%d%%"><span class="bfo-hourly-val">%s</span></div><div class="bfo-hourly-label">%02d</div></div>',
				esc_attr( $cls ),
				(int) max( $pct, 2 ),
				$row['count'] > 0 ? esc_html( (string) $row['count'] ) : '',
				(int) $row['hour']
			);
		}
		echo '</div>';
	}

	/**
	 * Renders the throughput bar chart.
	 *
	 * @since  1.0.0 (updated 1.2.0)
	 * @param  array $data  Array of {label, count}.
	 * @return void
	 */
	private function render_bar_chart( array $data ): void {
		if ( empty( $data ) ) {
			echo '<p class="bfo-no-data">' . esc_html__( 'No data for this period.', 'barcode-fulfillment-orders' ) . '</p>';
			return;
		}

		$max = max( max( array_column( $data, 'count' ) ), 1 );

		echo '<div class="bfo-bar-chart">';
		foreach ( $data as $row ) {
			$pct = round( ( $row['count'] / $max ) * 100 );
			?>
			<div class="bfo-bar-chart__item">
				<div class="bfo-bar-chart__bar" style="height:<?php echo esc_attr( max( $pct, 2 ) ); ?>%">
					<?php if ( $row['count'] > 0 ) : ?>
						<span class="bfo-bar-chart__value"><?php echo esc_html( $row['count'] ); ?></span>
					<?php endif; ?>
				</div>
				<div class="bfo-bar-chart__label"><?php echo esc_html( $row['label'] ); ?></div>
			</div>
			<?php
		}
		echo '</div>';
	}

	/**
	 * Renders the worker leaderboard with medals and progress bars.
	 *
	 * @since  1.0.0 (redesigned 1.2.0)
	 * @param  array $rows
	 * @return void
	 */
	private function render_leaderboard( array $rows ): void {
		if ( empty( $rows ) ) {
			echo '<p class="bfo-no-data">' . esc_html__( 'No completed sessions yet.', 'barcode-fulfillment-orders' ) . '</p>';
			return;
		}

		$medals  = array( '🥇', '🥈', '🥉' );
		$max_ord = max( max( array_column( $rows, 'orders_completed' ) ), 1 );

		echo '<ul class="bfo-leaderboard-list">';
		foreach ( $rows as $i => $row ) {
			$rank    = $i + 1;
			$medal   = $medals[ $i ] ?? '#' . $rank;
			$pct     = round( ( $row['orders_completed'] / $max_ord ) * 100 );
			$initial = strtoupper( mb_substr( $row['display_name'], 0, 1 ) );
			?>
			<li class="bfo-leaderboard-item<?php echo $rank <= 3 ? ' bfo-leaderboard-item--top' : ''; ?>">
				<div class="bfo-lb-avatar"><?php echo esc_html( $initial ); ?></div>
				<div class="bfo-lb-body">
					<div class="bfo-lb-top-row">
						<span class="bfo-lb-name"><?php echo esc_html( $row['display_name'] ); ?></span>
						<span class="bfo-lb-medal"><?php echo esc_html( $medal ); ?></span>
						<span class="bfo-lb-count"><?php echo absint( $row['orders_completed'] ); ?></span>
					</div>
					<div class="bfo-lb-bar-track">
						<div class="bfo-lb-bar-fill" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
					</div>
					<div class="bfo-lb-sub">
						<?php
						echo $row['avg_duration']
							? esc_html( sprintf(
								/* translators: %s: formatted duration */
								__( 'Avg %s / order', 'barcode-fulfillment-orders' ),
								bfo_format_duration( (int) $row['avg_duration'] )
							) )
							: '—';
						?>
					</div>
				</div>
			</li>
			<?php
		}
		echo '</ul>';
	}

	/**
	 * Renders the recent session activity feed.
	 *
	 * @since  1.2.0
	 * @param  array $sessions
	 * @return void
	 */
	private function render_activity_feed( array $sessions ): void {
		if ( empty( $sessions ) ) {
			echo '<p class="bfo-no-data">' . esc_html__( 'No recent sessions.', 'barcode-fulfillment-orders' ) . '</p>';
			return;
		}

		echo '<ul class="bfo-activity-feed">';
		foreach ( $sessions as $s ) {
			$user     = ! empty( $s['worker_id'] ) ? get_userdata( (int) $s['worker_id'] ) : null;
			$name     = $user ? $user->display_name : __( 'Unknown', 'barcode-fulfillment-orders' );
			$initial  = strtoupper( mb_substr( $name, 0, 1 ) );
			$order_id = (int) $s['order_id'];
			$order    = $order_id ? wc_get_order( $order_id ) : null;
			$duration = ( ! empty( $s['started_at'] ) && ! empty( $s['completed_at'] ) )
				? bfo_format_duration( strtotime( $s['completed_at'] ) - strtotime( $s['started_at'] ) )
				: '—';
			$time_ago = ! empty( $s['completed_at'] )
				? human_time_diff( strtotime( $s['completed_at'] ), current_time( 'timestamp' ) )
				: '—';
			?>
			<li class="bfo-activity-item">
				<div class="bfo-act-avatar"><?php echo esc_html( $initial ); ?></div>
				<div class="bfo-act-body">
					<div class="bfo-act-primary">
						<strong><?php echo esc_html( $name ); ?></strong>
						<?php if ( $order ) : ?>
							<?php esc_html_e( 'packed', 'barcode-fulfillment-orders' ); ?>
							<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>" target="_blank">#<?php echo absint( $order_id ); ?></a>
						<?php else : ?>
							<?php esc_html_e( 'completed a session', 'barcode-fulfillment-orders' ); ?>
						<?php endif; ?>
					</div>
					<div class="bfo-act-meta">
						<span class="bfo-act-duration">⏱ <?php echo esc_html( $duration ); ?></span>
						<span class="bfo-act-time-ago">
							<?php echo esc_html( sprintf(
								/* translators: %s: human time diff */
								__( '%s ago', 'barcode-fulfillment-orders' ),
								$time_ago
							) ); ?>
						</span>
					</div>
				</div>
			</li>
			<?php
		}
		echo '</ul>';
	}

	// -------------------------------------------------------------------------
	// Data queries
	// -------------------------------------------------------------------------

	/**
	 * Returns today's summary stats.
	 *
	 * @since  1.0.0
	 * @return array{orders_packed:int,active_sessions:int,items_scanned:int,missing_items:int}
	 */
	private function get_today_stats(): array {
		return $this->get_stats_for_date( current_time( 'Y-m-d' ) );
	}

	/**
	 * Returns yesterday's summary stats for trend comparisons.
	 *
	 * @since  1.2.0
	 * @return array
	 */
	private function get_yesterday_stats(): array {
		return $this->get_stats_for_date(
			gmdate( 'Y-m-d', strtotime( '-1 day', current_time( 'timestamp' ) ) )
		);
	}

	/**
	 * Returns stats for a specific date.
	 *
	 * @since  1.2.0
	 * @param  string $date  MySQL date Y-m-d.
	 * @return array
	 */
	private function get_stats_for_date( string $date ): array {
		global $wpdb;

		$sessions_table = $wpdb->prefix . 'bfo_packing_sessions';
		$scans_table    = $wpdb->prefix . 'bfo_scan_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$orders_packed = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$sessions_table}` WHERE status = %s AND DATE(completed_at) = %s",
			BFO_SESSION_STATUS_COMPLETED, $date
		) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$active_sessions = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$sessions_table}` WHERE status = %s",
			BFO_SESSION_STATUS_ACTIVE
		) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$items_scanned = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$scans_table}` WHERE action = %s AND DATE(scanned_at) = %s",
			BFO_SCAN_ACTION_SUCCESS, $date
		) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$missing_items = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$scans_table}` WHERE action = %s AND DATE(scanned_at) = %s",
			BFO_SCAN_ACTION_MISSING, $date
		) );

		return compact( 'orders_packed', 'active_sessions', 'items_scanned', 'missing_items' );
	}

	/**
	 * Returns per-hour scan counts for today (hours 00–23).
	 *
	 * @since  1.2.0
	 * @return array<int,array{hour:int,count:int}>
	 */
	private function get_hourly_scans_today(): array {
		global $wpdb;

		$scans_table = $wpdb->prefix . 'bfo_scan_logs';
		$today       = current_time( 'Y-m-d' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT HOUR(scanned_at) AS hour, COUNT(*) AS cnt
			 FROM `{$scans_table}`
			 WHERE DATE(scanned_at) = %s AND action = %s
			 GROUP BY HOUR(scanned_at)
			 ORDER BY HOUR(scanned_at) ASC",
			$today, BFO_SCAN_ACTION_SUCCESS
		), ARRAY_A );

		$buckets = array_fill( 0, 24, 0 );
		foreach ( $rows as $r ) {
			$buckets[ (int) $r['hour'] ] = (int) $r['cnt'];
		}

		$result = array();
		for ( $h = 0; $h < 24; $h++ ) {
			$result[] = array( 'hour' => $h, 'count' => $buckets[ $h ] );
		}
		return $result;
	}

	/**
	 * Returns date-range throughput data.
	 *
	 * @since  1.2.0
	 * @param  string $range  today|7days|30days
	 * @return array
	 */
	private function get_throughput_data( string $range = '7days' ): array {
		global $wpdb;

		$sessions_table = $wpdb->prefix . 'bfo_packing_sessions';
		$days = 'today' === $range ? 1 : ( '30days' === $range ? 30 : 7 );

		$result = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date  = gmdate( 'Y-m-d', strtotime( "-{$i} days", current_time( 'timestamp' ) ) );
			$label = 1 === $days ? gmdate( 'H:i', strtotime( $date ) ) : gmdate( 'D j', strtotime( $date ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM `{$sessions_table}` WHERE status = %s AND DATE(completed_at) = %s",
				BFO_SESSION_STATUS_COMPLETED, $date
			) );

			$result[] = array( 'label' => $label, 'count' => $count );
		}
		return $result;
	}

	/**
	 * Returns worker leaderboard for a given range.
	 *
	 * @since  1.0.0 (updated 1.2.0)
	 * @param  string $range  today|7days|30days
	 * @return array
	 */
	private function get_worker_leaderboard( string $range = '7days' ): array {
		global $wpdb;

		$sessions_table = $wpdb->prefix . 'bfo_packing_sessions';
		$days  = 'today' === $range ? 1 : ( '30days' === $range ? 30 : 7 );
		$since = gmdate( 'Y-m-d 00:00:00', strtotime( "-{$days} days", current_time( 'timestamp' ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT worker_id,
			        COUNT(*) AS orders_completed,
			        AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)) AS avg_duration
			 FROM `{$sessions_table}`
			 WHERE status = %s AND completed_at >= %s
			 GROUP BY worker_id
			 ORDER BY orders_completed DESC
			 LIMIT 10",
			BFO_SESSION_STATUS_COMPLETED, $since
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
	 * Returns recent completed sessions for the activity feed.
	 *
	 * @since  1.2.0
	 * @param  int $limit
	 * @return array[]
	 */
	private function get_recent_sessions( int $limit = 8 ): array {
		global $wpdb;

		$sessions_table = $wpdb->prefix . 'bfo_packing_sessions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, order_id, worker_id, started_at, completed_at
			 FROM `{$sessions_table}`
			 WHERE status = %s
			 ORDER BY completed_at DESC
			 LIMIT %d",
			BFO_SESSION_STATUS_COMPLETED, $limit
		), ARRAY_A );

		return $rows ?: array();
	}

	/**
	 * Returns the count of orders currently in processing or packing status.
	 *
	 * @since  1.2.0
	 * @return int
	 */
	private function get_pending_count(): int {
		$ids = wc_get_orders( array(
			'limit'  => -1,
			'status' => array( 'processing', 'wc-' . BFO_STATUS_PACKING ),
			'return' => 'ids',
		) );
		return count( $ids );
	}

	/**
	 * Returns average pack time in seconds for today's completed sessions, or null.
	 *
	 * @since  1.2.0
	 * @return float|null
	 */
	private function get_avg_pack_time_today(): ?float {
		global $wpdb;

		$sessions_table = $wpdb->prefix . 'bfo_packing_sessions';
		$today          = current_time( 'Y-m-d' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$val = $wpdb->get_var( $wpdb->prepare(
			"SELECT AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at))
			 FROM `{$sessions_table}`
			 WHERE status = %s AND DATE(completed_at) = %s",
			BFO_SESSION_STATUS_COMPLETED, $today
		) );

		return null !== $val ? (float) $val : null;
	}

	/**
	 * Returns count of sessions cancelled today.
	 *
	 * @since  1.2.0
	 * @return int
	 */
	private function get_cancelled_today(): int {
		global $wpdb;

		$sessions_table = $wpdb->prefix . 'bfo_packing_sessions';
		$today          = current_time( 'Y-m-d' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$sessions_table}` WHERE status = %s AND DATE(started_at) = %s",
			BFO_SESSION_STATUS_CANCELLED, $today
		) );
	}

	/**
	 * Returns scan accuracy percentage for today (0–100).
	 *
	 * @since  1.2.0
	 * @return int
	 */
	private function get_scan_accuracy_today(): int {
		global $wpdb;

		$scans_table = $wpdb->prefix . 'bfo_scan_logs';
		$today       = current_time( 'Y-m-d' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$scanned = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$scans_table}` WHERE action = %s AND DATE(scanned_at) = %s",
			BFO_SCAN_ACTION_SUCCESS, $today
		) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$missing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$scans_table}` WHERE action = %s AND DATE(scanned_at) = %s",
			BFO_SCAN_ACTION_MISSING, $today
		) );

		$total = $scanned + $missing;
		return $total === 0 ? 100 : (int) round( ( $scanned / $total ) * 100 );
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
		$stale = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$sessions_table}` WHERE status = %s AND last_ping < %s",
			BFO_SESSION_STATUS_ACTIVE, $cutoff
		) );

		if ( $stale ) {
			$alerts[] = array(
				'type'    => 'warning',
				/* translators: %d: stale session count */
				'message' => sprintf( _n( '%d active packing session appears to be stalled.', '%d active packing sessions appear to be stalled.', $stale, 'barcode-fulfillment-orders' ), $stale ),
			);
		}

		// Orders stuck in "packing" status for > 4 h.
		$old_threshold = gmdate( 'Y-m-d H:i:s', time() - ( 4 * HOUR_IN_SECONDS ) );
		$old_packing   = wc_get_orders( array(
			'status'      => array( 'wc-' . BFO_STATUS_PACKING ),
			'limit'       => 1,
			'date_before' => $old_threshold,
			'return'      => 'ids',
		) );

		if ( ! empty( $old_packing ) ) {
			$alerts[] = array(
				'type'    => 'error',
				'message' => sprintf(
					/* translators: %s: queue URL */
					__( 'Some orders have been in "Packing" status for more than 4 hours. <a href="%s">View queue</a>.', 'barcode-fulfillment-orders' ),
					esc_url( admin_url( 'admin.php?page=bfo-queue' ) )
				),
			);
		}

		return $alerts;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Computes a trend direction/diff object.
	 *
	 * @since  1.2.0
	 * @param  int  $current
	 * @param  int  $previous
	 * @param  bool $inverse  True when an increase is bad (e.g. missing items).
	 * @return array{dir:string,diff:int}
	 */
	private function trend( int $current, int $previous, bool $inverse = false ): array {
		$diff = $current - $previous;
		if ( $diff > 0 ) {
			$dir = $inverse ? 'down-bad' : 'up';
		} elseif ( $diff < 0 ) {
			$dir = $inverse ? 'up-good' : 'down';
		} else {
			$dir = 'flat';
		}
		return array( 'dir' => $dir, 'diff' => abs( $diff ) );
	}
}
