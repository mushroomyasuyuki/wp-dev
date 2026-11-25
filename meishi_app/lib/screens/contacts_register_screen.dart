import 'package:material_ui/material_ui.dart';

import '../contacts.dart';
import '../main.dart';
import '../models.dart';
import 'plan_screen.dart';

/// 電話帳への登録。「全件まとめて」か「選んで」を選べる。
class ContactsRegisterScreen extends StatefulWidget {
  const ContactsRegisterScreen({super.key});
  @override
  State<ContactsRegisterScreen> createState() => _ContactsRegisterScreenState();
}

enum _Mode { all, pick }

class _ContactsRegisterScreenState extends State<ContactsRegisterScreen> {
  List<BizCard> _cards = [];
  final Set<int> _selected = {};
  _Mode _mode = _Mode.all;
  bool _loaded = false;
  String? _progress;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final all = await repo.all();
    if (!mounted) return;
    setState(() {
      _cards = all;
      _loaded = true;
      _selected.removeWhere((id) => !all.any((c) => c.id == id));
    });
  }

  List<BizCard> get _todo => _cards.where((c) => !c.contactRegistered).toList();

  Future<void> _run(List<BizCard> targets) async {
    if (!plan.isPro) {
      Navigator.push(
        context,
        MaterialPageRoute(
          builder: (_) => const PlanScreen(reason: '電話帳への登録は有料プランの機能です。'),
        ),
      );
      return;
    }
    if (targets.isEmpty) return;
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text('${targets.length}件を電話帳に登録しますか？'),
        content: const Text(
          '端末の電話帳に新しい連絡先として追加します。同じ人がすでに電話帳にいても、別の連絡先として追加されます。',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('やめる'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('登録する'),
          ),
        ],
      ),
    );
    if (ok != true) return;
    String msg;
    try {
      final n = await registerCardsToContacts(
        targets,
        onProgress: (d, t) {
          if (mounted) setState(() => _progress = '電話帳に登録中… $d / $t');
        },
      );
      msg = '$n件を電話帳に登録しました';
    } catch (e) {
      msg = '登録できませんでした：${e is StateError ? e.message : e}';
    }
    if (!mounted) return;
    setState(() => _progress = null);
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
    _selected.clear();
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final todo = _todo;
    final registered = _cards.length - todo.length;
    return Scaffold(
      appBar: AppBar(title: const Text('電話帳に登録')),
      body: !_loaded
          ? const Center(child: CircularProgressIndicator())
          : Column(
              children: [
                Padding(
                  padding: const EdgeInsets.all(16),
                  child: SegmentedButton<_Mode>(
                    segments: const [
                      ButtonSegment(
                        value: _Mode.all,
                        label: Text('全件まとめて'),
                        icon: Icon(Icons.done_all),
                      ),
                      ButtonSegment(
                        value: _Mode.pick,
                        label: Text('選んで登録'),
                        icon: Icon(Icons.checklist),
                      ),
                    ],
                    selected: {_mode},
                    onSelectionChanged: (s) => setState(() => _mode = s.first),
                  ),
                ),
                if (_progress != null)
                  Padding(
                    padding: const EdgeInsets.all(8),
                    child: Text(_progress!),
                  ),
                Expanded(
                  child: _mode == _Mode.all
                      ? _allView(todo, registered)
                      : _pickView(),
                ),
              ],
            ),
    );
  }

  Widget _allView(List<BizCard> todo, int registered) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 16),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          '登録していない名刺：${todo.length}件\n登録済みの名刺：$registered件（二重には登録しません）',
          style: Theme.of(context).textTheme.bodyLarge,
        ),
        const SizedBox(height: 16),
        FilledButton.icon(
          onPressed: (todo.isEmpty || _progress != null)
              ? null
              : () => _run(todo),
          icon: const Icon(Icons.contacts_outlined),
          label: Text(
            plan.isPro
                ? '未登録の${todo.length}件を電話帳に登録'
                : '未登録の${todo.length}件を電話帳に登録（有料）',
          ),
        ),
        const SizedBox(height: 12),
        const Text(
          '端末の「連絡先」の許可を求められます。登録後は、電話帳アプリから確認できます。',
          style: TextStyle(color: Colors.grey),
        ),
      ],
    ),
  );

  Widget _pickView() => Column(
    children: [
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 8),
        child: Row(
          children: [
            TextButton(
              onPressed: () => setState(
                () => _selected
                  ..clear()
                  ..addAll(_todo.map((c) => c.id!)),
              ),
              child: const Text('未登録をすべて選ぶ'),
            ),
            TextButton(
              onPressed: () => setState(_selected.clear),
              child: const Text('選択を解除'),
            ),
          ],
        ),
      ),
      Expanded(
        child: _cards.isEmpty
            ? const Center(child: Text('名刺がありません'))
            : ListView.builder(
                itemCount: _cards.length,
                itemBuilder: (_, i) {
                  final c = _cards[i];
                  return CheckboxListTile(
                    value: _selected.contains(c.id),
                    onChanged: (v) => setState(
                      () => v == true
                          ? _selected.add(c.id!)
                          : _selected.remove(c.id),
                    ),
                    controlAffinity: ListTileControlAffinity.leading,
                    title: Text(c.name.isEmpty ? '(氏名なし)' : c.name),
                    subtitle: Text(
                      [
                        c.company,
                        c.phone.isNotEmpty ? c.phone : c.mobile,
                      ].where((s) => s.isNotEmpty).join('  '),
                    ),
                    secondary: c.contactRegistered
                        ? const Chip(label: Text('登録済み'))
                        : null,
                  );
                },
              ),
      ),
      SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: SizedBox(
            width: double.infinity,
            child: FilledButton.icon(
              onPressed: (_selected.isEmpty || _progress != null)
                  ? null
                  : () => _run(
                      _cards.where((c) => _selected.contains(c.id)).toList(),
                    ),
              icon: const Icon(Icons.person_add_alt),
              label: Text('選んだ${_selected.length}件を電話帳に登録'),
            ),
          ),
        ),
      ),
    ],
  );
}
