import 'dart:io';
import 'dart:typed_data';

import 'package:material_ui/material_ui.dart';
import 'package:flutter_image_compress/flutter_image_compress.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../main.dart';
import '../models.dart';
import '../ocr.dart';
import '../plan.dart';
import '../storage.dart';
import 'confirm_screen.dart';
import 'detail_screen.dart';
import 'duplicates_screen.dart';
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
  String _period = 'all'; // all / today / week / month / custom
  DateTimeRange? _custom;
  String? _busy; // 処理中の表示（nullなら非表示）
  static const _kCapturing = 'capturing';

  @override
  void initState() {
    super.initState();
    _reload();
    _recoverLost();
  }

  Future<void> _setCapturing(bool v) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_kCapturing, v);
  }

  /// 撮影中にアプリが終了させられた場合、撮った写真をここで受け取って続きを行う。
  Future<void> _recoverLost() async {
    if (!Platform.isAndroid) return;
    final prefs = await SharedPreferences.getInstance();
    // 撮影中にアプリが終了していた場合は、起動した瞬間から「読み込み中」を出す
    if (prefs.getBool(_kCapturing) ?? false) {
      if (mounted) setState(() => _busy = '撮影した名刺を読み込み中…');
    }
    try {
      final r = await ImagePicker().retrieveLostData();
      final f = r.isEmpty
          ? null
          : (r.file ?? (r.files?.isNotEmpty == true ? r.files!.first : null));
      if (f != null && mounted) {
        await _process(f);
        return;
      }
    } catch (_) {}
    await _setCapturing(false);
    if (mounted) setState(() => _busy = null);
  }

  /// 選んだ期間を「以上・未満」の日時にする。
  (DateTime?, DateTime?) _range() {
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final tomorrow = today.add(const Duration(days: 1));
    switch (_period) {
      case 'today':
        return (today, tomorrow);
      case 'week':
        return (today.subtract(const Duration(days: 6)), tomorrow);
      case 'month':
        return (DateTime(now.year, now.month - 1, now.day), tomorrow);
      case 'custom':
        final c = _custom;
        if (c == null) return (null, null);
        return (
          c.start,
          DateTime(
            c.end.year,
            c.end.month,
            c.end.day,
          ).add(const Duration(days: 1)),
        );
      default:
        return (null, null);
    }
  }

  Future<void> _reload() async {
    final (from, to) = _range();
    final r = await repo.search(_q, from: from, to: to);
    if (mounted) setState(() => _cards = r);
  }

  Future<void> _pickRange() async {
    final now = DateTime.now();
    final r = await showDateRangePicker(
      context: context,
      firstDate: DateTime(2000),
      lastDate: now,
      initialDateRange: _custom,
      helpText: '登録した日の範囲を選ぶ',
      saveText: '決定',
    );
    if (r == null) return;
    _custom = r;
    _period = 'custom';
    _reload();
  }

  String _md(DateTime d) => '${d.month}/${d.day}';

  Widget _chips() {
    Widget chip(String key, String label) => Padding(
      padding: const EdgeInsets.only(right: 8),
      child: ChoiceChip(
        label: Text(label),
        selected: _period == key,
        onSelected: (_) {
          _period = key;
          _reload();
        },
      ),
    );
    final c = _custom;
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.fromLTRB(16, 8, 8, 4),
      child: Row(
        children: [
          chip('all', 'すべて'),
          chip('today', '今日'),
          chip('week', '過去1週間'),
          chip('month', '過去1か月'),
          Padding(
            padding: const EdgeInsets.only(right: 8),
            child: ChoiceChip(
              avatar: const Icon(Icons.date_range, size: 18),
              label: Text(
                _period == 'custom' && c != null
                    ? '${_md(c.start)}〜${_md(c.end)}'
                    : '日付を指定',
              ),
              selected: _period == 'custom',
              onSelected: (_) => _pickRange(),
            ),
          ),
        ],
      ),
    );
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
    await _setCapturing(true);
    final XFile? picked;
    try {
      picked = await ImagePicker().pickImage(source: source);
    } catch (_) {
      await _setCapturing(false);
      rethrow;
    }
    if (picked == null || !mounted) {
      await _setCapturing(false);
      return;
    }
    await _process(picked);
  }

  Future<void> _process(XFile picked) async {
    setState(() => _busy = '画像を保存中…');
    BizCard? card;
    String? problem;
    try {
      final name = '${_stamp(DateTime.now())}.jpg';
      final original = await File(picked.path).readAsBytes();
      Uint8List big = original;
      try {
        big =
            await FlutterImageCompress.compressWithFile(
              picked.path,
              minWidth: 1600,
              minHeight: 1600,
              quality: 85,
            ) ??
            original;
      } catch (e) {
        problem = '画像の圧縮に失敗: $e';
      }
      final saved = await ExternalStore.save(big, name);
      if (mounted) setState(() => _busy = '文字を認識中…');
      var thumbPath = '';
      try {
        final dir = Directory(
          '${(await getApplicationSupportDirectory()).path}/thumbs',
        );
        await dir.create(recursive: true);
        final thumb = File('${dir.path}/$name');
        await thumb.writeAsBytes(
          await FlutterImageCompress.compressWithFile(
                picked.path,
                minWidth: 800,
                minHeight: 800,
                quality: 70,
              ) ??
              big,
        );
        thumbPath = thumb.path;
      } catch (e) {
        problem ??= '表示用画像の作成に失敗: $e';
      }
      BizCard parsed = BizCard();
      try {
        // 圧縮前の画像から、整えた画像も含めて読み取る
        parsed = await recognizeBestCard(picked.path, onStage: (m) {
          if (mounted) setState(() => _busy = m);
        });
        if (parsed.rawText.isEmpty) problem ??= '文字が見つかりませんでした（明るい場所で、名刺だけが大きく写るように撮ってください）';
      } catch (e) {
        problem ??= '文字認識に失敗: $e';
      }
      card = parsed
        ..imageUri = saved.uri
        ..imagePending = saved.pending
        ..thumbPath = thumbPath;
    } catch (e) {
      problem = '処理に失敗: $e';
    } finally {
      await _setCapturing(false);
      if (mounted) setState(() => _busy = null);
    }
    if (!mounted) return;
    if (problem != null) {
      await showDialog<void>(
        context: context,
        builder: (_) => AlertDialog(
          title: const Text('読み取りの結果'),
          content: SingleChildScrollView(child: SelectableText(problem!)),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('OK'),
            ),
          ],
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
          tooltip: '重複を探す',
          icon: const Icon(Icons.content_copy_outlined),
          onPressed: () => _go(const DuplicatesScreen()),
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
    body: Stack(
      children: [
        Column(
          children: [
            _chips(),
            Expanded(
              child: _cards.isEmpty
                  ? Center(
                      child: Text(
                        _q.isEmpty && _period == 'all'
                            ? '右下のボタンで名刺を撮影しましょう'
                            : '条件に合う名刺がありません',
                      ),
                    )
                  : ListView.separated(
                      itemCount: _cards.length,
                      separatorBuilder: (_, _) => const Divider(height: 1),
                      itemBuilder: (_, i) {
                        final c = _cards[i];
                        return ListTile(
                          leading: _Thumb(c.thumbPath),
                          title: Text(c.name.isEmpty ? '(氏名なし)' : c.name),
                          subtitle: Text(
                            [
                              c.company,
                              c.title,
                            ].where((s) => s.isNotEmpty).join('  '),
                          ),
                          trailing: Text(
                            '${c.createdAt.month}/${c.createdAt.day}',
                            style: const TextStyle(color: Colors.grey),
                          ),
                          onTap: () => _go(DetailScreen(id: c.id!)),
                        );
                      },
                    ),
            ),
          ],
        ),
        if (_busy != null)
          Positioned.fill(
            child: ColoredBox(
              color: Colors.black54,
              child: Center(
                child: Card(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 28,
                      vertical: 24,
                    ),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const CircularProgressIndicator(),
                        const SizedBox(height: 16),
                        Text(
                          _busy!,
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ),
      ],
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
          ? const ColoredBox(
              color: Color(0x11000000),
              child: Icon(Icons.badge_outlined),
            )
          : Image.file(
              File(path),
              fit: BoxFit.cover,
              cacheWidth: 160,
              errorBuilder: (_, _, _) => const Icon(Icons.badge_outlined),
            ),
    ),
  );
}
