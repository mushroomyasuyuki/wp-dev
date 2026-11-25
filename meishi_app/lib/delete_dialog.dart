import 'package:material_ui/material_ui.dart';

/// 削除の確認。アプリ内の画像も消すかどうかを選べる（初期値は「残す」）。
/// 外部ストレージ(SDカード等)に保存した画像は、どちらを選んでも消えない。
Future<({bool removeImage})?> confirmDelete(
  BuildContext context, {
  required String title,
}) {
  var removeImage = false;
  return showDialog<({bool removeImage})>(
    context: context,
    builder: (_) => StatefulBuilder(
      builder: (context, setState) => AlertDialog(
        title: Text(title),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              '名刺の文字の情報を削除します。この操作は元に戻せません。\nSDカードなどに保存した画像ファイルは消えません。',
            ),
            const SizedBox(height: 8),
            CheckboxListTile(
              contentPadding: EdgeInsets.zero,
              controlAffinity: ListTileControlAffinity.leading,
              value: removeImage,
              onChanged: (v) => setState(() => removeImage = v ?? false),
              title: const Text('アプリ内の画像も削除する'),
              subtitle: const Text('チェックしないと、アプリ内の画像は残ります'),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('やめる'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, (removeImage: removeImage)),
            child: const Text('削除する'),
          ),
        ],
      ),
    ),
  );
}
