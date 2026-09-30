<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
$table = $wpdb->prefix . 'ps_audit_log';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 専用テーブルのため直接クエリ
$logs = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 100" );
?>
<div class="wrap payroll-system-wrap">
	<h1><?php esc_html_e( '監査ログ', 'payroll-system' ); ?></h1>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( '日時', 'payroll-system' ); ?></th>
				<th><?php esc_html_e( '対象明細ID', 'payroll-system' ); ?></th>
				<th><?php esc_html_e( '操作', 'payroll-system' ); ?></th>
				<th><?php esc_html_e( '操作者', 'payroll-system' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $logs ) ) : ?>
			<tr><td colspan="4"><?php esc_html_e( 'ログはまだありません。', 'payroll-system' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $logs as $log ) : ?>
				<tr>
					<td><?php echo esc_html( $log->created_at ); ?></td>
					<td><?php echo esc_html( $log->slip_id ); ?></td>
					<td><?php echo esc_html( $log->action ); ?></td>
					<td><?php echo esc_html( get_user_by( 'id', $log->user_id )->display_name ?? '—' ); ?></td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>
</div>
