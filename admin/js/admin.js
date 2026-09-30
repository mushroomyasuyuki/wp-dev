/**
 * 給与計算プラグイン 管理画面用JS（雛形）
 * PayrollSystem.ajax_url / PayrollSystem.nonce は wp_localize_script で渡している。
 */
( function ( $ ) {
	'use strict';

	$( function () {
		// TODO: 勤怠CSVアップロード後のAjaxプレビュー処理をここに実装
		// 例:
		// $('#attendance_csv').on('change', function () {
		//   const formData = new FormData();
		//   formData.append('action', 'ps_import_attendance_preview');
		//   formData.append('nonce', PayrollSystem.nonce);
		//   formData.append('file', this.files[0]);
		//   $.ajax({
		//     url: PayrollSystem.ajax_url,
		//     method: 'POST',
		//     data: formData,
		//     contentType: false,
		//     processData: false,
		//   }).done(function (response) { /* プレビュー表示 */ });
		// });
	} );
} )( jQuery );
