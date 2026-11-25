import 'package:material_ui/material_ui.dart';
import 'db.dart';
import 'plan.dart';
import 'screens/list_screen.dart';
import 'storage.dart';

late CardRepo repo;
final plan = PlanService();

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  repo = await CardRepo.open();
  runApp(const MeishiApp());
  // 起動後に、未移動の画像の移動と契約状態の確認を行う
  ExternalStore.flushPending(repo);
  plan.init();
}

class MeishiApp extends StatelessWidget {
  const MeishiApp({super.key});

  @override
  Widget build(BuildContext context) => MaterialApp(
        title: '名刺',
        debugShowCheckedModeBanner: false,
        theme: ThemeData(colorSchemeSeed: const Color(0xFF1565C0), useMaterial3: true),
        home: const ListScreen(),
      );
}
