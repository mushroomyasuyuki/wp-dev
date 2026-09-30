<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$message = isset( $_GET['message'] ) ? sanitize_key( $_GET['message'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$resynced_count = isset( $_GET['count'] ) ? absint( $_GET['count'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$active_employee_count = PS_Employee::count( array( 'status' => 'active' ) );

$current_year_month = current_time( 'Y-m' );
$slips_table         = $wpdb->prefix . 'ps_payroll_slips';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$unconfirmed_count = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$slips_table} WHERE target_year_month = %s AND status IN ('calculating','reviewing') AND is_deleted = 0",
	$current_year_month
) );

$attendance_table = $wpdb->prefix . 'ps_attendance';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$latest_attendance = $wpdb->get_row( "SELECT target_year_month, created_at FROM {$attendance_table} ORDER BY created_at DESC LIMIT 1" );
?>
<div class="wrap payroll-system-wrap">
	<h1><?php esc_html_e( '給与計算 ダッシュボード', 'payroll-system' ); ?></h1>

	<p>
		<?php
		printf(
			/* translators: %s: 今月（YYYY-MM） */
			esc_html__( '当月（%s）の計算状況サマリーです。', 'payroll-system' ),
			esc_html( $current_year_month )
		);
		?>
	</p>

	<div class="ps-dashboard-cards">
		<div class="ps-card">
			<h2><?php esc_html_e( '在籍従業員数', 'payroll-system' ); ?></h2>
			<p class="ps-card-number"><?php echo esc_html( number_format( $active_employee_count ) ); ?></p>
		</div>
		<div class="ps-card">
			<h2><?php esc_html_e( '今月の計算未確定件数', 'payroll-system' ); ?></h2>
			<p class="ps-card-number"><?php echo esc_html( number_format( $unconfirmed_count ) ); ?></p>
		</div>
		<div class="ps-card">
			<h2><?php esc_html_e( '直近の勤怠取込', 'payroll-system' ); ?></h2>
			<p class="ps-card-number" style="font-size:16px;">
				<?php if ( $latest_attendance ) : ?>
					<?php echo esc_html( $latest_attendance->target_year_month . '（' . mysql2date( 'Y-m-d H:i', $latest_attendance->created_at ) . '）' ); ?>
				<?php else : ?>
					—
				<?php endif; ?>
			</p>
		</div>
	</div>

	<h2 style="margin-top:32px;"><?php esc_html_e( 'フロント（固定ページ）URL一覧', 'payroll-system' ); ?></h2>
	<p class="description">
		<?php esc_html_e( '有効化時に自動作成された固定ページです。担当者・従業員へ案内するURLはここから確認できます。本文（ショートコード）は自由に編集・レイアウト変更して構いません。', 'payroll-system' ); ?>
	</p>
	<?php if ( 'page_content_resynced' === $message ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %d: 更新したページ数 */
					esc_html__( '%d件の固定ページの本文を、現在のURLに基づいて再生成しました。', 'payroll-system' ),
					(int) $resynced_count
				);
				?>
			</p>
		</div>
	<?php endif; ?>
	<?php if ( 'demo_account_created' === $message ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'デモアカウント（従業員コード「0123」／給与担当者ユーザー名「0123」、いずれもパスワードは「0123」）を作成・更新しました。', 'payroll-system' ); ?></p>
		</div>
	<?php endif; ?>
	<?php
	$page_ids   = get_option( 'payroll_system_page_ids', array() );
	$page_specs = PS_Activator::page_specs();
	?>
	<table class="wp-list-table widefat fixed striped" style="max-width:700px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'ページ', 'payroll-system' ); ?></th>
				<th><?php esc_html_e( 'URL', 'payroll-system' ); ?></th>
				<th><?php esc_html_e( '操作', 'payroll-system' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $page_specs as $key => $spec ) : ?>
			<?php
			$page_id = $page_ids[ $key ] ?? 0;
			$post    = $page_id ? get_post( $page_id ) : null;
			?>
			<tr>
				<td><?php echo esc_html( $spec['title'] ); ?></td>
				<td>
					<?php if ( $post && 'trash' !== $post->post_status ) : ?>
						<a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_permalink( $page_id ) ); ?></a>
					<?php else : ?>
						<em><?php esc_html_e( '未作成', 'payroll-system' ); ?></em>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $post && 'trash' !== $post->post_status ) : ?>
						<a href="<?php echo esc_url( get_edit_post_link( $page_id ) ); ?>"><?php esc_html_e( '編集', 'payroll-system' ); ?></a>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<p style="margin-top:12px;">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;"
			onsubmit="return confirm('<?php echo esc_js( __( '上記8ページの本文を、現在のURLに基づくショートコードで上書きします。ショートコード以外に追記した文章やレイアウトは失われます。実行しますか？', 'payroll-system' ) ); ?>');">
			<input type="hidden" name="action" value="ps_resync_page_content">
			<?php wp_nonce_field( 'ps_resync_page_content' ); ?>
			<button type="submit" class="button">
				<?php esc_html_e( '固定ページの本文を再生成する', 'payroll-system' ); ?>
			</button>
		</form>
		<span class="description" style="margin-left:8px;">
			<?php esc_html_e( '固定ページの重複作成が起きた後などに、ページ同士の相互参照URLがずれてしまった場合の復旧用です。通常時に実行する必要はありません。', 'payroll-system' ); ?>
		</span>
	</p>

	<div class="notice notice-error" style="margin-top:24px; padding:12px;">
		<p>
			<strong><?php esc_html_e( '販売・展示用デモアカウントの作成（実データを扱う本番サイトでは絶対に使用しないこと）', 'payroll-system' ); ?></strong><br>
			<?php esc_html_e( '誰でも「0123」というコード・パスワードでログインできるデモ専用アカウント（従業員セルフサービス側・給与担当者ダッシュボード側の両方）を作成します。給与担当者アカウントは全従業員のデータを閲覧できるため、実際の給与データを保存しているサイトでこれを実行すると、部外者にデータを見られる状態になります。実データを入れない、販売・デモ専用のサイトでのみ実行してください。', 'payroll-system' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			onsubmit="return confirm('<?php echo esc_js( __( '「0123」でログインできるデモアカウントを作成します。実際の給与データを扱うサイトでは絶対に実行しないでください。よろしいですか？', 'payroll-system' ) ); ?>');">
			<input type="hidden" name="action" value="ps_create_demo_account">
			<?php wp_nonce_field( 'ps_create_demo_account' ); ?>
			<button type="submit" class="button">
				<?php esc_html_e( 'デモアカウントを作成する', 'payroll-system' ); ?>
			</button>
		</form>
	</div>

	<div class="notice notice-warning" style="margin-top:24px;">
		<p>
			<strong><?php esc_html_e( 'ご利用にあたっての免責事項', 'payroll-system' ); ?></strong><br>
			<?php esc_html_e( '本プラグインが算出する給与額・社会保険料・所得税額は、登録された料率マスタ・従業員情報・勤怠データに基づく計算結果であり、その正確性・法令適合性を保証するものではありません。実際の給与支払いに用いる前に、必ず自社の社会保険労務士・税理士等の専門家による確認を行ってください。計算結果の誤りに起因する損害について、本プラグインの作者は責任を負いません。', 'payroll-system' ); ?>
		</p>
	</div>
</div>
