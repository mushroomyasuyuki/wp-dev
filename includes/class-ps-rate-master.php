<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 各種料率・税額表マスタ（健保／厚年／雇用保険／所得税額表／割増賃金率）のCRUDを
 * 一つのクラスに集約したリポジトリ。5種別ともテーブル構造は異なるが、
 * 「適用開始年月＋区分キーで履歴管理する」という構造が共通しているため、
 * get_type_defs() の定義に従って汎用的に処理する。
 *
 * 画面側（admin/views/rates.php）・コントローラー（PS_Rate_Master_Controller）・
 * CSVインポーター（PS_Rate_Importer）は、すべてこのクラスのget_type_defs()を
 * 唯一の情報源としてフィールド構成を決定する。
 */
class PS_Rate_Master {

	/**
	 * マスタ種別ごとの定義。
	 * table       : $wpdb->prefix . 'ps_' に続くテーブル名
	 * natural_key : CSVインポート時の上書き判定・手動登録時の重複チェックに使う列
	 * list_order  : 一覧表示のORDER BY句
	 * fields      : フォーム・CSV列・バリデーションの唯一の情報源
	 */
	public static function get_type_defs(): array {
		return array(
			'health_insurance'     => array(
				'label'       => __( '健康保険料率', 'payroll-system' ),
				'table'       => 'rate_health_insurance',
				'natural_key' => array( 'effective_year_month', 'prefecture', 'grade' ),
				'list_order'  => 'effective_year_month DESC, prefecture ASC, grade ASC',
				'fields'      => array(
					'effective_year_month'         => array( 'label' => __( '適用開始年月', 'payroll-system' ), 'input' => 'month', 'required' => true ),
					'prefecture'                    => array( 'label' => __( '都道府県', 'payroll-system' ), 'input' => 'select', 'required' => true, 'options' => self::prefecture_options() ),
					'grade'                         => array( 'label' => __( '等級', 'payroll-system' ), 'input' => 'number', 'step' => '1', 'cast' => 'int', 'required' => true ),
					'standard_monthly_remuneration' => array( 'label' => __( '標準報酬月額', 'payroll-system' ), 'input' => 'number', 'step' => '1', 'cast' => 'float', 'required' => true ),
					'employee_amount'               => array( 'label' => __( '本人負担額', 'payroll-system' ), 'input' => 'number', 'step' => '0.01', 'cast' => 'float', 'required' => true ),
					'employee_amount_with_care'     => array( 'label' => __( '本人負担額（介護保険込み）', 'payroll-system' ), 'input' => 'number', 'step' => '0.01', 'cast' => 'float', 'required' => false ),
				),
			),
			'pension'               => array(
				'label'       => __( '厚生年金保険料率', 'payroll-system' ),
				'table'       => 'rate_pension',
				'natural_key' => array( 'effective_year_month', 'grade' ),
				'list_order'  => 'effective_year_month DESC, grade ASC',
				'fields'      => array(
					'effective_year_month'         => array( 'label' => __( '適用開始年月', 'payroll-system' ), 'input' => 'month', 'required' => true ),
					'grade'                         => array( 'label' => __( '等級', 'payroll-system' ), 'input' => 'number', 'step' => '1', 'cast' => 'int', 'required' => true ),
					'standard_monthly_remuneration' => array( 'label' => __( '標準報酬月額', 'payroll-system' ), 'input' => 'number', 'step' => '1', 'cast' => 'float', 'required' => true ),
					'employee_amount'               => array( 'label' => __( '本人負担額', 'payroll-system' ), 'input' => 'number', 'step' => '0.01', 'cast' => 'float', 'required' => true ),
				),
			),
			'employment_insurance'  => array(
				'label'       => __( '雇用保険料率', 'payroll-system' ),
				'table'       => 'rate_employment_insurance',
				'natural_key' => array( 'effective_year_month', 'business_type' ),
				'list_order'  => 'effective_year_month DESC, business_type ASC',
				'fields'      => array(
					'effective_year_month' => array( 'label' => __( '適用開始年月', 'payroll-system' ), 'input' => 'month', 'required' => true ),
					'business_type'         => array(
						'label'    => __( '事業区分', 'payroll-system' ),
						'input'    => 'select',
						'required' => true,
						'options'  => array(
							'general'      => __( '一般の事業', 'payroll-system' ),
							'agriculture'  => __( '農林水産・清酒製造の事業', 'payroll-system' ),
							'construction' => __( '建設の事業', 'payroll-system' ),
						),
					),
					'employee_rate'         => array( 'label' => __( '被保険者負担率', 'payroll-system' ), 'input' => 'number', 'step' => '0.0001', 'cast' => 'float', 'required' => true ),
					'employer_rate'         => array( 'label' => __( '事業主負担率', 'payroll-system' ), 'input' => 'number', 'step' => '0.0001', 'cast' => 'float', 'required' => true ),
				),
			),
			'income_tax'            => array(
				'label'       => __( '所得税額表', 'payroll-system' ),
				'table'       => 'rate_income_tax',
				'natural_key' => array( 'effective_year_month', 'column_type', 'salary_from' ),
				'list_order'  => 'effective_year_month DESC, column_type ASC, salary_from ASC',
				'fields'      => array(
					'effective_year_month' => array( 'label' => __( '適用開始年月', 'payroll-system' ), 'input' => 'month', 'required' => true ),
					'column_type'           => array(
						'label'    => __( '甲欄／乙欄', 'payroll-system' ),
						'input'    => 'select',
						'required' => true,
						'options'  => array(
							'ko'   => __( '甲欄', 'payroll-system' ),
							'otsu' => __( '乙欄', 'payroll-system' ),
						),
					),
					'salary_from'      => array( 'label' => __( '給与等の金額（以上）', 'payroll-system' ), 'input' => 'number', 'step' => '1', 'cast' => 'float', 'required' => true ),
					'salary_to'        => array( 'label' => __( '給与等の金額（未満）', 'payroll-system' ), 'input' => 'number', 'step' => '1', 'cast' => 'float', 'required' => true ),
					'dependents_0'     => array( 'label' => __( '扶養0人', 'payroll-system' ), 'input' => 'number', 'step' => '1', 'cast' => 'float', 'required' => false ),
					'dependents_1'     => array( 'label' => __( '扶養1人', 'payroll-system' ), 'input' => 'number', 'step' => '1', 'cast' => 'float', 'required' => false ),
					'dependents_2'     => array( 'label' => __( '扶養2人', 'payroll-system' ), 'input' => 'number', 'step' => '1', 'cast' => 'float', 'required' => false ),
					'dependents_3'     => array( 'label' => __( '扶養3人', 'payroll-system' ), 'input' => 'number', 'step' => '1', 'cast' => 'float', 'required' => false ),
					'dependents_4'     => array( 'label' => __( '扶養4人', 'payroll-system' ), 'input' => 'number', 'step' => '1', 'cast' => 'float', 'required' => false ),
					'dependents_5'     => array( 'label' => __( '扶養5人', 'payroll-system' ), 'input' => 'number', 'step' => '1', 'cast' => 'float', 'required' => false ),
					'dependents_6'     => array( 'label' => __( '扶養6人', 'payroll-system' ), 'input' => 'number', 'step' => '1', 'cast' => 'float', 'required' => false ),
					'dependents_7'     => array( 'label' => __( '扶養7人', 'payroll-system' ), 'input' => 'number', 'step' => '1', 'cast' => 'float', 'required' => false ),
					'tax_amount_otsu'  => array( 'label' => __( '乙欄税額', 'payroll-system' ), 'input' => 'number', 'step' => '1', 'cast' => 'float', 'required' => false ),
				),
			),
			'premium_wage'          => array(
				'label'       => __( '割増賃金率', 'payroll-system' ),
				'table'       => 'rate_premium_wage',
				'natural_key' => array( 'effective_year_month' ),
				'list_order'  => 'effective_year_month DESC',
				'fields'      => array(
					'effective_year_month'  => array( 'label' => __( '適用開始年月', 'payroll-system' ), 'input' => 'month', 'required' => true ),
					'overtime_rate'          => array( 'label' => __( '時間外割増率', 'payroll-system' ), 'input' => 'number', 'step' => '0.01', 'cast' => 'float', 'required' => true ),
					'late_night_addon_rate'  => array( 'label' => __( '深夜割増率', 'payroll-system' ), 'input' => 'number', 'step' => '0.01', 'cast' => 'float', 'required' => true ),
					'holiday_rate'           => array( 'label' => __( '休日割増率', 'payroll-system' ), 'input' => 'number', 'step' => '0.01', 'cast' => 'float', 'required' => true ),
				),
			),
		);
	}

	private static function prefecture_options(): array {
		$options = array();
		foreach ( PS_Employee::get_prefectures() as $pref ) {
			$options[ $pref ] = $pref;
		}
		return $options;
	}

	private static function table( string $type ): string {
		global $wpdb;
		$def = self::get_type_defs()[ $type ] ?? null;
		return $def ? $wpdb->prefix . 'ps_' . $def['table'] : '';
	}

	public static function get_all( string $type, array $args = array() ) {
		global $wpdb;
		$def = self::get_type_defs()[ $type ] ?? null;
		if ( ! $def ) {
			return array();
		}

		$defaults = array( 'per_page' => 200, 'paged' => 1 );
		$args     = wp_parse_args( $args, $defaults );
		$offset   = max( 0, ( (int) $args['paged'] - 1 ) * (int) $args['per_page'] );
		$table    = self::table( $type );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepareArgument
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} ORDER BY {$def['list_order']} LIMIT %d OFFSET %d",
			(int) $args['per_page'],
			$offset
		) );
	}

	public static function find( string $type, int $id ) {
		global $wpdb;
		$table = self::table( $type );
		if ( ! $table ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	public static function find_by_natural_key( string $type, array $key_values, int $exclude_id = 0 ) {
		global $wpdb;
		$table = self::table( $type );
		if ( ! $table ) {
			return null;
		}

		$where  = array();
		$params = array();
		foreach ( $key_values as $col => $val ) {
			$where[]  = "{$col} = %s";
			$params[] = (string) $val;
		}
		if ( $exclude_id > 0 ) {
			$where[]  = 'id != %d';
			$params[] = $exclude_id;
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' LIMIT 1';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_row( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * 入力値のサニタイズ＋バリデーション。フィールド構成はget_type_defs()に従う。
	 *
	 * $check_duplicate は手動フォーム（新規追加・編集）でのみtrueにする。CSVインポートは
	 * 既存行と同じ適用年月・区分の行を「上書き更新」として扱う（upsert）ため、ここでの
	 * 重複エラー判定は行わない。
	 *
	 * @return array [ 'data' => 保存用の連想配列, 'errors' => エラーメッセージの配列 ]
	 */
	public static function validate( string $type, array $raw, int $exclude_id = 0, bool $check_duplicate = true ): array {
		$def = self::get_type_defs()[ $type ] ?? null;
		if ( ! $def ) {
			return array( 'data' => array(), 'errors' => array( __( '不明なマスタ種別です。', 'payroll-system' ) ) );
		}

		$data   = array();
		$errors = array();

		foreach ( $def['fields'] as $key => $field ) {
			$value = self::cast_field( $field, $raw[ $key ] ?? '', $errors );
			if ( $field['required'] && null === $value ) {
				$errors[] = sprintf(
					/* translators: %s: 項目名 */
					__( '「%s」は必須です。', 'payroll-system' ),
					$field['label']
				);
			}
			$data[ $key ] = $value;
		}

		if ( 'income_tax' === $type && null !== ( $data['salary_from'] ?? null ) && null !== ( $data['salary_to'] ?? null )
			&& $data['salary_to'] <= $data['salary_from'] ) {
			$errors[] = __( '「給与等の金額（未満）」は「（以上）」より大きい値にしてください。', 'payroll-system' );
		}

		if ( $check_duplicate && empty( $errors ) ) {
			$key_values = array();
			foreach ( $def['natural_key'] as $k ) {
				$key_values[ $k ] = $data[ $k ];
			}
			if ( self::find_by_natural_key( $type, $key_values, $exclude_id ) ) {
				$errors[] = __( '同じ適用年月・区分の行が既に存在します。編集するか、既存行を削除してから登録してください。', 'payroll-system' );
			}
		}

		return array( 'data' => $data, 'errors' => $errors );
	}

	private static function cast_field( array $field, $raw_value, array &$errors ) {
		$raw_value = is_string( $raw_value ) ? trim( $raw_value ) : $raw_value;

		if ( 'month' === $field['input'] ) {
			$raw_value = sanitize_text_field( (string) $raw_value );
			if ( '' === $raw_value ) {
				return null;
			}
			if ( ! preg_match( '/^\d{4}-\d{2}$/', $raw_value ) ) {
				$errors[] = sprintf(
					/* translators: %s: 項目名 */
					__( '「%s」はYYYY-MM形式で指定してください。', 'payroll-system' ),
					$field['label']
				);
				return null;
			}
			return $raw_value;
		}

		if ( 'select' === $field['input'] ) {
			$raw_value = sanitize_text_field( (string) $raw_value );
			if ( '' === $raw_value ) {
				return null;
			}
			if ( ! isset( $field['options'][ $raw_value ] ) ) {
				$errors[] = sprintf(
					/* translators: %s: 項目名 */
					__( '「%s」の値が不正です。', 'payroll-system' ),
					$field['label']
				);
				return null;
			}
			return $raw_value;
		}

		// number
		$raw_value = str_replace( ',', '', (string) $raw_value );
		if ( '' === $raw_value ) {
			return null;
		}
		if ( ! is_numeric( $raw_value ) ) {
			$errors[] = sprintf(
				/* translators: %s: 項目名 */
				__( '「%s」は数値で指定してください。', 'payroll-system' ),
				$field['label']
			);
			return null;
		}
		return 'int' === ( $field['cast'] ?? 'float' ) ? (int) $raw_value : (float) $raw_value;
	}

	public static function create( string $type, array $data ) {
		global $wpdb;
		$table = self::table( $type );
		if ( ! $table ) {
			return new WP_Error( 'unknown_type', __( '不明なマスタ種別です。', 'payroll-system' ) );
		}
		$result = $wpdb->insert( $table, $data );
		if ( false === $result ) {
			return new WP_Error( 'db_insert_failed', $wpdb->last_error );
		}
		return (int) $wpdb->insert_id;
	}

	public static function update( string $type, int $id, array $data ) {
		global $wpdb;
		$table = self::table( $type );
		if ( ! $table ) {
			return new WP_Error( 'unknown_type', __( '不明なマスタ種別です。', 'payroll-system' ) );
		}
		$result = $wpdb->update( $table, $data, array( 'id' => $id ) );
		if ( false === $result ) {
			return new WP_Error( 'db_update_failed', $wpdb->last_error );
		}
		return true;
	}

	public static function delete( string $type, int $id ) {
		global $wpdb;
		$table = self::table( $type );
		if ( ! $table ) {
			return new WP_Error( 'unknown_type', __( '不明なマスタ種別です。', 'payroll-system' ) );
		}
		$result = $wpdb->delete( $table, array( 'id' => $id ) );
		if ( false === $result ) {
			return new WP_Error( 'db_delete_failed', $wpdb->last_error );
		}
		return true;
	}

	/**
	 * CSVインポート専用：natural keyが一致する行があれば更新、無ければ新規作成する。
	 * $data はvalidate()を通過済みの値を想定（重複チェックはここでは行わない）。
	 *
	 * @return array [ 'id' => int, 'action' => 'inserted'|'updated' ]
	 */
	public static function upsert( string $type, array $data ): array {
		$def        = self::get_type_defs()[ $type ];
		$key_values = array();
		foreach ( $def['natural_key'] as $k ) {
			$key_values[ $k ] = $data[ $k ];
		}
		$existing = self::find_by_natural_key( $type, $key_values );

		if ( $existing ) {
			self::update( $type, (int) $existing->id, $data );
			return array( 'id' => (int) $existing->id, 'action' => 'updated' );
		}

		return array( 'id' => self::create( $type, $data ), 'action' => 'inserted' );
	}
}
