<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 管理画面メニュー「給与計算」とサブメニューを登録する。
 * 各サブメニューのcallbackは admin/views/ 配下のテンプレートを読み込むだけの
 * 薄いラッパーとし、実処理は各機能クラス（PS_Employee, PS_Attendance_Importer 等）に委譲する。
 *
 * 権限は 'manage_payroll' capability を持つロール（administrator, payroll_manager）のみアクセス可。
 */
class PS_Admin_Menu {

	const CAPABILITY = 'manage_payroll';

	public function register_menus() {
		add_menu_page(
			__( '給与計算', 'payroll-system' ),
			__( '給与計算', 'payroll-system' ),
			self::CAPABILITY,
			'payroll-system',
			array( $this, 'render_dashboard' ),
			'dashicons-money-alt',
			30
		);

		add_submenu_page(
			'payroll-system',
			__( 'ダッシュボード', 'payroll-system' ),
			__( 'ダッシュボード', 'payroll-system' ),
			self::CAPABILITY,
			'payroll-system',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'payroll-system',
			__( '従業員管理', 'payroll-system' ),
			__( '従業員管理', 'payroll-system' ),
			self::CAPABILITY,
			'payroll-system-employees',
			array( $this, 'render_employees' )
		);

		add_submenu_page(
			'payroll-system',
			__( '勤怠データ取込', 'payroll-system' ),
			__( '勤怠データ取込', 'payroll-system' ),
			self::CAPABILITY,
			'payroll-system-attendance-import',
			array( $this, 'render_attendance_import' )
		);

		add_submenu_page(
			'payroll-system',
			__( '給与計算実行', 'payroll-system' ),
			__( '給与計算実行', 'payroll-system' ),
			self::CAPABILITY,
			'payroll-system-run',
			array( $this, 'render_run' )
		);

		add_submenu_page(
			'payroll-system',
			__( '給与設定（マスタ）', 'payroll-system' ),
			__( '給与設定（マスタ）', 'payroll-system' ),
			self::CAPABILITY,
			'payroll-system-rates',
			array( $this, 'render_rates' )
		);

		add_submenu_page(
			'payroll-system',
			__( '給与明細・帳票出力', 'payroll-system' ),
			__( '給与明細・帳票出力', 'payroll-system' ),
			self::CAPABILITY,
			'payroll-system-slips',
			array( $this, 'render_slips' )
		);

		add_submenu_page(
			'payroll-system',
			__( '監査ログ', 'payroll-system' ),
			__( '監査ログ', 'payroll-system' ),
			self::CAPABILITY,
			'payroll-system-audit-log',
			array( $this, 'render_audit_log' )
		);
	}

	/**
	 * ダッシュボードの「固定ページ本文を再生成」ボタンから呼ばれる。
	 * 固定ページの重複作成バグ（v1.1.0以前）で生じた、ページ同士の
	 * 相互参照URLのズレを手動で修復するための復旧アクション。
	 */
	public function handle_resync_page_content() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}

		check_admin_referer( 'ps_resync_page_content' );

		$updated = PS_Activator::resync_shortcode_content();

		wp_safe_redirect( add_query_arg(
			array( 'message' => 'page_content_resynced', 'count' => $updated ),
			admin_url( 'admin.php?page=payroll-system' )
		) );
		exit;
	}

	/**
	 * ダッシュボードの「デモアカウントを作成する」ボタンから呼ばれる。
	 * 販売用デモサイトで、誰でも0123/0123でログインできるようにするための
	 * デモ専用アカウント一式（従業員側・給与担当者側の両方）を作成する。
	 */
	public function handle_create_demo_account() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'payroll-system' ) );
		}

		check_admin_referer( 'ps_create_demo_account' );

		PS_Activator::create_demo_account();

		wp_safe_redirect( add_query_arg(
			array( 'message' => 'demo_account_created' ),
			admin_url( 'admin.php?page=payroll-system' )
		) );
		exit;
	}

	public function enqueue_assets( $hook ) {
		// このプラグインの画面以外では読み込まない
		if ( strpos( $hook, 'payroll-system' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'payroll-system-admin',
			PAYROLL_SYSTEM_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			PAYROLL_SYSTEM_VERSION
		);

		wp_enqueue_script(
			'payroll-system-admin',
			PAYROLL_SYSTEM_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			PAYROLL_SYSTEM_VERSION,
			true
		);

		wp_localize_script( 'payroll-system-admin', 'PayrollSystem', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'payroll_system_nonce' ),
		) );
	}

	private function view( $template, array $args = array() ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'この画面へのアクセス権限がありません。', 'payroll-system' ) );
		}

		extract( $args ); // phpcs:ignore -- テンプレートへの変数展開用途に限定
		$view_path = PAYROLL_SYSTEM_PLUGIN_DIR . 'admin/views/' . $template . '.php';

		if ( file_exists( $view_path ) ) {
			include $view_path;
		} else {
			echo '<div class="wrap"><p>' . esc_html( sprintf( 'テンプレートが見つかりません: %s', $template ) ) . '</p></div>';
		}
	}

	public function render_dashboard() {
		$this->view( 'dashboard' );
	}

	public function render_employees() {
		$this->view( 'employees' );
	}

	public function render_attendance_import() {
		$this->view( 'attendance-import' );
	}

	public function render_run() {
		$this->view( 'run' );
	}

	public function render_rates() {
		$this->view( 'rates' );
	}

	public function render_slips() {
		$this->view( 'slips' );
	}

	public function render_audit_log() {
		$this->view( 'audit-log' );
	}
}
