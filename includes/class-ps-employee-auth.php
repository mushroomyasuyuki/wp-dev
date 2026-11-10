<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 従業員本人用ログインの認証コア。
 *
 * 設計方針：
 * - WordPressユーザー（wp_users）は使わない。従業員は「給与担当者」とは別の
 *   独立したログイン主体として、wp_ps_employees に持たせたpassword_hash列と
 *   専用のセッション機構（wp_ps_employee_sessions）で認証する。
 * - パスワードのハッシュ化自体はWordPressコアのwp_hash_password()/wp_check_password()
 *   （phpassベース）をそのまま利用する。独自に暗号処理を書くのは避ける。
 * - ログイン状態はWordPressのauth cookie（wp_signon等）とは完全に別の、
 *   専用cookie（ランダムトークン）＋DB照合で管理する。トークンはハッシュ化して
 *   DBに保存し、生の値はcookieの中にしか存在しない（DB漏洩時にも再利用されない）。
 */
class PS_Employee_Auth {

	const COOKIE_NAME = 'ps_employee_auth';
	const SESSION_TTL = 7 * DAY_IN_SECONDS;

	/**
	 * ログイン試行回数の制限（総当たり攻撃対策）。
	 * 失敗回数は「社員コード単位」と「接続元IP単位」で数え、どちらかが上限に達すると
	 * 最後の失敗から LOCKOUT_WINDOW の間はパスワードが正しくてもログインさせない。
	 * IP単位の上限は、社内の全従業員が同じIP（NAT）から接続するケースを考慮して高めにしている。
	 */
	const MAX_FAILURES_PER_CODE = 5;
	const MAX_FAILURES_PER_IP   = 30;
	const LOCKOUT_WINDOW        = 15 * MINUTE_IN_SECONDS;

	private static function sessions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ps_employee_sessions';
	}

	private static function employees_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ps_employees';
	}

	/**
	 * 社員コード＋パスワードで認証する。成功時はセッションを開始してcookieをセットする。
	 *
	 * @return object|WP_Error 成功時は従業員レコード
	 */
	public static function attempt_login( string $employee_code, string $password ) {
		global $wpdb;

		$employee_code = trim( $employee_code );
		if ( '' === $employee_code || '' === $password ) {
			return new WP_Error( 'invalid_credentials', __( '社員コードとパスワードを入力してください。', 'payroll-system' ) );
		}

		// 社員コードの存在有無にかかわらず同じ判定をする（存在するコードの推測に使われないように）
		$code_key = 'ps_emp_login_fail_c_' . md5( strtolower( $employee_code ) );
		$ip_key   = 'ps_emp_login_fail_ip_' . md5( self::client_ip() );
		if ( (int) get_transient( $code_key ) >= self::MAX_FAILURES_PER_CODE
			|| (int) get_transient( $ip_key ) >= self::MAX_FAILURES_PER_IP ) {
			return new WP_Error(
				'too_many_attempts',
				__( 'ログインの失敗が続いたため、一時的にログインを制限しています。15分ほど時間をおいてから、もう一度お試しください。', 'payroll-system' )
			);
		}

		$table = self::employees_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$employee = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE employee_code = %s AND status = 'active'",
			$employee_code
		) );

		// 社員コードが存在しない場合／パスワード未発行の場合も、同じ汎用エラーにする
		// （社員コードの存在有無を外部から推測されないようにするため）。
		if ( ! $employee || empty( $employee->password_hash ) || ! wp_check_password( $password, $employee->password_hash ) ) {
			// 失敗のたびに有効期限を延ばす（最後の失敗から LOCKOUT_WINDOW の間カウントを保持）
			set_transient( $code_key, (int) get_transient( $code_key ) + 1, self::LOCKOUT_WINDOW );
			set_transient( $ip_key, (int) get_transient( $ip_key ) + 1, self::LOCKOUT_WINDOW );
			return new WP_Error( 'invalid_credentials', __( '社員コードまたはパスワードが正しくありません。', 'payroll-system' ) );
		}

		delete_transient( $code_key );

		self::start_session( (int) $employee->id );

		return $employee;
	}

	private static function start_session( int $employee_id ) {
		global $wpdb;

		$token      = bin2hex( random_bytes( 32 ) );
		$token_hash = hash( 'sha256', $token );
		// expires_at はUTCで保存し、照合時もUTC（gmdate）で比較する
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + self::SESSION_TTL );

		// 期限切れセッションの掃除（ログインのたびに行えば溜まり続けることはない）
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::sessions_table() . ' WHERE expires_at <= %s', gmdate( 'Y-m-d H:i:s' ) ) );

		$wpdb->insert( self::sessions_table(), array(
			'employee_id' => $employee_id,
			'token_hash'  => $token_hash,
			'expires_at'  => $expires_at,
			'created_at'  => current_time( 'mysql' ),
		) );

		self::set_cookie( $token, time() + self::SESSION_TTL );
	}

	private static function set_cookie( string $value, int $expires ) {
		setcookie( self::COOKIE_NAME, $value, array(
			'expires'  => $expires,
			'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		) );
	}

	/**
	 * 現在のリクエストのcookieから、ログイン中の従業員を取得する（未ログインならnull）。
	 * 1リクエスト内で何度呼ばれても問い合わせは1回だけ。
	 */
	public static function get_current_employee() {
		static $resolved = false;
		static $cached   = null;

		if ( $resolved ) {
			return $cached;
		}
		$resolved = true;

		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return null;
		}

		$token = wp_unslash( $_COOKIE[ self::COOKIE_NAME ] );
		if ( ! is_string( $token ) || ! preg_match( '/^[0-9a-f]{64}$/', $token ) ) {
			return null;
		}
		$token_hash = hash( 'sha256', $token );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$session = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::sessions_table() . ' WHERE token_hash = %s AND expires_at > %s',
			$token_hash,
			gmdate( 'Y-m-d H:i:s' ) // expires_at はUTCで保存しているため、UTCの現在時刻と比較する
		) );
		if ( ! $session ) {
			return null;
		}

		$employee = PS_Employee::find( (int) $session->employee_id );
		if ( ! $employee || 'active' !== $employee->status ) {
			return null;
		}

		$cached = $employee;
		return $employee;
	}

	/**
	 * ログアウトする。現在のセッションだけを失効させる（他端末のセッションはそのまま）。
	 */
	public static function logout() {
		if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			$token = wp_unslash( $_COOKIE[ self::COOKIE_NAME ] );
			if ( is_string( $token ) && preg_match( '/^[0-9a-f]{64}$/', $token ) ) {
				global $wpdb;
				$wpdb->delete( self::sessions_table(), array( 'token_hash' => hash( 'sha256', $token ) ) );
			}
		}
		self::set_cookie( '', time() - HOUR_IN_SECONDS );
	}

	/**
	 * 管理者（給与担当者）が仮パスワードを発行する。
	 * 生成した平文パスワードはこの場限りで管理者に表示するためだけに返す（DBには保存しない）。
	 */
	public static function issue_temp_password( int $employee_id ): string {
		$temp_password = wp_generate_password( 10, false );
		self::set_password( $employee_id, $temp_password, true );
		self::revoke_all_sessions( $employee_id );
		return $temp_password;
	}

	/**
	 * 従業員本人がログイン中にパスワードを変更する。
	 * 変更後は（現在の端末も含め）全セッションを失効させ、新しいパスワードで
	 * 再ログインしてもらう（セッション固定化・多重ログイン状態の混乱を避けるため）。
	 */
	public static function change_password( int $employee_id, string $new_password ) {
		self::set_password( $employee_id, $new_password, false );
		self::revoke_all_sessions( $employee_id );
	}

	/**
	 * 給与担当者が、従業員のログイン用パスワードを任意の文字列で直接設定する
	 * （仮パスワード発行とは異なりランダム生成ではなく、担当者が指定した値をそのまま使う）。
	 * 担当者が明示的に設定したものなので、次回ログイン時の変更強制はしない。
	 */
	public static function set_password_by_admin( int $employee_id, string $plain_password ) {
		self::set_password( $employee_id, $plain_password, false );
		self::revoke_all_sessions( $employee_id );
	}

	private static function set_password( int $employee_id, string $plain_password, bool $must_change ) {
		global $wpdb;
		$wpdb->update( self::employees_table(), array(
			'password_hash'        => wp_hash_password( $plain_password ),
			'must_change_password' => $must_change ? 1 : 0,
			'password_updated_at'  => current_time( 'mysql' ),
			'updated_at'           => current_time( 'mysql' ),
		), array( 'id' => $employee_id ) );
	}

	private static function client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	}

	private static function revoke_all_sessions( int $employee_id ) {
		global $wpdb;
		$wpdb->delete( self::sessions_table(), array( 'employee_id' => $employee_id ) );
	}
}
