import 'dart:io';
import 'dart:isolate';
import 'dart:typed_data';
import 'package:flutter/foundation.dart';
import 'package:flutter_image_compress/flutter_image_compress.dart';
import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';
import 'package:image/image.dart' as img;
import 'package:path_provider/path_provider.dart';
import 'models.dart';
import 'parser.dart';

class _Ocr {
  final List<OcrLine> lines;
  final Rect? box; // 文字が写っている範囲（画像内の座標）
  _Ocr(this.lines, this.box);
}

class Rect {
  final double l, t, r, b;
  const Rect(this.l, this.t, this.r, this.b);
}

/// 端末内で文字を読み取る（通信なし）。
Future<_Ocr> _recognize(String path) async {
  final recognizer = TextRecognizer(script: TextRecognitionScript.japanese);
  try {
    final result = await recognizer.processImage(InputImage.fromFilePath(path));
    final lines = <OcrLine>[];
    double l = double.infinity, t = double.infinity, r = 0, b = 0;
    for (final block in result.blocks) {
      for (final line in block.lines) {
        final bb = line.boundingBox;
        lines.add(OcrLine(line.text, bb.height, bb.top));
        if (bb.left < l) l = bb.left;
        if (bb.top < t) t = bb.top;
        if (bb.right > r) r = bb.right;
        if (bb.bottom > b) b = bb.bottom;
      }
    }
    return _Ocr(lines, lines.isEmpty ? null : Rect(l, t, r, b));
  } finally {
    await recognizer.close();
  }
}

/// 白黒にしてコントラストを引き伸ばす。向きは写真の情報に合わせて直す。
@visibleForTesting
Uint8List? enhanceForOcr(Uint8List jpg) {
  final src = img.decodeImage(jpg);
  if (src == null) return null;
  var im = img.bakeOrientation(src);
  im = img.grayscale(im);
  im = img.normalize(im, min: 0, max: 255);
  im = img.contrast(im, contrast: 125);
  return Uint8List.fromList(img.encodeJpg(im, quality: 92));
}

/// 文字の範囲だけを、少し余白をつけて切り出す。文字が相対的に大きくなり、読み取りやすくなる。
@visibleForTesting
Uint8List? cropForOcr(Uint8List jpg, Rect box) {
  final im = img.decodeImage(jpg);
  if (im == null) return null;
  final mx = (box.r - box.l) * 0.05 + 12, my = (box.b - box.t) * 0.08 + 12;
  final x = (box.l - mx).clamp(0, im.width - 1).toInt();
  final y = (box.t - my).clamp(0, im.height - 1).toInt();
  final w = (box.r + mx - x).clamp(1, im.width - x).toInt();
  final h = (box.b + my - y).clamp(1, im.height - y).toInt();
  if (w < 200 || h < 100) return null;
  return Uint8List.fromList(img.encodeJpg(img.copyCrop(im, x: x, y: y, width: w, height: h), quality: 92));
}

int _score(BizCard c) {
  final fields = [c.name, c.company, c.title, c.department, c.phone, c.mobile, c.fax, c.email, c.address, c.url];
  return fields.where((f) => f.isNotEmpty).length * 1000 + c.rawText.length;
}

/// 元の画像・整えた画像・文字の範囲を切り出した画像の3通りで読み取り、
/// いちばん項目が埋まった結果を採用する。空の項目は、ほかの結果で補う。
Future<BizCard> recognizeBestCard(String imagePath, {void Function(String)? onStage}) async {
  final cards = <BizCard>[];
  void add(_Ocr o) => cards.add(parseCard(o.lines));

  onStage?.call('文字を認識中…（1/3）');
  final first = await _recognize(imagePath);
  add(first);

  final tmp = await getTemporaryDirectory();
  try {
    onStage?.call('画像を整えています…（2/3）');
    final small = await FlutterImageCompress.compressWithFile(imagePath, minWidth: 2200, minHeight: 2200, quality: 92);
    if (small != null) {
      final enhanced = await Isolate.run(() => enhanceForOcr(small));
      if (enhanced != null) {
        final f = File('${tmp.path}/ocr_enhanced.jpg');
        await f.writeAsBytes(enhanced);
        final second = await _recognize(f.path);
        add(second);

        onStage?.call('文字の範囲を拡大して認識中…（3/3）');
        final box = second.box;
        if (box != null) {
          final cropped = await Isolate.run(() => cropForOcr(enhanced, box));
          if (cropped != null) {
            final g = File('${tmp.path}/ocr_cropped.jpg');
            await g.writeAsBytes(cropped);
            add(await _recognize(g.path));
          }
        }
      }
    }
  } catch (_) {
    // 画像を整える処理に失敗しても、元の画像の結果は使える
  }

  cards.sort((a, b) => _score(b).compareTo(_score(a)));
  final best = cards.first;
  for (final other in cards.skip(1)) {
    if (best.name.isEmpty) best.name = other.name;
    if (best.company.isEmpty) best.company = other.company;
    if (best.title.isEmpty) best.title = other.title;
    if (best.department.isEmpty) best.department = other.department;
    if (best.phone.isEmpty) best.phone = other.phone;
    if (best.mobile.isEmpty) best.mobile = other.mobile;
    if (best.fax.isEmpty) best.fax = other.fax;
    if (best.email.isEmpty) best.email = other.email;
    if (best.address.isEmpty) best.address = other.address;
    if (best.url.isEmpty) best.url = other.url;
  }
  return best;
}
