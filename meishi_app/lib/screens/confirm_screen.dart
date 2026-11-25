import 'dart:io';
import 'package:material_ui/material_ui.dart';
import '../main.dart';
import '../models.dart';

/// 読み取り結果の確認・修正。新規登録と編集の両方で使う。
class ConfirmScreen extends StatefulWidget {
  const ConfirmScreen({super.key, required this.card});
  final BizCard card;
  @override
  State<ConfirmScreen> createState() => _ConfirmScreenState();
}

class _ConfirmScreenState extends State<ConfirmScreen> {
  late final Map<String, TextEditingController> _c;
  bool get _isNew => widget.card.id == null;

  static const _fields = <(String, String, TextInputType, int)>[
    ('name', '氏名', TextInputType.name, 1),
    ('nameKana', 'よみがな', TextInputType.text, 1),
    ('company', '会社名', TextInputType.text, 1),
    ('department', '部署', TextInputType.text, 1),
    ('title', '役職', TextInputType.text, 1),
    ('phone', '電話', TextInputType.phone, 1),
    ('mobile', '携帯', TextInputType.phone, 1),
    ('fax', 'FAX', TextInputType.phone, 1),
    ('email', 'メール', TextInputType.emailAddress, 1),
    ('address', '住所', TextInputType.streetAddress, 2),
    ('url', 'Webサイト', TextInputType.url, 1),
    ('memo', 'メモ', TextInputType.multiline, 4),
  ];

  String _get(String k) {
    final c = widget.card;
    return switch (k) {
      'name' => c.name,
      'nameKana' => c.nameKana,
      'company' => c.company,
      'department' => c.department,
      'title' => c.title,
      'phone' => c.phone,
      'mobile' => c.mobile,
      'fax' => c.fax,
      'email' => c.email,
      'address' => c.address,
      'url' => c.url,
      _ => c.memo,
    };
  }

  @override
  void initState() {
    super.initState();
    _c = {for (final f in _fields) f.$1: TextEditingController(text: _get(f.$1))};
  }

  @override
  void dispose() {
    for (final c in _c.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    final c = widget.card;
    String v(String k) => _c[k]!.text.trim();
    c
      ..name = v('name')
      ..nameKana = v('nameKana')
      ..company = v('company')
      ..department = v('department')
      ..title = v('title')
      ..phone = v('phone')
      ..mobile = v('mobile')
      ..fax = v('fax')
      ..email = v('email')
      ..address = v('address')
      ..url = v('url')
      ..memo = v('memo');
    if (_isNew) {
      await repo.insert(c);
    } else {
      await repo.update(c);
    }
    if (mounted) Navigator.pop(context, true);
  }

  @override
  Widget build(BuildContext context) {
    final c = widget.card;
    return Scaffold(
      appBar: AppBar(
        title: Text(_isNew ? '確認・修正' : '編集'),
        actions: [TextButton(onPressed: _save, child: const Text('保存'))],
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (c.thumbPath.isNotEmpty)
            ClipRRect(
              borderRadius: BorderRadius.circular(8),
              child: Image.file(File(c.thumbPath), height: 180, fit: BoxFit.contain, errorBuilder: (_, _, _) => const SizedBox()),
            ),
          if (_isNew && c.imagePending)
            const Padding(
              padding: EdgeInsets.only(top: 8),
              child: Text('保存先フォルダが使えないため、画像はアプリ内に一時保存しました。設定でフォルダを選ぶと自動で移動します。',
                  style: TextStyle(color: Colors.orange)),
            ),
          const SizedBox(height: 12),
          for (final f in _fields)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: TextField(
                controller: _c[f.$1],
                keyboardType: f.$3,
                minLines: f.$4 == 1 ? 1 : f.$4,
                maxLines: f.$4 == 1 ? 1 : f.$4 + 2,
                decoration: InputDecoration(labelText: f.$2, border: const OutlineInputBorder()),
              ),
            ),
          if (c.rawText.isNotEmpty)
            ExpansionTile(
              title: const Text('読み取った全文'),
              children: [Padding(padding: const EdgeInsets.all(12), child: SelectableText(c.rawText))],
            ),
          const SizedBox(height: 60),
        ],
      ),
    );
  }
}
