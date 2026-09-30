<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 勤怠CSV取込のコアロジック。
 *
 * 前提：1つのCSVファイルは「1つの対象年月」の全従業員分の月次集計データとする
 * （対象年月はアップロード時に管理者が別途指定し、CSVの列マッピングには含めない）。
 * 日次の打刻データではなく、勤怠システム側で月次集計されたエクスポートを想定している。
 */
class PS_Attendance_Importer {

	/**
	 * マッピング対象となる内部項目の定義。
	 * employee_code のみ必須（従業員の突合キー）。他は未マッピングなら0として扱う。
	 */
	public static function get_internal_fields(): array {
		return array(
			'employee_code'      => array( 'label' => __( '社員コード（従業員特定用）', 'payroll-system' ), 'required' => true ),
			'work_days'          => array( 'label' => __( '出勤日数', 'payroll-system' ), 'required' => false ),
			'absence_days'       => array( 'label' => __( '欠勤日数', 'payroll-system' ), 'required' => false ),
			'scheduled_hours'    => array( 'label' => __( '所定内労働時間', 'payroll-system' ), 'required' => false ),
			'overtime_hours'     => array( 'label' => __( '時間外労働時間', 'payroll-system' ), 'required' => false ),
			'late_night_hours'   => array( 'label' => __( '深夜労働時間', 'payroll-system' ), 'required' => false ),
			'holiday_work_hours' => array( 'label' => __( '休日労働時間', 'payroll-system' ), 'required' => false ),
			'paid_leave_days'    => array( 'label' => __( '有休取得日数', 'payroll-system' ), 'required' => false ),
			'late_early_hours'   => array( 'label' => __( '遅刻早退時間', 'payroll-system' ), 'required' => false ),
		);
	}

	private static function tmp_dir(): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'payroll/tmp/';
	}

	/**
	 * アップロードされたファイルを一時保存領域に移動する。
	 * 保存先は .htaccess で外部アクセスを禁止する。
	 *
	 * @param array $file $_FILES['attendance_csv'] 相当
	 * @return string|WP_Error 保存後のファイルパス
	 */
	public static function store_uploaded_file( array $file ) {
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

		$dest = $dir . 'attendance_' . get_current_user_id() . '_' . time() . '.csv';

		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			return new WP_Error( 'move_failed', __( 'ファイルの保存に失敗しました。', 'payroll-system' ) );
		}

		return $dest;
	}

	/**
	 * CSVの1行目（ヘッダー）を読み取る。
	 * Excel由来のCSVで文字化けしやすいため、Shift_JIS→UTF-8変換も試みる。
	 */
	public static function parse_headers( string $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_not_found', __( 'アップロードされたファイルが見つかりません。再度アップロードしてください。', 'payroll-system' ) );
		}

		$handle = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			return new WP_Error( 'file_open_failed', __( 'ファイルを開けませんでした。', 'payroll-system' ) );
		}

		$header = fgetcsv( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $header ) {
			return new WP_Error( 'empty_file', __( 'CSVファイルが空か、読み取れませんでした。', 'payroll-system' ) );
		}

		return array_map( array( __CLASS__, 'to_utf8' ), $header );
	}

	private static function to_utf8( string $value ): string {
		$encoding = mb_detect_encoding( $value, array( 'UTF-8', 'SJIS-win', 'EUC-JP', 'ASCII' ), true );
		if ( $encoding && 'UTF-8' !== $encoding ) {
			return mb_convert_encoding( $value, 'UTF-8', $encoding );
		}
		return $value;
	}

	/**
	 * マッピングを適用してCSV全行を読み込み、従業員突合・検証を行う。
	 *
	 * @param string $file_path
	 * @param array  $mapping 内部項目名 => CSV列名
	 * @param string $target_year_month 'YYYY-MM'
	 * @return array [ 'rows' => [ [csv_row_number, employee_code, employee_id, employee_name, values..., status(ok/error), messages[]] ], 'ok_count', 'error_count' ]
	 */
	public static function build_preview( string $file_path, array $mapping, string $target_year_month ): array {
		$handle = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$header = fgetcsv( $handle );
		$header = array_map( array( __CLASS__, 'to_utf8' ), $header );

		// CSV列名 → 列インデックス
		$col_index = array();
		foreach ( $header as $i => $col_name ) {
			$col_index[ $col_name ] = $i;
		}

		$rows       = array();
		$ok_count   = 0;
		$error_count = 0;
		$seen_codes = array(); // 同一ファイル内の重複社員コード検出用
		$row_number = 1; // ヘッダーを1行目として、データは2行目から

		while ( ( $line = fgetcsv( $handle ) ) !== false ) { // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments
			$row_number++;
			if ( count( array_filter( $line, fn( $v ) => '' !== trim( (string) $v ) ) ) === 0 ) {
				continue; // 空行はスキップ
			}
			$line = array_map( array( __CLASS__, 'to_utf8' ), $line );

			$get_value = function ( string $field ) use ( $mapping, $col_index, $line ) {
				if ( empty( $mapping[ $field ] ) || ! isset( $col_index[ $mapping[ $field ] ] ) ) {
					return null;
				}
				$idx = $col_index[ $mapping[ $field ] ];
				return $line[ $idx ] ?? null;
			};

			$messages = array();

			$employee_code = trim( (string) $get_value( 'employee_code' ) );
			if ( '' === $employee_code ) {
				$messages[] = __( '社員コードが空です。', 'payroll-system' );
			}

			if ( isset( $seen_codes[ $employee_code ] ) && '' !== $employee_code ) {
				$messages[] = __( 'このファイル内に同じ社員コードが複数行あります。', 'payroll-system' );
			}
			$seen_codes[ $employee_code ] = true;

			$employee = '' !== $employee_code ? PS_Employee::find_by_code( $employee_code ) : null;
			if ( '' !== $employee_code && ! $employee ) {
				$messages[] = __( 'この社員コードに一致する従業員が見つかりません。', 'payroll-system' );
			}

			$numeric_fields = array( 'work_days', 'absence_days', 'scheduled_hours', 'overtime_hours', 'late_night_hours', 'holiday_work_hours', 'paid_leave_days', 'late_early_hours' );
			$values = array();
			foreach ( $numeric_fields as $field ) {
				$raw = $get_value( $field );
				if ( null === $raw || '' === trim( (string) $raw ) ) {
					$values[ $field ] = 0.0;
					continue;
				}
				$normalized = str_replace( ',', '', trim( (string) $raw ) );
				if ( ! is_numeric( $normalized ) ) {
					$messages[] = sprintf(
						/* translators: %s: 項目名 */
						__( '「%s」が数値ではありません。', 'payroll-system' ),
						self::get_internal_fields()[ $field ]['label']
					);
					$values[ $field ] = 0.0;
				} else {
					$values[ $field ] = (float) $normalized;
				}
			}

			// 異常値チェック：欠勤日数がマイナス、休日労働時間が異常に大きい等
			foreach ( $values as $field => $v ) {
				if ( $v < 0 ) {
					$messages[] = sprintf(
						/* translators: %s: 項目名 */
						__( '「%s」がマイナスの値です。', 'payroll-system' ),
						self::get_internal_fields()[ $field ]['label']
					);
				}
			}
			if ( $values['work_days'] > 31 || $values['absence_days'] > 31 || $values['paid_leave_days'] > 31 ) {
				$messages[] = __( '日数が31日を超えています。値を確認してください。', 'payroll-system' );
			}

			$status = empty( $messages ) ? 'ok' : 'error';
			if ( 'ok' === $status ) {
				$ok_count++;
			} else {
				$error_count++;
			}

			$rows[] = array(
				'row_number'     => $row_number,
				'employee_code'  => $employee_code,
				'employee_id'    => $employee->id ?? null,
				'employee_name'  => $employee->name ?? '',
				'values'         => $values,
				'status'         => $status,
				'messages'       => $messages,
			);
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return array(
			'rows'        => $rows,
			'ok_count'    => $ok_count,
			'error_count' => $error_count,
			'target_year_month' => $target_year_month,
		);
	}

	/**
	 * プレビューで status=ok だった行のみをDBへ取込確定する。
	 *
	 * @return array [ 'imported' => int, 'skipped' => int ]
	 */
	public static function commit( array $preview_rows, string $target_year_month ): array {
		$import_batch_id = time(); // 簡易的な取込バッチ識別子（取消/再取込時の追跡用）
		$imported = 0;
		$skipped  = 0;

		foreach ( $preview_rows as $row ) {
			if ( 'ok' !== $row['status'] || empty( $row['employee_id'] ) ) {
				$skipped++;
				continue;
			}

			PS_Attendance::upsert( (int) $row['employee_id'], $target_year_month, $row['values'], $import_batch_id );
			$imported++;
		}

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'batch_id' => $import_batch_id,
		);
	}

	public static function cleanup_temp_file( string $file_path ) {
		if ( file_exists( $file_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			unlink( $file_path );
		}
	}
}
