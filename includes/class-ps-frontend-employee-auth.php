<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 従業員本人用のログイン・ログアウト・パスワード変更フォーム。
 * 認証処理自体はPS_Employee_Authに委譲し、ここでは画面（ショートコード）と
 * フォーム送信のハンドリングだけを担当する。
 *
 * 使い方（固定ページに設置するショートコード）：
 *   [payroll_employee_login redirect="https://example.com/my-slips/" change_password_page="https://example.com/change-password/"]
 *   [payroll_employee_change_password login_page="https://example.com/employee-login/"]
 *   [payroll_employee_logout redirect="https://example.com/employee-login/"]
 *
 * 「給与担当者」用の[payroll_login]（WordPressユーザー・wp_signon）とは
 * 完全に別のログイン主体であり、混同されないようショートコード名・cookie名を
 * 分けている。
 */
class PS_Frontend_Employee_Auth {

	public function register_hooks() {
		add_shortcode( 'payroll_employee_login', array( $this, 'render_login' ) );
		add_shortcode( 'payroll_employee_logout', array( $this, 'render_logout' ) );
		add_shortcode( 'payroll_employee_change_password', array( $this, 'render_change_password' ) );

		// フォーム送信処理はショートコードのレンダリングより前（init時点）に行い、
		// ヘッダー送信前にリダイレクト・cookie設定できるようにする。
		add_action( 'init', array( $this, 'maybe_handle_login_submit' ) );
		add_action( 'init', array( $this, 'maybe_handle_logout' ) );
		add_action( 'init', array( $this, 'maybe_handle_change_password_submit' ) );
	}

	/**
	 * [payroll_employee_login]
	 */
	public function render_login( $atts ): string {
		$atts = shortcode_atts( array(
			'redirect'              => home_url( '/' ),
			'change_password_page'  => home_url( '/' ),
		), $atts, 'payroll_employee_login' );

		$employee = PS_Employee_Auth::get_current_employee();
		if ( $employee ) {
			ob_start();
			?>
			<div class="ps-frontend ps-frontend-login">
				<p>
					<?php
					printf(
						/* translators: %s: 従業員氏名 */
						esc_html__( '%s としてログイン済みです。', 'payroll-system' ),
						esc_html( $employee->name )
					);
					?>
				</p>
				<p><a class="ps-btn ps-btn-primary" href="<?php echo esc_url( $atts['redirect'] ); ?>"><?php esc_html_e( '続ける', 'payroll-system' ); ?></a></p>
			</div>
			<?php
			return ob_get_clean();
		}

		$error = get_transient( 'ps_employee_login_error_' . self::visitor_key() );
		delete_transient( 'ps_employee_login_error_' . self::visitor_key() );

		ob_start();
		?>
		<div class="ps-frontend ps-frontend-login">
			<?php if ( ! empty( $_GET['ps_password_changed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示のみ ?>
				<p class="ps-notice ps-notice-success"><?php esc_html_e( 'パスワードを変更しました。新しいパスワードでログインしてください。', 'payroll-system' ); ?></p>
			<?php endif; ?>
			<?php if ( $error ) : ?>
				<p class="ps-notice ps-notice-error"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>

			<form method="post" class="ps-login-form">
				<?php wp_nonce_field( 'ps_employee_login' ); ?>
				<input type="hidden" name="ps_employee_login_submit" value="1">
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $atts['redirect'] ); ?>">
				<input type="hidden" name="change_password_page" value="<?php echo esc_attr( $atts['change_password_page'] ); ?>">

				<p class="ps-field">
					<label for="ps_employee_code"><?php esc_html_e( '社員コード', 'payroll-system' ); ?></label>
					<input type="text" id="ps_employee_code" name="ps_employee_code" required autocomplete="username">
				</p>
				<p class="ps-field">
					<label for="ps_employee_password"><?php esc_html_e( 'パスワード', 'payroll-system' ); ?></label>
					<input type="password" id="ps_employee_password" name="ps_employee_password" required autocomplete="current-password">
				</p>
				<p class="ps-submit">
					<button type="submit" class="ps-btn ps-btn-primary"><?php esc_html_e( 'ログイン', 'payroll-system' ); ?></button>
				</p>
				<p class="ps-description"><?php esc_html_e( '初回ログイン用の仮パスワードは、給与担当者にお問い合わせください。', 'payroll-system' ); ?></p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	public function maybe_handle_login_submit() {
		if ( empty( $_POST['ps_employee_login_submit'] ) ) {
			return;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'ps_employee_login' ) ) {
			wp_die( esc_html__( 'セキュリティ確認に失敗しました。ページを再読み込みして、もう一度お試しください。', 'payroll-system' ) );
		}

		$employee_code = isset( $_POST['ps_employee_code'] ) ? sanitize_text_field( wp_unslash( $_POST['ps_employee_code'] ) ) : '';
		$password      = isset( $_POST['ps_employee_password'] ) ? (string) wp_unslash( $_POST['ps_employee_password'] ) : ''; // パスワードはsanitize_text_fieldしない
		$redirect_to   = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : home_url( '/' );
		$change_password_page = isset( $_POST['change_password_page'] ) ? esc_url_raw( wp_unslash( $_POST['change_password_page'] ) ) : $redirect_to;

		$employee = PS_Employee_Auth::attempt_login( $employee_code, $password );

		if ( is_wp_error( $employee ) ) {
			set_transient( 'ps_employee_login_error_' . self::visitor_key(), $employee->get_error_message(), 60 );
			return; // フォームを再表示（エラーメッセージ付き）
		}

		$destination = ! empty( $employee->must_change_password ) ? $change_password_page : $redirect_to;
		wp_safe_redirect( $destination ?: home_url( '/' ) );
		exit;
	}

	/**
	 * [payroll_employee_logout]
	 */
	public function render_logout( $atts ): string {
		$atts = shortcode_atts( array(
			'redirect' => home_url( '/' ),
			'label'    => __( 'ログアウト', 'payroll-system' ),
		), $atts, 'payroll_employee_logout' );

		if ( ! PS_Employee_Auth::get_current_employee() ) {
			return '';
		}

		$url = add_query_arg( array(
			'ps_employee_logout' => '1',
			'redirect_to'         => rawurlencode( $atts['redirect'] ),
			'_wpnonce'            => wp_create_nonce( 'ps_employee_logout' ),
		), home_url( '/' ) );

		return sprintf(
			'<a class="ps-btn ps-btn-secondary ps-logout-link" href="%s">%s</a>',
			esc_url( $url ),
			esc_html( $atts['label'] )
		);
	}

	public function maybe_handle_logout() {
		if ( empty( $_GET['ps_employee_logout'] ) ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'ps_employee_logout' ) ) {
			wp_die( esc_html__( 'セキュリティ確認に失敗しました。', 'payroll-system' ) );
		}

		PS_Employee_Auth::logout();

		$redirect_to = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( rawurldecode( $_GET['redirect_to'] ) ) ) : home_url( '/' );
		wp_safe_redirect( $redirect_to );
		exit;
	}

	/**
	 * [payroll_employee_change_password]
	 * ログイン中（must_change_password=1で強制された場合を含む）の従業員がパスワードを変更する。
	 * 変更成功後は全セッションを失効させ、新しいパスワードで再ログインを促す
	 * （このページ自体も再ログインするまで閲覧できなくなる）。
	 */
	public function render_change_password( $atts ): string {
		$atts = shortcode_atts( array(
			'login_page' => home_url( '/' ),
		), $atts, 'payroll_employee_change_password' );

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

		$errors = get_transient( 'ps_change_password_errors_' . self::visitor_key() );
		delete_transient( 'ps_change_password_errors_' . self::visitor_key() );

		ob_start();
		?>
		<div class="ps-frontend ps-frontend-login">
			<h2><?php esc_html_e( 'パスワード変更', 'payroll-system' ); ?></h2>

			<?php if ( ! empty( $employee->must_change_password ) ) : ?>
				<p class="ps-notice ps-notice-error"><?php esc_html_e( '初回ログインのため、パスワードの変更が必要です。', 'payroll-system' ); ?></p>
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

			<form method="post" class="ps-login-form">
				<?php wp_nonce_field( 'ps_employee_change_password' ); ?>
				<input type="hidden" name="ps_employee_change_password_submit" value="1">
				<input type="hidden" name="login_page" value="<?php echo esc_attr( $atts['login_page'] ); ?>">

				<p class="ps-field">
					<label for="ps_current_password"><?php esc_html_e( '現在のパスワード', 'payroll-system' ); ?></label>
					<input type="password" id="ps_current_password" name="ps_current_password" required autocomplete="current-password">
				</p>
				<p class="ps-field">
					<label for="ps_new_password"><?php esc_html_e( '新しいパスワード（8文字以上）', 'payroll-system' ); ?></label>
					<input type="password" id="ps_new_password" name="ps_new_password" required minlength="8" autocomplete="new-password">
				</p>
				<p class="ps-field">
					<label for="ps_new_password_confirm"><?php esc_html_e( '新しいパスワード（確認）', 'payroll-system' ); ?></label>
					<input type="password" id="ps_new_password_confirm" name="ps_new_password_confirm" required minlength="8" autocomplete="new-password">
				</p>
				<p class="ps-submit">
					<button type="submit" class="ps-btn ps-btn-primary"><?php esc_html_e( 'パスワードを変更する', 'payroll-system' ); ?></button>
				</p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	public function maybe_handle_change_password_submit() {
		if ( empty( $_POST['ps_employee_change_password_submit'] ) ) {
			return;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'ps_employee_change_password' ) ) {
			wp_die( esc_html__( 'セキュリティ確認に失敗しました。ページを再読み込みして、もう一度お試しください。', 'payroll-system' ) );
		}

		$employee = PS_Employee_Auth::get_current_employee();
		if ( ! $employee ) {
			wp_die( esc_html__( 'ログインが必要です。', 'payroll-system' ) );
		}

		$login_page       = isset( $_POST['login_page'] ) ? esc_url_raw( wp_unslash( $_POST['login_page'] ) ) : home_url( '/' );
		$current_password = isset( $_POST['ps_current_password'] ) ? (string) wp_unslash( $_POST['ps_current_password'] ) : '';
		$new_password      = isset( $_POST['ps_new_password'] ) ? (string) wp_unslash( $_POST['ps_new_password'] ) : '';
		$new_password_confirm = isset( $_POST['ps_new_password_confirm'] ) ? (string) wp_unslash( $_POST['ps_new_password_confirm'] ) : '';

		$errors = array();

		if ( ! wp_check_password( $current_password, $employee->password_hash ) ) {
			$errors[] = __( '現在のパスワードが正しくありません。', 'payroll-system' );
		}
		if ( strlen( $new_password ) < 8 ) {
			$errors[] = __( '新しいパスワードは8文字以上で入力してください。', 'payroll-system' );
		}
		if ( $new_password !== $new_password_confirm ) {
			$errors[] = __( '新しいパスワード（確認）が一致しません。', 'payroll-system' );
		}

		if ( ! empty( $errors ) ) {
			set_transient( 'ps_change_password_errors_' . self::visitor_key(), $errors, 60 );
			return;
		}

		PS_Employee_Auth::change_password( (int) $employee->id, $new_password );

		wp_safe_redirect( add_query_arg( 'ps_password_changed', '1', $login_page ) );
		exit;
	}

	/**
	 * 未ログイン者ごとにエラーメッセージのtransientキーを分けるための簡易識別子。
	 */
	private static function visitor_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return md5( $ip . ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}
}
