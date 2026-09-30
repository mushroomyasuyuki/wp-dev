<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 給与設定（マスタ）画面の送信処理を担当するコントローラー。
 * CSVインポートは「アップロード→プレビュー→確定」の2ステップ
 * （列は固定のため勤怠取込のような列マッピング画面は挟まない）。
 * 手動での1行追加・編集・削除、CSVテンプレートのダウンロードも扱う。
 */
class PS_Rate_Master_Controller {

	const CAPABILITY = 'manage_payroll';
	const STATE_TTL  = 30 * MINUTE_IN_SECONDS;

	public function register_hooks() {
		add_action( 'admin_post_ps_rate_import_upload', array( $this, 'handle_import_upload' ) );
		add_action( 'admin_post_ps_rate_import_confirm', array( $this, 'handle_import_confirm' ) );
		add_action( 'admin_post_ps_rate_import_cancel', array( $this, 'handle_import_cancel' ) );
		add_action( 'admin_post_ps_rate_template_download', array( $this, 'handle_template_download' ) );
		add_action( 'admin_post_ps_rate_export_download', array( $this, 'handle_export_download' ) );
		add_action( 'admin_post_ps_save_rate', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ps_delete_rate', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_ps_save_business_type', array( $this, 'handle_save_business_type' ) );
		add_action( 'admin_post_ps_save_company_settings', array( $this, 'handle_save_company_settings' ) );
	}

	private function valid_type( string $type ): bool {
		return isset( PS_Rate_Master::get_type_defs()[ $type ] );
	}

	private function state_key( string $type ): string {
		return self::get_state_key_for( get_current_user_id(), $type );
	}

	public static function get_state_key_for( int $user_id, string $type ): string {
		return 'ps_rate_import_' . $type . '_' . $user_id;
	}

	public static function get_current_state( string $type ): array {
		$state = get_transient( self::get_state_key_for( get_current_user_id(), $type ) );
		return is_array( $state ) ? $state : array();
	}

	public static function pull_error( string $type ): ?string {
		$key = 'ps_rate_import_error_' . $type . '_' . get_current_user_id();
		$msg = get_transient( $key );
		delete_transient( $key );
		return $msg ?: null;
	}

	private function get_state( string $type ): array {
		$state = get_transient( $this->state_key( $type ) );
		return is_array( $state ) ? $state : array();
	}

	private function save_state( string $type, array $state ) {
		set_transient( $this->state_key( $type ), $state, self::STATE_TTL );
	}

	private function clear_state( string $type ) {
		$state = $this->get_state( $type );
		if ( ! empty( $state['file_path'] ) ) {
			PS_Rate_Importer::cleanup_temp_file( $state['file_path'] );
		}
		delete_transient( $this->state_key( $type ) );
	}

	private function redirect_to( string $type, string $step, array $extra = array() ) {
		$args = array_merge( array( 'page' => 'payroll-system-rates', 'tab' => $type ), $extra );
		if ( $step ) {
			$args['step'] = $step;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function fail( string $type, string $message ) {
		set_transient( 'ps_rate_import_error_' . $type . '_' . get_current_user_id(), $message, 60 );
		$this->redirect_to( $type, '' );
	}

	/**
	 * ステップ1：CSVアップロード＋プレビュー生成
	 */
	public function handle_import_upload() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}
		check_admin_referer( 'ps_rate_import_upload' );

		$type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
		if ( ! $this->valid_type( $type ) ) {
			wp_die( esc_html__( '不明なマスタ種別です。', 'payroll-system' ) );
		}

		if ( empty( $_FILES['rate_csv']['name'] ) ) {
			$this->fail( $type, __( 'CSVファイルを選択してください。', 'payroll-system' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- 直前でcheck_admin_referer済み
		$file_path = PS_Rate_Importer::store_uploaded_file( $type, $_FILES['rate_csv'] );
		if ( is_wp_error( $file_path ) ) {
			$this->fail( $type, $file_path->get_error_message() );
		}

		$preview = PS_Rate_Importer::build_preview( $type, $file_path );
		if ( is_wp_error( $preview ) ) {
			PS_Rate_Importer::cleanup_temp_file( $file_path );
			$this->fail( $type, $preview->get_error_message() );
		}

		$this->save_state( $type, array(
			'file_path' => $file_path,
			'preview'   => $preview,
		) );

		$this->redirect_to( $type, 'preview' );
	}

	/**
	 * ステップ2：確定登録（natural key一致行は上書き更新）
	 */
	public function handle_import_confirm() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}
		check_admin_referer( 'ps_rate_import_confirm' );

		$type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
		if ( ! $this->valid_type( $type ) ) {
			wp_die( esc_html__( '不明なマスタ種別です。', 'payroll-system' ) );
		}

		$state = $this->get_state( $type );
		if ( empty( $state['preview']['rows'] ) ) {
			$this->fail( $type, __( 'セッションが切れました。最初からやり直してください。', 'payroll-system' ) );
		}

		$result = PS_Rate_Importer::commit( $type, $state['preview']['rows'] );
		$this->clear_state( $type );

		$this->redirect_to( $type, '', array(
			'message'  => 'imported',
			'inserted' => $result['inserted'],
			'updated'  => $result['updated'],
			'skipped'  => $result['skipped'],
		) );
	}

	public function handle_import_cancel() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}
		check_admin_referer( 'ps_rate_import_cancel' );

		$type = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
		if ( $this->valid_type( $type ) ) {
			$this->clear_state( $type );
		}
		$this->redirect_to( $type ?: 'health_insurance', '' );
	}

	public function handle_template_download() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}

		$type = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
		check_admin_referer( 'ps_rate_template_download_' . $type );

		if ( ! $this->valid_type( $type ) ) {
			wp_die( esc_html__( '不明なマスタ種別です。', 'payroll-system' ) );
		}

		PS_Rate_Importer::output_template_csv( $type );
	}

	/**
	 * 現在登録済みの全行をCSVエクスポートする（年次改定の配布・バックアップ用途）。
	 */
	public function handle_export_download() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}

		$type = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
		check_admin_referer( 'ps_rate_export_download_' . $type );

		if ( ! $this->valid_type( $type ) ) {
			wp_die( esc_html__( '不明なマスタ種別です。', 'payroll-system' ) );
		}

		PS_Rate_Importer::output_export_csv( $type );
	}

	/**
	 * 手動での1行追加・編集
	 */
	public function handle_save() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}
		check_admin_referer( 'ps_save_rate' );

		$type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
		if ( ! $this->valid_type( $type ) ) {
			wp_die( esc_html__( '不明なマスタ種別です。', 'payroll-system' ) );
		}

		$rate_id  = isset( $_POST['rate_id'] ) ? absint( $_POST['rate_id'] ) : 0;
		$base_url = add_query_arg( 'tab', $type, admin_url( 'admin.php?page=payroll-system-rates' ) );

		$validated = PS_Rate_Master::validate( $type, wp_unslash( $_POST ), $rate_id );

		if ( ! empty( $validated['errors'] ) ) {
			set_transient( 'ps_rate_form_errors_' . get_current_user_id(), $validated['errors'], 60 );
			set_transient( 'ps_rate_form_input_' . get_current_user_id(), wp_unslash( $_POST ), 60 );
			$redirect_args = array( 'action' => $rate_id ? 'edit' : 'add' );
			if ( $rate_id ) {
				$redirect_args['id'] = $rate_id;
			}
			wp_safe_redirect( add_query_arg( $redirect_args, $base_url ) );
			exit;
		}

		if ( $rate_id > 0 ) {
			$result  = PS_Rate_Master::update( $type, $rate_id, $validated['data'] );
			$message = 'updated';
		} else {
			$result  = PS_Rate_Master::create( $type, $validated['data'] );
			$message = 'created';
		}

		if ( is_wp_error( $result ) ) {
			set_transient( 'ps_rate_form_errors_' . get_current_user_id(), array( $result->get_error_message() ), 60 );
			set_transient( 'ps_rate_form_input_' . get_current_user_id(), wp_unslash( $_POST ), 60 );
			wp_safe_redirect( add_query_arg( array(
				'action' => $rate_id ? 'edit' : 'add',
				'id'     => $rate_id ?: '',
			), $base_url ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( array( 'message' => $message ), $base_url ) );
		exit;
	}

	/**
	 * 雇用保険の事業区分（会社単位の設定）の保存。
	 * PS_Payroll_Calculator が雇用保険料計算時に payroll_system_business_type オプションを参照する。
	 */
	public function handle_save_business_type() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}
		check_admin_referer( 'ps_save_business_type' );

		$allowed = array_keys( PS_Rate_Master::get_type_defs()['employment_insurance']['fields']['business_type']['options'] );
		$value   = isset( $_POST['business_type'] ) ? sanitize_key( $_POST['business_type'] ) : '';

		if ( in_array( $value, $allowed, true ) ) {
			update_option( 'payroll_system_business_type', $value );
		}

		wp_safe_redirect( add_query_arg(
			array( 'tab' => 'employment_insurance', 'message' => 'business_type_saved' ),
			admin_url( 'admin.php?page=payroll-system-rates' )
		) );
		exit;
	}

	/**
	 * 会社名・支給日（会社単位の設定、給与明細PDFのヘッダーに使用）の保存。
	 */
	public function handle_save_company_settings() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}
		check_admin_referer( 'ps_save_company_settings' );

		$company_name = isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : '';
		$payment_day  = isset( $_POST['payment_day'] ) ? absint( $_POST['payment_day'] ) : 25;
		$payment_day  = ( $payment_day >= 1 && $payment_day <= 31 ) ? $payment_day : 25;

		update_option( 'payroll_system_company_name', $company_name );
		update_option( 'payroll_system_payment_day', $payment_day );

		wp_safe_redirect( add_query_arg(
			array( 'tab' => 'health_insurance', 'message' => 'company_settings_saved' ),
			admin_url( 'admin.php?page=payroll-system-rates' )
		) );
		exit;
	}

	public function handle_delete() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}

		$type    = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
		$rate_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'ps_delete_rate_' . $type . '_' . $rate_id );

		if ( ! $this->valid_type( $type ) ) {
			wp_die( esc_html__( '不明なマスタ種別です。', 'payroll-system' ) );
		}

		$result = PS_Rate_Master::delete( $type, $rate_id );

		$redirect_args = array( 'tab' => $type );
		$redirect_args['message'] = is_wp_error( $result ) ? 'delete_failed' : 'deleted';

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php?page=payroll-system-rates' ) ) );
		exit;
	}
}
