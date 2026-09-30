<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 「どの勤怠システムのCSVか」ごとに列マッピングを保存しておくためのリポジトリ。
 * 一度作成しておけば、次回以降は選択するだけでマッピング画面を省略できる。
 */
class PS_Import_Profile {

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ps_import_profiles';
	}

	public static function get_all() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY profile_name ASC" );
	}

	public static function find( int $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	public static function get_mapping( int $id ): array {
		$profile = self::find( $id );
		if ( ! $profile ) {
			return array();
		}
		$mapping = json_decode( $profile->mapping_json, true );
		return is_array( $mapping ) ? $mapping : array();
	}

	/**
	 * @param string $name
	 * @param array  $mapping 内部項目名 => CSV列名 の連想配列
	 */
	public static function save( string $name, array $mapping ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		return $wpdb->insert( self::table(), array(
			'profile_name'  => $name,
			'mapping_json'  => wp_json_encode( $mapping ),
			'created_at'    => $now,
			'updated_at'    => $now,
		) );
	}

	public static function delete( int $id ) {
		global $wpdb;
		return $wpdb->delete( self::table(), array( 'id' => $id ) );
	}
}
