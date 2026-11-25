import 'dart:io';
import 'package:flutter/services.dart';
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'db.dart';

class SavedImage {
  final String uri; // 保存先（Androidはcontent://、それ以外はファイルのパス）
  final bool pending; // 外部に保存できず、アプリ内に一時保存した
  const SavedImage(this.uri, this.pending);
}

/// 画像・CSVを外部ストレージへ保存する。
/// Android: ユーザーが選んだフォルダ（SDカード可）へ。許可は端末に記憶される。
/// iPhone: 「ファイル」アプリから見える本アプリのフォルダへ。
class ExternalStore {
  static const _ch = MethodChannel('meishi/storage');
  static const _kFolder = 'folder_uri';

  static Future<String?> folderUri() async =>
      (await SharedPreferences.getInstance()).getString(_kFolder);

  /// Androidのみ。保存先フォルダを選ぶ。選べたらtrue。
  static Future<bool> pickFolder(CardRepo repo) async {
    if (!Platform.isAndroid) return false;
    final uri = await _ch.invokeMethod<String>('pickFolder');
    if (uri == null) return false;
    await (await SharedPreferences.getInstance()).setString(_kFolder, uri);
    await flushPending(repo);
    return true;
  }

  static Future<Directory> _pendingDir() async {
    final d = Directory('${(await getApplicationSupportDirectory()).path}/pending');
    return d.create(recursive: true);
  }

  static Future<Directory> _iosDir() async {
    final d = Directory('${(await getApplicationDocumentsDirectory()).path}/名刺');
    return d.create(recursive: true);
  }

  static Future<SavedImage> save(Uint8List bytes, String name, {String mime = 'image/jpeg'}) async {
    try {
      if (Platform.isAndroid) {
        final tree = await folderUri();
        if (tree != null) {
          final uri = await _ch.invokeMethod<String>(
              'saveFile', {'tree': tree, 'name': name, 'mime': mime, 'bytes': bytes});
          if (uri != null) return SavedImage(uri, false);
        }
      } else {
        final f = File('${(await _iosDir()).path}/$name');
        await f.writeAsBytes(bytes);
        return SavedImage(f.path, false);
      }
    } catch (_) {/* 失敗したら一時保存へ */}
    final f = File('${(await _pendingDir()).path}/$name');
    await f.writeAsBytes(bytes);
    return SavedImage(f.path, true);
  }

  /// 一時保存してある画像を、保存先が使えるようになったら移す。移せた件数を返す。
  static Future<int> flushPending(CardRepo repo) async {
    var moved = 0;
    for (final c in await repo.pendingImages()) {
      final f = File(c.imageUri);
      if (!await f.exists()) {
        c.imagePending = false;
        await repo.update(c);
        continue;
      }
      final r = await save(await f.readAsBytes(), f.uri.pathSegments.last);
      if (!r.pending) {
        c.imageUri = r.uri;
        c.imagePending = false;
        await repo.update(c);
        await f.delete();
        moved++;
      }
    }
    return moved;
  }
}
