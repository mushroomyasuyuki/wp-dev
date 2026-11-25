/// 名刺1枚分のデータ。DBの1行に対応する。
class BizCard {
  int? id;
  String name, nameKana, company, department, title;
  String phone, mobile, fax, email, address, url, memo, rawText;
  String imageUri; // 外部ストレージ上の画像の場所
  String thumbPath; // 表示用の小さな画像（アプリ内）
  bool imagePending; // 外部保存がまだ済んでいない
  DateTime createdAt, updatedAt;

  BizCard({
    this.id,
    this.name = '',
    this.nameKana = '',
    this.company = '',
    this.department = '',
    this.title = '',
    this.phone = '',
    this.mobile = '',
    this.fax = '',
    this.email = '',
    this.address = '',
    this.url = '',
    this.memo = '',
    this.rawText = '',
    this.imageUri = '',
    this.thumbPath = '',
    this.imagePending = false,
    DateTime? createdAt,
    DateTime? updatedAt,
  })  : createdAt = createdAt ?? DateTime.now(),
        updatedAt = updatedAt ?? DateTime.now();

  Map<String, Object?> toMap() => {
        if (id != null) 'id': id,
        'name': name,
        'name_kana': nameKana,
        'company': company,
        'department': department,
        'title': title,
        'phone': phone,
        'mobile': mobile,
        'fax': fax,
        'email': email,
        'address': address,
        'url': url,
        'memo': memo,
        'raw_text': rawText,
        'image_uri': imageUri,
        'thumb_path': thumbPath,
        'image_pending': imagePending ? 1 : 0,
        'created_at': createdAt.millisecondsSinceEpoch,
        'updated_at': updatedAt.millisecondsSinceEpoch,
      };

  factory BizCard.fromMap(Map<String, Object?> m) => BizCard(
        id: m['id'] as int?,
        name: m['name'] as String? ?? '',
        nameKana: m['name_kana'] as String? ?? '',
        company: m['company'] as String? ?? '',
        department: m['department'] as String? ?? '',
        title: m['title'] as String? ?? '',
        phone: m['phone'] as String? ?? '',
        mobile: m['mobile'] as String? ?? '',
        fax: m['fax'] as String? ?? '',
        email: m['email'] as String? ?? '',
        address: m['address'] as String? ?? '',
        url: m['url'] as String? ?? '',
        memo: m['memo'] as String? ?? '',
        rawText: m['raw_text'] as String? ?? '',
        imageUri: m['image_uri'] as String? ?? '',
        thumbPath: m['thumb_path'] as String? ?? '',
        imagePending: (m['image_pending'] as int? ?? 0) == 1,
        createdAt: DateTime.fromMillisecondsSinceEpoch(m['created_at'] as int),
        updatedAt: DateTime.fromMillisecondsSinceEpoch(m['updated_at'] as int),
      );
}
