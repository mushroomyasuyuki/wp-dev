<?php
/**
 * Plugin Name:       給与計算システム
 * Plugin URI:        https://example.com/
 * Description:       従業員マスタ管理・勤怠CSV取込・給与計算（支給/控除/社会保険/所得税）・給与明細PDF出力までを行うカスタムプラグイン。
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Your Company
 * Text Domain:       payroll-system
 * Domain Path:       /languages
 *
 * このファイルはプラグインのエントリポイント。実処理は includes/ 以下のクラスに委譲する。
 */

// 直接アクセス禁止
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 定数定義
 * バージョンはキャッシュバスティング（CSS/JSの読み込み）にも使うため、
 * ヘッダーコメントのVersionと必ず一致させること。
 */
define( 'PAYROLL_SYSTEM_VERSION', '0.1.0' );
define( 'PAYROLL_SYSTEM_PLUGIN_FILE', __FILE__ );
define( 'PAYROLL_SYSTEM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PAYROLL_SYSTEM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PAYROLL_SYSTEM_DB_VERSION', '1.4.0' ); // テーブル構造・固定ページ構成（page_specs）を変更したら上げる。
// maybe_upgrade()はこの値と保存済みオプションが一致する限り何もしないため、
// 新しい固定ページ種別の追加だけの変更でも、既存サイトに反映させるには
// この値を必ず更新すること（今回：ダッシュボード固定ページ追加のため1.2.0に）。

/**
 * 有効化・無効化フック
 * dbDeltaによるテーブル作成、カスタムロールの登録などを行う。
 * クラス自体はまだオートロードされていないため、ここで直接requireする。
 */
require_once PAYROLL_SYSTEM_PLUGIN_DIR . 'includes/class-ps-activator.php';
require_once PAYROLL_SYSTEM_PLUGIN_DIR . 'includes/class-ps-deactivator.php';

register_activation_hook( __FILE__, array( 'PS_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PS_Deactivator', 'deactivate' ) );

/**
 * オートローダー
 * PS_Xxx_Yyy クラス名 → class-ps-xxx-yyy.php というファイル名規則で解決する。
 * includes/ と admin/ の両方を探索対象にする。
 */
spl_autoload_register( function ( $class_name ) {
	if ( strpos( $class_name, 'PS_' ) !== 0 ) {
		return;
	}

	$file_name = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';

	$search_dirs = array(
		PAYROLL_SYSTEM_PLUGIN_DIR . 'includes/',
		PAYROLL_SYSTEM_PLUGIN_DIR . 'includes/calculation/',
		PAYROLL_SYSTEM_PLUGIN_DIR . 'admin/',
		PAYROLL_SYSTEM_PLUGIN_DIR . 'public/',
	);

	foreach ( $search_dirs as $dir ) {
		if ( file_exists( $dir . $file_name ) ) {
			require_once $dir . $file_name;
			return;
		}
	}
} );

/**
 * プラグイン本体の起動
 * WordPressの読み込みが一通り終わった後（plugins_loaded）に初期化する。
 */
function payroll_system_init() {
	load_plugin_textdomain( 'payroll-system', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	$plugin = new PS_Core();
	$plugin->run();
}
add_action( 'plugins_loaded', 'payroll_system_init' );

/**
 * maybe_upgrade()は固定ページの新規作成（wp_insert_post→内部でget_permalink）を
 * 行う可能性があるため、$wp_rewriteがまだ生成されていないplugins_loaded時点では
 * 呼べない（"Call to a member function get_page_permastruct() on null"で
 * 致命的エラーになる）。$wp_rewriteはinitフックまでに生成されるため、
 * pluginsloadedではなくinitで呼ぶ。
 */
add_action( 'init', array( 'PS_Activator', 'maybe_upgrade' ), 0 );
