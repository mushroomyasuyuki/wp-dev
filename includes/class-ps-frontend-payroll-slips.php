<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 固定ページに [payroll_slips] を埋め込むと、給与明細一覧の閲覧とPDF出力ができる。
 *
 * 確定・訂正はwp-adminの「給与明細・帳票出力」画面でのみ行う（金額を扱う不可逆な操作のため、
 * フロントには一覧表示とPDF出力のみを提供する設計）。PDF出力はwp-admin側と同じ
 * admin-post.phpアクション（ps_slip_pdf_download / ps_slip_pdf_download_bulk）を再利用しており、
 * capabilityチェック（manage_payroll）はコントローラー側で行われるため、ここでは
 * フロントログイン済みの給与担当者にリンクを表示するだけでよい。
 *
 * 使い方：
 *   [payroll_slips login_page="https://example.com/payroll-login/"]
 */
class PS_Frontend_Payroll_Slips {

	public function register_hooks() {
		add_shortcode( 'payroll_slips', array( $this, 'render' ) );
	}

	public function render( $atts ): string {
		$atts = shortcode_atts( array(
			'login_page'     => home_url( '/' ),
			'dashboard_page' => home_url( '/' ),
		), $atts, 'payroll_slips' );

		$guard_html = PS_Frontend_Auth::guard( $atts['login_page'] );
		if ( null !== $guard_html ) {
			return $guard_html;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'ps_payroll_slips';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 専用テーブルのため直接クエリ
		$slips = $wpdb->get_results( "SELECT * FROM {$table} WHERE is_deleted = 0 ORDER BY target_year_month DESC, id DESC LIMIT 50" );

		$status_labels = array(
			'calculating' => __( '計算中', 'payroll-system' ),
			'reviewing'    => __( '確認中', 'payroll-system' ),
			'confirmed'    => __( '確定済み', 'payroll-system' ),
			'corrected'    => __( '訂正済み', 'payroll-system' ),
		);

		ob_start();
		?>
		<div class="ps-frontend ps-frontend-payroll-slips">
			<p class="ps-back-link"><a href="<?php echo esc_url( $atts['dashboard_page'] ); ?>">&larr; <?php esc_html_e( 'ダッシュボードに戻る', 'payroll-system' ); ?></a></p>
			<h2><?php esc_html_e( '給与明細一覧', 'payroll-system' ); ?></h2>

			<p class="ps-description">
				<?php esc_html_e( '内容の確認・確定・訂正は管理画面（wp-admin）から行ってください。ここでは閲覧と確定済み明細のPDF出力のみ行えます。', 'payroll-system' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=ps_slip_pdf_download_bulk' ) ); ?>">
				<?php wp_nonce_field( 'ps_slip_pdf_download_bulk', 'nonce_pdf' ); ?>

				<p class="ps-submit">
					<button type="submit" class="ps-btn ps-btn-primary"><?php esc_html_e( '選択した明細をまとめてPDF出力（ZIP）', 'payroll-system' ); ?></button>
				</p>

				<table class="ps-table">
					<thead>
						<tr>
							<th></th>
							<th><?php esc_html_e( '対象年月', 'payroll-system' ); ?></th>
							<th><?php esc_html_e( '従業員', 'payroll-system' ); ?></th>
							<th><?php esc_html_e( 'ステータス', 'payroll-system' ); ?></th>
							<th><?php esc_html_e( '総支給額', 'payroll-system' ); ?></th>
							<th><?php esc_html_e( '総控除額', 'payroll-system' ); ?></th>
							<th><?php esc_html_e( '差引支給額', 'payroll-system' ); ?></th>
							<th><?php esc_html_e( '操作', 'payroll-system' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( empty( $slips ) ) : ?>
						<tr>
							<td colspan="8"><?php esc_html_e( '給与明細データがありません。', 'payroll-system' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $slips as $slip ) : ?>
							<?php
							$employee      = PS_Employee::find( (int) $slip->employee_id );
							$employee_name = $employee ? ( $employee->employee_code . '：' . $employee->name ) : sprintf( '(ID:%d)', $slip->employee_id );
							$can_pdf       = in_array( $slip->status, array( 'confirmed', 'corrected' ), true );
							?>
							<tr>
								<td>
									<?php if ( $can_pdf ) : ?>
										<input type="checkbox" name="slip_ids[]" value="<?php echo esc_attr( $slip->id ); ?>">
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $slip->target_year_month ); ?></td>
								<td><?php echo esc_html( $employee_name ); ?></td>
								<td><?php echo esc_html( $status_labels[ $slip->status ] ?? $slip->status ); ?></td>
								<td><?php echo esc_html( number_format( (float) $slip->total_gross ) ); ?> <?php esc_html_e( '円', 'payroll-system' ); ?></td>
								<td><?php echo esc_html( number_format( (float) $slip->total_deduction ) ); ?> <?php esc_html_e( '円', 'payroll-system' ); ?></td>
								<td><?php echo esc_html( number_format( (float) $slip->net_amount ) ); ?> <?php esc_html_e( '円', 'payroll-system' ); ?></td>
								<td>
									<?php if ( $can_pdf ) : ?>
										<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ps_slip_pdf_download&id=' . $slip->id ), 'ps_slip_pdf_download_' . $slip->id ) ); ?>">
											<?php esc_html_e( 'PDF出力', 'payroll-system' ); ?>
										</a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}
}
