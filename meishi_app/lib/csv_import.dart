import 'dart:convert';
import 'dart:typed_data';

import 'package:charset_converter/charset_converter.dart';

import 'kana.dart';
import 'models.dart';

/// 文字コードを判定して文字列にする。UTF-8（BOMつきも可）を先に試し、だめならShift_JIS（Excelの日本語CSV）。
Future<String> decodeCsvBytes(Uint8List bytes) async {
  var b = bytes;
  if (b.length >= 3 && b[0] == 0xEF && b[1] == 0xBB && b[2] == 0xBF)
    b = b.sublist(3);
  try {
    return utf8.decode(b);
  } on FormatException {
    return CharsetConverter.decode('Shift_JIS', b);
  }
}

/// CSVを行と列に分ける。引用符("")、引用符の中のカンマ・改行、CRLF/LF に対応。区切りはカンマかタブ。
List<List<String>> parseCsv(String text) {
  final firstLine = text
      .split(RegExp(r'\r?\n'))
      .firstWhere((l) => l.trim().isNotEmpty, orElse: () => '');
  final sep = (firstLine.split('\t').length > firstLine.split(',').length)
      ? '\t'
      : ',';
  final rows = <List<String>>[];
  var row = <String>[];
  final cell = StringBuffer();
  var inQuotes = false;
  var started = false; // 現在の行に何か文字（区切りを含む）があるか
  void endCell() {
    row.add(cell.toString());
    cell.clear();
  }

  void endRow() {
    endCell();
    if (started || row.any((c) => c.isNotEmpty)) rows.add(row);
    row = <String>[];
    started = false;
  }

  for (var i = 0; i < text.length; i++) {
    final ch = text[i];
    if (inQuotes) {
      if (ch == '"') {
        if (i + 1 < text.length && text[i + 1] == '"') {
          cell.write('"');
          i++;
        } else {
          inQuotes = false;
        }
      } else {
        cell.write(ch);
      }
    } else if (ch == '"') {
      inQuotes = true;
      started = true;
    } else if (ch == sep) {
      endCell();
      started = true;
    } else if (ch == '\n') {
      endRow();
    } else if (ch == '\r') {
      if (i + 1 < text.length && text[i + 1] == '\n') i++;
      endRow();
    } else {
      cell.write(ch);
      started = true;
    }
  }
  if (started || cell.isNotEmpty || row.isNotEmpty) endRow();
  return rows;
}

const _synonyms = <String, List<String>>{
  'name': ['氏名', '名前', 'お名前', 'name', 'fullname'],
  'companyKana': [
    '会社名よみがな',
    '会社名ふりがな',
    '会社よみがな',
    '会社ふりがな',
    '会社名フリガナ',
    'companykana',
  ],
  'kana': ['よみがな', 'ふりがな', 'フリガナ', 'ヨミガナ', '読み', '読みがな', 'kana', 'furigana'],
  'company': [
    '会社名',
    '会社',
    '社名',
    '企業名',
    '組織',
    '組織名',
    '勤務先',
    'company',
    'organization',
    'organisation',
  ],
  'department': ['部署', '部署名', '部門', '所属', 'department', 'dept'],
  'title': ['役職', '肩書', '肩書き', 'title', 'jobtitle', 'position'],
  'phone': ['電話', '電話番号', 'tel', 'phone', 'telephone'],
  'mobile': ['携帯', '携帯電話', '携帯番号', 'mobile', 'cell', 'cellphone'],
  'fax': ['fax', 'ファックス', 'ファクス'],
  'email': ['メール', 'メールアドレス', 'email', 'e-mail', 'mail'],
  'address': ['住所', '所在地', 'address'],
  'url': ['url', 'webサイト', 'ウェブサイト', 'ホームページ', 'hp', 'website', 'web'],
  'memo': ['メモ', '備考', 'memo', 'note', 'notes'],
  'created': ['登録日時', '登録日', '作成日', '作成日時', '日時', 'created', 'createdat'],
  'last': ['姓', '苗字', '名字', 'lastname', 'familyname'],
  'first': ['名', 'firstname', 'givenname'],
};

/// 書き出したCSVと同じ並び（1行目の項目名がないCSVを読むときの順番）。
const _defaultOrder = [
  'name',
  'kana',
  'company',
  'department',
  'title',
  'phone',
  'mobile',
  'fax',
  'email',
  'address',
  'url',
  'memo',
  'created',
  'companyKana',
];

String _norm(String s) => s.replaceAll(RegExp(r'[\s　"()（）]'), '').toLowerCase();

String? _fieldOf(String header) {
  final h = _norm(header);
  for (final e in _synonyms.entries) {
    if (e.value.map(_norm).contains(h)) return e.key;
  }
  return null;
}

class CsvResult {
  final List<BizCard> cards;
  final bool hadHeader;
  const CsvResult(this.cards, this.hadHeader);
}

/// 行と列から名刺を作る。1行目に項目名があればそれで列を判断し、なければ書き出しCSVの順番とみなす。
CsvResult cardsFromRows(List<List<String>> rows, {DateTime? now}) {
  if (rows.isEmpty) return const CsvResult([], false);
  final base = now ?? DateTime.now();
  var cols = rows.first.map(_fieldOf).toList();
  final hasHeader = cols.any((c) => c != null);
  var data = rows;
  if (hasHeader) {
    data = rows.skip(1).toList();
  } else {
    cols = List<String?>.generate(
      rows.first.length,
      (i) => i < _defaultOrder.length ? _defaultOrder[i] : null,
    );
  }
  final cards = <BizCard>[];
  for (final r in data) {
    final v = <String, String>{};
    for (var i = 0; i < cols.length && i < r.length; i++) {
      final f = cols[i];
      if (f != null && r[i].trim().isNotEmpty && !v.containsKey(f))
        v[f] = r[i].trim();
    }
    var name = v['name'] ?? '';
    if (name.isEmpty && (v['last'] != null || v['first'] != null)) {
      name = [
        v['last'],
        v['first'],
      ].where((e) => e != null && e.isNotEmpty).join(' ');
    }
    // 中身がほとんどない行は取り込まない
    if (name.isEmpty &&
        (v['company'] ?? '').isEmpty &&
        (v['phone'] ?? '').isEmpty &&
        (v['mobile'] ?? '').isEmpty &&
        (v['email'] ?? '').isEmpty)
      continue;
    final created = v['created'] == null
        ? null
        : DateTime.tryParse(v['created']!.replaceAll('/', '-'));
    // 日時のないものは、ファイルの並び順どおり一覧の上から並ぶようにする
    final at = created ?? base.subtract(Duration(milliseconds: cards.length));
    cards.add(
      BizCard(
        name: name,
        nameKana: toHiragana(v['kana'] ?? ''),
        companyKana: toHiragana(v['companyKana'] ?? ''),
        company: v['company'] ?? '',
        department: v['department'] ?? '',
        title: v['title'] ?? '',
        phone: v['phone'] ?? '',
        mobile: v['mobile'] ?? '',
        fax: v['fax'] ?? '',
        email: v['email'] ?? '',
        address: v['address'] ?? '',
        url: v['url'] ?? '',
        memo: v['memo'] ?? '',
        createdAt: at,
        updatedAt: at,
      ),
    );
  }
  return CsvResult(cards, hasHeader);
}
