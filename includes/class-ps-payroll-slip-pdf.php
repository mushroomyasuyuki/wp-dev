<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 給与明細PDFの生成。mPDF（vendor/mpdf/mpdf、composerで導入）を使用する。
 *
 * composerのオートローダーはここで初めて読み込む（他の全リクエストで毎回
 * 読み込むと無駄なため、実際にPDFを生成するタイミングまで遅延させる）。
 *
 * 生成したPDFはダウンロードのたびに作り直す一時ファイルとして扱い、サーバーには残さない。
 * 保存先は wp-content/uploads/payroll/slips/ だが、ファイル名は推測できないランダム値にし、
 * 呼び出し元（PS_Payroll_Pdf_Controller）が送信後に必ず削除する。
 * uploads配下は公開ディレクトリであり、.htaccessによるアクセス拒否はApache以外
 * （nginx等）では効かないため、「推測できない名前」と「残さない」の両方で守る。
 */
class PS_Payroll_Slip_Pdf {

	private static function dir(): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'payroll/slips/';
	}

	/**
	 * ディレクトリを作成し、外部からのアクセス拒否用の .htaccess（Apache 2.2/2.4両対応）と
	 * ディレクトリ一覧防止用の index.php を置く。既存ディレクトリにも毎回上書きで置き直す。
	 */
	public static function protect_dir( string $dir ): string {
		$dir = trailingslashit( $dir );
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$htaccess = "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n";
		if ( ! file_exists( $dir . '.htaccess' ) || file_get_contents( $dir . '.htaccess' ) !== $htaccess ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $dir . '.htaccess', $htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $dir . 'index.php' ) ) {
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	private static function ensure_dir(): string {
		return self::protect_dir( self::dir() );
	}

	private static function mpdf_tmp_dir(): string {
		$upload_dir = wp_upload_dir();
		return self::protect_dir( trailingslashit( $upload_dir['basedir'] ) . 'payroll/mpdf-tmp/' );
	}

	/**
	 * 以前のバージョンで slips/ に保存していた（社員コード・年月から推測できる名前の）
	 * PDFを全て削除し、pdf_file_path を空にする。DB_VERSION 1.5.0へのアップグレード時に呼ぶ。
	 * PDFは毎回再生成するため、削除しても機能上の影響はない。
	 */
	public static function purge_stored_files(): void {
		$dir = self::ensure_dir();
		foreach ( (array) glob( $dir . '*.pdf' ) as $file ) {
			if ( is_file( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
				@unlink( $file );
			}
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "UPDATE {$wpdb->prefix}ps_payroll_slips SET pdf_file_path = NULL WHERE pdf_file_path IS NOT NULL" );
	}

	/**
	 * 明細のPDFを一時ファイルとして生成し、そのパスを返す。
	 * 返したファイルは呼び出し元が送信後に削除すること（サーバーに残さない）。
	 *
	 * @return string|WP_Error
	 */
	public static function generate( int $slip_id ) {
		$slip = PS_Payroll_Slip::find( $slip_id );
		if ( ! $slip ) {
			return new WP_Error( 'not_found', __( '給与明細が見つかりません。', 'payroll-system' ) );
		}

		$employee = PS_Employee::find( (int) $slip->employee_id );
		if ( ! $employee ) {
			return new WP_Error( 'employee_not_found', __( '従業員が見つかりません。', 'payroll-system' ) );
		}

		$autoload = PAYROLL_SYSTEM_PLUGIN_DIR . 'vendor/autoload.php';
		if ( ! file_exists( $autoload ) ) {
			return new WP_Error(
				'mpdf_missing',
				__( 'PDF生成ライブラリ（mPDF）が導入されていません。プラグインディレクトリで composer install を実行してください。', 'payroll-system' )
			);
		}
		require_once $autoload;

		$dir       = self::ensure_dir();
		// 社員コード・年月を含めると第三者がURLを推測できるため、ランダムな名前にする
		$file_name = 'slip_' . bin2hex( random_bytes( 16 ) ) . '.pdf';
		$file_path = $dir . $file_name;

		$attendance = PS_Attendance::find( (int) $slip->employee_id, $slip->target_year_month );
		$ytd        = PS_Payroll_Slip::get_ytd_totals( (int) $slip->employee_id, $slip->target_year_month );

		$html = self::render_html( $slip, $employee, $attendance, $ytd );

		try {
			$mpdf = new \Mpdf\Mpdf( array(
				'mode'    => 'utf-8',
				'format'  => 'A4',
				'tempDir' => self::mpdf_tmp_dir(),
			) );
			$mpdf->SetTitle( sprintf( '%s 給与明細（%s）', $employee->name, $slip->target_year_month ) );
			$mpdf->WriteHTML( $html );
			$mpdf->Output( $file_path, \Mpdf\Output\Destination::FILE );
		} catch ( \Throwable $e ) {
			if ( file_exists( $file_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
				@unlink( $file_path );
			}
			return new WP_Error( 'mpdf_error', sprintf(
				/* translators: %s: エラーメッセージ */
				__( 'PDF生成に失敗しました: %s', 'payroll-system' ),
				$e->getMessage()
			) );
		}

		return $file_path;
	}

	private static function render_html( $slip, $employee, $attendance, array $ytd ): string {
		$gross      = json_decode( $slip->gross_amounts_json, true ) ?: array();
		$deductions = json_decode( $slip->deduction_amounts_json, true ) ?: array();

		$fmt = function ( $v ) {
			return number_format( (float) $v );
		};
		$fmt_days = function ( $v ) {
			return number_format( (float) $v, 1 );
		};
		$fmt_hours = function ( $v ) {
			return number_format( (float) $v, 2 );
		};

		// ---- 勤怠 ----
		$paid_leave_used      = PS_Attendance::get_paid_leave_used_total( (int) $employee->id, $slip->target_year_month );
		$paid_leave_remaining = max( 0, (float) $employee->paid_leave_granted_days - $paid_leave_used );

		$attendance_rows = array(
			__( '労働日数', 'payroll-system' )     => $fmt_days( $attendance->work_days ?? 0 ),
			__( '欠勤日数', 'payroll-system' )     => $fmt_days( $attendance->absence_days ?? 0 ),
			__( '有休日数', 'payroll-system' )     => $fmt_days( $attendance->paid_leave_days ?? 0 ),
			__( '有休残日数', 'payroll-system' )   => $fmt_days( $paid_leave_remaining ),
			__( '総労働時間', 'payroll-system' )   => $fmt_hours( $gross['total_working_hours'] ?? 0 ),
			__( '普通残業時間', 'payroll-system' ) => $fmt_hours( $attendance->overtime_hours ?? 0 ),
			__( '休日労働時間', 'payroll-system' ) => $fmt_hours( $attendance->holiday_work_hours ?? 0 ),
			__( '深夜残業時間', 'payroll-system' ) => $fmt_hours( $attendance->late_night_hours ?? 0 ),
			__( '遅刻早退時間', 'payroll-system' ) => $fmt_hours( $attendance->late_early_hours ?? 0 ),
		);

		// ---- 支給 ----
		$gross_rows = array(
			__( '基本給', 'payroll-system' )         => $fmt( $gross['base_salary'] ?? 0 ),
			__( '役職手当', 'payroll-system' )       => $fmt( $gross['position_allowance'] ?? 0 ),
			__( '家族手当', 'payroll-system' )       => $fmt( $gross['family_allowance'] ?? 0 ),
			__( '住宅手当', 'payroll-system' )       => $fmt( $gross['housing_allowance'] ?? 0 ),
			__( '非課税通勤費', 'payroll-system' )   => $fmt( $gross['commuting_allowance'] ?? 0 ),
			__( '課税通勤費', 'payroll-system' )     => $fmt( $gross['commuting_allowance_taxable'] ?? 0 ),
			__( '普通残業手当', 'payroll-system' )   => $fmt( $gross['overtime_pay'] ?? 0 ),
			__( '深夜残業手当', 'payroll-system' )   => $fmt( $gross['late_night_pay'] ?? 0 ),
			__( '休日労働手当', 'payroll-system' )   => $fmt( $gross['holiday_work_pay'] ?? 0 ),
			__( '欠勤控除', 'payroll-system' )       => $fmt( -1 * ( $gross['absence_deduction'] ?? 0 ) ),
			__( '遅刻早退控除', 'payroll-system' )   => $fmt( -1 * ( $gross['late_early_deduction'] ?? 0 ) ),
		);

		// ---- 控除 ----
		$deduction_rows = array(
			__( '健康保険料', 'payroll-system' )     => $fmt( $deductions['health_insurance'] ?? 0 ),
			__( '介護保険料', 'payroll-system' )     => $fmt( $deductions['nursing_care_insurance'] ?? 0 ),
			__( '厚生年金保険料', 'payroll-system' ) => $fmt( $deductions['pension'] ?? 0 ),
			__( '雇用保険料', 'payroll-system' )     => $fmt( $deductions['employment_insurance'] ?? 0 ),
			__( '社保料調整', 'payroll-system' )     => $fmt( $deductions['social_insurance_adjustment'] ?? 0 ),
			__( '所得税', 'payroll-system' )         => $fmt( $deductions['income_tax'] ?? 0 ),
			__( '住民税', 'payroll-system' )         => $fmt( $deductions['resident_tax'] ?? 0 ),
			__( '調整控除', 'payroll-system' )       => $fmt( $deductions['adjustment_deduction'] ?? 0 ),
		);

		// ---- その他（年末調整・過年度調整） ----
		$nenmatsu_refund     = (float) ( $deductions['nenmatsu_chousei_refund'] ?? 0 );
		$nenmatsu_deduction  = (float) ( $deductions['nenmatsu_chousei_deduction'] ?? 0 );
		$prior_year_adjustment = (float) ( $deductions['prior_year_adjustment'] ?? 0 );
		$other_rows = array(
			__( '年末調整還付', 'payroll-system' ) => $fmt( $nenmatsu_refund ),
			__( '年末調整徴収', 'payroll-system' ) => $fmt( $nenmatsu_deduction ),
			__( '過年度調整', 'payroll-system' )   => $fmt( $prior_year_adjustment ),
		);
		$other_total = $nenmatsu_refund - $nenmatsu_deduction - $prior_year_adjustment;

		// ---- 振込・現金・現物 ----
		$cash_payment    = (float) ( $gross['cash_payment'] ?? 0 );
		$in_kind_payment = (float) ( $gross['in_kind_payment'] ?? 0 );
		$transfer_amount = max( 0, (float) $slip->net_amount - $cash_payment - $in_kind_payment );

		$company_name = get_option( 'payroll_system_company_name', '' );
		$payment_day  = (int) get_option( 'payroll_system_payment_day', 25 );
		$payment_date = $slip->target_year_month . '-' . str_pad( (string) $payment_day, 2, '0', STR_PAD_LEFT );

		$tax_column_label = 'otsu' === $employee->tax_column ? __( '乙欄', 'payroll-system' ) : __( '甲欄', 'payroll-system' );

		ob_start();
		?>
		<style>
			body { font-family: sun-exta; font-size: 10px; color: #222; }
			h1 { font-size: 16px; text-align: center; margin: 0 0 10px; }
			.header-table { border: none; }
			.header-table td { border: none; padding: 2px 4px; vertical-align: top; }
			.header-left { font-size: 11px; }
			.header-left .company { font-size: 12px; font-weight: bold; margin-bottom: 4px; }
			.header-right { text-align: right; font-size: 11px; }
			table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
			th, td { border: 1px solid #999; padding: 3px 5px; font-size: 9.5px; }
			.grid-outer td { vertical-align: top; padding: 0 2px; border: none; width: 25%; }
			.mini th { background: #eee; text-align: center; font-weight: bold; }
			.mini td.label { width: 60%; text-align: left; }
			.mini td.amount { width: 40%; text-align: right; }
			.mini tr.totals td { font-weight: bold; background: #f6f6f6; }
			.net-table th, .net-table td { font-size: 14px; background: #eef6ff; text-align: center; font-weight: bold; }
			.footer-table td { font-size: 9.5px; }
			.footer-table th { background: #eee; text-align: left; width: 45%; font-weight: normal; }
			.footer-table td.amount { text-align: right; width: 30%; }
			.stamp-box { display: inline-block; width: 46px; height: 46px; border: 1px solid #999; line-height: 46px; font-size: 8px; }
			table.bottom-row { border: none; }
			table.bottom-row td.outer-cell { border: none; vertical-align: top; padding: 0 2px; }
			.tax-box th { background: #eee; font-weight: normal; text-align: left; }
			.tax-box td.amount { text-align: right; }
		</style>

		<h1><?php echo esc_html__( '給与明細書', 'payroll-system' ); ?></h1>

		<table class="header-table">
			<tr>
				<td class="header-left" style="width:65%;">
					<?php if ( $company_name ) : ?>
						<div class="company"><?php echo esc_html( $company_name ); ?></div>
					<?php endif; ?>
					<?php echo esc_html( $slip->target_year_month . '分給与明細書' ); ?><br>
					<?php
					printf(
						/* translators: 1: 社員コード 2: 氏名 */
						esc_html__( '従業員氏名　（%1$s）%2$s 様', 'payroll-system' ),
						esc_html( $employee->employee_code ),
						esc_html( $employee->name )
					);
					?>
				</td>
				<td class="header-right" style="width:30%;">
					<?php
					printf(
						/* translators: %s: 支給日 */
						esc_html__( '支給日　%s', 'payroll-system' ),
						esc_html( $payment_date )
					);
					?>
				</td>
				<td style="width:15%; text-align:center;">
					<div class="stamp-box"><?php echo esc_html__( '受領印', 'payroll-system' ); ?></div>
				</td>
			</tr>
		</table>

		<table class="grid-outer">
			<tr>
				<td>
					<table class="mini">
						<tr><th colspan="2"><?php echo esc_html__( '勤怠', 'payroll-system' ); ?></th></tr>
						<?php foreach ( $attendance_rows as $label => $value ) : ?>
							<tr><td class="label"><?php echo esc_html( $label ); ?></td><td class="amount"><?php echo esc_html( $value ); ?></td></tr>
						<?php endforeach; ?>
					</table>
				</td>
				<td>
					<table class="mini">
						<tr><th colspan="2"><?php echo esc_html__( '支給', 'payroll-system' ); ?></th></tr>
						<?php foreach ( $gross_rows as $label => $value ) : ?>
							<tr><td class="label"><?php echo esc_html( $label ); ?></td><td class="amount"><?php echo esc_html( $value ); ?></td></tr>
						<?php endforeach; ?>
						<tr class="totals"><td class="label"><?php echo esc_html__( '合計', 'payroll-system' ); ?></td><td class="amount"><?php echo esc_html( $fmt( $slip->total_gross ) ); ?></td></tr>
					</table>
				</td>
				<td>
					<table class="mini">
						<tr><th colspan="2"><?php echo esc_html__( '控除', 'payroll-system' ); ?></th></tr>
						<?php foreach ( $deduction_rows as $label => $value ) : ?>
							<tr><td class="label"><?php echo esc_html( $label ); ?></td><td class="amount"><?php echo esc_html( $value ); ?></td></tr>
						<?php endforeach; ?>
						<tr class="totals"><td class="label"><?php echo esc_html__( '合計', 'payroll-system' ); ?></td><td class="amount"><?php echo esc_html( $fmt( $slip->total_deduction ) ); ?></td></tr>
					</table>
				</td>
				<td>
					<table class="mini">
						<tr><th colspan="2"><?php echo esc_html__( 'その他', 'payroll-system' ); ?></th></tr>
						<?php foreach ( $other_rows as $label => $value ) : ?>
							<tr><td class="label"><?php echo esc_html( $label ); ?></td><td class="amount"><?php echo esc_html( $value ); ?></td></tr>
						<?php endforeach; ?>
						<tr class="totals"><td class="label"><?php echo esc_html__( '合計', 'payroll-system' ); ?></td><td class="amount"><?php echo esc_html( $fmt( $other_total ) ); ?></td></tr>
					</table>
				</td>
			</tr>
		</table>

		<table class="bottom-row">
			<tr>
				<td class="outer-cell" style="width:25%;">
					<table class="mini tax-box">
						<tr><th><?php echo esc_html__( '税額表', 'payroll-system' ); ?></th><td class="amount"><?php echo esc_html( $tax_column_label ); ?></td></tr>
						<tr><th><?php echo esc_html__( '扶養人数', 'payroll-system' ); ?></th><td class="amount"><?php echo esc_html( (int) $employee->dependents_count ); ?></td></tr>
					</table>
				</td>
				<td class="outer-cell" style="width:75%;"></td>
			</tr>
		</table>

		<table class="net-table">
			<tr><th><?php echo esc_html__( '差引支給額', 'payroll-system' ); ?></th><td><?php echo esc_html( $fmt( $slip->net_amount ) . '円' ); ?></td></tr>
		</table>

		<table class="bottom-row">
			<tr>
				<td class="outer-cell" style="width:50%;"></td>
				<td class="outer-cell" style="width:50%;">
					<table class="footer-table">
						<tr><th><?php echo esc_html__( '振込支給額1', 'payroll-system' ); ?><?php echo $employee->bank_name ? ' (' . esc_html( $employee->bank_name . ' ' . $employee->bank_branch ) . ')' : ''; ?></th><td class="amount"><?php echo esc_html( $fmt( $transfer_amount ) ); ?></td></tr>
						<tr><th><?php echo esc_html__( '振込支給額2', 'payroll-system' ); ?></th><td class="amount">0</td></tr>
						<tr><th><?php echo esc_html__( '振込支給額3', 'payroll-system' ); ?></th><td class="amount">0</td></tr>
						<tr class="totals"><th><?php echo esc_html__( '合計', 'payroll-system' ); ?></th><td class="amount"><?php echo esc_html( $fmt( $transfer_amount ) ); ?></td></tr>
						<tr><th><?php echo esc_html__( '現金支給額', 'payroll-system' ); ?></th><td class="amount"><?php echo esc_html( $fmt( $cash_payment ) ); ?></td></tr>
						<tr><th><?php echo esc_html__( '現物支給額', 'payroll-system' ); ?></th><td class="amount"><?php echo esc_html( $fmt( $in_kind_payment ) ); ?></td></tr>
						<tr><th><?php echo esc_html__( '課税支給累計', 'payroll-system' ); ?></th><td class="amount"><?php echo esc_html( $fmt( $ytd['taxable_ytd'] ?? 0 ) ); ?></td></tr>
						<tr><th><?php echo esc_html__( '社会保険累計', 'payroll-system' ); ?></th><td class="amount"><?php echo esc_html( $fmt( $ytd['social_insurance_ytd'] ?? 0 ) ); ?></td></tr>
						<tr><th><?php echo esc_html__( '所得税累計', 'payroll-system' ); ?></th><td class="amount"><?php echo esc_html( $fmt( $ytd['income_tax_ytd'] ?? 0 ) ); ?></td></tr>
					</table>
				</td>
			</tr>
		</table>
		<?php
		return ob_get_clean();
	}
}
