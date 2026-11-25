import 'dart:io';

import 'package:material_ui/material_ui.dart';
import 'package:flutter_contacts/flutter_contacts.dart';
import 'package:url_launcher/url_launcher.dart';

import '../contacts.dart';
import '../delete_dialog.dart';
import '../main.dart';
import '../models.dart';
import 'confirm_screen.dart';
import 'plan_screen.dart';

class DetailScreen extends StatefulWidget {
  const DetailScreen({super.key, required this.id});
  final int id;
  @override
  State<DetailScreen> createState() => _DetailScreenState();
}

class _DetailScreenState extends State<DetailScreen> {
  BizCard? _c;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final c = await repo.get(widget.id);
    if (mounted) setState(() => _c = c);
  }

  Future<void> _open(String url) async {
    if (!await launchUrl(
          Uri.parse(url),
          mode: LaunchMode.externalApplication,
        ) &&
        mounted) {
      ScaffoldMessenger.of(context)
          .showSnackBar(const SnackBar(content: Text('開けませんでした')));
    }
  }

  Future<void> _edit() async {
    await Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => ConfirmScreen(card: _c!)),
    );
    _load();
  }

  Future<void> _delete() async {
    final r = await confirmDelete(context, title: 'この名刺を削除しますか？');
    if (r == null) return;
    await repo.delete(widget.id, removeLocalImage: r.removeImage);
    if (mounted) Navigator.pop(context);
  }

  /// 端末の連絡先アプリの登録画面を開く（連絡先の権限は不要）
  Future<void> _addToContacts() async {
    if (!plan.isPro) {
      Navigator.push(
        context,
        MaterialPageRoute(
          builder: (_) => const PlanScreen(reason: '連絡先への登録は有料プランの機能です。'),
        ),
      );
      return;
    }
    final c = _c!;
    // 端末の連絡先アプリの登録画面を開く（作成されたら登録済みの印を付ける）
    final id = await FlutterContacts.native.showCreator(
      contact: bizToContact(c, forIntent: true),
    );
    if (id != null) {
      c.contactRegistered = true;
      await repo.update(c);
      _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = _c;
    if (c == null)
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    Widget row(IconData icon, String label, String value, {String? url}) =>
        value.isEmpty
        ? const SizedBox.shrink()
        : ListTile(
            leading: Icon(icon),
            title: SelectableText(value),
            subtitle: Text(label),
            onTap: url == null ? null : () => _open(url),
            trailing: url == null
                ? null
                : const Icon(Icons.open_in_new, size: 18),
          );
    return Scaffold(
      appBar: AppBar(
        title: Text(c.name.isEmpty ? '名刺' : c.name),
        actions: [
          TextButton.icon(
            onPressed: _edit,
            icon: const Icon(Icons.edit_outlined),
            label: const Text('編集'),
          ),
          TextButton.icon(
            onPressed: _delete,
            icon: const Icon(Icons.delete_outline),
            label: const Text('削除'),
          ),
          const SizedBox(width: 4),
        ],
      ),
      body: ListView(
        children: [
          if (c.thumbPath.isNotEmpty)
            Padding(
              padding: const EdgeInsets.all(16),
              child: InteractiveViewer(
                child: Image.file(
                  File(c.thumbPath),
                  fit: BoxFit.contain,
                  errorBuilder: (_, _, _) => const Padding(
                    padding: EdgeInsets.all(12),
                    child: Text(
                      '画像がありません（アプリ内の画像が削除されています）',
                      style: TextStyle(color: Colors.grey),
                    ),
                  ),
                ),
              ),
            ),
          row(Icons.business, '会社名', c.company),
          row(Icons.groups_outlined, '部署', c.department),
          row(Icons.work_outline, '役職', c.title),
          row(Icons.phone, '電話', c.phone, url: 'tel:${c.phone}'),
          row(Icons.smartphone, '携帯', c.mobile, url: 'tel:${c.mobile}'),
          row(Icons.print_outlined, 'FAX', c.fax),
          row(Icons.mail_outline, 'メール', c.email, url: 'mailto:${c.email}'),
          row(
            Icons.place_outlined,
            '住所',
            c.address,
            url: 'https://maps.google.com/?q=${Uri.encodeComponent(c.address)}',
          ),
          row(
            Icons.language,
            'Webサイト',
            c.url,
            url: c.url.startsWith('http') ? c.url : 'https://${c.url}',
          ),
          row(Icons.notes, 'メモ', c.memo),
          const SizedBox(height: 12),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: FilledButton.icon(
              onPressed: _addToContacts,
              icon: const Icon(Icons.person_add_alt),
              label: Text(
                c.contactRegistered
                    ? '連絡先に登録済み（もう一度登録）'
                    : (plan.isPro ? '連絡先に登録' : '連絡先に登録（有料）'),
              ),
            ),
          ),
          const SizedBox(height: 32),
        ],
      ),
    );
  }
}
