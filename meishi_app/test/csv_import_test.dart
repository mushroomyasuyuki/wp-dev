import 'package:flutter_test/flutter_test.dart';
import 'package:meishi_app/csv_import.dart';

void main() {
  test('引用符・カンマ・改行を含むCSVを読める', () {
    final rows = parseCsv(
      '氏名,会社名,メモ\r\n"山田, 太郎",株式会社A,"1行目\n2行目 ""引用"""\r\n鈴木,B社,\r\n',
    );
    expect(rows.length, 3);
    expect(rows[1], ['山田, 太郎', '株式会社A', '1行目\n2行目 "引用"']);
    expect(rows[2], ['鈴木', 'B社', '']);
  });

  test('書き出したCSVと同じ形式を名刺にできる（登録日時も戻る）', () {
    final r = cardsFromRows(
      parseCsv(
        '"氏名","よみがな","会社名","部署","役職","電話","携帯","FAX","メール","住所","Webサイト","メモ","登録日時"\r\n'
        '"若崎 義和","","若崎特許商標事務所","","弁理士","0285-36-1366","","0285-44-4054","wakapat@kuf.biglobe.ne.jp","〒329-0434 栃木県下野市","https://www.waka-ip.com","","2026-11-25T14:30:05.000"\r\n',
      ),
    );
    expect(r.hadHeader, isTrue);
    expect(r.cards.length, 1);
    final c = r.cards.first;
    expect(c.name, '若崎 義和');
    expect(c.company, '若崎特許商標事務所');
    expect(c.phone, '0285-36-1366');
    expect(c.fax, '0285-44-4054');
    expect(c.email, 'wakapat@kuf.biglobe.ne.jp');
    expect(c.createdAt, DateTime(2026, 11, 25, 14, 30, 5));
  });

  test('項目名が英語・姓と名が別の列でも読める', () {
    final r = cardsFromRows(
      parseCsv(
        'Last Name,First Name,Company,E-mail,Mobile\n山田,太郎,A社,t@x.jp,090-1111-2222\n',
      ),
    );
    expect(r.cards.single.name, '山田 太郎');
    expect(r.cards.single.company, 'A社');
    expect(r.cards.single.email, 't@x.jp');
    expect(r.cards.single.mobile, '090-1111-2222');
  });

  test('項目名のないCSVは書き出しCSVの順番とみなす。空の行は飛ばす', () {
    final r = cardsFromRows(
      parseCsv('山田太郎,やまだ,A社,営業部,部長,03-1111-2222\n\n,,,,,\n'),
    );
    expect(r.hadHeader, isFalse);
    expect(r.cards.length, 1);
    expect(r.cards.single.department, '営業部');
    expect(r.cards.single.title, '部長');
  });

  test('日時のないものは、ファイルの順に上から並ぶ', () {
    final r = cardsFromRows(
      parseCsv('氏名\nA\nB\nC\n'),
      now: DateTime(2026, 1, 1),
    );
    expect(r.cards.map((c) => c.name).toList(), ['A', 'B', 'C']);
    expect(r.cards[0].createdAt.isAfter(r.cards[1].createdAt), isTrue);
  });
}
