import 'package:flutter_test/flutter_test.dart';
import 'package:image/image.dart' as img;
import 'package:meishi_app/ocr.dart';

void main() {
  test('画像を整えて、文字の範囲を切り出せる', () {
    final im = img.Image(width: 1600, height: 1000);
    img.fill(im, color: img.ColorRgb8(150, 140, 140));
    img.fillRect(
      im,
      x1: 400,
      y1: 300,
      x2: 900,
      y2: 360,
      color: img.ColorRgb8(60, 60, 60),
    );
    final jpg = img.encodeJpg(im, quality: 90);
    final enhanced = enhanceForOcr(jpg);
    expect(enhanced, isNotNull);
    final out = img.decodeImage(enhanced!)!;
    expect(out.width, 1600);
    final cropped = cropForOcr(enhanced, const Rect(400, 300, 900, 360));
    // 範囲が小さすぎる(高さ100未満)ときは切り出さない
    expect(cropped == null || img.decodeImage(cropped)!.width < 1600, isTrue);
    final cropped2 = cropForOcr(enhanced, const Rect(300, 200, 1300, 700));
    expect(img.decodeImage(cropped2!)!.width, lessThan(1600));
  });
}
