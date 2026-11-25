import 'package:camera/camera.dart';
import 'package:flutter/services.dart';
import 'package:material_ui/material_ui.dart';

/// 名刺サイズの枠つきカメラ。撮影した写真(XFile)を返す。
/// 標準カメラが使えない場合は null を返し、呼び出し側が標準カメラに切り替える。
class CameraScreen extends StatefulWidget {
  const CameraScreen({super.key});
  @override
  State<CameraScreen> createState() => _CameraScreenState();
}

class _CameraScreenState extends State<CameraScreen>
    with WidgetsBindingObserver {
  CameraController? _ctrl;
  String? _error;
  bool _vertical = false; // 縦向きの名刺
  bool _torch = false;
  bool _shooting = false;

  // 名刺 91mm × 55mm
  static const _ratio = 91 / 55;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _init();
  }

  Future<void> _init() async {
    try {
      final cams = await availableCameras();
      if (cams.isEmpty) throw CameraException('none', 'カメラが見つかりません');
      final back = cams.firstWhere(
        (c) => c.lensDirection == CameraLensDirection.back,
        orElse: () => cams.first,
      );
      final c = CameraController(
        back,
        ResolutionPreset.veryHigh,
        enableAudio: false,
      );
      await c.initialize();
      await c.lockCaptureOrientation(DeviceOrientation.portraitUp);
      if (!mounted) {
        await c.dispose();
        return;
      }
      setState(() => _ctrl = c);
    } on CameraException catch (e) {
      if (mounted)
        setState(
          () => _error = e.code == 'CameraAccessDenied'
              ? 'カメラの使用が許可されていません。'
              : 'カメラを開けませんでした（${e.code}）。',
        );
    } catch (e) {
      if (mounted) setState(() => _error = 'カメラを開けませんでした。');
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final c = _ctrl;
    if (c == null || !c.value.isInitialized) return;
    if (state == AppLifecycleState.inactive) {
      c.dispose();
      _ctrl = null;
    } else if (state == AppLifecycleState.resumed && _ctrl == null) {
      _init();
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _ctrl?.dispose();
    super.dispose();
  }

  Future<void> _shoot() async {
    final c = _ctrl;
    if (c == null || _shooting || c.value.isTakingPicture) return;
    setState(() => _shooting = true);
    try {
      final f = await c.takePicture();
      if (mounted) Navigator.pop(context, f);
    } catch (_) {
      if (mounted) setState(() => _shooting = false);
    }
  }

  Future<void> _toggleTorch() async {
    final c = _ctrl;
    if (c == null) return;
    _torch = !_torch;
    try {
      await c.setFlashMode(_torch ? FlashMode.torch : FlashMode.off);
    } catch (_) {
      _torch = false;
    }
    if (mounted) setState(() {});
  }

  /// タップした場所にピントを合わせる
  Future<void> _focus(TapDownDetails d, Size size) async {
    final c = _ctrl;
    if (c == null) return;
    final p = Offset(
      d.localPosition.dx / size.width,
      d.localPosition.dy / size.height,
    );
    try {
      await c.setFocusPoint(p);
      await c.setExposurePoint(p);
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    final c = _ctrl;
    return Scaffold(
      backgroundColor: Colors.black,
      body: _error != null
          ? _errorView()
          : c == null
          ? const Center(child: CircularProgressIndicator())
          : LayoutBuilder(
              builder: (context, box) {
                final size = Size(box.maxWidth, box.maxHeight);
                final ps = c.value.previewSize!; // 横向きの値なので、縦長に入れ替える
                return Stack(
                  fit: StackFit.expand,
                  children: [
                    GestureDetector(
                      onTapDown: (d) => _focus(d, size),
                      child: ClipRect(
                        child: FittedBox(
                          fit: BoxFit.cover,
                          child: SizedBox(
                            width: ps.height,
                            height: ps.width,
                            child: CameraPreview(c),
                          ),
                        ),
                      ),
                    ),
                    IgnorePointer(
                      child: CustomPaint(
                        painter: _GuidePainter(_guideRect(size)),
                      ),
                    ),
                    Positioned(
                      left: 0,
                      right: 0,
                      top: _guideRect(size).top - 44,
                      child: const IgnorePointer(
                        child: Text(
                          '名刺を枠に合わせて撮影',
                          textAlign: TextAlign.center,
                          style: TextStyle(color: Colors.white, fontSize: 16),
                        ),
                      ),
                    ),
                    SafeArea(
                      child: Align(
                        alignment: Alignment.topCenter,
                        child: Row(
                          children: [
                            _topButton(
                              Icons.close,
                              '閉じる',
                              () => Navigator.pop(context),
                            ),
                            const Spacer(),
                            _topButton(
                              _vertical
                                  ? Icons.crop_landscape
                                  : Icons.crop_portrait,
                              _vertical ? '横向き名刺' : '縦向き名刺',
                              () => setState(() => _vertical = !_vertical),
                            ),
                            _topButton(
                              _torch ? Icons.flash_on : Icons.flash_off,
                              _torch ? 'ライト入' : 'ライト切',
                              _toggleTorch,
                              color: _torch ? Colors.amber : Colors.white,
                            ),
                          ],
                        ),
                      ),
                    ),
                    SafeArea(
                      child: Align(
                        alignment: Alignment.bottomCenter,
                        child: Padding(
                          padding: const EdgeInsets.only(bottom: 28),
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              GestureDetector(
                                onTap: _shoot,
                                child: Container(
                                  width: 76,
                                  height: 76,
                                  decoration: BoxDecoration(
                                    shape: BoxShape.circle,
                                    border: Border.all(
                                      color: Colors.white,
                                      width: 4,
                                    ),
                                  ),
                                  padding: const EdgeInsets.all(6),
                                  child: DecoratedBox(
                                    decoration: BoxDecoration(
                                      shape: BoxShape.circle,
                                      color: _shooting
                                          ? Colors.grey
                                          : Colors.white,
                                    ),
                                  ),
                                ),
                              ),
                              const SizedBox(height: 4),
                              const Text(
                                '撮影',
                                style: TextStyle(
                                  color: Colors.white,
                                  fontSize: 13,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ],
                );
              },
            ),
    );
  }

  Widget _topButton(
    IconData icon,
    String label,
    VoidCallback onTap, {
    Color color = Colors.white,
  }) => InkWell(
    onTap: onTap,
    child: Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, color: color),
          Text(label, style: TextStyle(color: color, fontSize: 11)),
        ],
      ),
    ),
  );

  /// 画面の中央よりやや上に、名刺と同じ縦横比の枠を置く
  Rect _guideRect(Size s) {
    final w = _vertical ? s.width * 0.6 : s.width * 0.9;
    final h = _vertical ? w * _ratio : w / _ratio;
    return Rect.fromCenter(
      center: Offset(s.width / 2, s.height * 0.43),
      width: w,
      height: h,
    );
  }

  Widget _errorView() => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            _error!,
            style: const TextStyle(color: Colors.white),
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: () => Navigator.pop(context, null),
            child: const Text('標準のカメラで撮る'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('閉じる'),
          ),
        ],
      ),
    ),
  );
}

class _GuidePainter extends CustomPainter {
  _GuidePainter(this.rect);
  final Rect rect;

  @override
  void paint(Canvas canvas, Size size) {
    final rr = RRect.fromRectAndRadius(rect, const Radius.circular(10));
    // 枠の外側を暗くする
    final dim = Path()
      ..fillType = PathFillType.evenOdd
      ..addRect(Offset.zero & size)
      ..addRRect(rr);
    canvas.drawPath(dim, Paint()..color = Colors.black54);
    canvas.drawRRect(
      rr,
      Paint()
        ..color = Colors.white70
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.5,
    );
    // 四隅を強調
    final p = Paint()
      ..color = Colors.white
      ..style = PaintingStyle.stroke
      ..strokeWidth = 4
      ..strokeCap = StrokeCap.round;
    const L = 26.0;
    for (final (cx, cy, dx, dy) in [
      (rect.left, rect.top, 1.0, 1.0),
      (rect.right, rect.top, -1.0, 1.0),
      (rect.left, rect.bottom, 1.0, -1.0),
      (rect.right, rect.bottom, -1.0, -1.0),
    ]) {
      canvas.drawLine(Offset(cx, cy), Offset(cx + dx * L, cy), p);
      canvas.drawLine(Offset(cx, cy), Offset(cx, cy + dy * L), p);
    }
  }

  @override
  bool shouldRepaint(_GuidePainter old) => old.rect != rect;
}
