<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 固定ページに [payroll_attendance_import] を埋め込むと、勤怠CSV取込の
 * 4ステップウィザードをフロントで操作できる。
 *
 * wp-admin側の admin/views/attendance-import.php と同じ
 * PS_Attendance_Controller / PS_Attendance_Importer をそのまま利用し、
 * 見た目とガード処理だけをフロント向けに差し替えている。フォームの隠しフィールド
 * _ps_redirect_base に「このページ自身のURL」を渡すことで、ステップ間の
 * リダイレクトがwp-adminではなくこの固定ページに戻ってくるようにしている。
 *
 * 使い方：
 *   [payroll_attendance_import login_page="https://example.com/payroll-login/"]
 */
class PS_Frontend_Attendance_Import {

	public function register_hooks() {
		add_shortcode( 'payroll_attendance_import', array( $this, 'render' ) );
	}

	public function render( $atts ): string {
		$atts = shortcode_atts( array(
			'login_page'     => home_url( '/' ),
			'dashboard_page' => home_url( '/' ),
		), $atts, 'payroll_attendance_import' );

		$guard_html = PS_Frontend_Auth::guard( $atts['login_page'] );
		if ( null !== $guard_html ) {
			return $guard_html;
		}

		$base_url = get_permalink();
		$step     = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : 'upload'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state    = PS_Attendance_Controller::get_current_state();
		$error    = PS_Attendance_Controller::pull_error();

		if ( in_array( $step, array( 'mapping', 'preview' ), true ) && empty( $state['file_path'] ) ) {
			$step = 'upload';
		}

		ob_start();
		?>
		<div class="ps-frontend ps-frontend-attendance-import">
			<p class="ps-back-link"><a href="<?php echo esc_url( $atts['dashboard_page'] ); ?>">&larr; <?php esc_html_e( 'ダッシュボードに戻る', 'payroll-system' ); ?></a></p>
			<h2><?php esc_html_e( '勤怠データ取込', 'payroll-system' ); ?></h2>

			<ol class="ps-import-steps">
				<li<?php echo 'upload' === $step ? ' class="ps-step-current"' : ''; ?>><?php esc_html_e( '① CSVアップロード', 'payroll-system' ); ?></li>
				<li<?php echo 'mapping' === $step ? ' class="ps-step-current"' : ''; ?>><?php esc_html_e( '② 列マッピング', 'payroll-system' ); ?></li>
				<li<?php echo 'preview' === $step ? ' class="ps-step-current"' : ''; ?>><?php esc_html_e( '③ プレビュー確認', 'payroll-system' ); ?></li>
				<li<?php echo 'done' === $step ? ' class="ps-step-current"' : ''; ?>><?php esc_html_e( '④ 完了', 'payroll-system' ); ?></li>
			</ol>

			<?php if ( $error ) : ?>
				<p class="ps-notice ps-notice-error"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>

			<?php if ( 'upload' === $step ) : ?>

				<form method="post" enctype="multipart/form-data" class="ps-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ps_attendance_upload' ); ?>
					<input type="hidden" name="action" value="ps_attendance_upload">
					<input type="hidden" name="_ps_redirect_base" value="<?php echo esc_url( $base_url ); ?>">

					<p class="ps-field">
						<label><?php esc_html_e( '対象年月', 'payroll-system' ); ?></label>
						<input type="month" name="target_year_month" required>
					</p>
					<p class="ps-field">
						<label><?php esc_html_e( 'CSVファイル', 'payroll-system' ); ?></label>
						<input type="file" name="attendance_csv" accept=".csv" required>
					</p>
					<p class="ps-field">
						<label><?php esc_html_e( '取込プロファイル', 'payroll-system' ); ?></label>
						<select name="import_profile">
							<option value=""><?php esc_html_e( '新規にマッピングを作成する', 'payroll-system' ); ?></option>
							<?php foreach ( PS_Import_Profile::get_all() as $profile ) : ?>
								<option value="<?php echo esc_attr( $profile->id ); ?>"><?php echo esc_html( $profile->profile_name ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>

					<p class="ps-submit">
						<button type="submit" class="ps-btn ps-btn-primary"><?php esc_html_e( '次へ（アップロード）', 'payroll-system' ); ?></button>
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

				<form method="post" class="ps-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ps_attendance_mapping' ); ?>
					<input type="hidden" name="action" value="ps_attendance_mapping">

					<table class="ps-table">
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
									<?php if ( $field['required'] ) : ?><span class="ps-required">*</span><?php endif; ?>
								</td>
								<td>
									<select name="map_<?php echo esc_attr( $field_key ); ?>">
										<option value=""><?php esc_html_e( '－ 使用しない（0として扱う） －', 'payroll-system' ); ?></option>
										<?php foreach ( $state['headers'] as $header ) : ?>
											<option value="<?php echo esc_attr( $header ); ?>"><?php echo esc_html( $header ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<p class="ps-submit">
						<button type="submit" class="ps-btn ps-btn-primary"><?php esc_html_e( '次へ（プレビュー確認）', 'payroll-system' ); ?></button>
					</p>
				</form>

				<p>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ps_attendance_cancel' ), 'ps_attendance_cancel' ) ); ?>" class="ps-btn-link">
						<?php esc_html_e( '取込を中止して最初からやり直す', 'payroll-system' ); ?>
					</a>
				</p>

			<?php elseif ( 'preview' === $step && ! empty( $state['preview'] ) ) : ?>

				<?php $preview = $state['preview']; ?>

				<p>
					<?php
					printf(
						/* translators: 1: OK件数 2: エラー件数 */
						esc_html__( '正常：%1$d件 ／ エラー：%2$d件（エラー行は取込対象から除外されます）', 'payroll-system' ),
						(int) $preview['ok_count'],
						(int) $preview['error_count']
					);
					?>
				</p>

				<table class="ps-table">
					<thead>
						<tr>
							<th><?php esc_html_e( '行', 'payroll-system' ); ?></th>
							<th><?php esc_html_e( '社員コード', 'payroll-system' ); ?></th>
							<th><?php esc_html_e( '氏名', 'payroll-system' ); ?></th>
							<th><?php esc_html_e( '状態', 'payroll-system' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $preview['rows'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['row_number'] ); ?></td>
							<td><?php echo esc_html( $row['employee_code'] ); ?></td>
							<td><?php echo esc_html( $row['employee_name'] ?: '—' ); ?></td>
							<td>
								<?php if ( 'ok' === $row['status'] ) : ?>
									<?php esc_html_e( '正常', 'payroll-system' ); ?>
								<?php else : ?>
									<?php echo esc_html( implode( ' / ', $row['messages'] ) ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<form method="post" class="ps-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ps_attendance_confirm' ); ?>
					<input type="hidden" name="action" value="ps_attendance_confirm">
					<button type="submit" class="ps-btn ps-btn-primary" <?php disabled( $preview['ok_count'], 0 ); ?>>
						<?php
						printf(
							/* translators: %d: 件数 */
							esc_html__( '正常な%d件を取込確定する', 'payroll-system' ),
							(int) $preview['ok_count']
						);
						?>
					</button>
				</form>

				<p>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ps_attendance_cancel' ), 'ps_attendance_cancel' ) ); ?>" class="ps-btn-link">
						<?php esc_html_e( '取込を中止して最初からやり直す', 'payroll-system' ); ?>
					</a>
				</p>

			<?php elseif ( 'done' === $step ) : ?>

				<p class="ps-notice ps-notice-success">
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

				<a href="<?php echo esc_url( $base_url ); ?>" class="ps-btn ps-btn-primary">
					<?php esc_html_e( '続けて別のCSVを取り込む', 'payroll-system' ); ?>
				</a>

			<?php else : ?>

				<p><?php esc_html_e( 'セッションが切れました。最初からやり直してください。', 'payroll-system' ); ?></p>
				<a href="<?php echo esc_url( $base_url ); ?>" class="ps-btn"><?php esc_html_e( '最初に戻る', 'payroll-system' ); ?></a>

			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
