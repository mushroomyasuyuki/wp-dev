import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:material_ui/material_ui.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../main.dart';
import '../plan.dart';
import '../storage.dart';
import 'plan_screen.dart';

class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key});
  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  String? _folder;
  String _version = '';
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
    if (!mounted) return;
    setState(() {
      _folder = f;
      _pending = p;
      _version = '${info.version} (${info.buildNumber})';
    });
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
