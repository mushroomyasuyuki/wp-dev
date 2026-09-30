<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 料率マスタCSV取込のコアロジック。
 *
 * 勤怠取込（PS_Attendance_Importer）と異なり、各マスタ種別ごとに列（ヘッダー）が
 * PS_Rate_Master::get_type_defs() のフィールドキーで固定されているため、列マッピング
 * 画面は用意せず、ヘッダー名で列を対応付けるだけの2ステップ（アップロード→確定）とする。
 */
class PS_Rate_Importer {

	private static function tmp_dir(): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'payroll/tmp/';
	}

	public static function store_uploaded_file( string $type, array $file ) {
		if ( ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'upload_error', __( 'ファイルのアップロードに失敗しました。', 'payroll-system' ) );
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'csv' !== $ext ) {
			return new WP_Error( 'invalid_file_type', __( 'CSVファイルを指定してください。', 'payroll-system' ) );
		}

		$dir = self::tmp_dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$dest = $dir . 'rate_' . sanitize_key( $type ) . '_' . get_current_user_id() . '_' . time() . '.csv';

		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			return new WP_Error( 'move_failed', __( 'ファイルの保存に失敗しました。', 'payroll-system' ) );
		}

		return $dest;
	}

	private static function to_utf8( string $value ): string {
		$encoding = mb_detect_encoding( $value, array( 'UTF-8', 'SJIS-win', 'EUC-JP', 'ASCII' ), true );
		if ( $encoding && 'UTF-8' !== $encoding ) {
			return mb_convert_encoding( $value, 'UTF-8', $encoding );
		}
		return $value;
	}

	/**
	 * CSVを読み込み、ヘッダーの過不足チェックと行ごとの検証を行う。
	 *
	 * @return array|WP_Error [ 'rows' => [ [row_number, data, status(ok/error), messages[]] ], 'ok_count', 'error_count' ]
	 */
	public static function build_preview( string $type, string $file_path ) {
		$def = PS_Rate_Master::get_type_defs()[ $type ] ?? null;
		if ( ! $def ) {
			return new WP_Error( 'unknown_type', __( '不明なマスタ種別です。', 'payroll-system' ) );
		}

		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_not_found', __( 'アップロードされたファイルが見つかりません。再度アップロードしてください。', 'payroll-system' ) );
		}

		$handle = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			return new WP_Error( 'file_open_failed', __( 'ファイルを開けませんでした。', 'payroll-system' ) );
		}

		$header = fgetcsv( $handle );
		if ( ! $header ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'empty_file', __( 'CSVファイルが空か、読み取れませんでした。', 'payroll-system' ) );
		}
		$header = array_map( array( __CLASS__, 'to_utf8' ), array_map( 'trim', $header ) );

		$col_index = array();
		foreach ( $header as $i => $col_name ) {
			$col_index[ $col_name ] = $i;
		}

		$missing_required = array();
		foreach ( $def['fields'] as $key => $field ) {
			if ( $field['required'] && ! isset( $col_index[ $key ] ) ) {
				$missing_required[] = $key;
			}
		}
		if ( ! empty( $missing_required ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error(
				'missing_columns',
				sprintf(
					/* translators: %s: 不足している列名 */
					__( 'CSVのヘッダーに必須列が足りません：%s（「テンプレートをダウンロード」で正しい列名を確認してください）', 'payroll-system' ),
					implode( ', ', $missing_required )
				)
			);
		}

		$rows        = array();
		$ok_count    = 0;
		$error_count = 0;
		$seen_keys   = array();
		$row_number  = 1;

		while ( ( $line = fgetcsv( $handle ) ) !== false ) { // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments
			$row_number++;
			if ( count( array_filter( $line, fn( $v ) => '' !== trim( (string) $v ) ) ) === 0 ) {
				continue; // 空行はスキップ
			}
			$line = array_map( array( __CLASS__, 'to_utf8' ), $line );

			$raw = array();
			foreach ( $def['fields'] as $key => $field ) {
				$idx         = $col_index[ $key ] ?? null;
				$raw[ $key ] = null !== $idx ? ( $line[ $idx ] ?? '' ) : '';
			}

			$validated = PS_Rate_Master::validate( $type, $raw, 0, false );
			$messages  = $validated['errors'];

			// ファイル内の重複natural key検出
			$key_parts = array();
			foreach ( $def['natural_key'] as $k ) {
				$key_parts[] = (string) ( $validated['data'][ $k ] ?? '' );
			}
			$natural_key_str = implode( '|', $key_parts );
			if ( isset( $seen_keys[ $natural_key_str ] ) ) {
				$messages[] = __( 'このファイル内に同じ適用年月・区分の行が複数あります。', 'payroll-system' );
			}
			$seen_keys[ $natural_key_str ] = true;

			$status = empty( $messages ) ? 'ok' : 'error';
			if ( 'ok' === $status ) {
				$ok_count++;
			} else {
				$error_count++;
			}

			$rows[] = array(
				'row_number' => $row_number,
				'data'       => $validated['data'],
				'status'     => $status,
				'messages'   => $messages,
			);
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return array(
			'rows'        => $rows,
			'ok_count'    => $ok_count,
			'error_count' => $error_count,
		);
	}

	/**
	 * プレビューでstatus=okだった行のみをDBへ取込確定する（natural key一致行は上書き更新）。
	 *
	 * @return array [ 'inserted' => int, 'updated' => int, 'skipped' => int ]
	 */
	public static function commit( string $type, array $preview_rows ): array {
		$inserted = 0;
		$updated  = 0;
		$skipped  = 0;

		foreach ( $preview_rows as $row ) {
			if ( 'ok' !== $row['status'] ) {
				$skipped++;
				continue;
			}

			$result = PS_Rate_Master::upsert( $type, $row['data'] );
			if ( 'updated' === $result['action'] ) {
				$updated++;
			} else {
				$inserted++;
			}
		}

		return array( 'inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped );
	}

	public static function cleanup_temp_file( string $file_path ) {
		if ( file_exists( $file_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			unlink( $file_path );
		}
	}

	/**
	 * 「テンプレートをダウンロード」用：ヘッダー行＋サンプル1行のCSVを出力する。
	 */
	public static function output_template_csv( string $type ) {
		$def = PS_Rate_Master::get_type_defs()[ $type ] ?? null;
		if ( ! $def ) {
			wp_die( esc_html__( '不明なマスタ種別です。', 'payroll-system' ) );
		}

		$keys   = array_keys( $def['fields'] );
		$sample = self::sample_row( $type );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $type ) . '_template.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, $keys );
		fputcsv( $out, array_map( fn( $k ) => $sample[ $k ] ?? '', $keys ) );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * 「CSVエクスポート」用：現在登録されている全行をCSVで出力する。
	 * 年次の料率改定を配布する際、まず現行データのバックアップを取る用途や、
	 * 取込フォーマット（列名）を実データで確認する用途を想定している。
	 * インポート側は natural_key が一致する行を上書き更新するため、このCSVを
	 * そのまま編集して再インポートすれば既存データを更新できる。
	 */
	public static function output_export_csv( string $type ) {
		$def = PS_Rate_Master::get_type_defs()[ $type ] ?? null;
		if ( ! $def ) {
			wp_die( esc_html__( '不明なマスタ種別です。', 'payroll-system' ) );
		}

		$keys = array_keys( $def['fields'] );
		$rows = PS_Rate_Master::get_all( $type, array( 'per_page' => 100000 ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $type ) . '_export_' . gmdate( 'Ymd' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, $keys );
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( fn( $k ) => $row->$k ?? '', $keys ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	private static function sample_row( string $type ): array {
		$samples = array(
			'health_insurance'    => array(
				'effective_year_month' => '2026-03', 'prefecture' => '東京都', 'grade' => 1,
				'standard_monthly_remuneration' => 58000, 'employee_amount' => 2871.0, 'employee_amount_with_care' => 3335.4,
			),
			'pension'              => array(
				'effective_year_month' => '2026-03', 'grade' => 1,
				'standard_monthly_remuneration' => 88000, 'employee_amount' => 8052.0,
			),
			'employment_insurance' => array(
				'effective_year_month' => '2026-04', 'business_type' => 'general',
				'employee_rate' => 0.006, 'employer_rate' => 0.0095,
			),
			'income_tax'           => array(
				'effective_year_month' => '2026-01', 'column_type' => 'ko', 'salary_from' => 0, 'salary_to' => 88000,
				'dependents_0' => 0, 'dependents_1' => 0, 'dependents_2' => 0, 'dependents_3' => 0,
				'dependents_4' => 0, 'dependents_5' => 0, 'dependents_6' => 0, 'dependents_7' => 0,
				'tax_amount_otsu' => 3200,
			),
			'premium_wage'         => array(
				'effective_year_month' => '2026-01', 'overtime_rate' => 1.25, 'late_night_addon_rate' => 0.25, 'holiday_rate' => 1.35,
			),
		);

		return $samples[ $type ] ?? array();
	}
}
