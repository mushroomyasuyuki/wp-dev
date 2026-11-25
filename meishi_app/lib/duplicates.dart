import 'models.dart';

/// 重複とみなす手がかり。メール、電話・携帯、氏名と会社名の組み合わせのどれかが一致したら重複。
/// 氏名だけの一致（同姓同名）では重複にしない。
Set<String> duplicateKeys(BizCard c) {
  String squash(String s) => s.replaceAll(RegExp(r'[\s　]+'), '').toLowerCase();
  final keys = <String>{};
  final email = squash(c.email);
  if (email.isNotEmpty) keys.add('e:$email');
  for (final n in [c.phone, c.mobile]) {
    final d = n.replaceAll(RegExp(r'\D'), '');
    if (d.length >= 9) keys.add('p:$d');
  }
  final name = squash(c.name), company = squash(c.company);
  if (name.isNotEmpty && company.isNotEmpty) keys.add('n:$name|$company');
  return keys;
}

String _reasonLabel(String key) => switch (key[0]) {
  'e' => 'メールが同じ',
  'p' => '電話番号が同じ',
  _ => '氏名と会社名が同じ',
};

class DupGroup {
  final List<BizCard> cards; // 登録が古い順
  final Set<String> reasons;
  DupGroup(this.cards, this.reasons);
}

/// 重複している名刺をグループにまとめる。
List<DupGroup> findDuplicates(List<BizCard> all) {
  final parent = List<int>.generate(all.length, (i) => i);
  int find(int i) => parent[i] == i ? i : parent[i] = find(parent[i]);
  final byKey = <String, int>{};
  final reasons = <int, Set<String>>{};
  final keys = [for (final c in all) duplicateKeys(c)];
  for (var i = 0; i < all.length; i++) {
    for (final k in keys[i]) {
      final j = byKey[k];
      if (j == null) {
        byKey[k] = i;
      } else {
        parent[find(i)] = find(j);
      }
    }
  }
  final groups = <int, List<int>>{};
  for (var i = 0; i < all.length; i++) {
    groups.putIfAbsent(find(i), () => []).add(i);
  }
  final out = <DupGroup>[];
  for (final members in groups.values) {
    if (members.length < 2) continue;
    // グループ内で2枚以上が共有している手がかりを理由にする
    final count = <String, int>{};
    for (final i in members) {
      for (final k in keys[i]) {
        count[k] = (count[k] ?? 0) + 1;
      }
    }
    final why = {
      for (final e in count.entries)
        if (e.value >= 2) _reasonLabel(e.key),
    };
    final cards = [for (final i in members) all[i]]
      ..sort((a, b) => a.createdAt.compareTo(b.createdAt));
    out.add(DupGroup(cards, why));
    reasons[members.first] = why;
  }
  out.sort((a, b) => b.cards.last.createdAt.compareTo(a.cards.last.createdAt));
  return out;
}

/// 新しい名刺と重複している、登録済みの名刺。
List<BizCard> duplicatesOf(BizCard c, List<BizCard> existing) {
  final k = duplicateKeys(c);
  if (k.isEmpty) return const [];
  return [
    for (final e in existing)
      if (e.id != c.id && duplicateKeys(e).any(k.contains)) e,
  ];
}
