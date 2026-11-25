import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:in_app_purchase/in_app_purchase.dart';
import 'package:shared_preferences/shared_preferences.dart';

const freeLimit = 30;
const productIds = {'meishi_pro_monthly', 'meishi_pro_yearly'};

/// 開発用: `--dart-define=FORCE_PRO=true` で有料版として動かせる。
const _forcePro = bool.fromEnvironment('FORCE_PRO');

/// サブスクの契約状態。サーバーは使わず、ストアに直接問い合わせる。
class PlanService extends ChangeNotifier {
  bool isPro = _forcePro;
  bool storeAvailable = false;
  List<ProductDetails> products = [];
  String? message;
  StreamSubscription<List<PurchaseDetails>>? _sub;
  static const _kPro = 'is_pro', _kChecked = 'pro_checked_at';

  Future<void> init() async {
    final prefs = await SharedPreferences.getInstance();
    if (!_forcePro) isPro = prefs.getBool(_kPro) ?? false;
    final iap = InAppPurchase.instance;
    storeAvailable = await iap.isAvailable();
    if (storeAvailable) {
      _sub = iap.purchaseStream.listen(_onPurchases, onError: (_) {});
      final res = await iap.queryProductDetails(productIds);
      products = res.productDetails.toList()
        ..sort((a, b) => a.rawPrice.compareTo(b.rawPrice));
      // 最後の確認から3日以上たっていたら、契約が続いているか再確認する
      final last = prefs.getInt(_kChecked) ?? 0;
      if (!_forcePro &&
          DateTime.now().millisecondsSinceEpoch - last >
              const Duration(days: 3).inMilliseconds) {
        unawaited(_recheck());
      }
    }
    notifyListeners();
  }

  bool _sawActive = false;

  Future<void> _recheck() async {
    _sawActive = false;
    await InAppPurchase.instance.restorePurchases();
    await Future.delayed(const Duration(seconds: 6));
    if (!_sawActive) await _setPro(false);
    (await SharedPreferences.getInstance()).setInt(
      _kChecked,
      DateTime.now().millisecondsSinceEpoch,
    );
  }

  Future<void> _setPro(bool v) async {
    if (_forcePro) return;
    isPro = v;
    (await SharedPreferences.getInstance()).setBool(_kPro, v);
    notifyListeners();
  }

  Future<void> _onPurchases(List<PurchaseDetails> list) async {
    for (final p in list) {
      if (!productIds.contains(p.productID)) continue;
      switch (p.status) {
        case PurchaseStatus.purchased:
        case PurchaseStatus.restored:
          _sawActive = true;
          await _setPro(true);
          message = '有料プランが有効になりました';
          break;
        case PurchaseStatus.error:
          message = '購入できませんでした';
          break;
        case PurchaseStatus.canceled:
          message = '購入を取り消しました';
          break;
        case PurchaseStatus.pending:
          message = '購入を処理しています…';
          break;
      }
      if (p.pendingCompletePurchase)
        await InAppPurchase.instance.completePurchase(p);
      notifyListeners();
    }
  }

  Future<void> buy(ProductDetails d) => InAppPurchase.instance.buyNonConsumable(
    purchaseParam: PurchaseParam(productDetails: d),
  );

  Future<void> restore() async {
    message = null;
    await InAppPurchase.instance.restorePurchases();
  }

  @override
  void dispose() {
    _sub?.cancel();
    super.dispose();
  }
}
