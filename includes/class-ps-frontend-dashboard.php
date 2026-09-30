<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 固定ページに [payroll_dashboard] を埋め込むと、wp-adminのダッシュボードと
 * 同等のサマリー（在籍従業員数・当月の未確定件数・直近の勤怠取込）を
 * フロント（固定ページ）側でも閲覧できる。
 *
 * wp-admin版と異なり、固定ページURL一覧や「固定ページ本文を再生成」ボタンのような
 * 開発・復旧用のツールはここには置かない（給与担当者向けの閲覧専用画面のため）。
 *
 * 使い方：
 *   [payroll_dashboard login_page="https://example.com/payroll-login/"]
 */
class PS_Frontend_Dashboard {

	public function register_hooks() {
		add_shortcode( 'payroll_dashboard', array( $this, 'render' ) );
	}

	public function render( $atts ): string {
		$atts = shortcode_atts( array(
			'login_page' => home_url( '/' ),
		), $atts, 'payroll_dashboard' );

		$guard_html = PS_Frontend_Auth::guard( $atts['login_page'] );
		if ( null !== $guard_html ) {
			return $guard_html;
		}

		global $wpdb;

		$active_employee_count = PS_Employee::count( array( 'status' => 'active' ) );

		$current_year_month = current_time( 'Y-m' );
		$slips_table         = $wpdb->prefix . 'ps_payroll_slips';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$unconfirmed_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$slips_table} WHERE target_year_month = %s AND status IN ('calculating','reviewing') AND is_deleted = 0",
			$current_year_month
		) );

		$attendance_table = $wpdb->prefix . 'ps_attendance';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$latest_attendance = $wpdb->get_row( "SELECT target_year_month, created_at FROM {$attendance_table} ORDER BY created_at DESC LIMIT 1" );

		ob_start();
		?>
		<div class="ps-frontend ps-frontend-dashboard">
			<h2><?php esc_html_e( '給与計算 ダッシュボード', 'payroll-system' ); ?></h2>

			<p class="ps-description">
				<?php
				printf(
					/* translators: %s: 今月（YYYY-MM） */
					esc_html__( '当月（%s）の計算状況サマリーです。', 'payroll-system' ),
					esc_html( $current_year_month )
				);
				?>
			</p>

			<table class="ps-table">
				<tbody>
					<tr>
						<th><?php esc_html_e( '在籍従業員数', 'payroll-system' ); ?></th>
						<td><?php echo esc_html( number_format( $active_employee_count ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( '今月の計算未確定件数', 'payroll-system' ); ?></th>
						<td><?php echo esc_html( number_format( $unconfirmed_count ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( '直近の勤怠取込', 'payroll-system' ); ?></th>
						<td>
							<?php if ( $latest_attendance ) : ?>
								<?php echo esc_html( $latest_attendance->target_year_month . '（' . mysql2date( 'Y-m-d H:i', $latest_attendance->created_at ) . '）' ); ?>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<nav class="ps-dashboard-nav">
				<h3><?php esc_html_e( 'メニュー', 'payroll-system' ); ?></h3>
				<ul>
					<?php
					$page_ids = get_option( 'payroll_system_page_ids', array() );
					$specs    = PS_Activator::page_specs();
					$nav_keys = array( 'employees', 'attendance_import', 'payroll_run', 'payroll_slips' );
					foreach ( $nav_keys as $key ) :
						$page_id = $page_ids[ $key ] ?? 0;
						if ( ! $page_id ) {
							continue;
						}
						$post = get_post( $page_id );
						if ( ! $post || 'trash' === $post->post_status ) {
							continue;
						}
						?>
						<li><a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>"><?php echo esc_html( $specs[ $key ]['title'] ?? $key ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</nav>

			<?php
			$employee_login_id = $page_ids['employee_login'] ?? 0;
			$employee_login_post = $employee_login_id ? get_post( $employee_login_id ) : null;
			?>
			<?php if ( $employee_login_post && 'trash' !== $employee_login_post->post_status ) : ?>
				<p class="ps-employee-portal-link">
					<?php
					printf(
						/* translators: %s: 従業員ログインページへのリンク */
						esc_html__( '従業員が自分の給与明細を見る場合はこちら： %s', 'payroll-system' ),
						'<a href="' . esc_url( get_permalink( $employee_login_id ) ) . '">' . esc_html__( '従業員ログイン', 'payroll-system' ) . '</a>'
					);
					?>
				</p>
			<?php endif; ?>

			<div class="ps-notice ps-notice-warning">
				<p>
					<strong><?php esc_html_e( 'ご利用にあたっての免責事項', 'payroll-system' ); ?></strong><br>
					<?php esc_html_e( '本プラグインが算出する給与額・社会保険料・所得税額は、登録された料率マスタ・従業員情報・勤怠データに基づく計算結果であり、その正確性・法令適合性を保証するものではありません。実際の給与支払いに用いる前に、必ず自社の社会保険労務士・税理士等の専門家による確認を行ってください。計算結果の誤りに起因する損害について、本プラグインの作者は責任を負いません。', 'payroll-system' ); ?>
				</p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
