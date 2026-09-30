<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 勤怠CSV取込の4ステップウィザードを制御するコントローラー。
 *
 * ステップ間の状態（アップロードしたファイルパス、選んだマッピング、
 * プレビュー結果）はユーザーごとのtransientに一時保存する。
 * 大量データの場合はtransient（wp_options）に載せるのは非効率になるため、
 * 本格運用時は専用の一時テーブルへの置き換えを検討すること。
 */
class PS_Attendance_Controller {

	const CAPABILITY  = 'manage_payroll';
	const STATE_TTL   = 30 * MINUTE_IN_SECONDS;

	public function register_hooks() {
		add_action( 'admin_post_ps_attendance_upload', array( $this, 'handle_upload' ) );
		add_action( 'admin_post_ps_attendance_mapping', array( $this, 'handle_mapping' ) );
		add_action( 'admin_post_ps_attendance_confirm', array( $this, 'handle_confirm' ) );
		add_action( 'admin_post_ps_attendance_cancel', array( $this, 'handle_cancel' ) );
	}

	private function state_key(): string {
		return self::get_state_key_for( get_current_user_id() );
	}

	public static function get_state_key_for( int $user_id ): string {
		return 'ps_attendance_import_' . $user_id;
	}

	/**
	 * 画面テンプレート側から現在の取込状態を読み出すための公開メソッド。
	 */
	public static function get_current_state(): array {
		$state = get_transient( self::get_state_key_for( get_current_user_id() ) );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * 画面テンプレート側からエラーメッセージを取得（取得後は消える）。
	 */
	public static function pull_error(): ?string {
		$key = 'ps_attendance_error_' . get_current_user_id();
		$msg = get_transient( $key );
		delete_transient( $key );
		return $msg ?: null;
	}

	private function get_state(): array {
		$state = get_transient( $this->state_key() );
		return is_array( $state ) ? $state : array();
	}

	private function save_state( array $state ) {
		set_transient( $this->state_key(), $state, self::STATE_TTL );
	}

	private function clear_state() {
		$state = $this->get_state();
		if ( ! empty( $state['file_path'] ) ) {
			PS_Attendance_Importer::cleanup_temp_file( $state['file_path'] );
		}
		delete_transient( $this->state_key() );
	}

	/**
	 * リダイレクト先のベースURLを決定する。
	 * 状態（transient）に保存された redirect_base（wp-adminかフロント固定ページかを問わず、
	 * ステップ間で引き継がれる）を優先し、無ければwp-admin側にフォールバックする。
	 * $redirect_base_override は clear_state() 後（state消去後）にredirect_toを呼ぶ場合など、
	 * 呼び出し元が消去前の値を保持している場合に使う。
	 */
	private function redirect_to( string $step, array $extra = array(), string $redirect_base_override = '' ) {
		$base = $redirect_base_override ?: ( $this->get_state()['redirect_base'] ?? '' );
		$base = $base ?: admin_url( 'admin.php?page=payroll-system-attendance-import' );
		wp_safe_redirect( add_query_arg( array_merge( array( 'step' => $step ), $extra ), $base ) );
		exit;
	}

	private function fail( string $message ) {
		set_transient( 'ps_attendance_error_' . get_current_user_id(), $message, 60 );
		$this->redirect_to( 'upload' );
	}

	/**
	 * ステップ1：CSVアップロード
	 */
	public function handle_upload() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}
		check_admin_referer( 'ps_attendance_upload' );

		// 呼び出し元（wp-admin/フロント固定ページ）のURLを最初に保存しておく。
		// 以降のバリデーションが失敗してfail()経由でredirect_toが呼ばれても、正しい画面に戻れるようにする。
		$redirect_base = isset( $_POST['_ps_redirect_base'] ) ? esc_url_raw( wp_unslash( $_POST['_ps_redirect_base'] ) ) : '';
		$this->save_state( array( 'redirect_base' => $redirect_base ) );

		$target_year_month = isset( $_POST['target_year_month'] ) ? sanitize_text_field( wp_unslash( $_POST['target_year_month'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $target_year_month ) ) {
			$this->fail( __( '対象年月を正しく指定してください。', 'payroll-system' ) );
		}

		if ( empty( $_FILES['attendance_csv']['name'] ) ) {
			$this->fail( __( 'CSVファイルを選択してください。', 'payroll-system' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- 直前でcheck_admin_referer済み
		$file_path = PS_Attendance_Importer::store_uploaded_file( $_FILES['attendance_csv'] );
		if ( is_wp_error( $file_path ) ) {
			$this->fail( $file_path->get_error_message() );
		}

		$headers = PS_Attendance_Importer::parse_headers( $file_path );
		if ( is_wp_error( $headers ) ) {
			PS_Attendance_Importer::cleanup_temp_file( $file_path );
			$this->fail( $headers->get_error_message() );
		}

		$profile_id = isset( $_POST['import_profile'] ) ? absint( $_POST['import_profile'] ) : 0;

		$state = array(
			'file_path'          => $file_path,
			'headers'            => $headers,
			'target_year_month'  => $target_year_month,
			'redirect_base'      => $redirect_base,
		);
		$this->save_state( $state );

		// 既存プロファイルを選んでいれば、マッピング画面を飛ばして即プレビューへ
		if ( $profile_id > 0 ) {
			$mapping = PS_Import_Profile::get_mapping( $profile_id );
			if ( ! empty( $mapping ) ) {
				$state['mapping']    = $mapping;
				$state['profile_id'] = $profile_id;
				$this->save_state( $state );
				$this->run_preview_and_redirect( $state );
			}
		}

		$this->redirect_to( 'mapping' );
	}

	/**
	 * ステップ2：列マッピングの確定（＋任意でプロファイル保存）
	 */
	public function handle_mapping() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}
		check_admin_referer( 'ps_attendance_mapping' );

		$state = $this->get_state();
		if ( empty( $state['file_path'] ) || empty( $state['headers'] ) ) {
			$this->fail( __( 'セッションが切れました。最初からやり直してください。', 'payroll-system' ) );
		}

		$mapping = array();
		foreach ( array_keys( PS_Attendance_Importer::get_internal_fields() ) as $field ) {
			$col = isset( $_POST[ 'map_' . $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'map_' . $field ] ) ) : '';
			if ( '' !== $col ) {
				$mapping[ $field ] = $col;
			}
		}

		if ( empty( $mapping['employee_code'] ) ) {
			$this->fail( __( '「社員コード」の列は必ず指定してください。', 'payroll-system' ) );
		}

		if ( ! empty( $_POST['save_as_profile'] ) && ! empty( $_POST['profile_name'] ) ) {
			PS_Import_Profile::save( sanitize_text_field( wp_unslash( $_POST['profile_name'] ) ), $mapping );
		}

		$state['mapping'] = $mapping;
		$this->save_state( $state );

		$this->run_preview_and_redirect( $state );
	}

	private function run_preview_and_redirect( array $state ) {
		$preview = PS_Attendance_Importer::build_preview(
			$state['file_path'],
			$state['mapping'],
			$state['target_year_month']
		);

		$state['preview'] = $preview;
		$this->save_state( $state );

		$this->redirect_to( 'preview' );
	}

	/**
	 * ステップ3→4：確定登録
	 */
	public function handle_confirm() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}
		check_admin_referer( 'ps_attendance_confirm' );

		$state = $this->get_state();
		if ( empty( $state['preview']['rows'] ) ) {
			$this->fail( __( 'セッションが切れました。最初からやり直してください。', 'payroll-system' ) );
		}

		$result = PS_Attendance_Importer::commit( $state['preview']['rows'], $state['target_year_month'] );

		$redirect_base = $state['redirect_base'] ?? '';
		$this->clear_state();

		$this->redirect_to( 'done', array(
			'imported' => $result['imported'],
			'skipped'  => $result['skipped'],
		), $redirect_base );
	}

	public function handle_cancel() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}
		check_admin_referer( 'ps_attendance_cancel' );

		$redirect_base = $this->get_state()['redirect_base'] ?? '';
		$this->clear_state();
		$this->redirect_to( 'upload', array(), $redirect_base );
	}
}
