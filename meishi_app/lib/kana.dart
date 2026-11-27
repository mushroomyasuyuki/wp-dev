import 'models.dart';

/// カタカナをひらがなにそろえる（検索・並べ替えで「ヤマダ」と「やまだ」を同じに扱うため）。
String toHiragana(String s) => String.fromCharCodes(
  s.runes.map((r) => (r >= 0x30A1 && r <= 0x30F6) ? r - 0x60 : r),
);

/// 50音の行。ラベルと、その行に入る文字（濁音・半濁音・小さい字も含む）。
const gojuonRows = <(String, String)>[
  ('あ', 'ぁあぃいぅうぇえぉおゔ'),
  ('か', 'かがきぎくぐけげこご'),
  ('さ', 'さざしじすずせぜそぞ'),
  ('た', 'ただちぢっつづてでとど'),
  ('な', 'なにぬねの'),
  ('は', 'はばぱひびぴふぶぷへべぺほぼぽ'),
  ('ま', 'まみむめも'),
  ('や', 'ゃやゅゆょよ'),
  ('ら', 'らりるれろ'),
  ('わ', 'わゎゐゑをん'),
];

const rowAlnum = '英数';
const rowUnknown = '未';

/// 並べ替え・行の判定に使う「読み」（ひらがな）。空なら読みが分からない。
/// 手入力のよみがなを優先し、なければ、先頭が仮名の名前・会社名はその文字列を読みとする。
String readingOf(BizCard c, {bool company = false}) {
  final kana = toHiragana(company ? c.companyKana : c.nameKana)
      .replaceAll(RegExp(r'[\s　]+'), '');
  if (kana.isNotEmpty) return kana;
  final text = (company ? c.company : c.name).replaceAll(RegExp(r'[\s　]+'), '');
  final m = RegExp(r'^[ぁ-ゖァ-ヶ]+').firstMatch(text);
  return m == null ? '' : toHiragana(m[0]!);
}

/// 50音の行（あ・か・さ…）。読みが分からない漢字は「未」、先頭が英数字なら「英数」。
String gojuonRow(BizCard c, {bool company = false}) {
  final r = readingOf(c, company: company);
  if (r.isNotEmpty) {
    for (final (label, chars) in gojuonRows) {
      if (chars.contains(r[0])) return label;
    }
  }
  final t = (company ? c.company : c.name).trim();
  if (t.isNotEmpty && RegExp(r'^[A-Za-z0-9Ａ-Ｚａ-ｚ０-９]').hasMatch(t))
    return rowAlnum;
  return rowUnknown;
}

/// 50音順（読みが分かるものが先、分からないものが後ろ）。
int compareByReading(BizCard a, BizCard b, {bool company = false}) {
  final ra = readingOf(a, company: company),
      rb = readingOf(b, company: company);
  if (ra.isEmpty != rb.isEmpty) return ra.isEmpty ? 1 : -1;
  final c = ra.compareTo(rb);
  return c != 0
      ? c
      : (company ? a.company : a.name).compareTo(company ? b.company : b.name);
}
