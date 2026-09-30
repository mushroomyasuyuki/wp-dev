<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 固定ページに [payroll_employees] を埋め込むと、従業員の一覧・登録・編集ができる。
 *
 * wp-admin側の admin/views/employees.php と同じ業務ロジック（PS_Employee, PS_Employee_Controller）を
 * そのまま利用し、見た目とガード処理だけをフロント向けに差し替えている。
 *
 * 使い方：
 *   [payroll_employees login_page="https://example.com/payroll-login/"]
 */
class PS_Frontend_Employees {

	public function register_hooks() {
		add_shortcode( 'payroll_employees', array( $this, 'render' ) );
	}

	public function render( $atts ): string {
		$atts = shortcode_atts( array(
			'login_page'     => home_url( '/' ),
			'dashboard_page' => home_url( '/' ),
		), $atts, 'payroll_employees' );

		$guard_html = PS_Frontend_Auth::guard( $atts['login_page'] );
		if ( null !== $guard_html ) {
			return $guard_html;
		}

		$action  = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = isset( $_GET['message'] ) ? sanitize_key( $_GET['message'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$user_id     = get_current_user_id();
		$form_errors = get_transient( 'ps_employee_form_errors_' . $user_id );
		$form_input  = get_transient( 'ps_employee_form_input_' . $user_id );
		delete_transient( 'ps_employee_form_errors_' . $user_id );
		delete_transient( 'ps_employee_form_input_' . $user_id );

		// このページ自身のURL（クエリ抜き）。コントローラーのリダイレクト先として渡す。
		$base_url = get_permalink();

		$employee = null;
		if ( 'edit' === $action && $edit_id > 0 ) {
			$employee = PS_Employee::find( $edit_id );
			if ( ! $employee ) {
				$action = 'list';
			}
		}

		$temp_password = null;
		if ( 'temp_password_issued' === $message && $edit_id > 0 ) {
			$temp_password = get_transient( 'ps_temp_password_' . $user_id . '_' . $edit_id );
			delete_transient( 'ps_temp_password_' . $user_id . '_' . $edit_id );
		}

		ob_start();
		?>
		<p class="ps-back-link"><a href="<?php echo esc_url( $atts['dashboard_page'] ); ?>">&larr; <?php esc_html_e( 'ダッシュボードに戻る', 'payroll-system' ); ?></a></p>
		<?php
		if ( 'add' === $action || 'edit' === $action ) {
			$this->render_form( $action, $employee, $form_input, $form_errors, $base_url, $temp_password );
		} else {
			$this->render_list( $message, $base_url );
		}

		return ob_get_clean();
	}

	private function field_value( $key, $form_input, $employee, $default = '' ) {
		if ( is_array( $form_input ) && isset( $form_input[ $key ] ) ) {
			return $form_input[ $key ];
		}
		if ( $employee && isset( $employee->$key ) ) {
			return $employee->$key;
		}
		return $default;
	}

	private function render_form( $action, $employee, $form_input, $form_errors, $base_url, $temp_password = null ) {
		?>
		<div class="ps-frontend ps-frontend-employees">
			<h2><?php echo 'edit' === $action ? esc_html__( '従業員を編集', 'payroll-system' ) : esc_html__( '従業員を新規登録', 'payroll-system' ); ?></h2>

			<?php if ( ! empty( $form_errors ) ) : ?>
				<div class="ps-notice ps-notice-error">
					<ul>
						<?php foreach ( $form_errors as $error ) : ?>
							<li><?php echo esc_html( $error ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( $temp_password ) : ?>
				<div class="ps-notice ps-notice-success">
					<p>
						<strong><?php esc_html_e( '仮パスワードを発行しました：', 'payroll-system' ); ?></strong>
						<code style="font-size:1.2em;"><?php echo esc_html( $temp_password ); ?></code><br>
						<?php esc_html_e( 'このパスワードは二度と表示されません。従業員本人に直接お伝えください。従業員は次回ログイン時にパスワードの変更を求められます。', 'payroll-system' ); ?>
					</p>
				</div>
			<?php elseif ( 'password_set' === $message ) : ?>
				<div class="ps-notice ps-notice-success">
					<p><?php esc_html_e( 'パスワードを設定しました。このパスワードのまま（変更を求められずに）ログインできます。', 'payroll-system' ); ?></p>
				</div>
			<?php elseif ( 'set_password_failed' === $message ) : ?>
				<div class="ps-notice ps-notice-error">
					<p><?php esc_html_e( 'パスワードを設定できませんでした。空欄になっていないか確認してください。', 'payroll-system' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( 'edit' === $action && $employee ) : ?>
				<p>
					<a class="ps-btn ps-btn-secondary"
					   href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'ps_issue_temp_password', 'id' => $employee->id, '_ps_redirect_base' => rawurlencode( $base_url ) ), admin_url( 'admin-post.php' ) ), 'ps_issue_temp_password_' . $employee->id ) ); ?>"
					   onclick="return confirm('<?php echo esc_js( __( '仮パスワードを再発行します。現在の従業員用ログインセッションはすべて無効になります。よろしいですか？', 'payroll-system' ) ); ?>');">
						<?php echo empty( $employee->password_hash ) ? esc_html__( '従業員用ログインの仮パスワードを発行する', 'payroll-system' ) : esc_html__( '仮パスワードを再発行する', 'payroll-system' ); ?>
					</a>
					<span class="ps-description">
						<?php
						if ( empty( $employee->password_hash ) ) {
							esc_html_e( '（未発行）', 'payroll-system' );
						} elseif ( ! empty( $employee->must_change_password ) ) {
							esc_html_e( '（仮パスワード発行済み・未変更）', 'payroll-system' );
						} else {
							esc_html_e( '（設定済み）', 'payroll-system' );
						}
						?>
					</span>
				</p>
				<p>
					<form method="post" class="ps-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-flex; align-items:center; gap:6px;">
						<?php wp_nonce_field( 'ps_set_employee_password_' . $employee->id ); ?>
						<input type="hidden" name="action" value="ps_set_employee_password">
						<input type="hidden" name="id" value="<?php echo esc_attr( $employee->id ); ?>">
						<input type="hidden" name="_ps_redirect_base" value="<?php echo esc_attr( $base_url ); ?>">
						<label for="new_password"><?php esc_html_e( 'パスワードを直接指定する：', 'payroll-system' ); ?></label>
						<input type="text" id="new_password" name="new_password" autocomplete="off" required>
						<button type="submit" class="ps-btn ps-btn-secondary"><?php esc_html_e( '設定する', 'payroll-system' ); ?></button>
					</form>
				</p>
			<?php endif; ?>

			<form method="post" class="ps-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ps_save_employee' ); ?>
				<input type="hidden" name="action" value="ps_save_employee">
				<input type="hidden" name="_ps_redirect_base" value="<?php echo esc_url( $base_url ); ?>">
				<input type="hidden" name="employee_id" value="<?php echo esc_attr( $employee->id ?? 0 ); ?>">

				<p class="ps-field">
					<label><?php esc_html_e( '社員コード', 'payroll-system' ); ?> <span class="ps-required">*</span></label>
					<input type="text" name="employee_code" required
						value="<?php echo esc_attr( $this->field_value( 'employee_code', $form_input, $employee ) ); ?>">
				</p>
				<p class="ps-field">
					<label><?php esc_html_e( '氏名', 'payroll-system' ); ?> <span class="ps-required">*</span></label>
					<input type="text" name="name" required
						value="<?php echo esc_attr( $this->field_value( 'name', $form_input, $employee ) ); ?>">
				</p>
				<p class="ps-field">
					<label><?php esc_html_e( '住所', 'payroll-system' ); ?></label>
					<input type="text" name="address"
						value="<?php echo esc_attr( $this->field_value( 'address', $form_input, $employee ) ); ?>">
				</p>
				<p class="ps-field">
					<label><?php esc_html_e( '電話番号', 'payroll-system' ); ?></label>
					<input type="text" name="phone"
						value="<?php echo esc_attr( $this->field_value( 'phone', $form_input, $employee ) ); ?>">
				</p>

				<p class="ps-field">
					<label><?php esc_html_e( '雇用区分', 'payroll-system' ); ?></label>
					<?php $employment_type = $this->field_value( 'employment_type', $form_input, $employee, 'employee' ); ?>
					<label class="ps-inline"><input type="radio" name="employment_type" value="employee" <?php checked( $employment_type, 'employee' ); ?>> <?php esc_html_e( '社員', 'payroll-system' ); ?></label>
					<label class="ps-inline"><input type="radio" name="employment_type" value="part_timer" <?php checked( $employment_type, 'part_timer' ); ?>> <?php esc_html_e( 'アルバイト', 'payroll-system' ); ?></label>
				</p>

				<p class="ps-field">
					<label><?php esc_html_e( '給与形態', 'payroll-system' ); ?></label>
					<?php $pay_type = $this->field_value( 'pay_type', $form_input, $employee, 'monthly' ); ?>
					<label class="ps-inline"><input type="radio" name="pay_type" value="hourly" <?php checked( $pay_type, 'hourly' ); ?>> <?php esc_html_e( '時給', 'payroll-system' ); ?></label>
					<label class="ps-inline"><input type="radio" name="pay_type" value="daily" <?php checked( $pay_type, 'daily' ); ?>> <?php esc_html_e( '日給', 'payroll-system' ); ?></label>
					<label class="ps-inline"><input type="radio" name="pay_type" value="monthly" <?php checked( $pay_type, 'monthly' ); ?>> <?php esc_html_e( '月給', 'payroll-system' ); ?></label>
				</p>

				<p class="ps-field">
					<label><?php esc_html_e( '単価（時給／日給／月給）', 'payroll-system' ); ?></label>
					<input type="number" min="0" step="1" name="pay_rate"
						value="<?php echo esc_attr( $this->field_value( 'pay_rate', $form_input, $employee, 0 ) ); ?>"> <?php esc_html_e( '円', 'payroll-system' ); ?>
				</p>
				<p class="ps-field">
					<label><?php esc_html_e( '通勤手当・非課税分（月額）', 'payroll-system' ); ?></label>
					<input type="number" min="0" step="1" name="commuting_allowance"
						value="<?php echo esc_attr( $this->field_value( 'commuting_allowance', $form_input, $employee, 0 ) ); ?>"> <?php esc_html_e( '円', 'payroll-system' ); ?>
				</p>
				<p class="ps-field">
					<label><?php esc_html_e( '通勤手当・課税分（月額）', 'payroll-system' ); ?></label>
					<input type="number" min="0" step="1" name="commuting_allowance_taxable"
						value="<?php echo esc_attr( $this->field_value( 'commuting_allowance_taxable', $form_input, $employee, 0 ) ); ?>"> <?php esc_html_e( '円', 'payroll-system' ); ?>
				</p>
				<p class="ps-field">
					<label><?php esc_html_e( '住宅手当（月額）', 'payroll-system' ); ?></label>
					<input type="number" min="0" step="1" name="housing_allowance"
						value="<?php echo esc_attr( $this->field_value( 'housing_allowance', $form_input, $employee, 0 ) ); ?>"> <?php esc_html_e( '円', 'payroll-system' ); ?>
				</p>
				<p class="ps-field">
					<label><?php esc_html_e( '役職手当（月額）', 'payroll-system' ); ?></label>
					<input type="number" min="0" step="1" name="position_allowance"
						value="<?php echo esc_attr( $this->field_value( 'position_allowance', $form_input, $employee, 0 ) ); ?>"> <?php esc_html_e( '円', 'payroll-system' ); ?>
				</p>
				<p class="ps-field">
					<label><?php esc_html_e( '家族手当（月額）', 'payroll-system' ); ?></label>
					<input type="number" min="0" step="1" name="family_allowance"
						value="<?php echo esc_attr( $this->field_value( 'family_allowance', $form_input, $employee, 0 ) ); ?>"> <?php esc_html_e( '円', 'payroll-system' ); ?>
				</p>
				<p class="ps-field">
					<label><?php esc_html_e( '有休付与日数（年間）', 'payroll-system' ); ?></label>
					<input type="number" min="0" step="0.5" name="paid_leave_granted_days"
						value="<?php echo esc_attr( $this->field_value( 'paid_leave_granted_days', $form_input, $employee, 0 ) ); ?>"> <?php esc_html_e( '日', 'payroll-system' ); ?>
				</p>

				<h3><?php esc_html_e( '振込先口座', 'payroll-system' ); ?></h3>

				<p class="ps-field">
					<label><?php esc_html_e( '金融機関名', 'payroll-system' ); ?></label>
					<input type="text" name="bank_name" value="<?php echo esc_attr( $this->field_value( 'bank_name', $form_input, $employee, '' ) ); ?>">
				</p>
				<p class="ps-field">
					<label><?php esc_html_e( '支店名', 'payroll-system' ); ?></label>
					<input type="text" name="bank_branch" value="<?php echo esc_attr( $this->field_value( 'bank_branch', $form_input, $employee, '' ) ); ?>">
				</p>
				<p class="ps-field">
					<label><?php esc_html_e( '口座種別', 'payroll-system' ); ?></label>
					<?php $current_account_type = $this->field_value( 'bank_account_type', $form_input, $employee, 'ordinary' ); ?>
					<select name="bank_account_type">
						<option value="ordinary" <?php selected( $current_account_type, 'ordinary' ); ?>><?php esc_html_e( '普通', 'payroll-system' ); ?></option>
						<option value="checking" <?php selected( $current_account_type, 'checking' ); ?>><?php esc_html_e( '当座', 'payroll-system' ); ?></option>
					</select>
				</p>
				<p class="ps-field">
					<label><?php esc_html_e( '口座番号', 'payroll-system' ); ?></label>
					<input type="text" name="bank_account_number" value="<?php echo esc_attr( $this->field_value( 'bank_account_number', $form_input, $employee, '' ) ); ?>">
				</p>
				<p class="ps-field">
					<label><?php esc_html_e( '口座名義', 'payroll-system' ); ?></label>
					<input type="text" name="bank_account_holder" value="<?php echo esc_attr( $this->field_value( 'bank_account_holder', $form_input, $employee, '' ) ); ?>">
				</p>

				<h3><?php esc_html_e( '社会保険・税金関連', 'payroll-system' ); ?></h3>

				<p class="ps-field">
					<label><?php esc_html_e( '社会保険加入区分', 'payroll-system' ); ?></label>
					<?php $sis = $this->field_value( 'social_insurance_status', $form_input, $employee, 'not_enrolled' ); ?>
					<label class="ps-inline"><input type="radio" name="social_insurance_status" value="enrolled" <?php checked( $sis, 'enrolled' ); ?>> <?php esc_html_e( '加入', 'payroll-system' ); ?></label>
					<label class="ps-inline"><input type="radio" name="social_insurance_status" value="not_enrolled" <?php checked( $sis, 'not_enrolled' ); ?>> <?php esc_html_e( '未加入', 'payroll-system' ); ?></label>
				</p>
				<p class="ps-field">
					<label><?php esc_html_e( '都道府県（健康保険料率参照用）', 'payroll-system' ); ?></label>
					<?php $prefecture = $this->field_value( 'prefecture', $form_input, $employee ); ?>
					<select name="prefecture">
						<option value=""><?php esc_html_e( '－ 選択してください －', 'payroll-system' ); ?></option>
						<?php foreach ( PS_Employee::get_prefectures() as $pref ) : ?>
							<option value="<?php echo esc_attr( $pref ); ?>" <?php selected( $prefecture, $pref ); ?>><?php echo esc_html( $pref ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="ps-field">
					<label><?php esc_html_e( '標準報酬月額等級', 'payroll-system' ); ?></label>
					<input type="number" min="1" step="1" name="standard_monthly_remuneration_grade"
						value="<?php echo esc_attr( $this->field_value( 'standard_monthly_remuneration_grade', $form_input, $employee ) ); ?>">
				</p>
				<p class="ps-field">
					<label><?php esc_html_e( '扶養人数', 'payroll-system' ); ?></label>
					<input type="number" min="0" max="7" step="1" name="dependents_count"
						value="<?php echo esc_attr( $this->field_value( 'dependents_count', $form_input, $employee, 0 ) ); ?>">
				</p>
				<p class="ps-field">
					<label><?php esc_html_e( '源泉徴収 甲欄／乙欄', 'payroll-system' ); ?></label>
					<?php $tax_column = $this->field_value( 'tax_column', $form_input, $employee, 'ko' ); ?>
					<label class="ps-inline"><input type="radio" name="tax_column" value="ko" <?php checked( $tax_column, 'ko' ); ?>> <?php esc_html_e( '甲欄', 'payroll-system' ); ?></label>
					<label class="ps-inline"><input type="radio" name="tax_column" value="otsu" <?php checked( $tax_column, 'otsu' ); ?>> <?php esc_html_e( '乙欄', 'payroll-system' ); ?></label>
				</p>
				<p class="ps-field">
					<label><?php esc_html_e( '住民税（月額）', 'payroll-system' ); ?></label>
					<input type="number" min="0" step="1" name="resident_tax_monthly"
						value="<?php echo esc_attr( $this->field_value( 'resident_tax_monthly', $form_input, $employee, 0 ) ); ?>"> <?php esc_html_e( '円', 'payroll-system' ); ?>
				</p>

				<h3><?php esc_html_e( 'その他', 'payroll-system' ); ?></h3>

				<p class="ps-field">
					<label><?php esc_html_e( '生年月日', 'payroll-system' ); ?></label>
					<input type="date" name="birth_date" value="<?php echo esc_attr( $this->field_value( 'birth_date', $form_input, $employee ) ); ?>">
				</p>
				<p class="ps-field">
					<label><?php esc_html_e( '入社日', 'payroll-system' ); ?></label>
					<input type="date" name="hire_date" value="<?php echo esc_attr( $this->field_value( 'hire_date', $form_input, $employee ) ); ?>">
				</p>
				<p class="ps-field">
					<label><?php esc_html_e( '状態', 'payroll-system' ); ?></label>
					<?php $status = $this->field_value( 'status', $form_input, $employee, 'active' ); ?>
					<label class="ps-inline"><input type="radio" name="status" value="active" <?php checked( $status, 'active' ); ?>> <?php esc_html_e( '在職中', 'payroll-system' ); ?></label>
					<label class="ps-inline"><input type="radio" name="status" value="retired" <?php checked( $status, 'retired' ); ?>> <?php esc_html_e( '退職済み', 'payroll-system' ); ?></label>
				</p>

				<p class="ps-submit">
					<button type="submit" class="ps-btn ps-btn-primary"><?php esc_html_e( '保存する', 'payroll-system' ); ?></button>
					<a class="ps-btn ps-btn-secondary" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'キャンセル', 'payroll-system' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	private function render_list( $message, $base_url ) {
		$employees = PS_Employee::get_all();
		?>
		<div class="ps-frontend ps-frontend-employees">
			<div class="ps-list-header">
				<h2><?php esc_html_e( '従業員管理', 'payroll-system' ); ?></h2>
				<a class="ps-btn ps-btn-primary" href="<?php echo esc_url( add_query_arg( 'action', 'add', $base_url ) ); ?>">
					<?php esc_html_e( '新規追加', 'payroll-system' ); ?>
				</a>
			</div>

			<?php if ( 'created' === $message ) : ?>
				<p class="ps-notice ps-notice-success"><?php esc_html_e( '従業員を登録しました。', 'payroll-system' ); ?></p>
			<?php elseif ( 'updated' === $message ) : ?>
				<p class="ps-notice ps-notice-success"><?php esc_html_e( '従業員情報を更新しました。', 'payroll-system' ); ?></p>
			<?php elseif ( 'deleted' === $message ) : ?>
				<p class="ps-notice ps-notice-success"><?php esc_html_e( '従業員を削除しました。', 'payroll-system' ); ?></p>
			<?php elseif ( 'delete_failed' === $message ) : ?>
				<p class="ps-notice ps-notice-error">
					<?php echo esc_html( isset( $_GET['reason'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['reason'] ) ) ) : __( '削除に失敗しました。', 'payroll-system' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				</p>
			<?php endif; ?>

			<table class="ps-table">
				<thead>
					<tr>
						<th><?php esc_html_e( '社員コード', 'payroll-system' ); ?></th>
						<th><?php esc_html_e( '氏名', 'payroll-system' ); ?></th>
						<th><?php esc_html_e( '雇用区分', 'payroll-system' ); ?></th>
						<th><?php esc_html_e( '給与形態', 'payroll-system' ); ?></th>
						<th><?php esc_html_e( '単価', 'payroll-system' ); ?></th>
						<th><?php esc_html_e( '状態', 'payroll-system' ); ?></th>
						<th><?php esc_html_e( '操作', 'payroll-system' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $employees ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( '従業員が登録されていません。', 'payroll-system' ); ?></td></tr>
				<?php else : ?>
					<?php
					$pay_type_labels   = array( 'hourly' => __( '時給', 'payroll-system' ), 'daily' => __( '日給', 'payroll-system' ), 'monthly' => __( '月給', 'payroll-system' ) );
					$employment_labels = array( 'employee' => __( '社員', 'payroll-system' ), 'part_timer' => __( 'アルバイト', 'payroll-system' ) );
					?>
					<?php foreach ( $employees as $emp ) : ?>
						<?php
						$edit_url   = add_query_arg( array( 'action' => 'edit', 'id' => $emp->id ), $base_url );
						$delete_url = wp_nonce_url(
							add_query_arg( array(
								'action'            => 'ps_delete_employee',
								'id'                => $emp->id,
								'_ps_redirect_base' => rawurlencode( $base_url ),
							), admin_url( 'admin-post.php' ) ),
							'ps_delete_employee_' . $emp->id
						);
						?>
						<tr>
							<td><?php echo esc_html( $emp->employee_code ); ?></td>
							<td><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $emp->name ); ?></a></td>
							<td><?php echo esc_html( $employment_labels[ $emp->employment_type ] ?? $emp->employment_type ); ?></td>
							<td><?php echo esc_html( $pay_type_labels[ $emp->pay_type ] ?? $emp->pay_type ); ?></td>
							<td><?php echo esc_html( number_format( (float) $emp->pay_rate ) ); ?> <?php esc_html_e( '円', 'payroll-system' ); ?></td>
							<td><?php echo 'active' === $emp->status ? esc_html__( '在職中', 'payroll-system' ) : esc_html__( '退職済み', 'payroll-system' ); ?></td>
							<td>
								<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( '編集', 'payroll-system' ); ?></a>
								|
								<a href="<?php echo esc_url( $delete_url ); ?>" class="ps-delete-link"
								   onclick="return confirm('<?php echo esc_js( __( '本当に削除しますか？（給与計算履歴がある場合は削除できません）', 'payroll-system' ) ); ?>');">
									<?php esc_html_e( '削除', 'payroll-system' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
