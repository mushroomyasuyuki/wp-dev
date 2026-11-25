import 'package:flutter_test/flutter_test.dart';
import 'package:meishi_app/parser.dart';

void main() {
  test('典型的な名刺を振り分ける', () {
    final c = parseCard(const [
      OcrLine('株式会社サンプル商事', 40),
      OcrLine('営業部', 30),
      OcrLine('部長', 28),
      OcrLine('山田 太郎', 80),
      OcrLine('〒100-0001', 20),
      OcrLine('東京都千代田区千代田1-1-1', 22),
      OcrLine('サンプルビル 3F', 22),
      OcrLine('TEL 03-1234-5678 FAX 03-1234-5679', 20),
      OcrLine('携帯 090-1111-2222', 20),
      OcrLine('E-mail: taro.yamada@example.co.jp', 20),
      OcrLine('https://www.example.co.jp', 20),
    ]);
    expect(c.company, '株式会社サンプル商事');
    expect(c.department, '営業部');
    expect(c.title, '部長');
    expect(c.name, '山田 太郎');
    expect(c.phone, '03-1234-5678');
    expect(c.fax, '03-1234-5679');
    expect(c.mobile, '090-1111-2222');
    expect(c.email, 'taro.yamada@example.co.jp');
    expect(c.url, 'https://www.example.co.jp');
    expect(c.address, contains('東京都千代田区'));
    expect(c.address, contains('サンプルビル'));
  });

  test('判定できない行はメモに残る', () {
    final c = parseCard(const [
      OcrLine('Creating the future', 20),
      OcrLine('佐藤', 60),
    ]);
    expect(c.name, '佐藤');
    expect(c.memo, 'Creating the future');
  });

  test('士業の名刺（実機で外れた例）', () {
    final c = parseCard(const [
      OcrLine('若崎特許商標事務所', 30, 10),
      OcrLine('弁 理 士', 20, 60),
      OcrLine('わか ざき', 10, 50),
      OcrLine('若崎 義和', 60, 70),
      OcrLine('〒329-0434', 18, 150),
      OcrLine('栃木県下野市祇園五丁目12番地11', 18, 170),
      OcrLine('TEL 0285-36-1366 FAX 0285-44-4054', 18, 190),
      OcrLine('URL https://www.waka-ip.com', 18, 210),
      OcrLine('E-mail wakapat@kuf.biglobe.ne.jp', 18, 230),
    ]);
    expect(c.company, '若崎特許商標事務所');
    expect(c.title, '弁理士');
    expect(c.name, '若崎 義和');
    expect(c.email, 'wakapat@kuf.biglobe.ne.jp');
    expect(c.url, 'https://www.waka-ip.com');
    expect(c.address, contains('栃木県下野市'));
    expect(c.phone, '0285-36-1366');
    expect(c.fax, '0285-44-4054');
  });

  test('@が読めなかったメールもメール欄に入る', () {
    final c = parseCard(const [
      OcrLine('E-mail wakapatakuf.biglobe.ne.jp', 18, 230),
    ]);
    expect(c.email, 'wakapatakuf.biglobe.ne.jp');
  });

  test('読み取りの誤り（T/点抜け/ロゴ）を直す', () {
    final c = parseCard(const [
      OcrLine('の若崎特許商標事務所', 30, 10),
      OcrLine('T329-0434 栃木県下野市祇園五丁目12番地11', 18, 170),
      OcrLine('E-mail wakapat@kuf.biglobe.nejp', 18, 230),
    ]);
    expect(c.company, '若崎特許商標事務所');
    expect(c.address, startsWith('〒329-0434'));
    expect(c.email, 'wakapat@kuf.biglobe.ne.jp');
  });
}
