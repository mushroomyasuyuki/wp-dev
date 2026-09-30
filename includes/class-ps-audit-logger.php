<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 給与明細に対する操作（作成/再計算/確定/訂正）を wp_ps_audit_log に記録する。
 * 変更前後の値をJSONでまるごと保持し、「誰が・いつ・何を・どう変えたか」を後から追跡できるようにする。
 */
class PS_Audit_Logger {

	/**
	 * @param string   $action    'create'|'recalculate'|'confirm'|'correct'
	 * @param int      $slip_id
	 * @param array|null $before  変更前の明細データ（新規作成時はnull）
	 * @param array|null $after   変更後の明細データ
	 * @param int|null $user_id   省略時は現在のユーザー
	 */
	public static function log( string $action, int $slip_id, ?array $before, ?array $after, ?int $user_id = null ) {
		global $wpdb;

		$wpdb->insert( $wpdb->prefix . 'ps_audit_log', array(
			'slip_id'    => $slip_id,
			'action'      => $action,
			'before_json' => null === $before ? null : wp_json_encode( $before ),
			'after_json'  => null === $after ? null : wp_json_encode( $after ),
			'user_id'     => $user_id ?? get_current_user_id(),
			'created_at'  => current_time( 'mysql' ),
		) );
	}
}
