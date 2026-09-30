<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 勤怠データ（wp_ps_attendance）のリポジトリ。
 * 1従業員×1対象年月＝1レコードで管理する（集計済み月次データを前提）。
 */
class PS_Attendance {

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ps_attendance';
	}

	public static function find( int $employee_id, string $target_year_month ) {
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
	 * 対象年月と同じ年（1月〜対象月）に取得した有休日数の合計。
	 * 従業員の有休付与日数から引いて「有休残日数」を出すのに使う。
	 */
	public static function get_paid_leave_used_total( int $employee_id, string $target_year_month ): float {
		global $wpdb;
		$table = self::table();
		$year  = substr( $target_year_month, 0, 4 );
		$from  = $year . '-01';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(paid_leave_days), 0) FROM {$table}
			 WHERE employee_id = %d AND target_year_month BETWEEN %s AND %s",
			$employee_id,
			$from,
			$target_year_month
		) );
	}

	public static function get_by_month( string $target_year_month ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT a.*, e.name AS employee_name, e.employee_code
			 FROM {$table} a
			 INNER JOIN {$wpdb->prefix}ps_employees e ON e.id = a.employee_id
			 WHERE a.target_year_month = %s
			 ORDER BY e.employee_code ASC",
			$target_year_month
		) );
	}

	/**
	 * 既存レコードがあれば更新、なければ新規作成（Upsert）。
	 * CSVの再取込（取り消し→再取込）に対応するため、同一従業員×同一年月は上書きされる。
	 */
	public static function upsert( int $employee_id, string $target_year_month, array $data, ?int $import_batch_id = null ) {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql' );

		$existing = self::find( $employee_id, $target_year_month );

		$record = array(
			'work_days'          => $data['work_days'],
			'absence_days'       => $data['absence_days'],
			'scheduled_hours'    => $data['scheduled_hours'],
			'overtime_hours'     => $data['overtime_hours'],
			'late_night_hours'   => $data['late_night_hours'],
			'holiday_work_hours' => $data['holiday_work_hours'],
			'paid_leave_days'    => $data['paid_leave_days'] ?? 0,
			'late_early_hours'   => $data['late_early_hours'] ?? 0,
			'import_batch_id'    => $import_batch_id,
			'updated_at'         => $now,
		);

		if ( $existing ) {
			return $wpdb->update( $table, $record, array( 'id' => $existing->id ) );
		}

		$record['employee_id']        = $employee_id;
		$record['target_year_month']  = $target_year_month;
		$record['created_at']         = $now;

		return $wpdb->insert( $table, $record );
	}
}
