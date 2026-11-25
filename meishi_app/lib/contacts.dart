import 'package:flutter_contacts/flutter_contacts.dart';

import 'main.dart';
import 'models.dart';

/// 名刺の内容を、電話帳の連絡先に変換する。
Contact bizToContact(BizCard c) {
  final parts = c.name.split(RegExp(r'[\s　]+'));
  return Contact(
    name: Name(
      last: parts.first,
      first: parts.length > 1 ? parts.sublist(1).join(' ') : null,
    ),
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
    addresses: [if (c.address.isNotEmpty) Address(formatted: c.address)],
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
