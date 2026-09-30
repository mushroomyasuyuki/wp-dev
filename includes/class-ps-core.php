<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * プラグインの起動クラス。
 * 管理画面・フロント（固定ページ）・共通処理のフック登録を一箇所に集約する。
 */
class PS_Core {

	public function run() {
		$this->define_admin_hooks();
		$this->define_frontend_hooks();
		$this->define_common_hooks();
	}

	private function define_admin_hooks() {
		if ( ! is_admin() ) {
			return;
		}

		$admin_menu = new PS_Admin_Menu();
		add_action( 'admin_menu', array( $admin_menu, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $admin_menu, 'enqueue_assets' ) );
		add_action( 'admin_post_ps_resync_page_content', array( $admin_menu, 'handle_resync_page_content' ) );
		add_action( 'admin_post_ps_create_demo_account', array( $admin_menu, 'handle_create_demo_account' ) );
	}

	/**
	 * フォーム送信処理（admin-post.php宛）は、wp-adminの画面からでも
	 * フロント（固定ページ）の画面からでも同じURLに飛ぶため、
	 * is_admin()の分岐に関係なく常に登録しておく必要がある。
	 */
	private function define_common_hooks() {
		$employee_controller = new PS_Employee_Controller();
		$employee_controller->register_hooks();

		$attendance_controller = new PS_Attendance_Controller();
		$attendance_controller->register_hooks();

		$rate_master_controller = new PS_Rate_Master_Controller();
		$rate_master_controller->register_hooks();

		$payroll_run_controller = new PS_Payroll_Run_Controller();
		$payroll_run_controller->register_hooks();

		$payroll_pdf_controller = new PS_Payroll_Pdf_Controller();
		$payroll_pdf_controller->register_hooks();
	}

	/**
	 * フロント（固定ページ）側の機能。
	 * ショートコードの登録、ログイン/ログアウト処理、専用CSSの読み込みを行う。
	 */
	private function define_frontend_hooks() {
		$front_auth = new PS_Frontend_Auth();
		$front_auth->register_hooks();

		$front_employees = new PS_Frontend_Employees();
		$front_employees->register_hooks();

		$front_attendance_import = new PS_Frontend_Attendance_Import();
		$front_attendance_import->register_hooks();

		$front_payroll_run = new PS_Frontend_Payroll_Run();
		$front_payroll_run->register_hooks();

		$front_payroll_slips = new PS_Frontend_Payroll_Slips();
		$front_payroll_slips->register_hooks();

		$front_employee_auth = new PS_Frontend_Employee_Auth();
		$front_employee_auth->register_hooks();

		$front_employee_slips = new PS_Frontend_Employee_Slips();
		$front_employee_slips->register_hooks();

		$front_dashboard = new PS_Frontend_Dashboard();
		$front_dashboard->register_hooks();

		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_frontend_assets' ) );
	}

	/**
	 * プラグインのショートコードを含む固定ページでのみフロント用CSSを読み込む。
	 */
	public function maybe_enqueue_frontend_assets() {
		global $post;

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$shortcodes = array(
			'payroll_login',
			'payroll_logout',
			'payroll_employees',
			'payroll_attendance_import',
			'payroll_run',
			'payroll_slips',
			'payroll_employee_login',
			'payroll_employee_logout',
			'payroll_employee_change_password',
			'payroll_employee_slips',
			'payroll_dashboard',
		);
		$has_shortcode = false;
		foreach ( $shortcodes as $tag ) {
			if ( has_shortcode( $post->post_content, $tag ) ) {
				$has_shortcode = true;
				break;
			}
		}

		if ( ! $has_shortcode ) {
			return;
		}

		wp_enqueue_style(
			'payroll-system-frontend',
			PAYROLL_SYSTEM_PLUGIN_URL . 'public/css/frontend.css',
			array(),
			PAYROLL_SYSTEM_VERSION
		);
	}
}
