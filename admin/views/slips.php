<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
$table = $wpdb->prefix . 'ps_payroll_slips';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 専用テーブルのため直接クエリ
$slips = $wpdb->get_results( "SELECT * FROM {$table} WHERE is_deleted = 0 ORDER BY target_year_month DESC, id DESC LIMIT 50" );

$message    = isset( $_GET['message'] ) ? sanitize_key( $_GET['message'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$errors     = PS_Payroll_Run_Controller::pull_errors();
$correct_id = isset( $_GET['correct_id'] ) ? absint( $_GET['correct_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$correct_slip = $correct_id > 0 ? PS_Payroll_Slip::find( $correct_id ) : null;

$adjust_id   = isset( $_GET['adjust_id'] ) ? absint( $_GET['adjust_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$adjust_slip = $adjust_id > 0 ? PS_Payroll_Slip::find( $adjust_id ) : null;

$status_labels = array(
	'calculating' => __( '計算中', 'payroll-system' ),
	'reviewing'    => __( '確認中', 'payroll-system' ),
	'confirmed'    => __( '確定済み', 'payroll-system' ),
	'corrected'    => __( '訂正済み', 'payroll-system' ),
);
?>
<div class="wrap payroll-system-wrap">
	<h1><?php esc_html_e( '給与明細・帳票出力', 'payroll-system' ); ?></h1>

	<?php if ( 'calculated' === $message ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
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
		</div>
	<?php elseif ( 'confirmed' === $message ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '明細を確定しました。', 'payroll-system' ); ?></p></div>
	<?php elseif ( 'confirm_failed' === $message ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( '確定できませんでした（すでに確定済み、または対象が見つかりません）。', 'payroll-system' ); ?></p></div>
	<?php elseif ( 'confirmed_bulk' === $message ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				printf(
					/* translators: 1: 確定件数 2: 失敗件数 */
					esc_html__( '%1$d件を確定しました（%2$d件は確定できませんでした）。', 'payroll-system' ),
					isset( $_GET['confirmed'] ) ? absint( $_GET['confirmed'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					isset( $_GET['failed'] ) ? absint( $_GET['failed'] ) : 0 // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				);
				?>
			</p>
		</div>
	<?php elseif ( 'corrected' === $message ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '明細を訂正しました。', 'payroll-system' ); ?></p></div>
	<?php elseif ( 'correct_failed' === $message ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( '訂正できませんでした。内容を確認してください。', 'payroll-system' ); ?></p></div>
	<?php elseif ( 'adjustments_saved' === $message ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '調整項目を保存しました。', 'payroll-system' ); ?></p></div>
	<?php elseif ( 'adjustments_failed' === $message ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( '調整項目を保存できませんでした。', 'payroll-system' ); ?></p></div>
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

	<?php if ( $correct_id > 0 && $correct_slip ) : ?>

		<?php
		$correct_employee      = PS_Employee::find( (int) $correct_slip->employee_id );
		$correct_employee_name = $correct_employee ? ( $correct_employee->employee_code . '：' . $correct_employee->name ) : sprintf( '(ID:%d)', $correct_slip->employee_id );
		?>
		<h2><?php esc_html_e( '明細を訂正する', 'payroll-system' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: 対象年月 2: 従業員 3: 現在の差引支給額 */
				esc_html__( '対象年月：%1$s ／ 従業員：%2$s ／ 現在の差引支給額：%3$s円', 'payroll-system' ),
				esc_html( $correct_slip->target_year_month ),
				esc_html( $correct_employee_name ),
				esc_html( number_format( (float) $correct_slip->net_amount ) )
			);
			?>
		</p>
		<p class="description">
			<?php esc_html_e( '訂正すると、現在の勤怠データ・料率マスタで再計算した結果に上書きされます。先に従業員マスタ・勤怠データ・料率マスタ側の誤りを修正してから訂正を実行してください。', 'payroll-system' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ps_run_correct' ); ?>
			<input type="hidden" name="action" value="ps_run_correct">
			<input type="hidden" name="id" value="<?php echo esc_attr( $correct_slip->id ); ?>">

			<table class="form-table">
				<tr>
					<th><label for="reason"><?php esc_html_e( '訂正理由', 'payroll-system' ); ?> <span class="required">*</span></label></th>
					<td>
						<textarea name="reason" id="reason" class="large-text" rows="3" required></textarea>
						<p class="description"><?php esc_html_e( '監査ログに記録されます。', 'payroll-system' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"
					onclick="return confirm('<?php echo esc_js( __( '再計算した内容で明細を上書きします。よろしいですか？', 'payroll-system' ) ); ?>');">
					<?php esc_html_e( '再計算して訂正を確定する', 'payroll-system' ); ?>
				</button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=payroll-system-slips' ) ); ?>" class="button">
					<?php esc_html_e( 'キャンセル', 'payroll-system' ); ?>
				</a>
			</p>
		</form>

	<?php elseif ( $adjust_id > 0 && $adjust_slip ) : ?>

		<?php
		$adjust_employee      = PS_Employee::find( (int) $adjust_slip->employee_id );
		$adjust_employee_name = $adjust_employee ? ( $adjust_employee->employee_code . '：' . $adjust_employee->name ) : sprintf( '(ID:%d)', $adjust_slip->employee_id );
		$adjust_deductions    = json_decode( $adjust_slip->deduction_amounts_json, true ) ?: array();
		$adjust_gross         = json_decode( $adjust_slip->gross_amounts_json, true ) ?: array();
		?>
		<h2><?php esc_html_e( '調整項目を編集する', 'payroll-system' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: 対象年月 2: 従業員 */
				esc_html__( '対象年月：%1$s ／ 従業員：%2$s', 'payroll-system' ),
				esc_html( $adjust_slip->target_year_month ),
				esc_html( $adjust_employee_name )
			);
			?>
		</p>
		<p class="description">
			<?php esc_html_e( '社保料調整・調整控除・年末調整・過年度調整は自動計算せず、ここで入力した金額をそのまま反映します（マイナスの値も入力できます）。現金支給額・現物支給額は明細への表示のみで、金額の合計計算には影響しません。', 'payroll-system' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ps_save_slip_adjustments' ); ?>
			<input type="hidden" name="action" value="ps_save_slip_adjustments">
			<input type="hidden" name="id" value="<?php echo esc_attr( $adjust_slip->id ); ?>">

			<table class="form-table">
				<tr>
					<th><label for="social_insurance_adjustment"><?php esc_html_e( '社保料調整', 'payroll-system' ); ?></label></th>
					<td><input type="number" step="1" id="social_insurance_adjustment" name="social_insurance_adjustment" class="regular-text" value="<?php echo esc_attr( $adjust_deductions['social_insurance_adjustment'] ?? 0 ); ?>"> <?php esc_html_e( '円', 'payroll-system' ); ?></td>
				</tr>
				<tr>
					<th><label for="adjustment_deduction"><?php esc_html_e( '調整控除', 'payroll-system' ); ?></label></th>
					<td><input type="number" step="1" id="adjustment_deduction" name="adjustment_deduction" class="regular-text" value="<?php echo esc_attr( $adjust_deductions['adjustment_deduction'] ?? 0 ); ?>"> <?php esc_html_e( '円', 'payroll-system' ); ?></td>
				</tr>
				<tr>
					<th><label for="nenmatsu_chousei_refund"><?php esc_html_e( '年末調整還付', 'payroll-system' ); ?></label></th>
					<td><input type="number" step="1" id="nenmatsu_chousei_refund" name="nenmatsu_chousei_refund" class="regular-text" value="<?php echo esc_attr( $adjust_deductions['nenmatsu_chousei_refund'] ?? 0 ); ?>"> <?php esc_html_e( '円', 'payroll-system' ); ?></td>
				</tr>
				<tr>
					<th><label for="nenmatsu_chousei_deduction"><?php esc_html_e( '年末調整徴収', 'payroll-system' ); ?></label></th>
					<td><input type="number" step="1" id="nenmatsu_chousei_deduction" name="nenmatsu_chousei_deduction" class="regular-text" value="<?php echo esc_attr( $adjust_deductions['nenmatsu_chousei_deduction'] ?? 0 ); ?>"> <?php esc_html_e( '円', 'payroll-system' ); ?></td>
				</tr>
				<tr>
					<th><label for="prior_year_adjustment"><?php esc_html_e( '過年度調整', 'payroll-system' ); ?></label></th>
					<td><input type="number" step="1" id="prior_year_adjustment" name="prior_year_adjustment" class="regular-text" value="<?php echo esc_attr( $adjust_deductions['prior_year_adjustment'] ?? 0 ); ?>"> <?php esc_html_e( '円', 'payroll-system' ); ?></td>
				</tr>
				<tr>
					<th><label for="cash_payment"><?php esc_html_e( '現金支給額', 'payroll-system' ); ?></label></th>
					<td><input type="number" step="1" min="0" id="cash_payment" name="cash_payment" class="regular-text" value="<?php echo esc_attr( $adjust_gross['cash_payment'] ?? 0 ); ?>"> <?php esc_html_e( '円', 'payroll-system' ); ?></td>
				</tr>
				<tr>
					<th><label for="in_kind_payment"><?php esc_html_e( '現物支給額', 'payroll-system' ); ?></label></th>
					<td><input type="number" step="1" min="0" id="in_kind_payment" name="in_kind_payment" class="regular-text" value="<?php echo esc_attr( $adjust_gross['in_kind_payment'] ?? 0 ); ?>"> <?php esc_html_e( '円', 'payroll-system' ); ?></td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( '調整項目を保存する', 'payroll-system' ); ?></button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=payroll-system-slips' ) ); ?>" class="button">
					<?php esc_html_e( 'キャンセル', 'payroll-system' ); ?>
				</a>
			</p>
		</form>

	<?php else : ?>

		<p class="submit">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=payroll-system-run' ) ); ?>" class="button">
				<?php esc_html_e( '給与計算を実行する', 'payroll-system' ); ?>
			</a>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=ps_run_confirm_selected' ) ); ?>">
			<?php wp_nonce_field( 'ps_run_confirm_selected' ); ?>
			<?php wp_nonce_field( 'ps_slip_pdf_download_bulk', 'nonce_pdf' ); ?>

			<p class="submit">
				<button type="submit" class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'チェックした明細を確定します。よろしいですか？', 'payroll-system' ) ); ?>');">
					<?php esc_html_e( '選択した明細を確定する', 'payroll-system' ); ?>
				</button>
				<button type="submit" class="button" formaction="<?php echo esc_url( admin_url( 'admin-post.php?action=ps_slip_pdf_download_bulk' ) ); ?>">
					<?php esc_html_e( '選択した明細をまとめてPDF出力（ZIP）', 'payroll-system' ); ?>
				</button>
				<span class="description"><?php esc_html_e( '確定済みの明細のみPDF化されます。', 'payroll-system' ); ?></span>
			</p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th class="check-column"></th>
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
						<td colspan="8"><?php esc_html_e( '給与明細データがありません。まず「給与計算実行」から計算してください。', 'payroll-system' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $slips as $slip ) : ?>
						<?php
						$employee       = PS_Employee::find( (int) $slip->employee_id );
						$employee_name  = $employee ? ( $employee->employee_code . '：' . $employee->name ) : sprintf( '(ID:%d)', $slip->employee_id );
						$can_confirm    = in_array( $slip->status, array( 'calculating', 'reviewing' ), true );
						$can_correct    = in_array( $slip->status, array( 'confirmed', 'corrected' ), true );
						?>
						<tr>
							<td>
								<input type="checkbox" name="slip_ids[]" value="<?php echo esc_attr( $slip->id ); ?>">
							</td>
							<td><?php echo esc_html( $slip->target_year_month ); ?></td>
							<td><?php echo esc_html( $employee_name ); ?></td>
							<td><?php echo esc_html( $status_labels[ $slip->status ] ?? $slip->status ); ?></td>
							<td><?php echo esc_html( number_format( (float) $slip->total_gross ) ); ?> <?php esc_html_e( '円', 'payroll-system' ); ?></td>
							<td><?php echo esc_html( number_format( (float) $slip->total_deduction ) ); ?> <?php esc_html_e( '円', 'payroll-system' ); ?></td>
							<td><?php echo esc_html( number_format( (float) $slip->net_amount ) ); ?> <?php esc_html_e( '円', 'payroll-system' ); ?></td>
							<td>
								<?php if ( $can_confirm ) : ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ps_run_confirm&id=' . $slip->id ), 'ps_run_confirm_' . $slip->id ) ); ?>">
										<?php esc_html_e( '確定', 'payroll-system' ); ?>
									</a>
								<?php elseif ( $can_correct ) : ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ps_slip_pdf_download&id=' . $slip->id ), 'ps_slip_pdf_download_' . $slip->id ) ); ?>">
										<?php esc_html_e( 'PDF出力', 'payroll-system' ); ?>
									</a>
									|
									<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'payroll-system-slips', 'correct_id' => $slip->id ), admin_url( 'admin.php' ) ) ); ?>">
										<?php esc_html_e( '訂正', 'payroll-system' ); ?>
									</a>
								<?php endif; ?>
								<?php if ( $can_confirm || $can_correct ) : ?>
									|
									<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'payroll-system-slips', 'adjust_id' => $slip->id ), admin_url( 'admin.php' ) ) ); ?>">
										<?php esc_html_e( '調整', 'payroll-system' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</form>

	<?php endif; ?>

</div>
