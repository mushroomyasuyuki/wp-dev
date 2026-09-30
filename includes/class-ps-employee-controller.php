<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 従業員登録フォームの送信を受け取り、保存・削除処理を行うコントローラー。
 * admin-post.php 経由（action=ps_save_employee / ps_delete_employee）で呼び出される。
 * 処理後は一覧画面へリダイレクトし、結果はクエリパラメータで伝える（PRGパターン）。
 *
 * wp-admin画面・固定ページ（フロント）画面の両方から同じコントローラーを使えるように、
 * リダイレクト先を「呼び出し元のフォームが指定したURL」に統一している。
 * フォーム側は隠しフィールド _ps_redirect_base に「戻りたい一覧ページのURL」を入れて送信する。
 * 指定が無ければ従来通りwp-adminの従業員管理画面に戻す。
 */
class PS_Employee_Controller {

	const CAPABILITY = 'manage_payroll';

	public function register_hooks() {
		add_action( 'admin_post_ps_save_employee', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ps_delete_employee', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_ps_issue_temp_password', array( $this, 'handle_issue_temp_password' ) );
		add_action( 'admin_post_ps_set_employee_password', array( $this, 'handle_set_password' ) );
	}

	/**
	 * リダイレクト先のベースURLを決定する。
	 * _ps_redirect_base はフロント側の固定ページURLを想定。外部サイトへの誘導を防ぐため
	 * wp_safe_redirect() 側でも最終的にホスト検証される。
	 */
	private function redirect_base( $source = 'POST' ): string {
		$raw = 'GET' === $source
			? ( $_GET['_ps_redirect_base'] ?? '' )   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: ( $_POST['_ps_redirect_base'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$base = ! empty( $raw ) ? esc_url_raw( wp_unslash( $raw ) ) : '';

		return $base ? $base : admin_url( 'admin.php?page=payroll-system-employees' );
	}

	public function handle_save() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}

		check_admin_referer( 'ps_save_employee' );

		$base_url    = $this->redirect_base( 'POST' );
		$employee_id = isset( $_POST['employee_id'] ) ? absint( $_POST['employee_id'] ) : 0;

		$validated = PS_Employee::validate( wp_unslash( $_POST ), $employee_id );

		if ( ! empty( $validated['errors'] ) ) {
			set_transient( 'ps_employee_form_errors_' . get_current_user_id(), $validated['errors'], 60 );
			set_transient( 'ps_employee_form_input_' . get_current_user_id(), wp_unslash( $_POST ), 60 );

			$redirect_args = array( 'action' => $employee_id ? 'edit' : 'add' );
			if ( $employee_id ) {
				$redirect_args['id'] = $employee_id;
			}
			wp_safe_redirect( add_query_arg( $redirect_args, $base_url ) );
			exit;
		}

		if ( $employee_id > 0 ) {
			$result  = PS_Employee::update( $employee_id, $validated['data'] );
			$message = 'updated';
		} else {
			$result  = PS_Employee::create( $validated['data'] );
			$message = 'created';
		}

		if ( is_wp_error( $result ) ) {
			set_transient( 'ps_employee_form_errors_' . get_current_user_id(), array( $result->get_error_message() ), 60 );
			set_transient( 'ps_employee_form_input_' . get_current_user_id(), wp_unslash( $_POST ), 60 );
			wp_safe_redirect( add_query_arg( array(
				'action' => $employee_id ? 'edit' : 'add',
				'id'     => $employee_id ?: '',
			), $base_url ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( array( 'message' => $message ), $base_url ) );
		exit;
	}

	public function handle_delete() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}

		$base_url    = $this->redirect_base( 'GET' );
		$employee_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'ps_delete_employee_' . $employee_id );

		$result = PS_Employee::delete( $employee_id );

		$redirect_args = array();

		if ( is_wp_error( $result ) ) {
			$redirect_args['message'] = 'delete_failed';
			$redirect_args['reason']  = rawurlencode( $result->get_error_message() );
		} else {
			$redirect_args['message'] = 'deleted';
		}

		wp_safe_redirect( add_query_arg( $redirect_args, $base_url ) );
		exit;
	}

	/**
	 * 従業員本人用ログイン（PS_Employee_Auth）の仮パスワードを発行する。
	 * 生成した平文パスワードは、リダイレクト先の画面で一度だけ表示するために
	 * 短命のtransientへ保存する（URLやDBに平文を残さないため）。
	 */
	public function handle_issue_temp_password() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}

		$base_url    = $this->redirect_base( 'GET' );
		$employee_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'ps_issue_temp_password_' . $employee_id );

		$employee = PS_Employee::find( $employee_id );
		if ( ! $employee ) {
			wp_safe_redirect( add_query_arg( array( 'message' => 'issue_temp_password_failed' ), $base_url ) );
			exit;
		}

		$temp_password = PS_Employee_Auth::issue_temp_password( $employee_id );
		set_transient( 'ps_temp_password_' . get_current_user_id() . '_' . $employee_id, $temp_password, 2 * MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( array(
			'action'  => 'edit',
			'id'      => $employee_id,
			'message' => 'temp_password_issued',
		), $base_url ) );
		exit;
	}

	/**
	 * 従業員本人用ログインのパスワードを、担当者が指定した任意の文字列で直接設定する
	 * （仮パスワード発行のランダム生成とは異なり、そのまま即ログインに使えるようにする）。
	 */
	public function handle_set_password() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}

		$base_url    = $this->redirect_base( 'POST' );
		$employee_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'ps_set_employee_password_' . $employee_id );

		$new_password = isset( $_POST['new_password'] ) ? sanitize_text_field( wp_unslash( $_POST['new_password'] ) ) : '';

		$employee = PS_Employee::find( $employee_id );
		if ( ! $employee || '' === $new_password ) {
			wp_safe_redirect( add_query_arg( array(
				'action'  => 'edit',
				'id'      => $employee_id,
				'message' => 'set_password_failed',
			), $base_url ) );
			exit;
		}

		PS_Employee_Auth::set_password_by_admin( $employee_id, $new_password );

		wp_safe_redirect( add_query_arg( array(
			'action'  => 'edit',
			'id'      => $employee_id,
			'message' => 'password_set',
		), $base_url ) );
		exit;
	}
}
