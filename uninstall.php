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
 * 以下はテーブルを完全削除する実装例だが、デフォルトでは無効化してある。
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
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
}

delete_option( 'payroll_system_db_version' );
remove_role( 'payroll_manager' );
