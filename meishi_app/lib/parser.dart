import 'models.dart';

/// OCRで読み取った1行（文字と、その行の高さ）。
class OcrLine {
  final String text;
  final double height;
  final double top; // 画像の上からの位置（会社名の推定に使う）
  final double left; // 画像の左からの位置（ふりがなの並びに使う）
  const OcrLine(this.text, this.height, [this.top = 0, this.left = 0]);
}

/// 読み取った行を、設計書の順番のルールで項目に振り分ける。
BizCard parseCard(List<OcrLine> lines) {
  final card = BizCard(rawText: lines.map((l) => l.text).join('\n'));
  final rest = <OcrLine>[];
  final addr = <String>[];
  var lastWasAddress = false;

  final reEmail = RegExp(
    r'[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)+',
  );
  final reUrl = RegExp(r'(?:https?://|www\.)[^\s]+', caseSensitive: false);
  final rePhone = RegExp(
    r'(?<!\d)0\d{1,4}-\d{1,4}-\d{3,4}(?!\d)|(?<!\d)0\d{9,10}(?!\d)',
  );
  final reAddr = RegExp(
    r'〒|(?<!\d)\d{3}-\d{4}(?!\d)|北海道|東京都|京都府|大阪府|[^\s]{2,3}県',
  );
  final reBuild = RegExp(r'ビル|タワー|階|\d+F|センター|マンション|\d丁目|号室');
  final reCompany = RegExp(
    r'株式会社|有限会社|合同会社|合資会社|一般社団法人|一般財団法人|公益社団法人|公益財団法人|社会福祉法人|医療法人|学校法人|特定非営利活動法人|NPO法人|\(株\)|（株）|㈱|㈲|事務所|法律事務所|組合|協会|銀行|信用金庫|病院|医院|クリニック|商店|製作所|研究所|大学|Inc\.?|Co\.|Ltd|Corp|LLC|GmbH',
    caseSensitive: false,
  );
  final reDept = RegExp(
    r'(部|課|室|センター|事業部|グループ|チーム)$|Division|Dept',
    caseSensitive: false,
  );
  final reTitle = RegExp(
    r'弁護士|弁理士|税理士|司法書士|行政書士|社会保険労務士|公認会計士|土地家屋調査士|医師|歯科医師|教授|代表|所長|理事長|院長|取締役|社長|部長|次長|課長|係長|主任|マネージャー|リーダー|所長|店長|CEO|CTO|CFO|Manager|Director|President|Engineer',
    caseSensitive: false,
  );

  String norm(String s) => s
      .replaceAll(RegExp(r'[‐‑‒–—―−ーｰ]'), '-')
      .replaceAll('（', '(')
      .replaceAll('）', ')')
      .replaceAllMapped(
        RegExp(r'[０-９]'),
        (m) => String.fromCharCode(m[0]!.codeUnitAt(0) - 0xFEE0),
      )
      .replaceAllMapped(
        RegExp(r'[Ａ-Ｚａ-ｚ]'),
        (m) => String.fromCharCode(m[0]!.codeUnitAt(0) - 0xFEE0),
      )
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
    // メールの「@」が読み取れなかった場合でも、「E-mail」と書かれた行は、書かれた内容をそのまま入れる
    final mailLabel = RegExp(
      r'e-?mail|ｅ-?ｍａｉｌ|メール',
      caseSensitive: false,
    ).firstMatch(t);
    if (mailLabel != null) {
      final after = t
          .substring(mailLabel.end)
          .replaceAll(RegExp(r'[\s:]+'), '');
      if (after.contains('.') && after.length >= 6) {
        if (card.email.isEmpty) card.email = after;
        lastWasAddress = false;
        continue;
      }
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
        final near = before.length > 8
            ? before.substring(before.length - 8)
            : before;
        final number = m[0]!;
        final isMobileNo = RegExp(r'^0[789]0')
            .hasMatch(number.replaceAll('-', ''));
        if (RegExp(
          r'fax|ファックス|ファクス|(^|[^A-Za-z])F\s*[:.]?\s*$',
          caseSensitive: false,
        ).hasMatch(near)) {
          put('fax', number);
        } else if (isMobileNo ||
            RegExp(
              r'携帯|mobile|cell|M\s*[:.]?\s*$',
              caseSensitive: false,
            ).hasMatch(near)) {
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
    final tc = t.replaceAll(RegExp(r'[\s　]+'), ''); // 「弁 理 士」のような字間の空白を無視して判定する
    // 7. 会社名
    if (reCompany.hasMatch(tc)) {
      if (card.company.isEmpty) {
        card.company = t;
      } else {
        rest.add(OcrLine(t, l.height, l.top, l.left));
      }
      continue;
    }
    // 9. 役職を先に判定（「部長」が部署判定に取られないように）
    if (reTitle.hasMatch(tc) && tc.length <= 20) {
      if (card.title.isEmpty) {
        card.title = tc.length <= 6 ? tc : t;
        continue;
      }
    }
    // 8. 部署
    if (reDept.hasMatch(tc) && tc.length <= 20) {
      if (card.department.isEmpty) {
        card.department = t;
        continue;
      }
    }
    rest.add(OcrLine(t, l.height, l.top, l.left));
  }
  card.address = addr.join(' ');
  // 読み取りの誤りを直す: 郵便マーク「〒」が「T」「丁」と読まれる、メールの「.jp」などの点が抜ける、
  // 会社名の先頭にロゴが「の」と読まれて付く
  card.address = card.address.replaceAllMapped(
    RegExp(r'(?:^|(?<=\s))[TＴ丁]\s*(\d{3}-\d{4})'),
    (m) => '〒${m[1]}',
  );
  card.email = card.email.replaceAllMapped(
    RegExp(r'\.(ne|co|or|ac|go|ed|ad|gr)(jp)$', caseSensitive: false),
    (m) => '.${m[1]}.${m[2]}',
  );
  card.company = card.company.replaceFirstMapped(
    RegExp(r'^[のノ©®◎○●◇◆□■△▽☆★]\s*(?=[一-鿿]{3})'),
    (_) => '',
  );

  // 10. 氏名：残りのうち2〜8文字で数字・記号を含まない、いちばん大きい行
  OcrLine? best;
  for (final l in rest) {
    final compact = l.text.replaceAll(RegExp(r'\s'), '');
    final ok =
        compact.length >= 2 &&
        compact.length <= 8 &&
        !RegExp(r'[0-9@:/.,()\-]').hasMatch(compact);
    if (ok && (best == null || l.height > best.height)) best = l;
  }
  if (best != null) {
    card.name = best.text;
    rest.remove(best);
  }
  // 名刺に印刷されたふりがな（仮名だけで、氏名より小さい文字の行）を、よみがなにする。
  // 「わか」「ざき」のように行が分かれていることがあるので、氏名の近くにあるものを左から順に合わせる。
  if (best != null) {
    final found = <OcrLine>[];
    for (final l in rest) {
      final c = l.text.replaceAll(RegExp(r'[\s　]+'), '');
      final near =
          l.top >= best.top - best.height * 1.8 &&
          l.top <= best.top + best.height * 0.5;
      if (c.length >= 2 &&
          c.length <= 14 &&
          RegExp(r'^[ぁ-ゖァ-ヶー]+$').hasMatch(c) &&
          l.height < best.height &&
          near) {
        found.add(l);
      }
    }
    if (found.isNotEmpty) {
      found.sort((a, b) => a.left.compareTo(b.left));
      card.nameKana = found
          .map((l) => l.text.replaceAll(RegExp(r'[\s　]+'), ''))
          .join()
          .replaceAllMapped(
            RegExp(r'[ァ-ヶ]'),
            (m) => String.fromCharCode(m[0]!.codeUnitAt(0) - 0x60),
          );
      rest.removeWhere(found.contains);
    }
  }
  // 会社名の記号が見つからなかったときは、残りの行のうち最も上にある3文字以上の行を会社名の候補にする
  if (card.company.isEmpty) {
    OcrLine? top;
    for (final l in rest) {
      final c = l.text.replaceAll(RegExp(r'\s'), '');
      if (c.length >= 3 &&
          RegExp(r'[぀-ヿ一-鿿]').hasMatch(c) &&
          !RegExp(r'[0-9@]').hasMatch(c) &&
          (top == null || l.top < top.top))
        top = l;
    }
    if (top != null) {
      card.company = top.text;
      rest.remove(top);
    }
  }
  // 判定できなかった行はメモ欄へ（情報が消えない）
  card.memo = rest.map((l) => l.text).join('\n');
  return card;
}
