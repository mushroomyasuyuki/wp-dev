import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:file_picker/file_picker.dart';
import 'package:material_ui/material_ui.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../csv_import.dart';
import '../duplicates.dart';
import '../main.dart';
import '../models.dart';
import '../plan.dart';
import '../storage.dart';
import 'contacts_register_screen.dart';
import 'plan_screen.dart';

class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key});
  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  String? _folder;
  String _version = '';
  int _thumbCount = 0;
  int _thumbBytes = 0;
  int _pending = 0;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  Future<void> _refresh() async {
    final f = await ExternalStore.folderUri();
    final p = (await repo.pendingImages()).length;
    final info = await PackageInfo.fromPlatform();
    final t = await repo.thumbsInfo();
    if (!mounted) return;
    setState(() {
      _folder = f;
      _pending = p;
      _thumbCount = t.count;
      _thumbBytes = t.bytes;
      _version = '${info.version} (${info.buildNumber})';
    });
  }

  Future<void> _deleteThumbs() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('アプリ内の画像をすべて削除しますか？'),
        content: const Text(
          '一覧や詳細に出る、アプリ内の画像を削除します。名刺の文字の情報と、SDカードなどに保存した画像は消えません。',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('残す'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('削除する'),
          ),
        ],
      ),
    );
    if (ok != true) return;
    await repo.deleteAllThumbs();
    _toast('アプリ内の画像を削除しました');
    _refresh();
  }

  void _toast(String s) =>
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(s)));

  Future<void> _pick() async {
    if (await ExternalStore.pickFolder(repo)) _toast('保存先を設定しました');
    _refresh();
  }

  Future<void> _flush() async {
    final n = await ExternalStore.flushPending(repo);
    _toast(n > 0 ? '$n枚を移しました' : '移せませんでした。保存先を確認してください');
    _refresh();
  }

  String _csvCell(String s) => '"${s.replaceAll('"', '""')}"';

  /// CSVから名刺を一括で取り込む。
  Future<void> _importCsv() async {
    // 無料プランは30枚まで。取り込みでも超えないようにする
    var room = 1 << 30;
    if (!plan.isPro) {
      room = freeLimit - await repo.count();
      if (room <= 0) {
        if (!mounted) return;
        Navigator.push(
          context,
          MaterialPageRoute(
            builder: (_) =>
                const PlanScreen(reason: '無料版で登録できるのは$freeLimit枚までです。'),
          ),
        );
        return;
      }
    }
    final picked = await FilePicker.pickFiles(type: FileType.any);
    if (picked.isEmpty) return;
    List<BizCard> cards;
    try {
      final text = await decodeCsvBytes(await picked.first.readAsBytes());
      cards = cardsFromRows(parseCsv(text)).cards;
    } catch (e) {
      _toast('ファイルを読めませんでした（CSVファイルを選んでください）');
      return;
    }
    if (cards.isEmpty) {
      _toast('取り込める名刺が見つかりませんでした。1行目に「氏名」「会社名」などの項目名があるCSVを選んでください');
      return;
    }
    // 登録済み、またはファイル内で先に出てきた名刺と重複しているものを調べる
    final seen = await repo.all();
    final dupIdx = <int>{};
    for (var i = 0; i < cards.length; i++) {
      if (duplicatesOf(cards[i], seen).isNotEmpty) dupIdx.add(i);
      seen.add(cards[i]);
    }
    if (!mounted) return;
    var skipDup = true;
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => StatefulBuilder(
        builder: (context, setState) => AlertDialog(
          title: Text('${cards.length}件の名刺が見つかりました'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                '重複の可能性があるもの：${dupIdx.length}件\n（メール・電話番号、または氏名と会社名が、登録済みの名刺またはファイル内の先の名刺と同じ）',
              ),
              if (!plan.isPro && cards.length > room)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(
                    '無料版は$freeLimit枚までのため、残り$room枚分だけ取り込みます。',
                    style: const TextStyle(color: Colors.orange),
                  ),
                ),
              CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
                value: skipDup,
                onChanged: (v) => setState(() => skipDup = v ?? true),
                title: const Text('重複するものは取り込まない'),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('やめる'),
            ),
            TextButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('取り込む'),
            ),
          ],
        ),
      ),
    );
    if (ok != true) return;
    var added = 0, skipped = 0;
    for (var i = 0; i < cards.length; i++) {
      if (skipDup && dupIdx.contains(i)) {
        skipped++;
        continue;
      }
      if (added >= room) break;
      await repo.insert(cards[i]);
      added++;
    }
    _toast('$added件を取り込みました${skipped > 0 ? '（重複$skipped件は取り込まず）' : ''}');
    _refresh();
  }

  Future<void> _exportCsv() async {
    if (!plan.isPro) {
      Navigator.push(
        context,
        MaterialPageRoute(
          builder: (_) => const PlanScreen(reason: 'CSV書き出しは有料プランの機能です。'),
        ),
      );
      return;
    }
    final cards = await repo.all();
    final head = [
      '氏名',
      'よみがな',
      '会社名',
      '部署',
      '役職',
      '電話',
      '携帯',
      'FAX',
      'メール',
      '住所',
      'Webサイト',
      'メモ',
      '登録日時',
      '会社名よみがな',
    ];
    final buf = StringBuffer('﻿${head.map(_csvCell).join(',')}\r\n');
    for (final c in cards) {
      buf.write(
        [
          c.name,
          c.nameKana,
          c.company,
          c.department,
          c.title,
          c.phone,
          c.mobile,
          c.fax,
          c.email,
          c.address,
          c.url,
          c.memo,
          c.createdAt.toIso8601String(),
          c.companyKana,
        ].map(_csvCell).join(','),
      );
      buf.write('\r\n');
    }
    final d = DateTime.now();
    final name =
        'meishi_${d.year}${d.month.toString().padLeft(2, '0')}${d.day.toString().padLeft(2, '0')}.csv';
    final r = await ExternalStore.save(
      Uint8List.fromList(utf8.encode(buf.toString())),
      name,
      mime: 'text/csv',
    );
    _toast(
      r.pending ? '保存先が使えないため、アプリ内に保存しました' : '${cards.length}件をCSVで書き出しました',
    );
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('設定')),
    body: ListenableBuilder(
      listenable: plan,
      builder: (_, _) => ListView(
        children: [
          if (Platform.isAndroid)
            ListTile(
              leading: const Icon(Icons.sd_storage_outlined),
              title: const Text('画像の保存先フォルダ'),
              subtitle: Text(
                _folder == null
                    ? '未設定（SDカードなどのフォルダを選んでください）'
                    : Uri.decodeFull(_folder!.split('/tree/').last),
              ),
              onTap: _pick,
            )
          else
            const ListTile(
              leading: Icon(Icons.folder_outlined),
              title: Text('画像の保存先'),
              subtitle: Text('「ファイル」アプリ ＞ このiPhone内 ＞ 名刺'),
            ),
          if (_pending > 0)
            ListTile(
              leading: const Icon(Icons.warning_amber, color: Colors.orange),
              title: Text('未移動の画像が$_pending枚あります'),
              subtitle: const Text('タップして保存先へ移す'),
              onTap: _flush,
            ),
          const Divider(),
          ListTile(
            leading: const Icon(Icons.image_outlined),
            title: const Text('アプリ内の画像'),
            subtitle: Text(
              '$_thumbCount枚・${(_thumbBytes / 1048576).toStringAsFixed(1)}MB\n削除しても、名刺の文字の情報は残ります',
            ),
            isThreeLine: true,
            trailing: OutlinedButton(
              onPressed: _thumbCount == 0 ? null : _deleteThumbs,
              child: const Text('すべて削除'),
            ),
          ),
          ListTile(
            leading: const Icon(Icons.contacts_outlined),
            title: Text(plan.isPro ? '電話帳に登録' : '電話帳に登録（有料）'),
            subtitle: const Text('全件まとめて、または選んで登録します'),
            onTap: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => const ContactsRegisterScreen()),
            ),
          ),
          ListTile(
            leading: const Icon(Icons.upload_file_outlined),
            title: const Text('CSVから取り込む'),
            subtitle: const Text(
              '書き出したCSVと同じ形式。1行目に「氏名」「会社名」などの項目名を入れます。Excelで作ったCSVも読めます',
            ),
            isThreeLine: true,
            onTap: _importCsv,
          ),
          ListTile(
            leading: const Icon(Icons.table_view_outlined),
            title: Text(plan.isPro ? 'CSVで書き出す' : 'CSVで書き出す（有料）'),
            subtitle: const Text('全件を、画像と同じ保存先に保存します'),
            onTap: _exportCsv,
          ),
          ListTile(
            leading: const Icon(Icons.workspace_premium_outlined),
            title: const Text('プラン'),
            subtitle: Text(plan.isPro ? '有料プラン（無制限）' : '無料プラン（$freeLimit枚まで）'),
            onTap: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => const PlanScreen()),
            ),
          ),
          const Divider(),
          const Padding(
            padding: EdgeInsets.all(16),
            child: Text(
              '名刺のデータと画像は、この端末と選んだ保存先にだけ保存されます。文字の読み取りも端末内で行い、名刺の情報を外部へ送信しません。',
              style: TextStyle(color: Colors.grey),
            ),
          ),
          ListTile(title: const Text('バージョン'), subtitle: Text(_version)),
        ],
      ),
    ),
  );
}
