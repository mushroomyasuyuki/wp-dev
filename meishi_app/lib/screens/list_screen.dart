import 'dart:io';
import 'dart:typed_data';
import 'package:material_ui/material_ui.dart';
import 'package:flutter_image_compress/flutter_image_compress.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';
import '../main.dart';
import '../models.dart';
import '../ocr.dart';
import '../parser.dart';
import '../plan.dart';
import '../storage.dart';
import 'confirm_screen.dart';
import 'detail_screen.dart';
import 'plan_screen.dart';
import 'settings_screen.dart';

class ListScreen extends StatefulWidget {
  const ListScreen({super.key});
  @override
  State<ListScreen> createState() => _ListScreenState();
}

class _ListScreenState extends State<ListScreen> {
  List<BizCard> _cards = [];
  String _q = '';

  @override
  void initState() {
    super.initState();
    _reload();
    _recoverLost();
  }

  /// 撮影中にアプリが終了させられた場合、撮った写真をここで受け取って続きを行う。
  Future<void> _recoverLost() async {
    if (!Platform.isAndroid) return;
    try {
      final r = await ImagePicker().retrieveLostData();
      if (r.isEmpty) return;
      final f = r.file ?? (r.files?.isNotEmpty == true ? r.files!.first : null);
      if (f != null && mounted) await _process(f);
    } catch (_) {}
  }

  Future<void> _reload() async {
    final r = await repo.search(_q);
    if (mounted) setState(() => _cards = r);
  }

  Future<void> _go(Widget w) async {
    await Navigator.push(context, MaterialPageRoute(builder: (_) => w));
    _reload();
  }

  String _stamp(DateTime d) {
    String t(int n, [int w = 2]) => n.toString().padLeft(w, '0');
    return '${t(d.year, 4)}${t(d.month)}${t(d.day)}_${t(d.hour)}${t(d.minute)}${t(d.second)}';
  }

  /// 撮影（または写真の選択）→ 圧縮して外部に自動保存 → 文字認識 → 確認画面へ
  Future<void> _capture(ImageSource source) async {
    if (!plan.isPro && await repo.count() >= freeLimit) {
      if (mounted) _go(const PlanScreen(reason: '無料版で登録できるのは$freeLimit枚までです。'));
      return;
    }
    final picked = await ImagePicker().pickImage(source: source);
    if (picked == null || !mounted) return;
    await _process(picked);
  }

  Future<void> _process(XFile picked) async {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => const PopScope(
        canPop: false,
        child: AlertDialog(content: Row(children: [CircularProgressIndicator(), SizedBox(width: 20), Text('読み取り中…')])),
      ),
    );
    BizCard? card;
    String? problem;
    try {
      final name = '${_stamp(DateTime.now())}.jpg';
      final original = await File(picked.path).readAsBytes();
      Uint8List big = original;
      try {
        big = await FlutterImageCompress.compressWithFile(picked.path, minWidth: 1600, minHeight: 1600, quality: 85) ?? original;
      } catch (e) {
        problem = '画像の圧縮に失敗: $e';
      }
      final saved = await ExternalStore.save(big, name);
      var thumbPath = '';
      try {
        final dir = Directory('${(await getApplicationSupportDirectory()).path}/thumbs');
        await dir.create(recursive: true);
        final thumb = File('${dir.path}/$name');
        await thumb.writeAsBytes(
            await FlutterImageCompress.compressWithFile(picked.path, minWidth: 800, minHeight: 800, quality: 70) ?? big);
        thumbPath = thumb.path;
      } catch (e) {
        problem ??= '表示用画像の作成に失敗: $e';
      }
      List<OcrLine> lines = [];
      try {
        lines = await recognizeCard(picked.path); // 圧縮前の画像で認識する
        if (lines.isEmpty) problem ??= '文字が見つかりませんでした（明るい場所で、名刺だけが大きく写るように撮ってください）';
      } catch (e) {
        problem ??= '文字認識に失敗: $e';
      }
      card = parseCard(lines)
        ..imageUri = saved.uri
        ..imagePending = saved.pending
        ..thumbPath = thumbPath;
    } catch (e) {
      problem = '処理に失敗: $e';
    } finally {
      if (mounted) Navigator.pop(context);
    }
    if (!mounted) return;
    if (problem != null) {
      await showDialog<void>(
        context: context,
        builder: (_) => AlertDialog(
          title: const Text('読み取りの結果'),
          content: SingleChildScrollView(child: SelectableText(problem!)),
          actions: [TextButton(onPressed: () => Navigator.pop(context), child: const Text('OK'))],
        ),
      );
    }
    if (card != null && mounted) _go(ConfirmScreen(card: card));
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: const Text('名刺'),
          actions: [
            IconButton(
              tooltip: '写真から読み込む',
              icon: const Icon(Icons.photo_library_outlined),
              onPressed: () => _capture(ImageSource.gallery),
            ),
            IconButton(
              tooltip: '設定',
              icon: const Icon(Icons.settings_outlined),
              onPressed: () => _go(const SettingsScreen()),
            ),
          ],
          bottom: PreferredSize(
            preferredSize: const Size.fromHeight(60),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
              child: SearchBar(
                hintText: '氏名・会社名・メモで検索',
                leading: const Icon(Icons.search),
                onChanged: (v) {
                  _q = v;
                  _reload();
                },
              ),
            ),
          ),
        ),
        body: _cards.isEmpty
            ? Center(child: Text(_q.isEmpty ? '右下のボタンで名刺を撮影しましょう' : '見つかりませんでした'))
            : ListView.separated(
                itemCount: _cards.length,
                separatorBuilder: (_, _) => const Divider(height: 1),
                itemBuilder: (_, i) {
                  final c = _cards[i];
                  return ListTile(
                    leading: _Thumb(c.thumbPath),
                    title: Text(c.name.isEmpty ? '(氏名なし)' : c.name),
                    subtitle: Text([c.company, c.title].where((s) => s.isNotEmpty).join('  ')),
                    onTap: () => _go(DetailScreen(id: c.id!)),
                  );
                },
              ),
        floatingActionButton: FloatingActionButton.extended(
          onPressed: () => _capture(ImageSource.camera),
          icon: const Icon(Icons.photo_camera),
          label: const Text('撮影'),
        ),
      );
}

class _Thumb extends StatelessWidget {
  const _Thumb(this.path);
  final String path;
  @override
  Widget build(BuildContext context) => ClipRRect(
        borderRadius: BorderRadius.circular(6),
        child: SizedBox(
          width: 64,
          height: 44,
          child: path.isEmpty
              ? const ColoredBox(color: Color(0x11000000), child: Icon(Icons.badge_outlined))
              : Image.file(File(path), fit: BoxFit.cover, cacheWidth: 160, errorBuilder: (_, _, _) => const Icon(Icons.badge_outlined)),
        ),
      );
}
