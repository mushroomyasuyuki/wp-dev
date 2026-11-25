import 'package:flutter_contacts/flutter_contacts.dart';

import 'main.dart';
import 'models.dart';

/// 氏名を（姓, 名）に分ける。空白があればそこで分ける。
/// 日本語の名前で空白がないときは、文字数から推定する（姓2文字が最も多いため）。
/// 推定なので外れることがある。確認・修正画面で「姓 名」と空白を入れておくと確実。
({String family, String given}) splitName(String name) {
  final n = name.trim();
  final parts = n.split(RegExp(r'[\s　]+'));
  final hasCjk = RegExp(r'[぀-ヿ一-鿿]').hasMatch(n);
  if (parts.length >= 2) {
    // 日本語は「姓 名」、英字は「名 姓」の順
    return hasCjk
        ? (family: parts.first, given: parts.sublist(1).join(' '))
        : (
            family: parts.last,
            given: parts.sublist(0, parts.length - 1).join(' '),
          );
  }
  if (!hasCjk || n.length < 2) return (family: n, given: '');
  final k = switch (n.length) {
    2 => 1,
    3 => 2,
    4 => 2,
    5 => 2,
    _ => (n.length / 2).ceil(),
  };
  return (family: n.substring(0, k), given: n.substring(k));
}

/// 名刺の内容を、電話帳の連絡先に変換する。
/// forIntent: 端末の連絡先アプリの登録画面を開く方式。名前を「姓 名」の1つの文字列で渡す
/// （画面側が姓と名に分ける）。まとめて登録する方式は、姓と名を別々に渡す。
Contact bizToContact(BizCard c, {bool forIntent = false}) {
  final nm = splitName(c.name);
  return Contact(
    name: forIntent
        ? Name(
            first: [nm.family, nm.given].where((e) => e.isNotEmpty).join(' '),
          )
        : Name(last: nm.family, first: nm.given.isEmpty ? null : nm.given),
    organizations: [
      if (c.company.isNotEmpty || c.title.isNotEmpty || c.department.isNotEmpty)
        Organization(
          name: c.company,
          jobTitle: c.title,
          departmentName: c.department,
        ),
    ],
    phones: [
      if (c.phone.isNotEmpty)
        Phone(number: c.phone, label: const Label(PhoneLabel.work)),
      if (c.mobile.isNotEmpty)
        Phone(number: c.mobile, label: const Label(PhoneLabel.mobile)),
      if (c.fax.isNotEmpty)
        Phone(number: c.fax, label: const Label(PhoneLabel.workFax)),
    ],
    emails: [
      if (c.email.isNotEmpty)
        Email(address: c.email, label: const Label(EmailLabel.work)),
    ],
    websites: [if (c.url.isNotEmpty) Website(url: c.url)],
    addresses: [
      if (c.address.isNotEmpty)
        Address(formatted: c.address, label: const Label(AddressLabel.work)),
    ],
  );
}

/// 電話帳へまとめて登録する。成功した件数を返す。許可がないときは例外。
/// 登録した名刺には「登録済み」の印を付け、二重登録を防ぐ。
Future<int> registerCardsToContacts(
  List<BizCard> cards, {
  void Function(int done, int total)? onProgress,
}) async {
  final st = await FlutterContacts.permissions.request(
    PermissionType.readWrite,
  );
  if (st != PermissionStatus.granted && st != PermissionStatus.limited) {
    throw StateError('電話帳の使用が許可されていません。端末の設定でこのアプリに連絡先の許可を与えてください。');
  }
  var done = 0;
  for (var i = 0; i < cards.length; i += 25) {
    final chunk = cards.sublist(
      i,
      i + 25 > cards.length ? cards.length : i + 25,
    );
    await FlutterContacts.createAll([for (final c in chunk) bizToContact(c)]);
    for (final c in chunk) {
      c.contactRegistered = true;
      await repo.update(c);
    }
    done += chunk.length;
    onProgress?.call(done, cards.length);
  }
  return done;
}
