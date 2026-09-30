<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$step  = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : 'upload'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$state = PS_Attendance_Controller::get_current_state();
$error = PS_Attendance_Controller::pull_error();

// セッション切れの場合はアップロード画面に戻す
if ( in_array( $step, array( 'mapping', 'preview' ), true ) && empty( $state['file_path'] ) ) {
	$step = 'upload';
}
?>
<div class="wrap payroll-system-wrap">
	<h1><?php esc_html_e( '勤怠データ取込', 'payroll-system' ); ?></h1>

	<ol class="ps-import-steps">
		<li<?php echo 'upload' === $step ? ' class="ps-step-current"' : ''; ?>><?php esc_html_e( '① CSVアップロード', 'payroll-system' ); ?></li>
		<li<?php echo 'mapping' === $step ? ' class="ps-step-current"' : ''; ?>><?php esc_html_e( '② 列マッピング', 'payroll-system' ); ?></li>
		<li<?php echo 'preview' === $step ? ' class="ps-step-current"' : ''; ?>><?php esc_html_e( '③ プレビュー確認', 'payroll-system' ); ?></li>
		<li<?php echo 'done' === $step ? ' class="ps-step-current"' : ''; ?>><?php esc_html_e( '④ 完了', 'payroll-system' ); ?></li>
	</ol>

	<?php if ( $error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
	<?php endif; ?>

	<?php if ( 'upload' === $step ) : ?>

		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ps_attendance_upload' ); ?>
			<input type="hidden" name="action" value="ps_attendance_upload">

			<table class="form-table">
				<tr>
					<th><label for="target_year_month"><?php esc_html_e( '対象年月', 'payroll-system' ); ?></label></th>
					<td>
						<input type="month" name="target_year_month" id="target_year_month" required>
						<p class="description"><?php esc_html_e( 'このCSVに含まれる勤怠データが、どの月の給与計算に使われるかを指定してください。', 'payroll-system' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="attendance_csv"><?php esc_html_e( 'CSVファイル', 'payroll-system' ); ?></label></th>
					<td><input type="file" name="attendance_csv" id="attendance_csv" accept=".csv" required></td>
				</tr>
				<tr>
					<th><label for="import_profile"><?php esc_html_e( '取込プロファイル', 'payroll-system' ); ?></label></th>
					<td>
						<select name="import_profile" id="import_profile">
							<option value=""><?php esc_html_e( '新規にマッピングを作成する', 'payroll-system' ); ?></option>
							<?php foreach ( PS_Import_Profile::get_all() as $profile ) : ?>
								<option value="<?php echo esc_attr( $profile->id ); ?>"><?php echo esc_html( $profile->profile_name ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( '以前保存したマッピングを選ぶと、列マッピング画面を省略してすぐプレビューに進みます。', 'payroll-system' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( '次へ（アップロード）', 'payroll-system' ); ?></button>
			</p>
		</form>

	<?php elseif ( 'mapping' === $step ) : ?>

		<p>
			<?php
			printf(
				/* translators: %s: 対象年月 */
				esc_html__( '対象年月：%s ／ CSVの列と、システム内部の項目を対応付けてください。', 'payroll-system' ),
				esc_html( $state['target_year_month'] )
			);
			?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ps_attendance_mapping' ); ?>
			<input type="hidden" name="action" value="ps_attendance_mapping">

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'システム内部の項目', 'payroll-system' ); ?></th>
						<th><?php esc_html_e( '対応するCSVの列', 'payroll-system' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( PS_Attendance_Importer::get_internal_fields() as $field_key => $field ) : ?>
					<tr>
						<td>
							<?php echo esc_html( $field['label'] ); ?>
							<?php if ( $field['required'] ) : ?><span class="required">*</span><?php endif; ?>
						</td>
						<td>
							<select name="map_<?php echo esc_attr( $field_key ); ?>">
								<option value=""><?php esc_html_e( '－ 使用しない（0として扱う） －', 'payroll-system' ); ?></option>
								<?php foreach ( $state['headers'] as $header ) : ?>
									<option value="<?php echo esc_attr( $header ); ?>">
										<?php echo esc_html( $header ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'このマッピングを保存', 'payroll-system' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="save_as_profile" value="1">
							<?php esc_html_e( '次回から選択できるようプロファイルとして保存する', 'payroll-system' ); ?>
						</label>
						<br>
						<input type="text" name="profile_name" class="regular-text" placeholder="<?php esc_attr_e( '例：King of Time用', 'payroll-system' ); ?>">
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( '次へ（プレビュー確認）', 'payroll-system' ); ?></button>
			</p>
		</form>

		<p style="margin-top:8px;">
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ps_attendance_cancel' ), 'ps_attendance_cancel' ) ); ?>" class="button-link" style="color:#b32d2e;">
				<?php esc_html_e( '取込を中止して最初からやり直す', 'payroll-system' ); ?>
			</a>
		</p>

	<?php elseif ( 'preview' === $step && ! empty( $state['preview'] ) ) : ?>

		<?php $preview = $state['preview']; ?>

		<p>
			<?php
			printf(
				/* translators: 1: OK件数 2: エラー件数 */
				esc_html__( '対象年月：%1$s ／ 正常：%2$d件 ／ エラー：%3$d件（エラー行は取込対象から除外されます）', 'payroll-system' ),
				esc_html( $state['target_year_month'] ),
				(int) $preview['ok_count'],
				(int) $preview['error_count']
			);
			?>
		</p>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( '行', 'payroll-system' ); ?></th>
					<th><?php esc_html_e( '社員コード', 'payroll-system' ); ?></th>
					<th><?php esc_html_e( '氏名', 'payroll-system' ); ?></th>
					<th><?php esc_html_e( '出勤/欠勤', 'payroll-system' ); ?></th>
					<th><?php esc_html_e( '所定内/残業/深夜/休日', 'payroll-system' ); ?></th>
					<th><?php esc_html_e( '状態', 'payroll-system' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $preview['rows'] as $row ) : ?>
				<tr style="<?php echo 'error' === $row['status'] ? 'background:#fbeaea;' : ''; ?>">
					<td><?php echo esc_html( $row['row_number'] ); ?></td>
					<td><?php echo esc_html( $row['employee_code'] ); ?></td>
					<td><?php echo esc_html( $row['employee_name'] ?: '—' ); ?></td>
					<td><?php echo esc_html( $row['values']['work_days'] . ' / ' . $row['values']['absence_days'] ); ?></td>
					<td><?php echo esc_html( $row['values']['scheduled_hours'] . ' / ' . $row['values']['overtime_hours'] . ' / ' . $row['values']['late_night_hours'] . ' / ' . $row['values']['holiday_work_hours'] ); ?></td>
					<td>
						<?php if ( 'ok' === $row['status'] ) : ?>
							<span style="color:#1a7e34;">✔ <?php esc_html_e( '正常', 'payroll-system' ); ?></span>
						<?php else : ?>
							<span style="color:#b32d2e;">✘ <?php echo esc_html( implode( ' / ', $row['messages'] ) ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
			<?php wp_nonce_field( 'ps_attendance_confirm' ); ?>
			<input type="hidden" name="action" value="ps_attendance_confirm">
			<button type="submit" class="button button-primary" <?php disabled( $preview['ok_count'], 0 ); ?>>
				<?php
				printf(
					/* translators: %d: 件数 */
					esc_html__( '正常な%d件を取込確定する', 'payroll-system' ),
					(int) $preview['ok_count']
				);
				?>
			</button>
		</form>

		<p style="margin-top:8px;">
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ps_attendance_cancel' ), 'ps_attendance_cancel' ) ); ?>" class="button-link" style="color:#b32d2e;">
				<?php esc_html_e( '取込を中止して最初からやり直す', 'payroll-system' ); ?>
			</a>
		</p>

	<?php elseif ( 'done' === $step ) : ?>

		<div class="notice notice-success">
			<p>
				<?php
				$imported = isset( $_GET['imported'] ) ? absint( $_GET['imported'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$skipped  = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				printf(
					/* translators: 1: 取込件数 2: スキップ件数 */
					esc_html__( '取込が完了しました。%1$d件を登録し、%2$d件をスキップしました。', 'payroll-system' ),
					$imported,
					$skipped
				);
				?>
			</p>
		</div>

		<a href="<?php echo esc_url( admin_url( 'admin.php?page=payroll-system-attendance-import' ) ); ?>" class="button button-primary">
			<?php esc_html_e( '続けて別のCSVを取り込む', 'payroll-system' ); ?>
		</a>

	<?php else : ?>

		<p><?php esc_html_e( 'セッションが切れました。最初からやり直してください。', 'payroll-system' ); ?></p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=payroll-system-attendance-import' ) ); ?>" class="button">
			<?php esc_html_e( '最初に戻る', 'payroll-system' ); ?>
		</a>

	<?php endif; ?>
</div>
