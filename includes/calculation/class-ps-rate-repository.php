<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 各種料率・税額表マスタから「対象年月時点で有効な行」を取得するリポジトリ。
 *
 * 共通ルール：
 *   対象年月以前の effective_year_month のうち、最も新しいものを「有効な行」とする。
 *   （= 未来の改定を先取りしない。過去分は改定前の当時の料率をそのまま使い続ける）
 */
class PS_Rate_Repository {

	public function get_health_insurance_rate( string $target_year_month, string $prefecture, int $grade ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ps_rate_health_insurance';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE prefecture = %s AND grade = %d AND effective_year_month <= %s
			 ORDER BY effective_year_month DESC LIMIT 1",
			$prefecture,
			$grade,
			$target_year_month
		) );
	}

	public function get_pension_rate( string $target_year_month, int $grade ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ps_rate_pension';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE grade = %d AND effective_year_month <= %s
			 ORDER BY effective_year_month DESC LIMIT 1",
			$grade,
			$target_year_month
		) );
	}

	public function get_employment_insurance_rate( string $target_year_month, string $business_type ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ps_rate_employment_insurance';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE business_type = %s AND effective_year_month <= %s
			 ORDER BY effective_year_month DESC LIMIT 1",
			$business_type,
			$target_year_month
		) );
	}

	public function get_income_tax_row( string $target_year_month, string $column_type, float $base_amount ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ps_rate_income_tax';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE column_type = %s AND effective_year_month <= %s
			 AND %f >= salary_from AND %f < salary_to
			 ORDER BY effective_year_month DESC LIMIT 1",
			$column_type,
			$target_year_month,
			$base_amount,
			$base_amount
		) );
	}

	public function get_premium_wage_rate( string $target_year_month ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ps_rate_premium_wage';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE effective_year_month <= %s
			 ORDER BY effective_year_month DESC LIMIT 1",
			$target_year_month
		) );

		// マスタ未登録時のフォールバック（法定最低ライン）
		if ( ! $row ) {
			$row = (object) array(
				'overtime_rate'          => 1.25,
				'late_night_addon_rate'   => 0.25,
				'holiday_rate'            => 1.35,
			);
		}

		return $row;
	}
}
