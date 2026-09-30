<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 従業員マスタのCRUDを担当するリポジトリクラス。
 * DBアクセスとバリデーションをここに集約し、画面側（admin/views）や
 * コントローラー（PS_Employee_Controller）からはこのクラス経由で操作する。
 */
class PS_Employee {

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ps_employees';
	}

	/**
	 * 一覧取得（簡易ページング付き）
	 */
	public static function get_all( array $args = array() ) {
		global $wpdb;
		$table = self::table();

		$defaults = array(
			'status'   => '',   // '' = 全件, 'active', 'retired'
			'orderby'  => 'id',
			'order'    => 'DESC',
			'per_page' => 50,
			'paged'    => 1,
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		$orderby = in_array( $args['orderby'], array( 'id', 'employee_code', 'name', 'created_at' ), true )
			? $args['orderby']
			: 'id';
		$order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$offset = max( 0, ( (int) $args['paged'] - 1 ) * (int) $args['per_page'] );

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where )
			. " ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$params[] = (int) $args['per_page'];
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	public static function count( array $args = array() ) {
		global $wpdb;
		$table = self::table();

		if ( ! empty( $args['status'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = %s",
				$args['status']
			) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	public static function find( int $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	public static function find_by_code( string $employee_code, int $exclude_id = 0 ) {
		global $wpdb;
		$table = self::table();
		if ( $exclude_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			return $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE employee_code = %s AND id != %d",
				$employee_code,
				$exclude_id
			) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE employee_code = %s", $employee_code ) );
	}

	/**
	 * 入力値のサニタイズ＋バリデーション。
	 * $raw は $_POST 相当の連想配列を想定。
	 *
	 * @return array [ 'data' => 保存用の連想配列, 'errors' => エラーメッセージの配列 ]
	 */
	public static function validate( array $raw, int $exclude_id = 0 ): array {
		$errors = array();

		$data = array(
			'employee_code'                        => sanitize_text_field( $raw['employee_code'] ?? '' ),
			'name'                                  => sanitize_text_field( $raw['name'] ?? '' ),
			'address'                               => sanitize_text_field( $raw['address'] ?? '' ),
			'phone'                                 => sanitize_text_field( $raw['phone'] ?? '' ),
			'employment_type'                       => in_array( $raw['employment_type'] ?? '', array( 'employee', 'part_timer' ), true )
				? $raw['employment_type'] : 'employee',
			'pay_type'                              => in_array( $raw['pay_type'] ?? '', array( 'hourly', 'daily', 'monthly' ), true )
				? $raw['pay_type'] : 'monthly',
			'pay_rate'                              => self::to_decimal( $raw['pay_rate'] ?? 0 ),
			'commuting_allowance'                   => self::to_decimal( $raw['commuting_allowance'] ?? 0 ),
			'commuting_allowance_taxable'            => self::to_decimal( $raw['commuting_allowance_taxable'] ?? 0 ),
			'housing_allowance'                     => self::to_decimal( $raw['housing_allowance'] ?? 0 ),
			'position_allowance'                     => self::to_decimal( $raw['position_allowance'] ?? 0 ),
			'family_allowance'                       => self::to_decimal( $raw['family_allowance'] ?? 0 ),
			'paid_leave_granted_days'                => self::to_decimal( $raw['paid_leave_granted_days'] ?? 0 ),
			'bank_name'                               => sanitize_text_field( $raw['bank_name'] ?? '' ),
			'bank_branch'                             => sanitize_text_field( $raw['bank_branch'] ?? '' ),
			'bank_account_type'                       => in_array( $raw['bank_account_type'] ?? '', array( 'ordinary', 'checking' ), true )
				? $raw['bank_account_type'] : 'ordinary',
			'bank_account_number'                     => sanitize_text_field( $raw['bank_account_number'] ?? '' ),
			'bank_account_holder'                     => sanitize_text_field( $raw['bank_account_holder'] ?? '' ),
			'social_insurance_status'               => in_array( $raw['social_insurance_status'] ?? '', array( 'enrolled', 'not_enrolled' ), true )
				? $raw['social_insurance_status'] : 'not_enrolled',
			'standard_monthly_remuneration_grade'   => ! empty( $raw['standard_monthly_remuneration_grade'] )
				? absint( $raw['standard_monthly_remuneration_grade'] ) : null,
			'prefecture'                             => sanitize_text_field( $raw['prefecture'] ?? '' ),
			'dependents_count'                       => absint( $raw['dependents_count'] ?? 0 ),
			'tax_column'                             => in_array( $raw['tax_column'] ?? '', array( 'ko', 'otsu' ), true )
				? $raw['tax_column'] : 'ko',
			'resident_tax_monthly'                   => self::to_decimal( $raw['resident_tax_monthly'] ?? 0 ),
			'birth_date'                             => self::to_date_or_null( $raw['birth_date'] ?? '' ),
			'hire_date'                               => self::to_date_or_null( $raw['hire_date'] ?? '' ),
			'status'                                  => in_array( $raw['status'] ?? '', array( 'active', 'retired' ), true )
				? $raw['status'] : 'active',
		);

		// ---- 必須項目 ----
		if ( '' === $data['employee_code'] ) {
			$errors[] = __( '社員コードは必須です。', 'payroll-system' );
		}
		if ( '' === $data['name'] ) {
			$errors[] = __( '氏名は必須です。', 'payroll-system' );
		}

		// ---- 社員コード重複チェック ----
		if ( '' !== $data['employee_code'] && self::find_by_code( $data['employee_code'], $exclude_id ) ) {
			$errors[] = __( 'この社員コードは既に使用されています。', 'payroll-system' );
		}

		// ---- 単価は0以上 ----
		if ( $data['pay_rate'] < 0 ) {
			$errors[] = __( '時給／日給／月給は0以上で入力してください。', 'payroll-system' );
		}

		// ---- 社会保険加入者は都道府県・等級が必要（健保・厚年計算に必須）----
		if ( 'enrolled' === $data['social_insurance_status'] ) {
			if ( '' === $data['prefecture'] ) {
				$errors[] = __( '社会保険加入者は都道府県の指定が必須です（健康保険料率の参照に使用します）。', 'payroll-system' );
			}
			if ( empty( $data['standard_monthly_remuneration_grade'] ) ) {
				$errors[] = __( '社会保険加入者は標準報酬月額等級の指定が必須です。', 'payroll-system' );
			}
		}

		return array(
			'data'   => $data,
			'errors' => $errors,
		);
	}

	public static function create( array $data ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$data['created_at'] = $now;
		$data['updated_at'] = $now;

		$result = $wpdb->insert( self::table(), $data );
		if ( false === $result ) {
			return new WP_Error( 'db_insert_failed', $wpdb->last_error );
		}
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update( self::table(), $data, array( 'id' => $id ) );
		if ( false === $result ) {
			return new WP_Error( 'db_update_failed', $wpdb->last_error );
		}
		return true;
	}

	/**
	 * 削除は「退職済みにする」論理的な意味に加え、
	 * 勤怠・給与明細の紐付けが一切ない場合のみ物理削除を許可する。
	 * 給与データが1件でも存在する従業員は、履歴保持のため削除させない。
	 */
	public static function delete( int $id ) {
		global $wpdb;

		$slip_table = $wpdb->prefix . 'ps_payroll_slips';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$has_slips = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$slip_table} WHERE employee_id = %d",
			$id
		) );

		if ( $has_slips > 0 ) {
			return new WP_Error(
				'has_payroll_history',
				__( 'この従業員には給与計算履歴があるため削除できません。「退職」ステータスに変更してください。', 'payroll-system' )
			);
		}

		$attendance_table = $wpdb->prefix . 'ps_attendance';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $attendance_table, array( 'employee_id' => $id ) );

		$result = $wpdb->delete( self::table(), array( 'id' => $id ) );
		if ( false === $result ) {
			return new WP_Error( 'db_delete_failed', $wpdb->last_error );
		}
		return true;
	}

	private static function to_decimal( $value ): float {
		$value = str_replace( ',', '', (string) $value );
		return is_numeric( $value ) ? (float) $value : 0.0;
	}

	private static function to_date_or_null( $value ) {
		$value = sanitize_text_field( $value );
		if ( '' === $value ) {
			return null;
		}
		// YYYY-MM-DD形式のみ許可
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
	}

	/**
	 * 都道府県一覧（健康保険料率の参照用プルダウンに使用）
	 */
	public static function get_prefectures(): array {
		return array(
			'北海道', '青森県', '岩手県', '宮城県', '秋田県', '山形県', '福島県',
			'茨城県', '栃木県', '群馬県', '埼玉県', '千葉県', '東京都', '神奈川県',
			'新潟県', '富山県', '石川県', '福井県', '山梨県', '長野県', '岐阜県',
			'静岡県', '愛知県', '三重県', '滋賀県', '京都府', '大阪府', '兵庫県',
			'奈良県', '和歌山県', '鳥取県', '島根県', '岡山県', '広島県', '山口県',
			'徳島県', '香川県', '愛媛県', '高知県', '福岡県', '佐賀県', '長崎県',
			'熊本県', '大分県', '宮崎県', '鹿児島県', '沖縄県',
		);
	}
}
