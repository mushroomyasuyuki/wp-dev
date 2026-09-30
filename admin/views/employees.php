<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 画面切替のみ
$edit_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$user_id       = get_current_user_id();
$form_errors   = get_transient( 'ps_employee_form_errors_' . $user_id );
$form_input    = get_transient( 'ps_employee_form_input_' . $user_id );
delete_transient( 'ps_employee_form_errors_' . $user_id );
delete_transient( 'ps_employee_form_input_' . $user_id );

$message = isset( $_GET['message'] ) ? sanitize_key( $_GET['message'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$temp_password = null;
if ( 'temp_password_issued' === $message && $edit_id > 0 ) {
	$temp_password = get_transient( 'ps_temp_password_' . $user_id . '_' . $edit_id );
	delete_transient( 'ps_temp_password_' . $user_id . '_' . $edit_id );
}

/**
 * フォーム表示用の値を決定する。
 * 優先順位: バリデーションエラーで戻ってきた入力値 > DBの既存値 > 空
 */
$employee = null;
if ( 'edit' === $action && $edit_id > 0 ) {
	$employee = PS_Employee::find( $edit_id );
	if ( ! $employee ) {
		$action = 'list';
	}
}

if ( ! function_exists( 'ps_field_value' ) ) {
	function ps_field_value( $key, $form_input, $employee, $default = '' ) {
		if ( is_array( $form_input ) && isset( $form_input[ $key ] ) ) {
			return $form_input[ $key ];
		}
		if ( $employee && isset( $employee->$key ) ) {
			return $employee->$key;
		}
		return $default;
	}
}
?>
<div class="wrap payroll-system-wrap">

<?php if ( 'add' === $action || 'edit' === $action ) : ?>

	<h1><?php echo 'edit' === $action ? esc_html__( '従業員を編集', 'payroll-system' ) : esc_html__( '従業員を新規登録', 'payroll-system' ); ?></h1>

	<?php if ( ! empty( $form_errors ) ) : ?>
		<div class="notice notice-error">
			<ul style="margin: 0.5em 0;">
				<?php foreach ( $form_errors as $error ) : ?>
					<li><?php echo esc_html( $error ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( $temp_password ) : ?>
		<div class="notice notice-success">
			<p>
				<strong><?php esc_html_e( '仮パスワードを発行しました：', 'payroll-system' ); ?></strong>
				<code style="font-size:1.2em;"><?php echo esc_html( $temp_password ); ?></code><br>
				<?php esc_html_e( 'このパスワードは二度と表示されません。従業員本人に直接お伝えください（このままではメール等で自動送信されません）。従業員は次回ログイン時にパスワードの変更を求められます。', 'payroll-system' ); ?>
			</p>
		</div>
	<?php elseif ( 'password_set' === $message ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'パスワードを設定しました。このパスワードのまま（変更を求められずに）ログインできます。', 'payroll-system' ); ?></p>
		</div>
	<?php elseif ( 'set_password_failed' === $message ) : ?>
		<div class="notice notice-error is-dismissible">
			<p><?php esc_html_e( 'パスワードを設定できませんでした。空欄になっていないか確認してください。', 'payroll-system' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( 'edit' === $action && $employee ) : ?>
		<p>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ps_issue_temp_password&id=' . $employee->id ), 'ps_issue_temp_password_' . $employee->id ) ); ?>"
			   class="button"
			   onclick="return confirm('<?php echo esc_js( __( '仮パスワードを再発行します。現在の従業員用ログインセッションはすべて無効になります。よろしいですか？', 'payroll-system' ) ); ?>');">
				<?php echo empty( $employee->password_hash ) ? esc_html__( '従業員用ログインの仮パスワードを発行する', 'payroll-system' ) : esc_html__( '仮パスワードを再発行する', 'payroll-system' ); ?>
			</a>
			<span class="description">
				<?php
				if ( empty( $employee->password_hash ) ) {
					esc_html_e( '（未発行：この従業員はまだフロント側の自分専用ページにログインできません）', 'payroll-system' );
				} elseif ( ! empty( $employee->must_change_password ) ) {
					esc_html_e( '（仮パスワード発行済み・未変更）', 'payroll-system' );
				} else {
					esc_html_e( '（設定済み）', 'payroll-system' );
				}
				?>
			</span>
		</p>
		<div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-flex; align-items:center; gap:6px;">
				<?php wp_nonce_field( 'ps_set_employee_password_' . $employee->id ); ?>
				<input type="hidden" name="action" value="ps_set_employee_password">
				<input type="hidden" name="id" value="<?php echo esc_attr( $employee->id ); ?>">
				<label for="new_password"><?php esc_html_e( 'パスワードを直接指定する：', 'payroll-system' ); ?></label>
				<input type="text" id="new_password" name="new_password" class="regular-text" style="width:180px;" autocomplete="off" required>
				<button type="submit" class="button"><?php esc_html_e( '設定する', 'payroll-system' ); ?></button>
			</form>
			<p class="description"><?php esc_html_e( '任意の文字列をそのままログインパスワードとして設定します（仮パスワードのようなランダム生成ではありません）。設定後、次回ログイン時の変更は求められません。', 'payroll-system' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'ps_save_employee' ); ?>
		<input type="hidden" name="action" value="ps_save_employee">
		<input type="hidden" name="employee_id" value="<?php echo esc_attr( $employee->id ?? 0 ); ?>">

		<table class="form-table">
			<tr>
				<th><label for="employee_code"><?php esc_html_e( '社員コード', 'payroll-system' ); ?> <span class="required">*</span></label></th>
				<td>
					<input type="text" id="employee_code" name="employee_code" class="regular-text"
						value="<?php echo esc_attr( ps_field_value( 'employee_code', $form_input, $employee ) ); ?>" required>
					<p class="description"><?php esc_html_e( '勤怠システム側の社員コードと一致させてください（CSV取込時の突合キーになります）。', 'payroll-system' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="name"><?php esc_html_e( '氏名', 'payroll-system' ); ?> <span class="required">*</span></label></th>
				<td><input type="text" id="name" name="name" class="regular-text"
					value="<?php echo esc_attr( ps_field_value( 'name', $form_input, $employee ) ); ?>" required></td>
			</tr>
			<tr>
				<th><label for="address"><?php esc_html_e( '住所', 'payroll-system' ); ?></label></th>
				<td><input type="text" id="address" name="address" class="regular-text"
					value="<?php echo esc_attr( ps_field_value( 'address', $form_input, $employee ) ); ?>"></td>
			</tr>
			<tr>
				<th><label for="phone"><?php esc_html_e( '電話番号', 'payroll-system' ); ?></label></th>
				<td><input type="text" id="phone" name="phone" class="regular-text"
					value="<?php echo esc_attr( ps_field_value( 'phone', $form_input, $employee ) ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( '雇用区分', 'payroll-system' ); ?></th>
				<td>
					<?php $employment_type = ps_field_value( 'employment_type', $form_input, $employee, 'employee' ); ?>
					<label><input type="radio" name="employment_type" value="employee" <?php checked( $employment_type, 'employee' ); ?>> <?php esc_html_e( '社員', 'payroll-system' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="radio" name="employment_type" value="part_timer" <?php checked( $employment_type, 'part_timer' ); ?>> <?php esc_html_e( 'アルバイト', 'payroll-system' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( '給与形態', 'payroll-system' ); ?></th>
				<td>
					<?php $pay_type = ps_field_value( 'pay_type', $form_input, $employee, 'monthly' ); ?>
					<label><input type="radio" name="pay_type" value="hourly" <?php checked( $pay_type, 'hourly' ); ?>> <?php esc_html_e( '時給', 'payroll-system' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="radio" name="pay_type" value="daily" <?php checked( $pay_type, 'daily' ); ?>> <?php esc_html_e( '日給', 'payroll-system' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="radio" name="pay_type" value="monthly" <?php checked( $pay_type, 'monthly' ); ?>> <?php esc_html_e( '月給', 'payroll-system' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><label for="pay_rate"><?php esc_html_e( '単価（時給／日給／月給）', 'payroll-system' ); ?></label></th>
				<td>
					<input type="number" step="1" min="0" id="pay_rate" name="pay_rate" class="regular-text"
						value="<?php echo esc_attr( ps_field_value( 'pay_rate', $form_input, $employee, 0 ) ); ?>"> <?php esc_html_e( '円', 'payroll-system' ); ?>
				</td>
			</tr>
			<tr>
				<th><label for="commuting_allowance"><?php esc_html_e( '通勤手当・非課税分（月額）', 'payroll-system' ); ?></label></th>
				<td>
					<input type="number" step="1" min="0" id="commuting_allowance" name="commuting_allowance" class="regular-text"
						value="<?php echo esc_attr( ps_field_value( 'commuting_allowance', $form_input, $employee, 0 ) ); ?>"> <?php esc_html_e( '円', 'payroll-system' ); ?>
				</td>
			</tr>
			<tr>
				<th><label for="commuting_allowance_taxable"><?php esc_html_e( '通勤手当・課税分（月額）', 'payroll-system' ); ?></label></th>
				<td>
					<input type="number" step="1" min="0" id="commuting_allowance_taxable" name="commuting_allowance_taxable" class="regular-text"
						value="<?php echo esc_attr( ps_field_value( 'commuting_allowance_taxable', $form_input, $employee, 0 ) ); ?>"> <?php esc_html_e( '円', 'payroll-system' ); ?>
				</td>
			</tr>
			<tr>
				<th><label for="housing_allowance"><?php esc_html_e( '住宅手当（月額）', 'payroll-system' ); ?></label></th>
				<td>
					<input type="number" step="1" min="0" id="housing_allowance" name="housing_allowance" class="regular-text"
						value="<?php echo esc_attr( ps_field_value( 'housing_allowance', $form_input, $employee, 0 ) ); ?>"> <?php esc_html_e( '円', 'payroll-system' ); ?>
				</td>
			</tr>
			<tr>
				<th><label for="position_allowance"><?php esc_html_e( '役職手当（月額）', 'payroll-system' ); ?></label></th>
				<td>
					<input type="number" step="1" min="0" id="position_allowance" name="position_allowance" class="regular-text"
						value="<?php echo esc_attr( ps_field_value( 'position_allowance', $form_input, $employee, 0 ) ); ?>"> <?php esc_html_e( '円', 'payroll-system' ); ?>
				</td>
			</tr>
			<tr>
				<th><label for="family_allowance"><?php esc_html_e( '家族手当（月額）', 'payroll-system' ); ?></label></th>
				<td>
					<input type="number" step="1" min="0" id="family_allowance" name="family_allowance" class="regular-text"
						value="<?php echo esc_attr( ps_field_value( 'family_allowance', $form_input, $employee, 0 ) ); ?>"> <?php esc_html_e( '円', 'payroll-system' ); ?>
				</td>
			</tr>
			<tr>
				<th><label for="paid_leave_granted_days"><?php esc_html_e( '有休付与日数（年間）', 'payroll-system' ); ?></label></th>
				<td>
					<input type="number" step="0.5" min="0" id="paid_leave_granted_days" name="paid_leave_granted_days" class="regular-text"
						value="<?php echo esc_attr( ps_field_value( 'paid_leave_granted_days', $form_input, $employee, 0 ) ); ?>"> <?php esc_html_e( '日', 'payroll-system' ); ?>
					<p class="description"><?php esc_html_e( '給与明細の「有休残日数」は、この日数から当年の勤怠取込済みの有休取得日数を差し引いて表示します。', 'payroll-system' ); ?></p>
				</td>
			</tr>

			<tr><th colspan="2"><h2 class="title"><?php esc_html_e( '振込先口座', 'payroll-system' ); ?></h2></th></tr>

			<tr>
				<th><label for="bank_name"><?php esc_html_e( '金融機関名', 'payroll-system' ); ?></label></th>
				<td><input type="text" id="bank_name" name="bank_name" class="regular-text" value="<?php echo esc_attr( ps_field_value( 'bank_name', $form_input, $employee, '' ) ); ?>"></td>
			</tr>
			<tr>
				<th><label for="bank_branch"><?php esc_html_e( '支店名', 'payroll-system' ); ?></label></th>
				<td><input type="text" id="bank_branch" name="bank_branch" class="regular-text" value="<?php echo esc_attr( ps_field_value( 'bank_branch', $form_input, $employee, '' ) ); ?>"></td>
			</tr>
			<tr>
				<th><label for="bank_account_type"><?php esc_html_e( '口座種別', 'payroll-system' ); ?></label></th>
				<td>
					<?php $current_account_type = ps_field_value( 'bank_account_type', $form_input, $employee, 'ordinary' ); ?>
					<select id="bank_account_type" name="bank_account_type">
						<option value="ordinary" <?php selected( $current_account_type, 'ordinary' ); ?>><?php esc_html_e( '普通', 'payroll-system' ); ?></option>
						<option value="checking" <?php selected( $current_account_type, 'checking' ); ?>><?php esc_html_e( '当座', 'payroll-system' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="bank_account_number"><?php esc_html_e( '口座番号', 'payroll-system' ); ?></label></th>
				<td><input type="text" id="bank_account_number" name="bank_account_number" class="regular-text" value="<?php echo esc_attr( ps_field_value( 'bank_account_number', $form_input, $employee, '' ) ); ?>"></td>
			</tr>
			<tr>
				<th><label for="bank_account_holder"><?php esc_html_e( '口座名義', 'payroll-system' ); ?></label></th>
				<td><input type="text" id="bank_account_holder" name="bank_account_holder" class="regular-text" value="<?php echo esc_attr( ps_field_value( 'bank_account_holder', $form_input, $employee, '' ) ); ?>"></td>
			</tr>

			<tr><th colspan="2"><h2 class="title"><?php esc_html_e( '社会保険・税金関連', 'payroll-system' ); ?></h2></th></tr>

			<tr>
				<th><?php esc_html_e( '社会保険加入区分', 'payroll-system' ); ?></th>
				<td>
					<?php $sis = ps_field_value( 'social_insurance_status', $form_input, $employee, 'not_enrolled' ); ?>
					<label><input type="radio" name="social_insurance_status" value="enrolled" <?php checked( $sis, 'enrolled' ); ?>> <?php esc_html_e( '加入', 'payroll-system' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="radio" name="social_insurance_status" value="not_enrolled" <?php checked( $sis, 'not_enrolled' ); ?>> <?php esc_html_e( '未加入', 'payroll-system' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><label for="prefecture"><?php esc_html_e( '都道府県（健康保険料率参照用）', 'payroll-system' ); ?></label></th>
				<td>
					<?php $prefecture = ps_field_value( 'prefecture', $form_input, $employee ); ?>
					<select id="prefecture" name="prefecture">
						<option value=""><?php esc_html_e( '－ 選択してください －', 'payroll-system' ); ?></option>
						<?php foreach ( PS_Employee::get_prefectures() as $pref ) : ?>
							<option value="<?php echo esc_attr( $pref ); ?>" <?php selected( $prefecture, $pref ); ?>><?php echo esc_html( $pref ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="standard_monthly_remuneration_grade"><?php esc_html_e( '標準報酬月額等級', 'payroll-system' ); ?></label></th>
				<td>
					<input type="number" step="1" min="1" id="standard_monthly_remuneration_grade" name="standard_monthly_remuneration_grade" class="small-text"
						value="<?php echo esc_attr( ps_field_value( 'standard_monthly_remuneration_grade', $form_input, $employee ) ); ?>">
					<p class="description"><?php esc_html_e( '年1回の定時決定、または随時改定のタイミングで担当者が更新してください。', 'payroll-system' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="dependents_count"><?php esc_html_e( '扶養人数', 'payroll-system' ); ?></label></th>
				<td><input type="number" step="1" min="0" max="7" id="dependents_count" name="dependents_count" class="small-text"
					value="<?php echo esc_attr( ps_field_value( 'dependents_count', $form_input, $employee, 0 ) ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( '源泉徴収 甲欄／乙欄', 'payroll-system' ); ?></th>
				<td>
					<?php $tax_column = ps_field_value( 'tax_column', $form_input, $employee, 'ko' ); ?>
					<label><input type="radio" name="tax_column" value="ko" <?php checked( $tax_column, 'ko' ); ?>> <?php esc_html_e( '甲欄（扶養控除等申告書 提出あり）', 'payroll-system' ); ?></label><br>
					<label><input type="radio" name="tax_column" value="otsu" <?php checked( $tax_column, 'otsu' ); ?>> <?php esc_html_e( '乙欄（掛け持ち勤務等・提出なし）', 'payroll-system' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><label for="resident_tax_monthly"><?php esc_html_e( '住民税（月額）', 'payroll-system' ); ?></label></th>
				<td>
					<input type="number" step="1" min="0" id="resident_tax_monthly" name="resident_tax_monthly" class="regular-text"
						value="<?php echo esc_attr( ps_field_value( 'resident_tax_monthly', $form_input, $employee, 0 ) ); ?>"> <?php esc_html_e( '円', 'payroll-system' ); ?>
					<p class="description"><?php esc_html_e( '市区町村から届く特別徴収税額決定通知書の金額を入力してください（毎年6月更新）。', 'payroll-system' ); ?></p>
				</td>
			</tr>

			<tr><th colspan="2"><h2 class="title"><?php esc_html_e( 'その他', 'payroll-system' ); ?></h2></th></tr>

			<tr>
				<th><label for="birth_date"><?php esc_html_e( '生年月日', 'payroll-system' ); ?></label></th>
				<td><input type="date" id="birth_date" name="birth_date"
					value="<?php echo esc_attr( ps_field_value( 'birth_date', $form_input, $employee ) ); ?>">
					<p class="description"><?php esc_html_e( '40〜64歳の介護保険料判定に使用します。', 'payroll-system' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="hire_date"><?php esc_html_e( '入社日', 'payroll-system' ); ?></label></th>
				<td><input type="date" id="hire_date" name="hire_date"
					value="<?php echo esc_attr( ps_field_value( 'hire_date', $form_input, $employee ) ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( '状態', 'payroll-system' ); ?></th>
				<td>
					<?php $status = ps_field_value( 'status', $form_input, $employee, 'active' ); ?>
					<label><input type="radio" name="status" value="active" <?php checked( $status, 'active' ); ?>> <?php esc_html_e( '在職中', 'payroll-system' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="radio" name="status" value="retired" <?php checked( $status, 'retired' ); ?>> <?php esc_html_e( '退職済み', 'payroll-system' ); ?></label>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( '保存する', 'payroll-system' ); ?></button>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=payroll-system-employees' ) ); ?>" class="button"><?php esc_html_e( 'キャンセル', 'payroll-system' ); ?></a>
		</p>
	</form>

<?php else : ?>

	<h1 class="wp-heading-inline"><?php esc_html_e( '従業員管理', 'payroll-system' ); ?></h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=payroll-system-employees&action=add' ) ); ?>" class="page-title-action">
		<?php esc_html_e( '新規追加', 'payroll-system' ); ?>
	</a>
	<hr class="wp-header-end">

	<?php if ( 'created' === $message ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '従業員を登録しました。', 'payroll-system' ); ?></p></div>
	<?php elseif ( 'updated' === $message ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '従業員情報を更新しました。', 'payroll-system' ); ?></p></div>
	<?php elseif ( 'deleted' === $message ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '従業員を削除しました。', 'payroll-system' ); ?></p></div>
	<?php elseif ( 'delete_failed' === $message ) : ?>
		<div class="notice notice-error is-dismissible">
			<p><?php echo esc_html( isset( $_GET['reason'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['reason'] ) ) ) : __( '削除に失敗しました。', 'payroll-system' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p>
		</div>
	<?php endif; ?>

	<?php
	$employees = PS_Employee::get_all();
	?>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( '社員コード', 'payroll-system' ); ?></th>
				<th><?php esc_html_e( '氏名', 'payroll-system' ); ?></th>
				<th><?php esc_html_e( '雇用区分', 'payroll-system' ); ?></th>
				<th><?php esc_html_e( '給与形態', 'payroll-system' ); ?></th>
				<th><?php esc_html_e( '単価', 'payroll-system' ); ?></th>
				<th><?php esc_html_e( '社会保険', 'payroll-system' ); ?></th>
				<th><?php esc_html_e( '状態', 'payroll-system' ); ?></th>
				<th><?php esc_html_e( '操作', 'payroll-system' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $employees ) ) : ?>
			<tr>
				<td colspan="8"><?php esc_html_e( '従業員が登録されていません。「新規追加」から登録してください。', 'payroll-system' ); ?></td>
			</tr>
		<?php else : ?>
			<?php
			$pay_type_labels = array( 'hourly' => __( '時給', 'payroll-system' ), 'daily' => __( '日給', 'payroll-system' ), 'monthly' => __( '月給', 'payroll-system' ) );
			$employment_labels = array( 'employee' => __( '社員', 'payroll-system' ), 'part_timer' => __( 'アルバイト', 'payroll-system' ) );
			?>
			<?php foreach ( $employees as $emp ) : ?>
				<tr>
					<td><?php echo esc_html( $emp->employee_code ); ?></td>
					<td>
						<strong>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=payroll-system-employees&action=edit&id=' . $emp->id ) ); ?>">
								<?php echo esc_html( $emp->name ); ?>
							</a>
						</strong>
					</td>
					<td><?php echo esc_html( $employment_labels[ $emp->employment_type ] ?? $emp->employment_type ); ?></td>
					<td><?php echo esc_html( $pay_type_labels[ $emp->pay_type ] ?? $emp->pay_type ); ?></td>
					<td><?php echo esc_html( number_format( (float) $emp->pay_rate ) ); ?> <?php esc_html_e( '円', 'payroll-system' ); ?></td>
					<td><?php echo 'enrolled' === $emp->social_insurance_status ? esc_html__( '加入', 'payroll-system' ) : esc_html__( '未加入', 'payroll-system' ); ?></td>
					<td>
						<?php if ( 'active' === $emp->status ) : ?>
							<span style="color:#1a7e34;">●</span> <?php esc_html_e( '在職中', 'payroll-system' ); ?>
						<?php else : ?>
							<span style="color:#999;">●</span> <?php esc_html_e( '退職済み', 'payroll-system' ); ?>
						<?php endif; ?>
					</td>
					<td>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=payroll-system-employees&action=edit&id=' . $emp->id ) ); ?>">
							<?php esc_html_e( '編集', 'payroll-system' ); ?>
						</a>
						|
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ps_delete_employee&id=' . $emp->id ), 'ps_delete_employee_' . $emp->id ) ); ?>"
						   onclick="return confirm('<?php echo esc_js( __( '本当に削除しますか？（給与計算履歴がある場合は削除できません）', 'payroll-system' ) ); ?>');"
						   style="color:#b32d2e;">
							<?php esc_html_e( '削除', 'payroll-system' ); ?>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

<?php endif; ?>

</div>
