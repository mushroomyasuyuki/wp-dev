import 'package:flutter_test/flutter_test.dart';
import 'package:meishi_app/contacts.dart';

void main() {
  test('氏名を姓と名に分ける', () {
    expect(splitName('若崎 義和'), (family: '若崎', given: '義和'));
    expect(splitName('若崎　義和'), (family: '若崎', given: '義和'));
    expect(splitName('若崎義和'), (family: '若崎', given: '義和')); // 4文字は2+2
    expect(splitName('山田太郎'), (family: '山田', given: '太郎'));
    expect(splitName('佐藤花'), (family: '佐藤', given: '花')); // 3文字は2+1
    expect(splitName('Taro Yamada'), (family: 'Yamada', given: 'Taro'));
    expect(splitName('鈴木'), (family: '鈴', given: '木'));
  });
}
