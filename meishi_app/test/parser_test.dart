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
    final c = parseCard(const [OcrLine('Creating the future', 20), OcrLine('佐藤', 60)]);
    expect(c.name, '佐藤');
    expect(c.memo, 'Creating the future');
  });
}
