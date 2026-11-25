import 'dart:io';

import 'package:material_ui/material_ui.dart';

import '../delete_dialog.dart';
import '../duplicates.dart';
import '../main.dart';
import '../models.dart';

/// 重複している名刺を一覧し、選んだものを削除する。
class DuplicatesScreen extends StatefulWidget {
  const DuplicatesScreen({super.key});
  @override
  State<DuplicatesScreen> createState() => _DuplicatesScreenState();
}

class _DuplicatesScreenState extends State<DuplicatesScreen> {
  List<DupGroup>? _groups;
  final Set<int> _selected = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final g = findDuplicates(await repo.all());
    if (!mounted) return;
    setState(() {
      _groups = g;
      _selected.clear();
      // 初期状態では、各グループの「最初に登録した1枚」だけ残し、あとを削除の候補にする
      for (final grp in g) {
        for (final c in grp.cards.skip(1)) {
          _selected.add(c.id!);
        }
      }
    });
  }

  Future<void> _deleteSelected() async {
    final n = _selected.length;
    final r = await confirmDelete(context, title: '$n件を削除しますか？');
    if (r == null) return;
    for (final id in _selected.toList()) {
      await repo.delete(id, removeLocalImage: r.removeImage);
    }
    if (!mounted) return;
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text('$n件を削除しました')));
    _load();
  }

  String _date(DateTime d) => '${d.year}/${d.month}/${d.day}';

  Widget _tile(DupGroup g, BizCard c, bool oldest) => CheckboxListTile(
    value: _selected.contains(c.id),
    onChanged: (v) => setState(
      () => v == true ? _selected.add(c.id!) : _selected.remove(c.id),
    ),
    controlAffinity: ListTileControlAffinity.leading,
    secondary: SizedBox(
      width: 64,
      height: 44,
      child: c.thumbPath.isEmpty
          ? const Icon(Icons.badge_outlined)
          : Image.file(
              File(c.thumbPath),
              fit: BoxFit.cover,
              cacheWidth: 160,
              errorBuilder: (_, _, _) => const Icon(Icons.badge_outlined),
            ),
    ),
    title: Text(
      '${c.name.isEmpty ? '(氏名なし)' : c.name}${oldest ? '  ・最初に登録' : ''}',
    ),
    subtitle: Text(
      [
        c.company,
        c.email,
        c.phone,
        _date(c.createdAt),
      ].where((s) => s.isNotEmpty).join('\n'),
    ),
    isThreeLine: true,
  );

  @override
  Widget build(BuildContext context) {
    final groups = _groups;
    return Scaffold(
      appBar: AppBar(title: const Text('重複した名刺')),
      body: groups == null
          ? const Center(child: CircularProgressIndicator())
          : groups.isEmpty
          ? const Center(child: Text('重複している名刺はありません'))
          : ListView(
              children: [
                const Padding(
                  padding: EdgeInsets.all(16),
                  child: Text(
                    'メール・電話番号、または氏名と会社名が同じ名刺を表示しています。チェックを入れたものが削除されます（初期状態は、最初に登録した1枚を残す選択です）。',
                  ),
                ),
                for (var i = 0; i < groups.length; i++) ...[
                  Container(
                    color: Theme.of(context)
                        .colorScheme
                        .surfaceContainerHighest,
                    padding: const EdgeInsets.symmetric(
                      horizontal: 16,
                      vertical: 8,
                    ),
                    child: Text(
                      '${i + 1}組目  ${groups[i].reasons.join('・')}（${groups[i].cards.length}枚）',
                      style: Theme.of(context).textTheme.titleSmall,
                    ),
                  ),
                  for (var j = 0; j < groups[i].cards.length; j++)
                    _tile(groups[i], groups[i].cards[j], j == 0),
                ],
                const SizedBox(height: 90),
              ],
            ),
      bottomNavigationBar: (groups == null || groups.isEmpty)
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: FilledButton.icon(
                  onPressed: _selected.isEmpty ? null : _deleteSelected,
                  icon: const Icon(Icons.delete_outline),
                  label: Text('選んだ${_selected.length}件を削除'),
                ),
              ),
            ),
    );
  }
}
