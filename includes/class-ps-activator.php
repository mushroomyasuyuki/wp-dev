<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * プラグイン有効化時の処理。
 * - 必要なDBテーブルをdbDeltaで作成
 * - カスタムロール「給与担当者」を登録
 * - DBバージョンをoptionsに記録（将来のマイグレーション判定に使用）
 */
class PS_Activator {

	public static function activate() {
		self::create_tables();
		self::register_roles();
		self::create_default_pages();

		update_option( 'payroll_system_db_version', PAYROLL_SYSTEM_DB_VERSION );

		// パーマリンクに関わるCPTを登録している場合はここでflush
		flush_rewrite_rules();
	}

	/**
	 * 既にプラグインが有効化されているサイトで、DBスキーマ・固定ページを更新する。
	 * register_activation_hookは有効化の瞬間にしか発火しないため、
	 * バージョンアップ時の変更はplugins_loadedのたびにここで確認する。
	 * dbDelta・create_default_pagesはいずれも差分がなければ何もしないため、
	 * 頻繁に呼んでもコストは小さい。
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'payroll_system_db_version' ) === PAYROLL_SYSTEM_DB_VERSION ) {
			return;
		}

		// plugins_loadedのたびに呼ばれるため、本番環境では複数リクエストが
		// ほぼ同時に「バージョン不一致」を検知しうる。add_option()は
		// option_nameのユニーク制約によりアトミックに排他できるため、
		// これをロックとして使い、create_default_pages()の二重実行
		// （＝固定ページの重複作成）を防ぐ。
		if ( ! self::acquire_upgrade_lock() ) {
			return;
		}

		try {
			if ( get_option( 'payroll_system_db_version' ) === PAYROLL_SYSTEM_DB_VERSION ) {
				return; // ロック待ちの間に他のリクエストが完了させていた
			}
			self::create_tables();
			self::create_default_pages();
			self::apply_lp_page_settings_to_existing_pages();
			// 1.5.0：給与明細PDFをサーバーに残さない方式に変更したため、以前のバージョンが
			// 推測可能なファイル名で保存していたPDFを削除する（何度呼んでも安全）。
			PS_Payroll_Slip_Pdf::purge_stored_files();
			update_option( 'payroll_system_db_version', PAYROLL_SYSTEM_DB_VERSION );
		} finally {
			self::release_upgrade_lock();
		}
	}

	private static function acquire_upgrade_lock(): bool {
		$lock_option = 'payroll_system_upgrade_lock';
		$existing    = get_option( $lock_option );

		if ( $existing && ( time() - (int) $existing ) < 60 ) {
			return false; // 他プロセスが処理中
		}

		if ( $existing ) {
			delete_option( $lock_option ); // 60秒以上前のロックは異常終了とみなし破棄
		}

		return add_option( $lock_option, time(), '', false );
	}

	private static function release_upgrade_lock(): void {
		delete_option( 'payroll_system_upgrade_lock' );
	}

	/**
	 * ショートコードを設置した固定ページ一式を作成する。
	 *
	 * 作成した各ページのIDはoptions（payroll_system_page_ids）に記録し、
	 * 既にそのページが存在する（＝ゴミ箱に入っていない）場合は再作成しない。
	 * 既存ページの本文もユーザーが編集している可能性があるため上書きしない
	 * （新規作成したページの本文だけを、このタイミングで一度だけ組み立てる）。
	 *
	 * ページ同士がURLを相互参照する（例：従業員管理ページはログインページのURLを
	 * 必要とする）ため、「全ページを空で作成 → 全URLが揃ってから本文を流し込む」
	 * の2段階で処理する。
	 */
	public static function create_default_pages() {
		$page_ids = get_option( 'payroll_system_page_ids', array() );
		if ( ! is_array( $page_ids ) ) {
			$page_ids = array();
		}

		$specs           = self::page_specs();
		$freshly_created = array();

		foreach ( $specs as $key => $spec ) {
			$existing_id = $page_ids[ $key ] ?? 0;
			$post        = $existing_id ? get_post( $existing_id ) : null;

			if ( $post && 'page' === $post->post_type && 'trash' !== $post->post_status ) {
				continue; // 既に存在する場合は内容・URLともにユーザーの手に委ねる
			}

			$new_id = wp_insert_post( array(
				'post_title'  => $spec['title'],
				'post_status' => 'publish',
				'post_type'   => 'page',
			), true );

			if ( $new_id && ! is_wp_error( $new_id ) ) {
				$page_ids[ $key ]        = $new_id;
				$freshly_created[ $key ] = true;
				self::apply_lp_page_settings( $new_id );
			}
		}

		if ( empty( $freshly_created ) ) {
			return;
		}

		update_option( 'payroll_system_page_ids', $page_ids );

		$content_map = self::build_content_map( $page_ids );

		foreach ( $freshly_created as $key => $_ ) {
			if ( ! isset( $content_map[ $key ] ) ) {
				continue;
			}
			wp_update_post( array(
				'ID'           => $page_ids[ $key ],
				'post_content' => $content_map[ $key ],
			) );
		}
	}

	/**
	 * Cocoonテーマの「ページ設定」を、ショートコードを埋め込むだけのLP的な
	 * 表示（本文のみ・フルワイド、タイトル/読了時間/目次を非表示）に設定する。
	 * 新規作成時のみ呼ぶ（post_contentと同様、既存ページの設定は上書きしない）。
	 */
	private static function apply_lp_page_settings( int $post_id ): void {
		update_post_meta( $post_id, 'page_type', 'content_only_full_wide' );
		update_post_meta( $post_id, 'the_page_title_novisible', 1 );
		update_post_meta( $post_id, 'the_page_read_time_novisible', 1 );
		update_post_meta( $post_id, 'the_page_toc_novisible', 1 );
	}

	/**
	 * DB_VERSION 1.4.0（apply_lp_page_settings()導入）へのアップグレード時、
	 * 既に作成済みの固定ページにもCocoonページ設定を遡って適用する。
	 * create_default_pages()側は新規作成分にしか設定しないため、1.3.0以前から
	 * 運用している既存サイトの9ページはこちらで初めて設定される。重複適用しても
	 * 同じ値で上書きするだけなので副作用はなく、以後のmaybe_upgrade()実行でも
	 * 安全に呼べる。
	 */
	private static function apply_lp_page_settings_to_existing_pages(): void {
		$page_ids = get_option( 'payroll_system_page_ids', array() );
		if ( ! is_array( $page_ids ) ) {
			return;
		}

		foreach ( $page_ids as $id ) {
			$post = get_post( $id );
			if ( $post && 'page' === $post->post_type && 'trash' !== $post->post_status ) {
				self::apply_lp_page_settings( $id );
			}
		}
	}

	/**
	 * 各ページの現在のURLから、相互参照込みのショートコード本文一式を組み立てる。
	 * create_default_pages()（新規作成分のみ）とresync_shortcode_content()
	 * （既存分も含め全件）の両方から使う共通ロジック。
	 */
	private static function build_content_map( array $page_ids ): array {
		$urls = array();
		foreach ( $page_ids as $key => $id ) {
			$urls[ $key ] = get_permalink( $id ) ?: home_url( '/' );
		}

		return array(
			'manager_login'            => sprintf( '[payroll_login redirect="%s"]', $urls['dashboard'] ?? home_url( '/' ) ),
			'dashboard'                => sprintf( "[payroll_logout redirect=\"%s\"]\n\n[payroll_dashboard login_page=\"%s\"]", $urls['manager_login'] ?? home_url( '/' ), $urls['manager_login'] ?? home_url( '/' ) ),
			'employees'                => sprintf( "[payroll_logout redirect=\"%s\"]\n\n[payroll_employees login_page=\"%s\" dashboard_page=\"%s\"]", $urls['manager_login'] ?? home_url( '/' ), $urls['manager_login'] ?? home_url( '/' ), $urls['dashboard'] ?? home_url( '/' ) ),
			'attendance_import'        => sprintf( '[payroll_attendance_import login_page="%s" dashboard_page="%s"]', $urls['manager_login'] ?? home_url( '/' ), $urls['dashboard'] ?? home_url( '/' ) ),
			'payroll_run'              => sprintf( '[payroll_run login_page="%s" dashboard_page="%s"]', $urls['manager_login'] ?? home_url( '/' ), $urls['dashboard'] ?? home_url( '/' ) ),
			'payroll_slips'            => sprintf( '[payroll_slips login_page="%s" dashboard_page="%s"]', $urls['manager_login'] ?? home_url( '/' ), $urls['dashboard'] ?? home_url( '/' ) ),
			'employee_login'           => sprintf( '[payroll_employee_login redirect="%s" change_password_page="%s"]', $urls['employee_slips'] ?? home_url( '/' ), $urls['employee_change_password'] ?? home_url( '/' ) ),
			'employee_change_password' => sprintf( '[payroll_employee_change_password login_page="%s"]', $urls['employee_login'] ?? home_url( '/' ) ),
			'employee_slips'           => sprintf( "[payroll_employee_logout redirect=\"%s\"]\n\n[payroll_employee_slips login_page=\"%s\"]", $urls['employee_login'] ?? home_url( '/' ), $urls['employee_login'] ?? home_url( '/' ) ),
		);
	}

	/**
	 * 現在options（payroll_system_page_ids）に登録されている8ページ全ての本文を、
	 * 現在のURLに基づくショートコードで強制的に上書きする。
	 *
	 * create_default_pages()の「既存ページの本文は上書きしない」という原則の
	 * 例外にあたる、管理者が明示的に実行する復旧用の操作。固定ページの重複作成
	 * バグ（v1.1.0以前）の影響で、生き残ったページの中に他の（既に削除された）
	 * 重複ページのURLを参照したままのショートコードが残ってしまうケースがある
	 * ため、ダッシュボードのボタンから手動実行できるようにしている。
	 * ページ本文にショートコード以外の文章を追加している場合は失われる点に注意。
	 */
	public static function resync_shortcode_content(): int {
		$page_ids = get_option( 'payroll_system_page_ids', array() );
		if ( ! is_array( $page_ids ) || empty( $page_ids ) ) {
			return 0;
		}

		$content_map = self::build_content_map( $page_ids );
		$updated     = 0;

		foreach ( $page_ids as $key => $id ) {
			if ( ! isset( $content_map[ $key ] ) ) {
				continue;
			}
			$post = get_post( $id );
			if ( ! $post || 'page' !== $post->post_type || 'trash' === $post->post_status ) {
				continue;
			}
			wp_update_post( array(
				'ID'           => $id,
				'post_content' => $content_map[ $key ],
			) );
			++$updated;
		}

		return $updated;
	}

	/**
	 * 販売用のデモサイトなどで、誰でも「0123 / 0123」でログインして
	 * 一通りの画面（従業員セルフサービス側・給与担当者ダッシュボード側の両方）を
	 * 確認できるようにするための、デモ用アカウント一式を作成・更新する。
	 *
	 * 実際の給与データを扱わない、公開デモ専用サイトでの使用を想定している。
	 * 実データを保存している本番サイトでは絶対に使用しないこと（給与担当者の
	 * デモアカウントはmanage_payroll権限を持ち、全従業員のデータを閲覧できる）。
	 *
	 * - 従業員セルフサービス側：従業員コード「0123」・パスワード「0123」
	 * - 給与担当者（ダッシュボード）側：WordPressユーザー名「0123」・パスワード「0123」
	 *   （administratorではなく、給与関連機能のみに限定されたpayroll_managerロール）
	 *
	 * 既に同名のアカウントが存在する場合は、新規作成せずパスワード等を上書きする
	 * （何度実行しても安全＝冪等）。
	 */
	public static function create_demo_account(): void {
		global $wpdb;
		$now = current_time( 'mysql' );

		// --- 従業員セルフサービス側のデモアカウント ---
		$employee = PS_Employee::find_by_code( '0123' );
		$employee_data = array(
			'employee_code'         => '0123',
			'name'                  => __( 'デモ従業員', 'payroll-system' ),
			'employment_type'       => 'employee',
			'pay_type'              => 'monthly',
			'pay_rate'              => 300000,
			'status'                => 'active',
			'password_hash'         => wp_hash_password( '0123' ),
			'must_change_password'  => 0,
			'password_updated_at'   => $now,
			'updated_at'            => $now,
		);
		if ( $employee ) {
			$employee_id = (int) $employee->id;
			PS_Employee::update( $employee_id, $employee_data );
		} else {
			$employee_data['created_at'] = $now;
			$employee_id                 = PS_Employee::create( $employee_data );
		}

		// デモ用の確定済み給与明細を1件用意しておく（従業員セルフサービス側で
		// 「給与明細（従業員本人）」に何も表示されないと、デモとして成立しないため）。
		if ( $employee_id ) {
			$slips_table = $wpdb->prefix . 'ps_payroll_slips';
			$target_year_month = current_time( 'Y-m' );
			$gross = wp_json_encode( array(
				'base_salary'         => 300000,
				'commuting_allowance' => 10000,
			) );
			$deduction = wp_json_encode( array(
				'health_insurance'     => 14790,
				'pension'              => 27450,
				'employment_insurance' => 1860,
				'income_tax'           => 5000,
				'resident_tax'         => 5000,
			) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$existing_slip_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$slips_table} WHERE employee_id = %d AND target_year_month = %s",
				$employee_id,
				$target_year_month
			) );

			$slip_data = array(
				'employee_id'            => $employee_id,
				'target_year_month'      => $target_year_month,
				'status'                 => 'confirmed',
				'gross_amounts_json'     => $gross,
				'deduction_amounts_json' => $deduction,
				'total_gross'            => 310000,
				'total_deduction'        => 54100,
				'net_amount'             => 255900,
				'confirmed_at'           => $now,
				'updated_at'             => $now,
			);

			if ( $existing_slip_id ) {
				$wpdb->update( $slips_table, $slip_data, array( 'id' => (int) $existing_slip_id ) );
			} else {
				$slip_data['created_at'] = $now;
				$wpdb->insert( $slips_table, $slip_data );
			}
		}

		// --- 給与担当者（ダッシュボード）側のデモアカウント ---
		$wp_user = get_user_by( 'login', '0123' );
		if ( $wp_user ) {
			wp_update_user( array(
				'ID'        => $wp_user->ID,
				'user_pass' => '0123',
				'role'      => 'payroll_manager',
			) );
		} else {
			wp_insert_user( array(
				'user_login' => '0123',
				'user_pass'  => '0123',
				'user_email' => 'demo-0123@example.invalid',
				'role'       => 'payroll_manager',
			) );
		}
	}

	/**
	 * 作成する固定ページの一覧（キー => タイトル）。
	 * キーはpayroll_system_page_idsオプションの添字として使う内部識別子。
	 * ダッシュボード画面（admin/views/dashboard.php）が、作成済みページの一覧表示に使う。
	 */
	public static function page_specs(): array {
		return array(
			'manager_login'            => array( 'title' => __( '給与担当者ログイン', 'payroll-system' ) ),
			'dashboard'                => array( 'title' => __( '給与計算ダッシュボード', 'payroll-system' ) ),
			'employees'                => array( 'title' => __( '従業員管理', 'payroll-system' ) ),
			'attendance_import'        => array( 'title' => __( '勤怠データ取込', 'payroll-system' ) ),
			'payroll_run'              => array( 'title' => __( '給与計算実行', 'payroll-system' ) ),
			'payroll_slips'            => array( 'title' => __( '給与明細一覧（給与担当者）', 'payroll-system' ) ),
			'employee_login'           => array( 'title' => __( '従業員ログイン', 'payroll-system' ) ),
			'employee_change_password' => array( 'title' => __( 'パスワード変更', 'payroll-system' ) ),
			'employee_slips'           => array( 'title' => __( '給与明細（従業員本人）', 'payroll-system' ) ),
		);
	}

	/**
	 * 給与計算に必要な全テーブルを作成する。
	 * dbDeltaは「存在すれば何もしない／差分があれば変更」を自動判定するため、
	 * バージョンアップ時の再実行にも安全に使える。
	 */
	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix . 'ps_'; // payroll system専用のプレフィックス

		$sql = array();

		// ---- 従業員マスタ ----
		$sql[] = "CREATE TABLE {$prefix}employees (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			employee_code VARCHAR(50) NOT NULL COMMENT '勤怠システム上の社員コード（CSV突合キー）',
			name VARCHAR(100) NOT NULL,
			address VARCHAR(255) DEFAULT NULL,
			phone VARCHAR(30) DEFAULT NULL,
			employment_type VARCHAR(20) NOT NULL DEFAULT 'employee' COMMENT 'employee/part_timer',
			pay_type VARCHAR(20) NOT NULL DEFAULT 'monthly' COMMENT 'hourly/daily/monthly',
			pay_rate DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '時給/日給/月給の単価',
			commuting_allowance DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '非課税通勤費',
			commuting_allowance_taxable DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '課税通勤費',
			housing_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
			position_allowance DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '役職手当',
			family_allowance DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '家族手当',
			paid_leave_granted_days DECIMAL(5,1) NOT NULL DEFAULT 0 COMMENT '有休付与日数（年間の残日数計算の基準）',
			bank_name VARCHAR(100) DEFAULT NULL,
			bank_branch VARCHAR(100) DEFAULT NULL,
			bank_account_type VARCHAR(10) DEFAULT NULL COMMENT 'ordinary(普通)/checking(当座)',
			bank_account_number VARCHAR(20) DEFAULT NULL,
			bank_account_holder VARCHAR(100) DEFAULT NULL,
			social_insurance_status VARCHAR(20) NOT NULL DEFAULT 'enrolled' COMMENT 'enrolled/not_enrolled',
			standard_monthly_remuneration_grade INT UNSIGNED DEFAULT NULL COMMENT '標準報酬月額等級',
			prefecture VARCHAR(20) DEFAULT NULL COMMENT '健保料率参照用（協会けんぽ）',
			dependents_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
			tax_column VARCHAR(10) NOT NULL DEFAULT 'ko' COMMENT 'ko(甲欄)/otsu(乙欄)',
			resident_tax_monthly DECIMAL(12,2) NOT NULL DEFAULT 0,
			birth_date DATE DEFAULT NULL,
			hire_date DATE DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active/retired',
			password_hash VARCHAR(255) DEFAULT NULL COMMENT '従業員本人用ログイン（WordPressユーザーとは別の独自認証）のパスワードハッシュ。wp_hash_password()で生成',
			must_change_password TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1=次回ログイン時にパスワード変更を強制（仮パスワード発行直後の状態）',
			password_updated_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY employee_code (employee_code)
		) $charset_collate;";

		// ---- 勤怠データ ----
		$sql[] = "CREATE TABLE {$prefix}attendance (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			employee_id BIGINT UNSIGNED NOT NULL,
			target_year_month CHAR(7) NOT NULL COMMENT '例: 2026-09',
			work_days DECIMAL(5,1) NOT NULL DEFAULT 0,
			absence_days DECIMAL(5,1) NOT NULL DEFAULT 0,
			scheduled_hours DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT '所定内労働時間',
			overtime_hours DECIMAL(6,2) NOT NULL DEFAULT 0,
			late_night_hours DECIMAL(6,2) NOT NULL DEFAULT 0,
			holiday_work_hours DECIMAL(6,2) NOT NULL DEFAULT 0,
			paid_leave_days DECIMAL(5,1) NOT NULL DEFAULT 0 COMMENT '当月の有休取得日数',
			late_early_hours DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT '遅刻早退時間',
			import_batch_id BIGINT UNSIGNED DEFAULT NULL COMMENT '取込バッチとの紐付け（取消/再取込用）',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY employee_month (employee_id, target_year_month),
			KEY target_year_month (target_year_month)
		) $charset_collate;";

		// ---- 勤怠取込プロファイル（列マッピング保存用）----
		$sql[] = "CREATE TABLE {$prefix}import_profiles (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			profile_name VARCHAR(100) NOT NULL COMMENT '例: King of Time用',
			mapping_json LONGTEXT NOT NULL COMMENT 'CSV列⇔内部項目の対応をJSONで保持',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		// ---- 健康保険料率 ----
		$sql[] = "CREATE TABLE {$prefix}rate_health_insurance (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			effective_year_month CHAR(7) NOT NULL,
			prefecture VARCHAR(20) NOT NULL,
			grade INT UNSIGNED NOT NULL,
			standard_monthly_remuneration DECIMAL(12,2) NOT NULL,
			employee_amount DECIMAL(12,2) NOT NULL COMMENT '本人負担額(介護保険なし)',
			employee_amount_with_care DECIMAL(12,2) DEFAULT NULL COMMENT '本人負担額(40-64歳/介護保険込み)',
			PRIMARY KEY  (id),
			KEY lookup (effective_year_month, prefecture, grade)
		) $charset_collate;";

		// ---- 厚生年金保険料率 ----
		$sql[] = "CREATE TABLE {$prefix}rate_pension (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			effective_year_month CHAR(7) NOT NULL,
			grade INT UNSIGNED NOT NULL,
			standard_monthly_remuneration DECIMAL(12,2) NOT NULL,
			employee_amount DECIMAL(12,2) NOT NULL,
			PRIMARY KEY  (id),
			KEY lookup (effective_year_month, grade)
		) $charset_collate;";

		// ---- 雇用保険料率 ----
		$sql[] = "CREATE TABLE {$prefix}rate_employment_insurance (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			effective_year_month CHAR(7) NOT NULL,
			business_type VARCHAR(30) NOT NULL COMMENT 'general/agriculture/construction',
			employee_rate DECIMAL(6,4) NOT NULL COMMENT '例: 0.0060',
			employer_rate DECIMAL(6,4) NOT NULL,
			PRIMARY KEY  (id),
			KEY lookup (effective_year_month, business_type)
		) $charset_collate;";

		// ---- 所得税額表 ----
		$sql[] = "CREATE TABLE {$prefix}rate_income_tax (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			effective_year_month CHAR(7) NOT NULL,
			column_type VARCHAR(10) NOT NULL COMMENT 'ko/otsu',
			salary_from DECIMAL(12,2) NOT NULL,
			salary_to DECIMAL(12,2) NOT NULL,
			dependents_0 DECIMAL(12,2) DEFAULT NULL,
			dependents_1 DECIMAL(12,2) DEFAULT NULL,
			dependents_2 DECIMAL(12,2) DEFAULT NULL,
			dependents_3 DECIMAL(12,2) DEFAULT NULL,
			dependents_4 DECIMAL(12,2) DEFAULT NULL,
			dependents_5 DECIMAL(12,2) DEFAULT NULL,
			dependents_6 DECIMAL(12,2) DEFAULT NULL,
			dependents_7 DECIMAL(12,2) DEFAULT NULL,
			tax_amount_otsu DECIMAL(12,2) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY lookup (effective_year_month, column_type, salary_from, salary_to)
		) $charset_collate;";

		// ---- 割増賃金率 ----
		$sql[] = "CREATE TABLE {$prefix}rate_premium_wage (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			effective_year_month CHAR(7) NOT NULL,
			overtime_rate DECIMAL(4,2) NOT NULL DEFAULT 1.25,
			late_night_addon_rate DECIMAL(4,2) NOT NULL DEFAULT 0.25,
			holiday_rate DECIMAL(4,2) NOT NULL DEFAULT 1.35,
			PRIMARY KEY  (id),
			KEY lookup (effective_year_month)
		) $charset_collate;";

		// ---- 給与明細 ----
		$sql[] = "CREATE TABLE {$prefix}payroll_slips (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			employee_id BIGINT UNSIGNED NOT NULL,
			target_year_month CHAR(7) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'calculating' COMMENT 'calculating/reviewing/confirmed/corrected',
			gross_amounts_json LONGTEXT NOT NULL COMMENT '支給項目内訳',
			deduction_amounts_json LONGTEXT NOT NULL COMMENT '控除項目内訳',
			total_gross DECIMAL(12,2) NOT NULL DEFAULT 0,
			total_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
			net_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			rate_master_version VARCHAR(50) DEFAULT NULL COMMENT '使用した料率マスタの適用年月等',
			pdf_file_path VARCHAR(255) DEFAULT NULL,
			calculated_by BIGINT UNSIGNED DEFAULT NULL,
			calculated_at DATETIME DEFAULT NULL,
			confirmed_at DATETIME DEFAULT NULL,
			is_deleted TINYINT(1) NOT NULL DEFAULT 0 COMMENT '論理削除フラグ（法定保存年数のため物理削除しない）',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY employee_month (employee_id, target_year_month),
			KEY target_year_month (target_year_month),
			KEY status (status)
		) $charset_collate;";

		// ---- 監査ログ ----
		$sql[] = "CREATE TABLE {$prefix}audit_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slip_id BIGINT UNSIGNED DEFAULT NULL,
			action VARCHAR(30) NOT NULL COMMENT 'create/recalculate/confirm/correct',
			before_json LONGTEXT DEFAULT NULL,
			after_json LONGTEXT DEFAULT NULL,
			user_id BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY slip_id (slip_id)
		) $charset_collate;";

		// ---- 従業員本人用ログインセッション（WordPressのauth cookieとは別の独自実装）----
		$sql[] = "CREATE TABLE {$prefix}employee_sessions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			employee_id BIGINT UNSIGNED NOT NULL,
			token_hash CHAR(64) NOT NULL COMMENT 'cookieに保存する生トークンのSHA-256ハッシュ（生トークン自体はDBに保存しない）',
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY employee_id (employee_id)
		) $charset_collate;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * 給与担当者専用ロールを登録する。
	 * 一般のEditor等には manage_payroll capability を持たせないことで、
	 * 給与関連メニューへのアクセスを制限する。
	 */
	private static function register_roles() {
		if ( ! get_role( 'payroll_manager' ) ) {
			add_role(
				'payroll_manager',
				__( '給与担当者', 'payroll-system' ),
				array(
					'read'            => true,
					'manage_payroll'  => true,
				)
			);
		}

		// 管理者(administrator)には常に権限を付与しておく
		$admin_role = get_role( 'administrator' );
		if ( $admin_role && ! $admin_role->has_cap( 'manage_payroll' ) ) {
			$admin_role->add_cap( 'manage_payroll' );
		}
	}
}
