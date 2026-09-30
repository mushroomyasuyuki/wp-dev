<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 固定ページに埋め込む専用ログインフォーム、およびログイン状態・権限のガード処理。
 *
 * 設計方針：
 * - WordPressのユーザーアカウント自体はそのまま使う（wp_signon()で認証）。
 *   「専用ログインフォーム」は見た目だけを差し替えるものであり、
 *   認証の仕組み自体を自作するとセキュリティリスクが増えるため避ける。
 * - ログイン後は 'manage_payroll' capability を持つユーザーのみ利用可能。
 *   一般のWordPressユーザー（capabilityなし）はログインできてもシステムには入れない。
 *
 * 使い方（固定ページに設置するショートコード）：
 *   [payroll_login redirect="https://example.com/payroll/employees/"]
 *   [payroll_logout redirect="https://example.com/payroll/login/"]
 */
class PS_Frontend_Auth {

	const CAPABILITY = 'manage_payroll';

	public function register_hooks() {
		add_shortcode( 'payroll_login', array( $this, 'render_login' ) );
		add_shortcode( 'payroll_logout', array( $this, 'render_logout' ) );

		// フォーム送信処理はショートコードのレンダリングより前（init時点）に行い、
		// ヘッダー送信前にリダイレクトできるようにする。
		add_action( 'init', array( $this, 'maybe_handle_login_submit' ) );
		add_action( 'init', array( $this, 'maybe_handle_logout' ) );
	}

	/**
	 * 他のフロント用ショートコード（従業員管理等）から呼び出す共通ガード。
	 * 権限がなければログインページへの案内HTMLを返し、あれば null を返す。
	 *
	 * @param string $login_page_url ログインページのURL（未ログイン時の遷移先）
	 * @return string|null
	 */
	public static function guard( string $login_page_url ): ?string {
		if ( ! is_user_logged_in() ) {
			return self::render_guard_message(
				__( 'この画面を利用するにはログインが必要です。', 'payroll-system' ),
				$login_page_url
			);
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return self::render_guard_message(
				__( 'このアカウントには給与管理システムを利用する権限がありません。管理者にお問い合わせください。', 'payroll-system' ),
				$login_page_url,
				false
			);
		}

		return null;
	}

	private static function render_guard_message( string $message, string $login_page_url, bool $show_login_link = true ): string {
		ob_start();
		?>
		<div class="ps-frontend ps-frontend-guard">
			<p><?php echo esc_html( $message ); ?></p>
			<?php if ( $show_login_link && $login_page_url ) : ?>
				<p><a class="ps-btn ps-btn-primary" href="<?php echo esc_url( $login_page_url ); ?>"><?php esc_html_e( 'ログインページへ', 'payroll-system' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [payroll_login] ショートコード本体。
	 */
	public function render_login( $atts ): string {
		$atts = shortcode_atts( array(
			'redirect' => home_url( '/' ),
		), $atts, 'payroll_login' );

		// 既にログイン済みで権限もある場合は、フォームの代わりに案内を出す
		if ( is_user_logged_in() && current_user_can( self::CAPABILITY ) ) {
			ob_start();
			?>
			<div class="ps-frontend ps-frontend-login">
				<p>
					<?php
					printf(
						/* translators: %s: ユーザー名 */
						esc_html__( '%s としてログイン済みです。', 'payroll-system' ),
						esc_html( wp_get_current_user()->display_name )
					);
					?>
				</p>
				<p><a class="ps-btn ps-btn-primary" href="<?php echo esc_url( $atts['redirect'] ); ?>"><?php esc_html_e( '続ける', 'payroll-system' ); ?></a></p>
			</div>
			<?php
			return ob_get_clean();
		}

		$error = get_transient( 'ps_login_error_' . self::visitor_key() );
		delete_transient( 'ps_login_error_' . self::visitor_key() );

		ob_start();
		?>
		<div class="ps-frontend ps-frontend-login">
			<?php if ( $error ) : ?>
				<p class="ps-notice ps-notice-error"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>

			<form method="post" class="ps-login-form">
				<?php wp_nonce_field( 'ps_frontend_login' ); ?>
				<input type="hidden" name="ps_frontend_login_submit" value="1">
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $atts['redirect'] ); ?>">

				<p class="ps-field">
					<label for="ps_login_username"><?php esc_html_e( 'ユーザー名またはメールアドレス', 'payroll-system' ); ?></label>
					<input type="text" id="ps_login_username" name="ps_login_username" required autocomplete="username">
				</p>
				<p class="ps-field">
					<label for="ps_login_password"><?php esc_html_e( 'パスワード', 'payroll-system' ); ?></label>
					<input type="password" id="ps_login_password" name="ps_login_password" required autocomplete="current-password">
				</p>
				<p class="ps-field">
					<label><input type="checkbox" name="ps_login_remember" value="1"> <?php esc_html_e( 'ログイン状態を保持する', 'payroll-system' ); ?></label>
				</p>
				<p class="ps-submit">
					<button type="submit" class="ps-btn ps-btn-primary"><?php esc_html_e( 'ログイン', 'payroll-system' ); ?></button>
				</p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [payroll_logout] ショートコード本体。ログアウト用リンクを表示するだけ。
	 * 実処理は maybe_handle_logout() が init フックで行う。
	 */
	public function render_logout( $atts ): string {
		$atts = shortcode_atts( array(
			'redirect' => home_url( '/' ),
			'label'    => __( 'ログアウト', 'payroll-system' ),
		), $atts, 'payroll_logout' );

		if ( ! is_user_logged_in() ) {
			return '';
		}

		$url = add_query_arg( array(
			'ps_logout'      => '1',
			'redirect_to'    => rawurlencode( $atts['redirect'] ),
			'_wpnonce'       => wp_create_nonce( 'ps_frontend_logout' ),
		), home_url( '/' ) );

		return sprintf(
			'<a class="ps-btn ps-btn-secondary ps-logout-link" href="%s">%s</a>',
			esc_url( $url ),
			esc_html( $atts['label'] )
		);
	}

	/**
	 * ログインフォームの送信処理（initフックで実行、リダイレクトのためヘッダー送信前に処理する）
	 */
	public function maybe_handle_login_submit() {
		if ( empty( $_POST['ps_frontend_login_submit'] ) ) {
			return;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'ps_frontend_login' ) ) {
			wp_die( esc_html__( 'セキュリティ確認に失敗しました。ページを再読み込みして、もう一度お試しください。', 'payroll-system' ) );
		}

		$username = isset( $_POST['ps_login_username'] ) ? sanitize_text_field( wp_unslash( $_POST['ps_login_username'] ) ) : '';
		$password = isset( $_POST['ps_login_password'] ) ? (string) wp_unslash( $_POST['ps_login_password'] ) : ''; // パスワードはsanitize_text_fieldしない（記号が壊れるため）
		$remember = ! empty( $_POST['ps_login_remember'] );
		$redirect_to = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : home_url( '/' );

		$user = wp_signon( array(
			'user_login'    => $username,
			'user_password' => $password,
			'remember'      => $remember,
		), is_ssl() );

		if ( is_wp_error( $user ) ) {
			set_transient(
				'ps_login_error_' . self::visitor_key(),
				__( 'ユーザー名またはパスワードが正しくありません。', 'payroll-system' ),
				60
			);
			return; // フォームを再表示（エラーメッセージ付き）
		}

		if ( ! user_can( $user, self::CAPABILITY ) ) {
			wp_logout();
			set_transient(
				'ps_login_error_' . self::visitor_key(),
				__( 'このアカウントには給与管理システムを利用する権限がありません。', 'payroll-system' ),
				60
			);
			return;
		}

		wp_safe_redirect( $redirect_to ?: home_url( '/' ) );
		exit;
	}

	/**
	 * ログアウト処理（initフック）
	 */
	public function maybe_handle_logout() {
		if ( empty( $_GET['ps_logout'] ) ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'ps_frontend_logout' ) ) {
			wp_die( esc_html__( 'セキュリティ確認に失敗しました。', 'payroll-system' ) );
		}

		wp_logout();

		$redirect_to = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( rawurldecode( $_GET['redirect_to'] ) ) ) : home_url( '/' );
		wp_safe_redirect( $redirect_to );
		exit;
	}

	/**
	 * 未ログイン者ごとにエラーメッセージのtransientキーを分けるための簡易識別子。
	 * Cookieが無い初回アクセス等も考慮し、IPアドレスのハッシュを使う（完全な一意性は求めない）。
	 */
	private static function visitor_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return md5( $ip . ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}
}
