import 'package:flutter_test/flutter_test.dart';
import 'package:meishi_app/duplicates.dart';
import 'package:meishi_app/models.dart';

BizCard c(
  int id, {
  String name = '',
  String company = '',
  String phone = '',
  String email = '',
  int day = 1,
}) => BizCard(
  id: id,
  name: name,
  company: company,
  phone: phone,
  email: email,
  createdAt: DateTime(2026, 11, day),
);

void main() {
  test('メール・電話・氏名+会社名が同じなら重複、同姓同名だけでは重複にしない', () {
    final all = [
      c(1, name: '山田 太郎', company: '株式会社A', day: 1),
      c(2, name: '山田太郎', company: '株式会社A', day: 2), // 氏名+会社名が同じ（空白違い）
      c(3, name: '鈴木', phone: '03-1234-5678', day: 3),
      c(4, name: '鈴木 一郎', phone: '0312345678', day: 4), // 電話が同じ
      c(5, name: '佐藤', email: 'Sato@Example.com', day: 5),
      c(6, name: '佐藤 花', email: 'sato@example.com', day: 6), // メールが同じ
      c(7, name: '山田 太郎', company: '株式会社B', day: 7), // 同姓同名だけ（会社が違う）
    ];
    final g = findDuplicates(all);
    expect(g.length, 3);
    expect(
      g.map((x) => x.cards.map((e) => e.id).toList()).toList(),
      containsAll([
        [1, 2],
        [3, 4],
        [5, 6],
      ]),
    );
    expect(g.expand((x) => x.cards).any((e) => e.id == 7), isFalse);
  });

  test('新しい名刺と重複する登録済みを探せる', () {
    final existing = [c(1, name: '山田', company: 'A', email: 'a@x.jp')];
    expect(duplicatesOf(c(99, email: 'A@x.jp'), existing).length, 1);
    expect(duplicatesOf(c(99, name: '別人', email: 'b@x.jp'), existing), isEmpty);
  });
}
