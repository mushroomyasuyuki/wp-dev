import 'package:material_ui/material_ui.dart';

import '../main.dart';
import '../plan.dart';

class PlanScreen extends StatelessWidget {
  const PlanScreen({super.key, this.reason});
  final String? reason;

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('プラン')),
    body: ListenableBuilder(
      listenable: plan,
      builder: (_, _) => ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (reason != null)
            Card(
              color: Theme.of(context).colorScheme.secondaryContainer,
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Text(reason!),
              ),
            ),
          const SizedBox(height: 8),
          Text(
            plan.isPro ? '現在：有料プラン' : '現在：無料プラン',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 12),
          Table(
            border: TableBorder.all(color: Colors.black12),
            children: [
              _row('', '無料', '有料', head: true),
              _row('登録枚数', '$freeLimit枚まで', '無制限'),
              _row('撮影・文字認識・検索', '○', '○'),
              _row('画像の自動保存', '○', '○'),
              _row('CSV書き出し', '—', '○'),
              _row('連絡先への登録', '—', '○'),
            ],
          ),
          const SizedBox(height: 16),
          if (!plan.isPro) ...[
            if (!plan.storeAvailable)
              const Text('ストアに接続できません。通信状況を確認してください。')
            else if (plan.products.isEmpty)
              const Text('購入できる商品が見つかりません。')
            else
              for (final p in plan.products)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: FilledButton(
                    onPressed: () => plan.buy(p),
                    child: Text(
                      '${p.title.split('(').first.trim()}  ${p.price}',
                    ),
                  ),
                ),
          ],
          OutlinedButton(onPressed: plan.restore, child: const Text('購入を復元')),
          if (plan.message != null)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Text(plan.message!),
            ),
          const SizedBox(height: 20),
          const Text(
            '・有料プランは自動更新のサブスクリプションです。解約しない限り、期間ごとに自動で更新され料金が請求されます。\n'
            '・解約は各ストアのアカウント設定（Google Play の「定期購入」／iPhone の「サブスクリプション」）から、いつでもできます。\n'
            '・契約が終了しても、登録済みの名刺はそのまま見られます。新しく登録できるのは$freeLimit枚までになります。',
            style: TextStyle(fontSize: 12, color: Colors.grey),
          ),
        ],
      ),
    ),
  );

  TableRow _row(String a, String b, String c, {bool head = false}) {
    Widget cell(String s, {bool left = false}) => Padding(
      padding: const EdgeInsets.all(10),
      child: Text(
        s,
        textAlign: left ? TextAlign.left : TextAlign.center,
        style: TextStyle(
          fontWeight: head ? FontWeight.bold : FontWeight.normal,
        ),
      ),
    );
    return TableRow(children: [cell(a, left: true), cell(b), cell(c)]);
  }
}
