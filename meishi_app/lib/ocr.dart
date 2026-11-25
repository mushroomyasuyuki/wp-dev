import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';
import 'parser.dart';

/// 端末内で文字を読み取る（通信なし）。
Future<List<OcrLine>> recognizeCard(String imagePath) async {
  final recognizer = TextRecognizer(script: TextRecognitionScript.japanese);
  try {
    final result = await recognizer.processImage(InputImage.fromFilePath(imagePath));
    return [
      for (final b in result.blocks)
        for (final l in b.lines) OcrLine(l.text, l.boundingBox.height),
    ];
  } finally {
    await recognizer.close();
  }
}
