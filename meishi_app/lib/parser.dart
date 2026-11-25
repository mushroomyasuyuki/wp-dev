import 'models.dart';

/// OCRで読み取った1行（文字と、その行の高さ）。
class OcrLine {
  final String text;
  final double height;
  const OcrLine(this.text, this.height);
}

/// 読み取った行を、設計書の順番のルールで項目に振り分ける。
BizCard parseCard(List<OcrLine> lines) {
  final card = BizCard(
    rawText: lines.map((l) => l.text).join('\n'),
  );
  final rest = <OcrLine>[];
  final addr = <String>[];
  var lastWasAddress = false;

  final reEmail = RegExp(r'[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)+');
  final reUrl = RegExp(r'(?:https?://|www\.)[^\s]+', caseSensitive: false);
  final rePhone = RegExp(r'(?<!\d)0\d{1,4}-\d{1,4}-\d{3,4}(?!\d)|(?<!\d)0\d{9,10}(?!\d)');
  final reAddr = RegExp(r'〒|(?<!\d)\d{3}-\d{4}(?!\d)|北海道|東京都|京都府|大阪府|[^\s]{2,3}県');
  final reBuild = RegExp(r'ビル|タワー|階|\d+F|センター|マンション|\d丁目|号室');
  final reCompany = RegExp(r'株式会社|有限会社|合同会社|一般社団法人|\(株\)|（株）|㈱|Inc\.?|Co\.|Ltd|Corp|LLC|GmbH', caseSensitive: false);
  final reDept = RegExp(r'(部|課|室|センター|事業部|グループ|チーム)$|Division|Dept', caseSensitive: false);
  final reTitle = RegExp(r'代表取締役|取締役|社長|部長|次長|課長|係長|主任|マネージャー|リーダー|所長|店長|CEO|CTO|CFO|Manager|Director|President|Engineer', caseSensitive: false);

  String norm(String s) => s
      .replaceAll(RegExp(r'[‐‑‒–—―−ーｰ]'), '-')
      .replaceAll('（', '(')
      .replaceAll('）', ')')
      .replaceAllMapped(RegExp(r'[０-９]'), (m) => String.fromCharCode(m[0]!.codeUnitAt(0) - 0xFEE0))
      .replaceAllMapped(RegExp(r'[Ａ-Ｚａ-ｚ]'), (m) => String.fromCharCode(m[0]!.codeUnitAt(0) - 0xFEE0))
      .replaceAll('＠', '@')
      .replaceAll('：', ':');

  void put(String field, String value) {
    switch (field) {
      case 'phone':
        if (card.phone.isEmpty) card.phone = value;
        break;
      case 'mobile':
        if (card.mobile.isEmpty) card.mobile = value;
        break;
      case 'fax':
        if (card.fax.isEmpty) card.fax = value;
        break;
    }
  }

  for (final l in lines) {
    final t = norm(l.text.trim());
    if (t.isEmpty) continue;

    // 1. メール
    final em = reEmail.firstMatch(t);
    if (em != null) {
      if (card.email.isEmpty) card.email = em[0]!;
      lastWasAddress = false;
      continue;
    }
    // 2. URL
    final um = reUrl.firstMatch(t);
    if (um != null) {
      if (card.url.isEmpty) card.url = um[0]!;
      lastWasAddress = false;
      continue;
    }
    // 3〜5. 携帯・FAX・電話（1行に複数ある場合は番号の直前のラベルで判定）
    final phones = rePhone.allMatches(t).toList();
    if (phones.isNotEmpty) {
      for (final m in phones) {
        final before = t.substring(0, m.start);
        final near = before.length > 8 ? before.substring(before.length - 8) : before;
        final number = m[0]!;
        final isMobileNo = RegExp(r'^0[789]0').hasMatch(number.replaceAll('-', ''));
        if (RegExp(r'fax|ファックス|ファクス|(^|[^A-Za-z])F\s*[:.]?\s*$', caseSensitive: false).hasMatch(near)) {
          put('fax', number);
        } else if (isMobileNo || RegExp(r'携帯|mobile|cell|M\s*[:.]?\s*$', caseSensitive: false).hasMatch(near)) {
          put('mobile', number);
        } else {
          put('phone', number);
        }
      }
      lastWasAddress = false;
      continue;
    }
    // 6. 住所（直後の建物名の行もつなげる）
    if (reAddr.hasMatch(t)) {
      addr.add(t);
      lastWasAddress = true;
      continue;
    }
    if (lastWasAddress && reBuild.hasMatch(t) && !reCompany.hasMatch(t)) {
      addr.add(t);
      continue;
    }
    lastWasAddress = false;
    // 7. 会社名
    if (reCompany.hasMatch(t)) {
      if (card.company.isEmpty) {
        card.company = t;
      } else {
        rest.add(OcrLine(t, l.height));
      }
      continue;
    }
    // 9. 役職を先に判定（「部長」が部署判定に取られないように）
    if (reTitle.hasMatch(t) && t.length <= 20) {
      if (card.title.isEmpty) {
        card.title = t;
        continue;
      }
    }
    // 8. 部署
    if (reDept.hasMatch(t) && t.length <= 20) {
      if (card.department.isEmpty) {
        card.department = t;
        continue;
      }
    }
    rest.add(OcrLine(t, l.height));
  }
  card.address = addr.join(' ');

  // 10. 氏名：残りのうち2〜8文字で数字・記号を含まない、いちばん大きい行
  OcrLine? best;
  for (final l in rest) {
    final compact = l.text.replaceAll(RegExp(r'\s'), '');
    final ok = compact.length >= 2 &&
        compact.length <= 8 &&
        !RegExp(r'[0-9@:/.,()\-]').hasMatch(compact);
    if (ok && (best == null || l.height > best.height)) best = l;
  }
  if (best != null) {
    card.name = best.text;
    rest.remove(best);
  }
  // 判定できなかった行はメモ欄へ（情報が消えない）
  card.memo = rest.map((l) => l.text).join('\n');
  return card;
}
