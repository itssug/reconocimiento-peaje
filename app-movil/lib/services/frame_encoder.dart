import 'dart:typed_data';
import 'package:image/image.dart' as img;

class RawFramePayload {
  final int width;
  final int height;
  final int sensorOrientation;
  final Uint8List yPlane;
  final Uint8List uPlane;
  final Uint8List vPlane;
  final int yRowStride;
  final int uvRowStride;
  final int uvPixelStride;

  RawFramePayload({
    required this.width,
    required this.height,
    required this.sensorOrientation,
    required this.yPlane,
    required this.uPlane,
    required this.vPlane,
    required this.yRowStride,
    required this.uvRowStride,
    required this.uvPixelStride,
  });
}

/// Convierte un frame YUV420 de la camara a JPEG ultrarrapido (384px = entrada nativa de M1) en Isolate.
Uint8List encodeYuv420ToJpeg(RawFramePayload f) {
  final scale = f.width > 384 ? (f.width / 384.0) : 1.0;
  final outW = (f.width / scale).round();
  final outH = (f.height / scale).round();
  final out = img.Image(width: outW, height: outH);

  for (var y = 0; y < outH; y++) {
    final sy = (y * scale).toInt().clamp(0, f.height - 1);
    final uvRow = (sy >> 1) * f.uvRowStride;
    final yRow = sy * f.yRowStride;
    for (var x = 0; x < outW; x++) {
      final sx = (x * scale).toInt().clamp(0, f.width - 1);
      final uvIdx = uvRow + (sx >> 1) * f.uvPixelStride;
      final yp = f.yPlane[yRow + sx];
      final up = f.uPlane[uvIdx] - 128;
      final vp = f.vPlane[uvIdx] - 128;
      final r = (yp + 1.370705 * vp).round().clamp(0, 255);
      final g = (yp - 0.337633 * up - 0.698001 * vp).round().clamp(0, 255);
      final b = (yp + 1.732446 * up).round().clamp(0, 255);
      out.setPixelRgb(x, y, r, g, b);
    }
  }

  final rotated = f.sensorOrientation != 0 ? img.copyRotate(out, angle: f.sensorOrientation) : out;
  return Uint8List.fromList(img.encodeJpg(rotated, quality: 60));
}
