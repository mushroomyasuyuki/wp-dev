import 'package:flutter_test/flutter_test.dart';
import 'package:meishi_app/kana.dart';
import 'package:meishi_app/models.dart';
import 'package:meishi_app/parser.dart';

BizCard c({
  String name = '',
  String kana = '',
  String company = '',
  String companyKana = '',
}) => BizCard(
  name: name,
  nameKana: kana,
  company: company,
  companyKana: companyKana,
);

void main() {
  test('よみがなから50音の行を決める（濁音・半濁音・カタカナも同じ行）', () {
    expect(gojuonRow(c(name: '山田', kana: 'やまだ')), 'や');
    expect(gojuonRow(c(name: '木村', kana: 'キムラ')), 'か');
    expect(gojuonRow(c(name: '後藤', kana: 'ごとう')), 'か');
    expect(gojuonRow(c(name: '佐藤', kana: 'さとう')), 'さ');
    expect(gojuonRow(c(name: '田中', kana: 'だなか')), 'た');
    expect(gojuonRow(c(name: '橋本', kana: 'ぱ')), 'は');
    expect(gojuonRow(c(name: '渡辺', kana: 'わたなべ')), 'わ');
    expect(gojuonRow(c(name: '安藤', kana: 'あんどう')), 'あ');
  });

  test('よみがながなくても、名前が仮名・英字で始まれば判定できる。漢字だけは「未」', () {
    expect(gojuonRow(c(name: 'さとう')), 'さ');
    expect(gojuonRow(c(name: 'ヤマダ')), 'や');
    expect(gojuonRow(c(name: 'Smith')), '英数');
    expect(gojuonRow(c(name: '若崎義和')), '未');
    expect(gojuonRow(c(name: '')), '未');
  });

  test('会社名の行は会社名のよみで判定する', () {
    final x = c(
      name: '山田',
      kana: 'やまだ',
      company: '若崎特許商標事務所',
      companyKana: 'わかざきとっきょ',
    );
    expect(gojuonRow(x), 'や');
    expect(gojuonRow(x, company: true), 'わ');
  });

  test('50音順に並べる（読みが分からないものは最後）', () {
    final list = [
      c(name: '渡辺', kana: 'わたなべ'),
      c(name: '若崎義和'),
      c(name: '安藤', kana: 'あんどう'),
      c(name: '佐藤', kana: 'さとう'),
    ]..sort(compareByReading);
    expect(list.map((e) => e.name).toList(), ['安藤', '佐藤', '渡辺', '若崎義和']);
  });

  test('名刺に印刷されたふりがなを、よみがなとして取り込む（行が分かれていても）', () {
    final r = parseCard(const [
      OcrLine('若崎特許商標事務所', 30, 10, 300),
      OcrLine('わか', 14, 120, 430),
      OcrLine('ざき', 14, 120, 520),
      OcrLine('若崎 義和', 60, 160, 400),
    ]);
    expect(r.name, '若崎 義和');
    expect(r.nameKana, 'わかざき');
    expect(r.memo, isEmpty);
  });
}
