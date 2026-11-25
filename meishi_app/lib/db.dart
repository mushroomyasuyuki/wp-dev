import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart';

import 'models.dart';

/// 名刺テーブル（cards）1つだけのデータベース。
class CardRepo {
  CardRepo._(this._db);
  final Database _db;

  static Future<CardRepo> open() async {
    final path = p.join(await getDatabasesPath(), 'meishi.db');
    final db = await openDatabase(
      path,
      version: 1,
      onCreate: (db, _) async {
        await db.execute('''
        CREATE TABLE cards(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          name TEXT, name_kana TEXT, company TEXT, department TEXT, title TEXT,
          phone TEXT, mobile TEXT, fax TEXT, email TEXT, address TEXT, url TEXT,
          memo TEXT, raw_text TEXT, image_uri TEXT, thumb_path TEXT,
          image_pending INTEGER, created_at INTEGER, updated_at INTEGER
        )''');
      },
    );
    return CardRepo._(db);
  }

  Future<int> count() async =>
      Sqflite.firstIntValue(await _db.rawQuery('SELECT COUNT(*) FROM cards')) ??
      0;

  /// 氏名・よみ・会社名・メモ・読み取った全文の部分一致で検索。
  /// from以上・to未満の登録日時で絞り込める。
  Future<List<BizCard>> search(String q, {DateTime? from, DateTime? to}) async {
    q = q.trim();
    final where = <String>[];
    final args = <Object?>[];
    if (q.isNotEmpty) {
      where.add(
        '(name LIKE ?1 OR name_kana LIKE ?1 OR company LIKE ?1 OR memo LIKE ?1 OR raw_text LIKE ?1)',
      );
      args.add('%$q%');
    }
    if (from != null) {
      where.add('created_at >= ?${args.length + 1}');
      args.add(from.millisecondsSinceEpoch);
    }
    if (to != null) {
      where.add('created_at < ?${args.length + 1}');
      args.add(to.millisecondsSinceEpoch);
    }
    final rows = await _db.query(
      'cards',
      where: where.isEmpty ? null : where.join(' AND '),
      whereArgs: args.isEmpty ? null : args,
      orderBy: 'created_at DESC',
    );
    return rows.map(BizCard.fromMap).toList();
  }

  Future<BizCard?> get(int id) async {
    final r = await _db.query('cards', where: 'id=?', whereArgs: [id]);
    return r.isEmpty ? null : BizCard.fromMap(r.first);
  }

  Future<int> insert(BizCard c) => _db.insert('cards', c.toMap());

  Future<void> update(BizCard c) async {
    c.updatedAt = DateTime.now();
    await _db.update('cards', c.toMap(), where: 'id=?', whereArgs: [c.id]);
  }

  Future<void> delete(int id) =>
      _db.delete('cards', where: 'id=?', whereArgs: [id]);

  Future<List<BizCard>> pendingImages() async => (await _db.query(
    'cards',
    where: 'image_pending=1',
  )).map(BizCard.fromMap).toList();

  Future<List<BizCard>> all() async => (await _db.query(
    'cards',
    orderBy: 'created_at DESC',
  )).map(BizCard.fromMap).toList();
}
