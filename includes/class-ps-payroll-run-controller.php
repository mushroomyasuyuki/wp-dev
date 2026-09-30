<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 「給与計算実行」画面の送信処理。
 * 実行（PS_Payroll_Calculatorで計算しwp_ps_payroll_slipsへ保存）は本画面、
 * 確認・確定は「給与明細・帳票出力」画面（admin/views/slips.php）で行う。
 * 各操作はPS_Audit_Loggerで監査ログに記録する。
 */
class PS_Payroll_Run_Controller {

	const CAPABILITY = 'manage_payroll';

	public function register_hooks() {
		add_action( 'admin_post_ps_run_calculate', array( $this, 'handle_calculate' ) );
		add_action( 'admin_post_ps_run_confirm', array( $this, 'handle_confirm' ) );
		add_action( 'admin_post_ps_run_confirm_selected', array( $this, 'handle_confirm_selected' ) );
		add_action( 'admin_post_ps_run_correct', array( $this, 'handle_correct' ) );
		add_action( 'admin_post_ps_save_slip_adjustments', array( $this, 'handle_save_adjustments' ) );
	}

	/**
	 * 対象年月・対象従業員（全員 or 個別選択）に対してPS_Payroll_Calculatorを実行し、
	 * 結果をwp_ps_payroll_slipsへ保存する（新規作成 or 再計算）。
	 * 確定済み明細・勤怠未登録の従業員はスキップし、件数を結果メッセージで通知する。
	 */
	public function handle_calculate() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}
		check_admin_referer( 'ps_run_payroll' );

		// wp-admin側からの実行はslips.php、フロント（固定ページ）版からの実行は
		// そのページ自身に結果を表示する。フォームの隠しフィールド _ps_redirect_base で指定する。
		$redirect_base = isset( $_POST['_ps_redirect_base'] ) ? esc_url_raw( wp_unslash( $_POST['_ps_redirect_base'] ) ) : '';

		$target_year_month = isset( $_POST['target_year_month'] ) ? sanitize_text_field( wp_unslash( $_POST['target_year_month'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $target_year_month ) ) {
			$this->fail( __( '対象年月を正しく指定してください。', 'payroll-system' ), $redirect_base );
		}

		$scope = isset( $_POST['target_scope'] ) ? sanitize_key( $_POST['target_scope'] ) : 'all';

		if ( 'selected' === $scope ) {
			$employee_ids = isset( $_POST['employee_ids'] ) ? array_map( 'absint', (array) $_POST['employee_ids'] ) : array();
			$employee_ids = array_values( array_filter( $employee_ids ) );
			if ( empty( $employee_ids ) ) {
				$this->fail( __( '対象従業員を1人以上選択してください。', 'payroll-system' ), $redirect_base );
			}
		} else {
			$employees    = PS_Employee::get_all( array( 'status' => 'active', 'per_page' => 1000 ) );
			$employee_ids = array_map( fn( $e ) => (int) $e->id, $employees );
		}

		$calculator = new PS_Payroll_Calculator();

		$created      = 0;
		$recalculated = 0;
		$errors       = array();

		foreach ( $employee_ids as $employee_id ) {
			try {
				$result = $calculator->calculate( $employee_id, $target_year_month );
			} catch ( InvalidArgumentException $e ) {
				$errors[] = $e->getMessage();
				continue;
			}

			$saved = PS_Payroll_Slip::save_calculation( $employee_id, $target_year_month, $result, get_current_user_id() );

			if ( is_wp_error( $saved ) ) {
				$errors[] = $saved->get_error_message();
				continue;
			}

			if ( 'created' === $saved['action'] ) {
				$created++;
			} else {
				$recalculated++;
			}

			PS_Audit_Logger::log(
				'created' === $saved['action'] ? 'create' : 'recalculate',
				$saved['id'],
				$saved['before'],
				$saved['after']
			);
		}

		if ( ! empty( $errors ) ) {
			set_transient( 'ps_run_errors_' . get_current_user_id(), array_slice( $errors, 0, 20 ), 60 );
		}

		$target_url = $redirect_base ?: admin_url( 'admin.php?page=payroll-system-slips' );
		wp_safe_redirect( add_query_arg( array(
			'message'      => 'calculated',
			'created'      => $created,
			'recalculated' => $recalculated,
			'error_count'  => count( $errors ),
		), $target_url ) );
		exit;
	}

	private function fail( string $message, string $redirect_base = '' ) {
		set_transient( 'ps_run_errors_' . get_current_user_id(), array( $message ), 60 );
		$target_url = $redirect_base ?: admin_url( 'admin.php?page=payroll-system-run' );
		wp_safe_redirect( add_query_arg( 'message', 'failed', $target_url ) );
		exit;
	}

	public static function pull_errors(): array {
		$key    = 'ps_run_errors_' . get_current_user_id();
		$errors = get_transient( $key );
		delete_transient( $key );
		return is_array( $errors ) ? $errors : array();
	}

	/**
	 * 明細を1件確定する（給与明細画面から）。
	 */
	public function handle_confirm() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'ps_run_confirm_' . $id );

		$before = PS_Payroll_Slip::find( $id );
		$result = PS_Payroll_Slip::confirm( $id );

		$message = 'confirmed';
		if ( is_wp_error( $result ) ) {
			$message = 'confirm_failed';
		} else {
			PS_Audit_Logger::log( 'confirm', $id, $before ? (array) $before : null, (array) PS_Payroll_Slip::find( $id ) );
		}

		wp_safe_redirect( add_query_arg( array(
			'page'    => 'payroll-system-slips',
			'message' => $message,
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * 給与明細画面のチェックボックスで選んだ複数明細をまとめて確定する。
	 */
	public function handle_confirm_selected() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}
		check_admin_referer( 'ps_run_confirm_selected' );

		$ids = isset( $_POST['slip_ids'] ) ? array_map( 'absint', (array) $_POST['slip_ids'] ) : array();
		$ids = array_values( array_filter( $ids ) );

		$confirmed = 0;
		$failed    = 0;

		foreach ( $ids as $id ) {
			$before = PS_Payroll_Slip::find( $id );
			$result = PS_Payroll_Slip::confirm( $id );

			if ( is_wp_error( $result ) ) {
				$failed++;
				continue;
			}

			$confirmed++;
			PS_Audit_Logger::log( 'confirm', $id, $before ? (array) $before : null, (array) PS_Payroll_Slip::find( $id ) );
		}

		wp_safe_redirect( add_query_arg( array(
			'page'      => 'payroll-system-slips',
			'message'   => 'confirmed_bulk',
			'confirmed' => $confirmed,
			'failed'    => $failed,
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * 確定済み（confirmed/corrected）の明細を訂正する。
	 * 対象従業員・対象年月の勤怠／料率マスタが更新されている前提で、PS_Payroll_Calculatorを
	 * 再実行して明細を上書きする。訂正理由は必須とし、監査ログのafter_jsonに含めて記録する
	 * （payroll_slipsテーブル自体には訂正理由専用の列を持たせていない）。
	 */
	public function handle_correct() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}
		check_admin_referer( 'ps_run_correct' );

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

		if ( '' === $reason ) {
			$this->fail_correct( $id, __( '訂正理由を入力してください。', 'payroll-system' ) );
		}

		$slip = PS_Payroll_Slip::find( $id );
		if ( ! $slip ) {
			$this->fail_correct( $id, __( '給与明細が見つかりません。', 'payroll-system' ) );
		}

		$calculator = new PS_Payroll_Calculator();
		try {
			$result = $calculator->calculate( (int) $slip->employee_id, $slip->target_year_month );
		} catch ( InvalidArgumentException $e ) {
			$this->fail_correct( $id, $e->getMessage() );
		}

		$before    = (array) $slip;
		$corrected = PS_Payroll_Slip::correct( $id, $result );

		if ( is_wp_error( $corrected ) ) {
			$this->fail_correct( $id, $corrected->get_error_message() );
		}

		$after                      = (array) PS_Payroll_Slip::find( $id );
		$after['correction_reason'] = $reason;
		PS_Audit_Logger::log( 'correct', $id, $before, $after );

		wp_safe_redirect( add_query_arg( array(
			'page'    => 'payroll-system-slips',
			'message' => 'corrected',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * 自動計算では扱わない手入力の調整項目（社保料調整・調整控除・年末調整還付／徴収・
	 * 過年度調整・現金支給額・現物支給額）を保存する。ステータスを問わず編集できる
	 * （年末調整は確定済みの明細に後から適用するのが通常の運用のため）。
	 */
	public function handle_save_adjustments() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}
		check_admin_referer( 'ps_save_slip_adjustments' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		$to_float = function ( $key ) {
			$raw = isset( $_POST[ $key ] ) ? str_replace( ',', '', sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : '0';
			return is_numeric( $raw ) ? (float) $raw : 0.0;
		};

		$adjustments = array(
			'social_insurance_adjustment' => $to_float( 'social_insurance_adjustment' ),
			'adjustment_deduction'        => $to_float( 'adjustment_deduction' ),
			'nenmatsu_chousei_deduction'  => $to_float( 'nenmatsu_chousei_deduction' ),
			'nenmatsu_chousei_refund'     => $to_float( 'nenmatsu_chousei_refund' ),
			'prior_year_adjustment'       => $to_float( 'prior_year_adjustment' ),
			'cash_payment'                => $to_float( 'cash_payment' ),
			'in_kind_payment'             => $to_float( 'in_kind_payment' ),
		);

		$before = PS_Payroll_Slip::find( $id );
		$result = PS_Payroll_Slip::update_adjustments( $id, $adjustments );

		$message = 'adjustments_saved';
		if ( is_wp_error( $result ) ) {
			$message = 'adjustments_failed';
		} else {
			PS_Audit_Logger::log( 'adjust', $id, $before ? (array) $before : null, (array) PS_Payroll_Slip::find( $id ) );
		}

		wp_safe_redirect( add_query_arg( array(
			'page'    => 'payroll-system-slips',
			'message' => $message,
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function fail_correct( int $id, string $message ) {
		set_transient( 'ps_run_errors_' . get_current_user_id(), array( $message ), 60 );
		wp_safe_redirect( add_query_arg( array(
			'page'       => 'payroll-system-slips',
			'message'    => 'correct_failed',
			'correct_id' => $id,
		), admin_url( 'admin.php' ) ) );
		exit;
	}
}
