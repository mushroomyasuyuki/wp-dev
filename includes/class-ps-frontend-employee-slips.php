<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 固定ページに [payroll_employee_slips] を埋め込むと、ログイン中の従業員本人の
 * 確定済み給与明細だけを一覧・PDFダウンロードできる（セルフサービス）。
 *
 * PS_Frontend_Payroll_Slips（給与担当者向け、全従業員分を閲覧）とは別物。
 * 認証もPS_Employee_Auth（従業員専用の独自セッション）を使い、manage_payroll
 * capabilityとは無関係。他の従業員の明細は一切表示・取得できない
 * （PDFダウンロードも明細のemployee_idとログイン中の従業員IDが一致する場合のみ許可）。
 *
 * 使い方：
 *   [payroll_employee_slips login_page="https://example.com/employee-login/"]
 */
class PS_Frontend_Employee_Slips {

	public function register_hooks() {
		add_shortcode( 'payroll_employee_slips', array( $this, 'render' ) );
	}

	public function render( $atts ): string {
		$atts = shortcode_atts( array(
			'login_page' => home_url( '/' ),
		), $atts, 'payroll_employee_slips' );

		$employee = PS_Employee_Auth::get_current_employee();
		if ( ! $employee ) {
			ob_start();
			?>
			<div class="ps-frontend ps-frontend-guard">
				<p><?php esc_html_e( 'この画面を利用するにはログインが必要です。', 'payroll-system' ); ?></p>
				<p><a class="ps-btn ps-btn-primary" href="<?php echo esc_url( $atts['login_page'] ); ?>"><?php esc_html_e( 'ログインページへ', 'payroll-system' ); ?></a></p>
			</div>
			<?php
			return ob_get_clean();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'ps_payroll_slips';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$slips = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE employee_id = %d AND is_deleted = 0 AND status IN ('confirmed','corrected')
			 ORDER BY target_year_month DESC",
			$employee->id
		) );

		$status_labels = array(
			'confirmed' => __( '確定済み', 'payroll-system' ),
			'corrected' => __( '訂正済み', 'payroll-system' ),
		);

		ob_start();
		?>
		<div class="ps-frontend ps-frontend-employee-slips">
			<h2><?php esc_html_e( '給与明細', 'payroll-system' ); ?></h2>
			<p class="ps-description">
				<?php
				printf(
					/* translators: %s: 従業員氏名 */
					esc_html__( '%s さんの確定済み給与明細です。', 'payroll-system' ),
					esc_html( $employee->name )
				);
				?>
			</p>

			<table class="ps-table">
				<thead>
					<tr>
						<th><?php esc_html_e( '対象年月', 'payroll-system' ); ?></th>
						<th><?php esc_html_e( 'ステータス', 'payroll-system' ); ?></th>
						<th><?php esc_html_e( '差引支給額', 'payroll-system' ); ?></th>
						<th><?php esc_html_e( '操作', 'payroll-system' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $slips ) ) : ?>
					<tr>
						<td colspan="4"><?php esc_html_e( '確定済みの給与明細はまだありません。', 'payroll-system' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $slips as $slip ) : ?>
						<tr>
							<td><?php echo esc_html( $slip->target_year_month ); ?></td>
							<td><?php echo esc_html( $status_labels[ $slip->status ] ?? $slip->status ); ?></td>
							<td><?php echo esc_html( number_format( (float) $slip->net_amount ) ); ?> <?php esc_html_e( '円', 'payroll-system' ); ?></td>
							<td>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ps_employee_slip_pdf_download&id=' . $slip->id ), 'ps_employee_slip_pdf_download_' . $slip->id ) ); ?>">
									<?php esc_html_e( 'PDFダウンロード', 'payroll-system' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}
}
