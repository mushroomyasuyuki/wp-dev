<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * プラグイン無効化時の処理。
 *
 * 重要：ここでは絶対にテーブルを削除しない。
 * 「無効化」と「アンインストール（削除）」は別の操作であり、
 * 給与データは有効化/無効化を繰り返すだけで消えてはならない。
 * データの完全削除はuninstall.phpでのみ、かつ管理者の明示操作でのみ行う。
 */
class PS_Deactivator {

	public static function deactivate() {
		flush_rewrite_rules();

		// 例：スケジュールしていたcronイベントの解除など
		$timestamp = wp_next_scheduled( 'payroll_system_monthly_batch' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'payroll_system_monthly_batch' );
		}
	}
}
