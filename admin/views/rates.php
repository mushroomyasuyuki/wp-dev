<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$type_defs = PS_Rate_Master::get_type_defs();

$tabs = array();
foreach ( $type_defs as $key => $def ) {
	$tabs[ $key ] = $def['label'];
}

$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'health_insurance'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示切替のみ
if ( ! isset( $type_defs[ $current_tab ] ) ) {
	$current_tab = 'health_insurance';
}
$type_def = $type_defs[ $current_tab ];

$action  = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$edit_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$step    = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$user_id     = get_current_user_id();
$form_errors = get_transient( 'ps_rate_form_errors_' . $user_id );
$form_input  = get_transient( 'ps_rate_form_input_' . $user_id );
delete_transient( 'ps_rate_form_errors_' . $user_id );
delete_transient( 'ps_rate_form_input_' . $user_id );

$message      = isset( $_GET['message'] ) ? sanitize_key( $_GET['message'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$import_error = PS_Rate_Master_Controller::pull_error( $current_tab );
$import_state = PS_Rate_Master_Controller::get_current_state( $current_tab );

if ( 'preview' === $step && empty( $import_state['preview'] ) ) {
	$step = '';
}

$rate = null;
if ( 'edit' === $action && $edit_id > 0 ) {
	$rate = PS_Rate_Master::find( $current_tab, $edit_id );
	if ( ! $rate ) {
		$action = 'list';
	}
}

if ( ! function_exists( 'ps_rate_field_value' ) ) {
	function ps_rate_field_value( $key, $form_input, $rate, $default = '' ) {
		if ( is_array( $form_input ) && isset( $form_input[ $key ] ) ) {
			return $form_input[ $key ];
		}
		if ( $rate && isset( $rate->$key ) ) {
			return $rate->$key;
		}
		return $default;
	}
}
?>
<div class="wrap payroll-system-wrap">
	<h1><?php esc_html_e( '給与設定（マスタ）', 'payroll-system' ); ?></h1>

	<h2 class="nav-tab-wrapper">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'payroll-system-rates', 'tab' => $key ), admin_url( 'admin.php' ) ) ); ?>"
			   class="nav-tab <?php echo $current_tab === $key ? 'nav-tab-active' : ''; ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</h2>

	<div class="ps-rate-tab-content">

	<?php if ( 'imported' === $message ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				printf(
					/* translators: 1: 新規件数 2: 更新件数 3: スキップ件数 */
					esc_html__( 'CSVインポートが完了しました。新規%1$d件・更新%2$d件を登録し、%3$d件をエラーのためスキップしました。', 'payroll-system' ),
					isset( $_GET['inserted'] ) ? absint( $_GET['inserted'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					isset( $_GET['updated'] ) ? absint( $_GET['updated'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0 // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				);
				?>
			</p>
		</div>
	<?php elseif ( 'created' === $message ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '登録しました。', 'payroll-system' ); ?></p></div>
	<?php elseif ( 'updated' === $message ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '更新しました。', 'payroll-system' ); ?></p></div>
	<?php elseif ( 'deleted' === $message ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '削除しました。', 'payroll-system' ); ?></p></div>
	<?php elseif ( 'business_type_saved' === $message ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '事業区分を保存しました。', 'payroll-system' ); ?></p></div>
	<?php elseif ( 'delete_failed' === $message ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( '削除に失敗しました。', 'payroll-system' ); ?></p></div>
	<?php endif; ?>

	<?php if ( $import_error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $import_error ); ?></p></div>
	<?php endif; ?>

	<?php if ( 'add' === $action || 'edit' === $action ) : ?>

		<h2><?php echo 'edit' === $action ? esc_html__( '行を編集', 'payroll-system' ) : esc_html__( '行を新規追加', 'payroll-system' ); ?></h2>

		<?php if ( ! empty( $form_errors ) ) : ?>
			<div class="notice notice-error">
				<ul style="margin: 0.5em 0;">
					<?php foreach ( $form_errors as $error ) : ?>
						<li><?php echo esc_html( $error ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ps_save_rate' ); ?>
			<input type="hidden" name="action" value="ps_save_rate">
			<input type="hidden" name="type" value="<?php echo esc_attr( $current_tab ); ?>">
			<input type="hidden" name="rate_id" value="<?php echo esc_attr( $rate->id ?? 0 ); ?>">

			<table class="form-table">
				<?php foreach ( $type_def['fields'] as $field_key => $field ) : ?>
					<tr>
						<th>
							<label for="<?php echo esc_attr( $field_key ); ?>">
								<?php echo esc_html( $field['label'] ); ?>
								<?php if ( $field['required'] ) : ?><span class="required">*</span><?php endif; ?>
							</label>
						</th>
						<td>
							<?php $value = ps_rate_field_value( $field_key, $form_input, $rate ); ?>
							<?php if ( 'month' === $field['input'] ) : ?>
								<input type="month" id="<?php echo esc_attr( $field_key ); ?>" name="<?php echo esc_attr( $field_key ); ?>"
									value="<?php echo esc_attr( $value ); ?>" <?php echo $field['required'] ? 'required' : ''; ?>>
							<?php elseif ( 'select' === $field['input'] ) : ?>
								<select id="<?php echo esc_attr( $field_key ); ?>" name="<?php echo esc_attr( $field_key ); ?>">
									<option value=""><?php esc_html_e( '－ 選択してください －', 'payroll-system' ); ?></option>
									<?php foreach ( $field['options'] as $opt_value => $opt_label ) : ?>
										<option value="<?php echo esc_attr( $opt_value ); ?>" <?php selected( $value, $opt_value ); ?>>
											<?php echo esc_html( $opt_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input type="number" step="<?php echo esc_attr( $field['step'] ?? '1' ); ?>"
									id="<?php echo esc_attr( $field_key ); ?>" name="<?php echo esc_attr( $field_key ); ?>"
									class="regular-text" value="<?php echo esc_attr( $value ); ?>"
									<?php echo $field['required'] ? 'required' : ''; ?>>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( '保存する', 'payroll-system' ); ?></button>
				<a href="<?php echo esc_url( add_query_arg( 'tab', $current_tab, admin_url( 'admin.php?page=payroll-system-rates' ) ) ); ?>" class="button">
					<?php esc_html_e( 'キャンセル', 'payroll-system' ); ?>
				</a>
			</p>
		</form>

	<?php elseif ( 'preview' === $step ) : ?>

		<?php $preview = $import_state['preview']; ?>

		<p>
			<?php
			printf(
				/* translators: 1: OK件数 2: エラー件数 */
				esc_html__( '正常：%1$d件 ／ エラー：%2$d件（エラー行は取込対象から除外されます。適用年月・区分が既存行と一致する場合は上書き更新されます）', 'payroll-system' ),
				(int) $preview['ok_count'],
				(int) $preview['error_count']
			);
			?>
		</p>

		<div class="ps-rate-table-scroll">
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( '行', 'payroll-system' ); ?></th>
					<?php foreach ( $type_def['fields'] as $field ) : ?>
						<th><?php echo esc_html( $field['label'] ); ?></th>
					<?php endforeach; ?>
					<th><?php esc_html_e( '状態', 'payroll-system' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $preview['rows'] as $row ) : ?>
				<tr style="<?php echo 'error' === $row['status'] ? 'background:#fbeaea;' : ''; ?>">
					<td><?php echo esc_html( $row['row_number'] ); ?></td>
					<?php foreach ( $type_def['fields'] as $field_key => $field ) : ?>
						<td>
							<?php
							$display = $row['data'][ $field_key ] ?? '';
							if ( 'select' === $field['input'] && isset( $field['options'][ $display ] ) ) {
								$display = $field['options'][ $display ];
							}
							echo esc_html( $display );
							?>
						</td>
					<?php endforeach; ?>
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
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
			<?php wp_nonce_field( 'ps_rate_import_confirm' ); ?>
			<input type="hidden" name="action" value="ps_rate_import_confirm">
			<input type="hidden" name="type" value="<?php echo esc_attr( $current_tab ); ?>">
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
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ps_rate_import_cancel&type=' . $current_tab ), 'ps_rate_import_cancel' ) ); ?>" class="button-link" style="color:#b32d2e;">
				<?php esc_html_e( '取込を中止する', 'payroll-system' ); ?>
			</a>
		</p>

	<?php else : ?>

		<p>
			<?php
			printf(
				/* translators: %s: タブ名 */
				esc_html__( '「%s」の年度別マスタを管理します。適用開始年月ごとに履歴を保持し、対象年月時点で有効な行が給与計算に自動的に参照されます。', 'payroll-system' ),
				esc_html( $type_def['label'] )
			);
			?>
		</p>

		<?php if ( 'health_insurance' === $current_tab ) : ?>
			<div class="ps-rate-import-box">
				<h3><?php esc_html_e( '会社情報（給与明細PDFのヘッダーに表示）', 'payroll-system' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ps_save_company_settings' ); ?>
					<input type="hidden" name="action" value="ps_save_company_settings">
					<table class="form-table">
						<tr>
							<th><label for="company_name"><?php esc_html_e( '会社名', 'payroll-system' ); ?></label></th>
							<td><input type="text" id="company_name" name="company_name" class="regular-text" value="<?php echo esc_attr( get_option( 'payroll_system_company_name', '' ) ); ?>"></td>
						</tr>
						<tr>
							<th><label for="payment_day"><?php esc_html_e( '支給日（毎月何日）', 'payroll-system' ); ?></label></th>
							<td>
								<input type="number" id="payment_day" name="payment_day" min="1" max="31" style="width:80px;" value="<?php echo esc_attr( get_option( 'payroll_system_payment_day', 25 ) ); ?>">
								<?php esc_html_e( '日', 'payroll-system' ); ?>
							</td>
						</tr>
					</table>
					<button type="submit" class="button button-secondary"><?php esc_html_e( '保存する', 'payroll-system' ); ?></button>
				</form>
			</div>
		<?php endif; ?>

		<?php if ( 'employment_insurance' === $current_tab ) : ?>
			<div class="ps-rate-import-box">
				<h3><?php esc_html_e( 'この会社の事業区分', 'payroll-system' ); ?></h3>
				<p class="description">
					<?php esc_html_e( '雇用保険料の計算で使用する事業区分です（全従業員共通・会社単位の設定）。', 'payroll-system' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ps_save_business_type' ); ?>
					<input type="hidden" name="action" value="ps_save_business_type">
					<?php $current_business_type = get_option( 'payroll_system_business_type', 'general' ); ?>
					<select name="business_type">
						<?php foreach ( $type_def['fields']['business_type']['options'] as $opt_value => $opt_label ) : ?>
							<option value="<?php echo esc_attr( $opt_value ); ?>" <?php selected( $current_business_type, $opt_value ); ?>>
								<?php echo esc_html( $opt_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button button-secondary"><?php esc_html_e( '保存する', 'payroll-system' ); ?></button>
				</form>
			</div>
		<?php endif; ?>

		<?php $rows = PS_Rate_Master::get_all( $current_tab ); ?>

		<div class="ps-rate-import-box">
			<h3><?php esc_html_e( 'CSVインポート', 'payroll-system' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'ヘッダー行に列名（英語）が必要です。適用年月・区分が既存行と一致する場合は上書き更新されます。', 'payroll-system' ); ?>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ps_rate_template_download&type=' . $current_tab ), 'ps_rate_template_download_' . $current_tab ) ); ?>">
					<?php esc_html_e( 'テンプレートをダウンロード', 'payroll-system' ); ?>
				</a>
				<?php if ( ! empty( $rows ) ) : ?>
					／
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ps_rate_export_download&type=' . $current_tab ), 'ps_rate_export_download_' . $current_tab ) ); ?>">
						<?php esc_html_e( '現在のデータをCSVエクスポート', 'payroll-system' ); ?>
					</a>
					<span class="description"><?php esc_html_e( '（年次改定の配布・バックアップ用）', 'payroll-system' ); ?></span>
				<?php endif; ?>
			</p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ps_rate_import_upload' ); ?>
				<input type="hidden" name="action" value="ps_rate_import_upload">
				<input type="hidden" name="type" value="<?php echo esc_attr( $current_tab ); ?>">
				<input type="file" name="rate_csv" accept=".csv" required>
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'アップロードしてプレビュー', 'payroll-system' ); ?></button>
			</form>
		</div>

		<p class="submit">
			<a href="<?php echo esc_url( add_query_arg( array( 'tab' => $current_tab, 'action' => 'add' ), admin_url( 'admin.php?page=payroll-system-rates' ) ) ); ?>" class="button">
				<?php esc_html_e( '手動で1行追加', 'payroll-system' ); ?>
			</a>
		</p>

		<div class="ps-rate-table-scroll">
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<?php foreach ( $type_def['fields'] as $field ) : ?>
						<th><?php echo esc_html( $field['label'] ); ?></th>
					<?php endforeach; ?>
					<th><?php esc_html_e( '操作', 'payroll-system' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr>
					<td colspan="<?php echo esc_attr( count( $type_def['fields'] ) + 1 ); ?>">
						<?php esc_html_e( 'まだ登録がありません。CSVインポートまたは「手動で1行追加」から登録してください。', 'payroll-system' ); ?>
					</td>
				</tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<?php foreach ( $type_def['fields'] as $field_key => $field ) : ?>
							<td>
								<?php
								$display = $row->$field_key ?? '';
								if ( 'select' === $field['input'] && isset( $field['options'][ $display ] ) ) {
									$display = $field['options'][ $display ];
								}
								echo esc_html( $display );
								?>
							</td>
						<?php endforeach; ?>
						<td>
							<a href="<?php echo esc_url( add_query_arg( array( 'tab' => $current_tab, 'action' => 'edit', 'id' => $row->id ), admin_url( 'admin.php?page=payroll-system-rates' ) ) ); ?>">
								<?php esc_html_e( '編集', 'payroll-system' ); ?>
							</a>
							|
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ps_delete_rate&type=' . $current_tab . '&id=' . $row->id ), 'ps_delete_rate_' . $current_tab . '_' . $row->id ) ); ?>"
							   onclick="return confirm('<?php echo esc_js( __( '本当に削除しますか？', 'payroll-system' ) ); ?>');"
							   style="color:#b32d2e;">
								<?php esc_html_e( '削除', 'payroll-system' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		</div>

	<?php endif; ?>

	</div>
</div>
