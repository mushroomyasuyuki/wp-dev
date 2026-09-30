<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 給与計算のオーケストレーター。
 * 「従業員1人・対象年月1件」を単位として実行する。
 *
 * 実際の料率参照・端数処理・法令上の細かい分岐（例:月60時間超の割増率変更等）は
 * 各サブメソッド内に実装していく。ここでは全体の処理順序と戻り値の形だけを定義した雛形。
 *
 * 使い方:
 *   $calculator = new PS_Payroll_Calculator();
 *   $result = $calculator->calculate( $employee_id, '2026-09' );
 */
class PS_Payroll_Calculator {

	/** @var PS_Rate_Repository */
	private $rates;

	public function __construct() {
		$this->rates = new PS_Rate_Repository();
	}

	/**
	 * @param int    $employee_id
	 * @param string $target_year_month 'YYYY-MM'
	 * @return array 計算結果一式（支給内訳・控除内訳・合計・使用マスタバージョン）
	 */
	public function calculate( int $employee_id, string $target_year_month ): array {
		global $wpdb;

		$employee  = $this->get_employee( $employee_id );
		$attendance = $this->get_attendance( $employee_id, $target_year_month );

		if ( ! $employee ) {
			throw new InvalidArgumentException( "従業員が見つかりません: {$employee_id}" );
		}
		if ( ! $attendance ) {
			throw new InvalidArgumentException( "勤怠データが見つかりません: {$employee_id} / {$target_year_month}" );
		}

		// 対象年月時点で有効な各種マスタを取得
		$premium_wage_rate = $this->rates->get_premium_wage_rate( $target_year_month );

		$gross = $this->calculate_gross_amounts( $employee, $attendance, $premium_wage_rate );

		$social_insurance = $this->calculate_social_insurance( $employee, $target_year_month, $gross['total_gross'] );
		$social_insurance_total = $social_insurance['health_insurance']
			+ $social_insurance['nursing_care_insurance']
			+ $social_insurance['pension']
			+ $social_insurance['employment_insurance'];

		$income_tax = $this->calculate_income_tax(
			$gross['taxable_amount'],
			$social_insurance_total,
			$employee,
			$target_year_month
		);

		$resident_tax = (float) $employee->resident_tax_monthly;

		$other_deductions = 0.0; // 財形貯蓄・社宅費等。今後 employee/deduction 設定から算出

		$deduction_total = $social_insurance_total + $income_tax + $resident_tax + $other_deductions;
		$net_amount      = $gross['total_gross'] - $deduction_total;

		return array(
			'employee_id'        => $employee_id,
			'target_year_month'  => $target_year_month,
			'gross'              => $gross,
			'deductions'         => array(
				'health_insurance'            => $social_insurance['health_insurance'],
				'nursing_care_insurance'      => $social_insurance['nursing_care_insurance'],
				'pension'                      => $social_insurance['pension'],
				'employment_insurance'         => $social_insurance['employment_insurance'],
				'income_tax'                   => $income_tax,
				'resident_tax'                 => $resident_tax,
				'other'                        => $other_deductions,
				// 手入力の調整項目（社保料調整・調整控除・年末調整・過年度調整）。
				// 自動計算では常に0で作成し、給与明細画面の「調整」から個別に上書きする。
				'social_insurance_adjustment'  => 0.0,
				'adjustment_deduction'         => 0.0,
				'nenmatsu_chousei_deduction'   => 0.0,
				'nenmatsu_chousei_refund'      => 0.0,
				'prior_year_adjustment'        => 0.0,
				'total'                        => $deduction_total,
			),
			'total_gross'        => $gross['total_gross'],
			'total_deduction'    => $deduction_total,
			'net_amount'         => $net_amount,
			'rate_master_version' => $target_year_month, // TODO: 実際に参照した各マスタのeffective_year_monthをまとめて記録する
		);
	}

	/**
	 * 支給項目の計算。
	 * 給与形態（時給/日給/月給）に応じて基本給を算出し、割増賃金・固定手当を加算する。
	 */
	private function calculate_gross_amounts( $employee, $attendance, $premium_wage_rate ): array {
		$base_salary     = 0.0;
		$absence_deduction = 0.0;
		$hourly_rate     = 0.0; // 割増計算のための時間単価換算

		switch ( $employee->pay_type ) {
			case 'monthly':
				$base_salary = (float) $employee->pay_rate;
				// TODO: 所定労働日数を従業員マスタまたは会社カレンダーマスタから取得する
				$scheduled_work_days = 20;
				if ( $attendance->absence_days > 0 && $scheduled_work_days > 0 ) {
					$daily_rate        = $base_salary / $scheduled_work_days;
					$absence_deduction = $daily_rate * (float) $attendance->absence_days;
				}
				// TODO: 所定労働時間(1日)を会社カレンダーマスタから取得する
				$scheduled_hours_per_day = 8;
				$hourly_rate = $scheduled_work_days > 0
					? $base_salary / ( $scheduled_work_days * $scheduled_hours_per_day )
					: 0.0;
				break;

			case 'daily':
				$base_salary = (float) $employee->pay_rate * (float) $attendance->work_days;
				$scheduled_hours_per_day = 8; // TODO: マスタ化
				$hourly_rate = $scheduled_hours_per_day > 0
					? (float) $employee->pay_rate / $scheduled_hours_per_day
					: 0.0;
				break;

			case 'hourly':
			default:
				$base_salary = (float) $employee->pay_rate * (float) $attendance->scheduled_hours;
				$hourly_rate = (float) $employee->pay_rate;
				break;
		}

		$overtime_pay     = $hourly_rate * (float) $premium_wage_rate->overtime_rate * (float) $attendance->overtime_hours;
		$late_night_pay   = $hourly_rate * (float) $premium_wage_rate->late_night_addon_rate * (float) $attendance->late_night_hours;
		$holiday_work_pay = $hourly_rate * (float) $premium_wage_rate->holiday_rate * (float) $attendance->holiday_work_hours;
		$late_early_deduction = $hourly_rate * (float) ( $attendance->late_early_hours ?? 0 );

		$commuting_allowance         = (float) $employee->commuting_allowance;
		$commuting_allowance_taxable = (float) ( $employee->commuting_allowance_taxable ?? 0 );
		$housing_allowance           = (float) $employee->housing_allowance;
		$position_allowance          = (float) ( $employee->position_allowance ?? 0 );
		$family_allowance            = (float) ( $employee->family_allowance ?? 0 );

		// TODO: 通勤手当の非課税限度額判定（現行:月15万円が目安、要最新法令確認）
		$non_taxable_commuting_limit = 150000;
		$non_taxable_commuting       = min( $commuting_allowance, $non_taxable_commuting_limit );

		$total_gross = $base_salary - $absence_deduction - $late_early_deduction
			+ $overtime_pay + $late_night_pay + $holiday_work_pay
			+ $commuting_allowance + $commuting_allowance_taxable + $housing_allowance
			+ $position_allowance + $family_allowance;

		$taxable_amount = $total_gross - $non_taxable_commuting;

		// 表示用の勤怠内訳（財務計算には影響しない）
		$total_working_hours = (float) $attendance->scheduled_hours + (float) $attendance->overtime_hours + (float) $attendance->holiday_work_hours;

		return array(
			'base_salary'                  => round( $base_salary, 0 ),
			'absence_deduction'            => round( $absence_deduction, 0 ),
			'late_early_deduction'         => round( $late_early_deduction, 0 ),
			'overtime_pay'                 => round( $overtime_pay, 0 ),
			'late_night_pay'               => round( $late_night_pay, 0 ),
			'holiday_work_pay'             => round( $holiday_work_pay, 0 ),
			'commuting_allowance'          => round( $commuting_allowance, 0 ),
			'commuting_allowance_taxable'  => round( $commuting_allowance_taxable, 0 ),
			'housing_allowance'            => round( $housing_allowance, 0 ),
			'position_allowance'           => round( $position_allowance, 0 ),
			'family_allowance'             => round( $family_allowance, 0 ),
			'total_gross'                  => round( $total_gross, 0 ),
			'taxable_amount'               => round( $taxable_amount, 0 ),
			'total_working_hours'          => round( $total_working_hours, 2 ),
			// 手入力の調整項目（振込/現金の内訳）。自動計算では常に0で作成する。
			'cash_payment'                 => 0.0,
			'in_kind_payment'              => 0.0,
		);
	}

	/**
	 * 社会保険料（健康保険・厚生年金・雇用保険）の計算。
	 * 健康保険・厚生年金は標準報酬月額等級を、雇用保険は総支給額（$total_gross）に
	 * 雇用保険料率マスタの被保険者負担率を乗じて算出する。
	 *
	 * 雇用保険の事業区分（一般/農林水産・清酒製造/建設）は従業員ごとではなく会社単位の設定
	 * （オプション payroll_system_business_type、給与設定（マスタ）＞雇用保険料率タブで変更）を使う。
	 */
	private function calculate_social_insurance( $employee, string $target_year_month, float $total_gross ): array {
		if ( $employee->social_insurance_status !== 'enrolled' ) {
			return array(
				'health_insurance'      => 0.0,
				'nursing_care_insurance' => 0.0,
				'pension'                => 0.0,
				'employment_insurance'   => 0.0,
			);
		}

		$health_row = $this->rates->get_health_insurance_rate(
			$target_year_month,
			$employee->prefecture,
			(int) $employee->standard_monthly_remuneration_grade
		);

		// 健康保険料（介護保険なし相当額）と介護保険料（40-64歳のみ・40歳未満/65歳以上は0円）を
		// 別々の明細項目として算出する。両者の合計は、従来「health_insuranceに介護保険込みの
		// employee_amount_with_careをそのまま入れていた」ときと同じ金額になる。
		$age = $this->calculate_age( $employee->birth_date );
		$health_insurance_base = (float) ( $health_row->employee_amount ?? 0 );
		$nursing_care_insurance = 0.0;
		if ( $age >= 40 && $age < 65 && $health_row && null !== $health_row->employee_amount_with_care ) {
			$nursing_care_insurance = (float) $health_row->employee_amount_with_care - $health_insurance_base;
		}

		$pension_row = $this->rates->get_pension_rate(
			$target_year_month,
			(int) $employee->standard_monthly_remuneration_grade
		);
		$pension = (float) ( $pension_row->employee_amount ?? 0 );

		$business_type = get_option( 'payroll_system_business_type', 'general' );
		$employment_insurance_row = $this->rates->get_employment_insurance_rate( $target_year_month, $business_type );
		$employment_insurance = $total_gross * (float) ( $employment_insurance_row->employee_rate ?? 0 );

		return array(
			'health_insurance'      => round( $health_insurance_base, 0 ),
			'nursing_care_insurance' => round( $nursing_care_insurance, 0 ),
			'pension'                => round( $pension, 0 ),
			'employment_insurance'   => round( $employment_insurance, 0 ),
		);
	}

	/**
	 * 所得税（源泉徴収）の計算。
	 * 「課税支給額－社会保険料合計」を所得税額表に照合する。
	 */
	private function calculate_income_tax( float $taxable_amount, float $social_insurance_total, $employee, string $target_year_month ): float {
		$base_for_tax = $taxable_amount - $social_insurance_total;

		$row = $this->rates->get_income_tax_row( $target_year_month, $employee->tax_column, $base_for_tax );

		if ( ! $row ) {
			return 0.0; // TODO: 税額表の範囲外（最高額超過等）の扱いを実装する
		}

		if ( $employee->tax_column === 'otsu' ) {
			return (float) $row->tax_amount_otsu;
		}

		$dependents_col = 'dependents_' . min( 7, max( 0, (int) $employee->dependents_count ) );
		return (float) $row->{$dependents_col};
	}

	private function get_employee( int $employee_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ps_employees';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $employee_id ) );
	}

	private function get_attendance( int $employee_id, string $target_year_month ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ps_attendance';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE employee_id = %d AND target_year_month = %s",
			$employee_id,
			$target_year_month
		) );
	}

	private function calculate_age( ?string $birth_date ): int {
		if ( empty( $birth_date ) ) {
			return 0;
		}
		try {
			$birth = new DateTime( $birth_date );
			$now   = new DateTime();
			return (int) $birth->diff( $now )->y;
		} catch ( Exception $e ) {
			return 0;
		}
	}
}
