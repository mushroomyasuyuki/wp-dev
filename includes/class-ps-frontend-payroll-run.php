<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 固定ページに [payroll_run] を埋め込むと、給与計算実行（対象年月・対象従業員を
 * 指定して計算するだけの操作）をフロントで行える。
 *
 * wp-admin側のadmin/views/run.phpと同じPS_Payroll_Run_Controllerを利用する。
 * 確認・確定・訂正はwp-adminの「給与明細・帳票出力」画面でのみ行う想定のため、
 * このページからは実行のみで、結果件数の通知のみをこのページ自身に表示する
 * （隠しフィールド _ps_redirect_base で自分自身のURLを指定）。
 *
 * 使い方：
 *   [payroll_run login_page="https://example.com/payroll-login/"]
 */
class PS_Frontend_Payroll_Run {

	public function register_hooks() {
		add_shortcode( 'payroll_run', array( $this, 'render' ) );
	}

	public function render( $atts ): string {
		$atts = shortcode_atts( array(
			'login_page'     => home_url( '/' ),
			'dashboard_page' => home_url( '/' ),
		), $atts, 'payroll_run' );

		$guard_html = PS_Frontend_Auth::guard( $atts['login_page'] );
		if ( null !== $guard_html ) {
			return $guard_html;
		}

		$base_url = get_permalink();
		$message  = isset( $_GET['message'] ) ? sanitize_key( $_GET['message'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$errors   = PS_Payroll_Run_Controller::pull_errors();
		$employees = PS_Employee::get_all( array( 'status' => 'active', 'per_page' => 1000, 'orderby' => 'employee_code', 'order' => 'ASC' ) );

		ob_start();
		?>
		<div class="ps-frontend ps-frontend-payroll-run">
			<p class="ps-back-link"><a href="<?php echo esc_url( $atts['dashboard_page'] ); ?>">&larr; <?php esc_html_e( 'ダッシュボードに戻る', 'payroll-system' ); ?></a></p>
			<h2><?php esc_html_e( '給与計算実行', 'payroll-system' ); ?></h2>

			<?php if ( 'calculated' === $message ) : ?>
				<p class="ps-notice ps-notice-success">
					<?php
					printf(
						/* translators: 1: 新規作成件数 2: 再計算件数 3: エラー件数 */
						esc_html__( '給与計算が完了しました。新規作成%1$d件・再計算%2$d件（%3$d件はエラーのためスキップ）。', 'payroll-system' ),
						isset( $_GET['created'] ) ? absint( $_GET['created'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						isset( $_GET['recalculated'] ) ? absint( $_GET['recalculated'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						isset( $_GET['error_count'] ) ? absint( $_GET['error_count'] ) : 0 // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					);
					?>
				</p>
			<?php elseif ( 'failed' === $message ) : ?>
				<p class="ps-notice ps-notice-error"><?php esc_html_e( '入力内容を確認してください。', 'payroll-system' ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $errors ) ) : ?>
				<div class="ps-notice ps-notice-error">
					<ul>
						<?php foreach ( $errors as $error ) : ?>
							<li><?php echo esc_html( $error ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<p class="ps-description">
				<?php esc_html_e( '計算結果の確認・確定・訂正は管理者にご依頼ください。ここでは計算の実行のみ行えます。', 'payroll-system' ); ?>
			</p>

			<form method="post" class="ps-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ps_run_payroll' ); ?>
				<input type="hidden" name="action" value="ps_run_calculate">
				<input type="hidden" name="_ps_redirect_base" value="<?php echo esc_url( $base_url ); ?>">

				<p class="ps-field">
					<label><?php esc_html_e( '対象年月', 'payroll-system' ); ?></label>
					<input type="month" name="target_year_month" required>
				</p>

				<p class="ps-field">
					<label><?php esc_html_e( '対象従業員', 'payroll-system' ); ?></label><br>
					<label class="ps-inline"><input type="radio" name="target_scope" value="all" checked> <?php esc_html_e( '在職中の全従業員', 'payroll-system' ); ?></label><br>
					<label class="ps-inline"><input type="radio" name="target_scope" value="selected"> <?php esc_html_e( '下記から個別選択', 'payroll-system' ); ?></label>

					<div class="ps-employee-checklist">
						<?php if ( empty( $employees ) ) : ?>
							<p class="ps-description"><?php esc_html_e( '在職中の従業員が登録されていません。', 'payroll-system' ); ?></p>
						<?php else : ?>
							<?php foreach ( $employees as $emp ) : ?>
								<label class="ps-checklist-item">
									<input type="checkbox" name="employee_ids[]" value="<?php echo esc_attr( $emp->id ); ?>">
									<?php echo esc_html( $emp->employee_code . '：' . $emp->name ); ?>
								</label>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</p>

				<p class="ps-submit">
					<button type="submit" class="ps-btn ps-btn-primary"><?php esc_html_e( '計算を実行する', 'payroll-system' ); ?></button>
				</p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}
}
