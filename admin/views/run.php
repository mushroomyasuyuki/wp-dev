<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$message = isset( $_GET['message'] ) ? sanitize_key( $_GET['message'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$errors  = PS_Payroll_Run_Controller::pull_errors();

$employees = PS_Employee::get_all( array( 'status' => 'active', 'per_page' => 1000, 'orderby' => 'employee_code', 'order' => 'ASC' ) );
?>
<div class="wrap payroll-system-wrap">
	<h1><?php esc_html_e( '給与計算実行', 'payroll-system' ); ?></h1>

	<?php if ( 'failed' === $message ) : ?>
		<div class="notice notice-error"><p><?php esc_html_e( '入力内容を確認してください。', 'payroll-system' ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! empty( $errors ) ) : ?>
		<div class="notice notice-error">
			<ul style="margin: 0.5em 0;">
				<?php foreach ( $errors as $error ) : ?>
					<li><?php echo esc_html( $error ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<p class="description">
		<?php esc_html_e( '対象年月・対象従業員を指定して計算を実行すると、結果は「給与明細・帳票出力」画面に保存されます（ステータス：確認中）。内容を確認のうえ、その画面から確定してください。すでに確定済みの明細は上書きされません。', 'payroll-system' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'ps_run_payroll' ); ?>
		<input type="hidden" name="action" value="ps_run_calculate">

		<table class="form-table">
			<tr>
				<th><label for="target_year_month"><?php esc_html_e( '対象年月', 'payroll-system' ); ?></label></th>
				<td><input type="month" name="target_year_month" id="target_year_month" required></td>
			</tr>
			<tr>
				<th><?php esc_html_e( '対象従業員', 'payroll-system' ); ?></th>
				<td>
					<label><input type="radio" name="target_scope" value="all" checked> <?php esc_html_e( '在職中の全従業員', 'payroll-system' ); ?></label><br>
					<label><input type="radio" name="target_scope" value="selected"> <?php esc_html_e( '下記から個別選択', 'payroll-system' ); ?></label>

					<div style="margin-top:8px;max-height:240px;overflow-y:auto;border:1px solid #dcdcde;padding:8px 12px;max-width:480px;background:#fff;">
						<?php if ( empty( $employees ) ) : ?>
							<p class="description"><?php esc_html_e( '在職中の従業員が登録されていません。', 'payroll-system' ); ?></p>
						<?php else : ?>
							<?php foreach ( $employees as $emp ) : ?>
								<label style="display:block;padding:2px 0;">
									<input type="checkbox" name="employee_ids[]" value="<?php echo esc_attr( $emp->id ); ?>">
									<?php echo esc_html( $emp->employee_code . '：' . $emp->name ); ?>
								</label>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
					<p class="description"><?php esc_html_e( '「下記から個別選択」を選んだ場合のみ、チェックした従業員が対象になります。', 'payroll-system' ); ?></p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary">
				<?php esc_html_e( '計算を実行する', 'payroll-system' ); ?>
			</button>
		</p>
	</form>
</div>
