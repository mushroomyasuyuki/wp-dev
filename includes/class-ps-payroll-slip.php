<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 給与明細（wp_ps_payroll_slips）のCRUDを担当するリポジトリ。
 * 「従業員1人・対象年月1件」につき1明細（employee_id, target_year_monthのUNIQUE制約）。
 */
class PS_Payroll_Slip {

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ps_payroll_slips';
	}

	public static function find( int $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	public static function find_by_employee_month( int $employee_id, string $target_year_month ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE employee_id = %d AND target_year_month = %s",
			$employee_id,
			$target_year_month
		) );
	}

	/**
	 * PS_Payroll_Calculator::calculate() の結果を保存する。
	 * 同一従業員・同一対象年月の明細が既にあれば再計算として上書きし、無ければ新規作成する。
	 * ただし確定済み（status=confirmed/corrected）の明細は上書きしない。確定後の修正は
	 * correct() を使う「訂正」フローで行う。再計算・訂正のどちらでも、手入力の調整項目は引き継ぐ。
	 *
	 * @return array|WP_Error 成功時 [ 'id', 'action' => 'created'|'recalculated', 'before', 'after' ]
	 */
	public static function save_calculation( int $employee_id, string $target_year_month, array $result, int $user_id ) {
		global $wpdb;

		$existing = self::find_by_employee_month( $employee_id, $target_year_month );

		if ( $existing && in_array( $existing->status, array( 'confirmed', 'corrected' ), true ) ) {
			return new WP_Error(
				'already_confirmed',
				__( 'この従業員のこの月の給与明細は確定済みのため、再計算できません。', 'payroll-system' )
			);
		}

		$now  = current_time( 'mysql' );
		$data = array(
			'employee_id'             => $employee_id,
			'target_year_month'       => $target_year_month,
			'status'                   => 'reviewing',
			'gross_amounts_json'       => wp_json_encode( $result['gross'] ),
			'deduction_amounts_json'   => wp_json_encode( $result['deductions'] ),
			'total_gross'              => $result['total_gross'],
			'total_deduction'          => $result['total_deduction'],
			'net_amount'               => $result['net_amount'],
			'rate_master_version'      => $result['rate_master_version'],
			'calculated_by'            => $user_id,
			'calculated_at'            => $now,
			'updated_at'               => $now,
		);

		if ( $existing ) {
			$wpdb->update( self::table(), $data, array( 'id' => $existing->id ) );
			// 計算結果の調整項目は常に0なので、手入力済みの調整項目を引き継いで合計を再計算する
			self::update_adjustments( (int) $existing->id, self::extract_adjustments( $existing ) );
			return array(
				'id'     => (int) $existing->id,
				'action' => 'recalculated',
				'before' => (array) $existing,
				'after'  => (array) self::find( (int) $existing->id ),
			);
		}

		$data['is_deleted'] = 0;
		$data['created_at'] = $now;

		$wpdb->insert( self::table(), $data );
		$id = (int) $wpdb->insert_id;

		return array(
			'id'     => $id,
			'action' => 'created',
			'before' => null,
			'after'  => (array) self::find( $id ),
		);
	}

	/**
	 * 明細を確定する（status: calculating/reviewing → confirmed）。
	 *
	 * @return true|WP_Error
	 */
	public static function confirm( int $id ) {
		$slip = self::find( $id );
		if ( ! $slip ) {
			return new WP_Error( 'not_found', __( '給与明細が見つかりません。', 'payroll-system' ) );
		}
		if ( 'confirmed' === $slip->status ) {
			return new WP_Error( 'already_confirmed', __( 'すでに確定済みです。', 'payroll-system' ) );
		}

		global $wpdb;
		$wpdb->update( self::table(), array(
			'status'       => 'confirmed',
			'confirmed_at' => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		), array( 'id' => $id ) );

		return true;
	}

	/**
	 * 確定済み（confirmed/corrected）の明細を訂正する。
	 * 訂正前のスナップショットは呼び出し元がPS_Audit_Loggerに渡して監査ログへ残す
	 * （このメソッド自体は上書きのみを行う。旧データの保持はDBの物理列ではなく監査ログ側の責務）。
	 *
	 * @return true|WP_Error
	 */
	public static function correct( int $id, array $result ) {
		$slip = self::find( $id );
		if ( ! $slip ) {
			return new WP_Error( 'not_found', __( '給与明細が見つかりません。', 'payroll-system' ) );
		}
		if ( ! in_array( $slip->status, array( 'confirmed', 'corrected' ), true ) ) {
			return new WP_Error( 'not_confirmed', __( '確定済みの明細のみ訂正できます。', 'payroll-system' ) );
		}

		global $wpdb;
		$wpdb->update( self::table(), array(
			'status'                   => 'corrected',
			'gross_amounts_json'       => wp_json_encode( $result['gross'] ),
			'deduction_amounts_json'   => wp_json_encode( $result['deductions'] ),
			'total_gross'              => $result['total_gross'],
			'total_deduction'          => $result['total_deduction'],
			'net_amount'               => $result['net_amount'],
			'rate_master_version'      => $result['rate_master_version'],
			'updated_at'               => current_time( 'mysql' ),
		), array( 'id' => $id ) );

		// 計算結果の調整項目は常に0なので、訂正前に手入力されていた調整項目
		// （年末調整の還付・徴収等）を引き継いで合計を再計算する
		self::update_adjustments( $id, self::extract_adjustments( $slip ) );

		return true;
	}

	/**
	 * 明細に保存されている手入力の調整項目（update_adjustments()で扱う7項目）を取り出す。
	 * 再計算・訂正で計算結果を上書きするときに、これらが0に戻らないよう引き継ぐために使う。
	 */
	private static function extract_adjustments( $slip ): array {
		$gross      = json_decode( (string) $slip->gross_amounts_json, true ) ?: array();
		$deductions = json_decode( (string) $slip->deduction_amounts_json, true ) ?: array();

		return array(
			'social_insurance_adjustment' => (float) ( $deductions['social_insurance_adjustment'] ?? 0 ),
			'adjustment_deduction'        => (float) ( $deductions['adjustment_deduction'] ?? 0 ),
			'nenmatsu_chousei_deduction'  => (float) ( $deductions['nenmatsu_chousei_deduction'] ?? 0 ),
			'nenmatsu_chousei_refund'     => (float) ( $deductions['nenmatsu_chousei_refund'] ?? 0 ),
			'prior_year_adjustment'       => (float) ( $deductions['prior_year_adjustment'] ?? 0 ),
			'cash_payment'                => (float) ( $gross['cash_payment'] ?? 0 ),
			'in_kind_payment'             => (float) ( $gross['in_kind_payment'] ?? 0 ),
		);
	}

	/**
	 * 対象年月と同じ年（1月〜対象月）の、確定済み（confirmed/corrected）明細を
	 * 合算した累計額を返す（課税支給累計・社会保険累計・所得税累計）。
	 * 給与明細PDFの「その他」欄に表示する。対象となる明細自身も含む。
	 */
	public static function get_ytd_totals( int $employee_id, string $target_year_month ): array {
		global $wpdb;
		$year      = substr( $target_year_month, 0, 4 );
		$from      = $year . '-01';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT gross_amounts_json, deduction_amounts_json FROM " . self::table() . "
			 WHERE employee_id = %d AND target_year_month BETWEEN %s AND %s
			 AND status IN ('confirmed','corrected') AND is_deleted = 0",
			$employee_id,
			$from,
			$target_year_month
		) );

		$taxable_ytd         = 0.0;
		$social_insurance_ytd = 0.0;
		$income_tax_ytd       = 0.0;

		foreach ( $rows as $row ) {
			$gross      = json_decode( $row->gross_amounts_json, true ) ?: array();
			$deductions = json_decode( $row->deduction_amounts_json, true ) ?: array();

			$taxable_ytd          += (float) ( $gross['taxable_amount'] ?? 0 );
			$social_insurance_ytd += (float) ( $deductions['health_insurance'] ?? 0 )
				+ (float) ( $deductions['nursing_care_insurance'] ?? 0 )
				+ (float) ( $deductions['pension'] ?? 0 )
				+ (float) ( $deductions['employment_insurance'] ?? 0 );
			$income_tax_ytd       += (float) ( $deductions['income_tax'] ?? 0 );
		}

		return array(
			'taxable_ytd'           => round( $taxable_ytd, 0 ),
			'social_insurance_ytd'  => round( $social_insurance_ytd, 0 ),
			'income_tax_ytd'        => round( $income_tax_ytd, 0 ),
		);
	}

	/**
	 * 自動計算では扱わない手入力の調整項目（社保料調整・調整控除・年末調整還付／徴収・
	 * 過年度調整・現金支給額・現物支給額）を保存する。
	 *
	 * 自動計算由来の内訳（health_insurance/pension/employment_insurance/income_tax/
	 * resident_tax/other）は一切変更せず、そこに調整項目を上乗せしてtotal_deduction・
	 * net_amountを再計算する。何度呼んでも「自動計算分＋最新の調整項目」から毎回
	 * 計算し直すため、保存し直しても二重加算にはならない（冪等）。
	 *
	 * @return true|WP_Error
	 */
	public static function update_adjustments( int $id, array $adjustments ) {
		$slip = self::find( $id );
		if ( ! $slip ) {
			return new WP_Error( 'not_found', __( '給与明細が見つかりません。', 'payroll-system' ) );
		}

		$gross      = json_decode( $slip->gross_amounts_json, true ) ?: array();
		$deductions = json_decode( $slip->deduction_amounts_json, true ) ?: array();

		$deductions['social_insurance_adjustment']  = (float) ( $adjustments['social_insurance_adjustment'] ?? 0 );
		$deductions['adjustment_deduction']         = (float) ( $adjustments['adjustment_deduction'] ?? 0 );
		$deductions['nenmatsu_chousei_deduction']   = (float) ( $adjustments['nenmatsu_chousei_deduction'] ?? 0 );
		$deductions['nenmatsu_chousei_refund']      = (float) ( $adjustments['nenmatsu_chousei_refund'] ?? 0 );
		$deductions['prior_year_adjustment']        = (float) ( $adjustments['prior_year_adjustment'] ?? 0 );

		$gross['cash_payment']    = (float) ( $adjustments['cash_payment'] ?? 0 );
		$gross['in_kind_payment'] = (float) ( $adjustments['in_kind_payment'] ?? 0 );

		$base_components = array( 'health_insurance', 'nursing_care_insurance', 'pension', 'employment_insurance', 'income_tax', 'resident_tax', 'other' );
		$base_total       = 0.0;
		foreach ( $base_components as $key ) {
			$base_total += (float) ( $deductions[ $key ] ?? 0 );
		}

		$adjustment_total = $deductions['social_insurance_adjustment']
			+ $deductions['adjustment_deduction']
			+ $deductions['nenmatsu_chousei_deduction']
			- $deductions['nenmatsu_chousei_refund']
			+ $deductions['prior_year_adjustment'];

		$new_total_deduction = $base_total + $adjustment_total;
		$deductions['total']  = $new_total_deduction;

		$new_net_amount = (float) $slip->total_gross - $new_total_deduction;

		global $wpdb;
		$wpdb->update( self::table(), array(
			'gross_amounts_json'     => wp_json_encode( $gross ),
			'deduction_amounts_json' => wp_json_encode( $deductions ),
			'total_deduction'        => $new_total_deduction,
			'net_amount'             => $new_net_amount,
			'updated_at'             => current_time( 'mysql' ),
		), array( 'id' => $id ) );

		return true;
	}
}
