<?php
/**
 * プラグイン削除（アンインストール）時にのみ実行される。
 *
 * 重要：給与データは法定保存年数（5年）があるため、
 * このファイルは「明示的に管理者がプラグインを削除した場合」のみ実行されることを
 * 十分に理解した上で扱うこと。無効化(deactivate)では絶対にデータを消してはならない。
 *
 * 運用上は、安易に全削除せず、以下のいずれかを推奨する：
 *   1) デフォルトでは何もしない（テーブルを残す）
 *   2) 削除前に管理画面でエクスポート（バックアップ）を必須フローにする
 * 以下はテーブル・設定・自動作成ページ・uploads/payroll/配下のファイルを完全削除する実装だが、
 * デフォルトでは無効化してある。
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 安全装置：この定数が明示的に定義されている場合のみ削除処理を実行する。
// wp-config.php 等で define('PAYROLL_SYSTEM_ALLOW_DATA_DELETE', true); した場合のみ有効。
if ( ! defined( 'PAYROLL_SYSTEM_ALLOW_DATA_DELETE' ) || PAYROLL_SYSTEM_ALLOW_DATA_DELETE !== true ) {
	return;
}

global $wpdb;

$tables = array(
	'ps_employees',
	'ps_attendance',
	'ps_import_profiles',
	'ps_rate_health_insurance',
	'ps_rate_pension',
	'ps_rate_employment_insurance',
	'ps_rate_income_tax',
	'ps_rate_premium_wage',
	'ps_payroll_slips',
	'ps_audit_log',
	'ps_employee_sessions',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
}

// 設定・固定ページID・DBバージョン等、payroll_system_ で始まるオプションをすべて削除
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$option_names = $wpdb->get_col( $wpdb->prepare(
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
	$wpdb->esc_like( 'payroll_system_' ) . '%'
) );

// 有効化時に自動作成した固定ページ（ショートコードのみのページ）を削除する。
// オプションを消す前にページIDを読み出しておく。
$page_ids = get_option( 'payroll_system_page_ids', array() );
if ( is_array( $page_ids ) ) {
	foreach ( $page_ids as $page_id ) {
		$post = get_post( (int) $page_id );
		if ( $post && 'page' === $post->post_type ) {
			wp_delete_post( (int) $page_id, true );
		}
	}
}

foreach ( $option_names as $option_name ) {
	delete_option( $option_name );
}

// 給与明細PDF・勤怠CSV・ZIP等の一時ファイル置き場（uploads/payroll/）を丸ごと削除
$upload_dir  = wp_upload_dir();
$payroll_dir = trailingslashit( $upload_dir['basedir'] ) . 'payroll';
if ( is_dir( $payroll_dir ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $payroll_dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		$item->isDir() && ! $item->isLink() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions
	@rmdir( $payroll_dir );
}

remove_role( 'payroll_manager' );

// 有効化時に管理者ロールへ付与した capability も外す
$admin_role = get_role( 'administrator' );
if ( $admin_role ) {
	$admin_role->remove_cap( 'manage_payroll' );
}
