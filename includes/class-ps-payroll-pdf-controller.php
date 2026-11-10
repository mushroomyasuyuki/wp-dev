<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 給与明細PDFのダウンロード処理。個別ダウンロードと、チェックボックスで複数選択しての
 * ZIP一括ダウンロードに対応する。確定済み（confirmed/corrected）の明細のみPDF化を許可する
 * （計算中・確認中の未確定な明細を誤って配布してしまわないようにするため）。
 */
class PS_Payroll_Pdf_Controller {

	const CAPABILITY = 'manage_payroll';

	public function register_hooks() {
		add_action( 'admin_post_ps_slip_pdf_download', array( $this, 'handle_download' ) );
		add_action( 'admin_post_ps_slip_pdf_download_bulk', array( $this, 'handle_download_bulk' ) );

		// 従業員本人（PS_Employee_Auth。WordPressにはログインしていない）向けのダウンロードは
		// admin_post_nopriv_ 側にも登録しないとフックが発火しない。
		add_action( 'admin_post_ps_employee_slip_pdf_download', array( $this, 'handle_employee_download' ) );
		add_action( 'admin_post_nopriv_ps_employee_slip_pdf_download', array( $this, 'handle_employee_download' ) );
	}

	private function is_confirmed_status( string $status ): bool {
		return in_array( $status, array( 'confirmed', 'corrected' ), true );
	}

	/**
	 * 明細1件のPDFをダウンロードする。
	 */
	public function handle_download() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'ps_slip_pdf_download_' . $id );

		$slip = PS_Payroll_Slip::find( $id );
		if ( ! $slip || ! $this->is_confirmed_status( $slip->status ) ) {
			wp_die( esc_html__( '確定済みの明細のみPDF出力できます。', 'payroll-system' ) );
		}

		// PDFはダウンロードのたびに一時ファイルとして生成し、送信後に削除する
		// （訂正・調整後も常に最新の内容になり、サーバーに明細ファイルが残らない）。
		$result = PS_Payroll_Slip_Pdf::generate( $id );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		$employee      = PS_Employee::find( (int) $slip->employee_id );
		$download_name = sprintf( '%s_%s.pdf', $slip->target_year_month, $employee ? $employee->employee_code : $slip->employee_id );

		$this->stream_file( $result, $download_name, 'application/pdf', true );
	}

	/**
	 * チェックボックスで選択した複数明細のPDFをまとめてZIPでダウンロードする。
	 * 対象外（未確定）の明細が混ざっていた場合はスキップし、確定済み分だけをZIPに含める。
	 */
	public function handle_download_bulk() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}
		check_admin_referer( 'ps_slip_pdf_download_bulk', 'nonce_pdf' );

		$ids = isset( $_POST['slip_ids'] ) ? array_map( 'absint', (array) $_POST['slip_ids'] ) : array();
		$ids = array_values( array_filter( $ids ) );

		if ( empty( $ids ) ) {
			wp_die( esc_html__( '明細を1件以上選択してください。', 'payroll-system' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'サーバーにZip拡張が導入されていないため、まとめてのダウンロードはできません。個別にダウンロードしてください。', 'payroll-system' ) );
		}

		$upload_dir = wp_upload_dir();
		$tmp_dir    = PS_Payroll_Slip_Pdf::protect_dir( trailingslashit( $upload_dir['basedir'] ) . 'payroll/tmp/' );
		$tmp_zip    = $tmp_dir . 'slips_' . bin2hex( random_bytes( 16 ) ) . '.zip';

		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			wp_die( esc_html__( 'ZIPファイルの作成に失敗しました。', 'payroll-system' ) );
		}

		$added     = 0;
		$pdf_files = array();
		foreach ( $ids as $id ) {
			$slip = PS_Payroll_Slip::find( $id );
			if ( ! $slip || ! $this->is_confirmed_status( $slip->status ) ) {
				continue;
			}

			$result = PS_Payroll_Slip_Pdf::generate( $id );
			if ( is_wp_error( $result ) ) {
				continue;
			}

			$employee   = PS_Employee::find( (int) $slip->employee_id );
			$entry_name = sprintf( '%s_%s.pdf', $slip->target_year_month, $employee ? $employee->employee_code : $slip->employee_id );
			$zip->addFile( $result, $entry_name );
			$pdf_files[] = $result;
			$added++;
		}
		$zip->close();

		// ZipArchiveはclose()の時点でファイルを読み込むため、ここで個別PDFを削除してよい
		foreach ( $pdf_files as $pdf_file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
			@unlink( $pdf_file );
		}

		if ( 0 === $added ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
			@unlink( $tmp_zip );
			wp_die( esc_html__( '確定済みの明細が選択されていません。', 'payroll-system' ) );
		}

		$this->stream_file( $tmp_zip, 'payroll_slips_' . current_time( 'Ymd_His' ) . '.zip', 'application/zip', true );
	}

	/**
	 * 従業員本人が自分の確定済み明細のPDFをダウンロードする。
	 * PS_Employee_Auth（独自セッション）で認証し、明細のemployee_idが
	 * ログイン中の従業員IDと一致する場合のみ許可する（他人の明細は一切取得できない）。
	 */
	public function handle_employee_download() {
		$employee = PS_Employee_Auth::get_current_employee();
		if ( ! $employee ) {
			wp_die( esc_html__( 'ログインが必要です。', 'payroll-system' ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'ps_employee_slip_pdf_download_' . $id );

		$slip = PS_Payroll_Slip::find( $id );
		if ( ! $slip || (int) $slip->employee_id !== (int) $employee->id || ! $this->is_confirmed_status( $slip->status ) ) {
			wp_die( esc_html__( 'この明細は表示できません。', 'payroll-system' ) );
		}

		// PDFはダウンロードのたびに一時ファイルとして生成し、送信後に削除する
		// （訂正・調整後も常に最新の内容になり、サーバーに明細ファイルが残らない）。
		$result = PS_Payroll_Slip_Pdf::generate( $id );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		$download_name = sprintf( '%s_%s.pdf', $slip->target_year_month, $employee->employee_code );
		$this->stream_file( $result, $download_name, 'application/pdf', true );
	}

	private function stream_file( string $path, string $download_name, string $content_type, bool $delete_after = false ) {
		if ( ! file_exists( $path ) ) {
			wp_die( esc_html__( 'ファイルが見つかりません。', 'payroll-system' ) );
		}

		if ( $delete_after ) {
			// クライアントが途中で接続を切ってスクリプトが中断された場合でも、
			// 一時ファイルが残らないようシャットダウン時に削除する
			register_shutdown_function( static function () use ( $path ) {
				if ( file_exists( $path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
					@unlink( $path );
				}
			} );
		}

		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $download_name ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		exit;
	}
}
