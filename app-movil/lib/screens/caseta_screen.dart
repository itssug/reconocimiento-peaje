import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'package:camera/camera.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:web_socket_channel/web_socket_channel.dart';
import '../config/app_config.dart';
import '../services/api_service.dart';
import '../services/frame_encoder.dart';

/// Dibuja en vivo sobre la camara estilo trafico en tiempo real:
/// - Multiples vehiculos rastreados (ByteTrack #ID) con mascara + esquinas de seguimiento + etiqueta
/// - Keypoints de ruedas/ejes (naranja/amarillo)
/// - Mascaras de pasajeros (morado silueta)
/// - Caja verde + texto de placa
class _LiveTrackPainter extends CustomPainter {
  final Map<String, dynamic> data;
  final Set<int> registeredTrackIds;
  _LiveTrackPainter(this.data, this.registeredTrackIds);

  static const _palette = [
    Color(0xFF00E5FF),
    Color(0xFF76FF03),
    Color(0xFFFFD740),
    Color(0xFFFF6E40),
    Color(0xFFE040FB),
  ];

  void _drawCorners(Canvas canvas, Rect r, Color color) {
    final len = (r.width * 0.18).clamp(12.0, 28.0);
    final p = Paint()
      ..color = color
      ..strokeWidth = 3.2
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round;
    // Top-left
    canvas.drawLine(r.topLeft, r.topLeft + Offset(len, 0), p);
    canvas.drawLine(r.topLeft, r.topLeft + Offset(0, len), p);
    // Top-right
    canvas.drawLine(r.topRight, r.topRight + Offset(-len, 0), p);
    canvas.drawLine(r.topRight, r.topRight + Offset(0, len), p);
    // Bottom-left
    canvas.drawLine(r.bottomLeft, r.bottomLeft + Offset(len, 0), p);
    canvas.drawLine(r.bottomLeft, r.bottomLeft + Offset(0, -len), p);
    // Bottom-right
    canvas.drawLine(r.bottomRight, r.bottomRight + Offset(-len, 0), p);
    canvas.drawLine(r.bottomRight, r.bottomRight + Offset(0, -len), p);
  }

  void _drawTag(Canvas canvas, Size size, Offset pos, String text, Color bg) {
    final tp = TextPainter(
      text: TextSpan(
        text: ' $text ',
        style: TextStyle(
          color: Colors.black,
          backgroundColor: bg,
          fontWeight: FontWeight.bold,
          fontSize: 11.5,
        ),
      ),
      textDirection: TextDirection.ltr,
    )..layout();
    tp.paint(
      canvas,
      Offset(
        pos.dx.clamp(2.0, (size.width - tp.width - 2).clamp(2.0, size.width)),
        pos.dy.clamp(2.0, (size.height - tp.height - 2).clamp(2.0, size.height)),
      ),
    );
  }

  @override
  void paint(Canvas canvas, Size size) {
    // 1. Multi-vehiculo (ByteTrack)
    final vehs = data['vehiculos'] as List?;
    if (vehs != null) {
      for (final raw in vehs) {
        final v = raw as Map<String, dynamic>;
        final tid = (v['track_id'] as num?)?.toInt() ?? 0;
        final color = _palette[tid % _palette.length];

        final poly = v['poligono'] as List?;
        if (poly != null && poly.length >= 3) {
          final path = Path();
          for (var i = 0; i < poly.length; i++) {
            final pt = poly[i] as List;
            final x = (pt[0] as num).toDouble() * size.width;
            final y = (pt[1] as num).toDouble() * size.height;
            if (i == 0) {
              path.moveTo(x, y);
            } else {
              path.lineTo(x, y);
            }
          }
          path.close();
          canvas.drawPath(path, Paint()..color = color.withValues(alpha: 0.22)..style = PaintingStyle.fill);
          canvas.drawPath(path, Paint()..color = color.withValues(alpha: 0.85)..style = PaintingStyle.stroke..strokeWidth = 1.6);
        }

        final bb = v['bbox'] as List?;
        if (bb != null && bb.length == 4) {
          final rect = Rect.fromLTRB(
            (bb[0] as num).toDouble() * size.width,
            (bb[1] as num).toDouble() * size.height,
            (bb[2] as num).toDouble() * size.width,
            (bb[3] as num).toDouble() * size.height,
          );
          _drawCorners(canvas, rect, color);
          final placa = v['placa'] as Map<String, dynamic>?;
          final okMark = registeredTrackIds.contains(tid) ? ' ✓' : '';
          final tag = '#$tid ${(v['categoria'] ?? '').toString().toUpperCase()}'
              '${placa != null ? ' • ${placa['texto']}' : ''}$okMark';
          _drawTag(canvas, size, Offset(rect.left, rect.top - 18), tag, color);
        }
      }
    } else {
      // Compatibilidad con overlay simple (modo 1 foto)
      final vPoly = data['vehiculo_poligono'] as List?;
      if (vPoly != null && vPoly.length >= 3) {
        final path = Path();
        for (var i = 0; i < vPoly.length; i++) {
          final pt = vPoly[i] as List;
          final x = (pt[0] as num).toDouble() * size.width;
          final y = (pt[1] as num).toDouble() * size.height;
          if (i == 0) {
            path.moveTo(x, y);
          } else {
            path.lineTo(x, y);
          }
        }
        path.close();
        canvas.drawPath(path, Paint()..color = const Color(0x4500BFFF)..style = PaintingStyle.fill);
        canvas.drawPath(path, Paint()..color = const Color(0xFF00BFFF)..style = PaintingStyle.stroke..strokeWidth = 2);
      }
    }

    // 2. M4: Mascaras de pasajeros (morado silueta)
    final pPolys = data['pasajeros_poligonos'] as List?;
    if (pPolys != null) {
      for (final raw in pPolys) {
        final pts = raw as List;
        if (pts.length < 3) continue;
        final path = Path();
        for (var i = 0; i < pts.length; i++) {
          final pt = pts[i] as List;
          final x = (pt[0] as num).toDouble() * size.width;
          final y = (pt[1] as num).toDouble() * size.height;
          if (i == 0) {
            path.moveTo(x, y);
          } else {
            path.lineTo(x, y);
          }
        }
        path.close();
        canvas.drawPath(path, Paint()..color = const Color(0x88C832DC)..style = PaintingStyle.fill);
        canvas.drawPath(path, Paint()..color = const Color(0xFFFF78FF)..style = PaintingStyle.stroke..strokeWidth = 1.5);
      }
    }

    // 3. M3: Keypoints de ruedas/ejes
    final ruedas = data['ruedas_kps'] as List?;
    if (ruedas != null) {
      final linePaint = Paint()..color = const Color(0xFFFFD600)..strokeWidth = 2;
      final dotPaint = Paint()..color = const Color(0xFFFF9100)..style = PaintingStyle.fill;
      final ringPaint = Paint()..color = const Color(0xFFFF6D00)..style = PaintingStyle.stroke..strokeWidth = 2;
      for (final raw in ruedas) {
        final kps = raw as List;
        if (kps.length < 5) continue;
        Offset pt(int idx) => Offset(
              ((kps[idx] as List)[0] as num).toDouble() * size.width,
              ((kps[idx] as List)[1] as num).toDouble() * size.height,
            );
        canvas.drawLine(pt(2), pt(1), linePaint);
        canvas.drawLine(pt(3), pt(4), linePaint);
        for (var i = 0; i < 5; i++) {
          canvas.drawCircle(pt(i), i == 0 ? 5 : 3.5, dotPaint);
        }
        canvas.drawCircle(pt(0), 8, ringPaint);
      }
    }

    // 4. M1: Placa (verde brillante)
    final pSuelta = data['placa_suelta'] as Map<String, dynamic>?;
    final bbox = (pSuelta?['bbox'] ?? data['placa_bbox']) as List?;
    final txt = (pSuelta?['texto'] ?? data['placa_texto'])?.toString();
    if (bbox != null && bbox.length == 4) {
      final rect = Rect.fromLTRB(
        (bbox[0] as num).toDouble() * size.width,
        (bbox[1] as num).toDouble() * size.height,
        (bbox[2] as num).toDouble() * size.width,
        (bbox[3] as num).toDouble() * size.height,
      );
      canvas.drawRect(rect, Paint()..color = const Color(0xFF00E676)..style = PaintingStyle.stroke..strokeWidth = 3);
      if (txt != null && txt.isNotEmpty) {
        _drawTag(canvas, size, Offset(rect.left, rect.top - 18), txt, const Color(0xFF00E676));
      }
    }
  }

  @override
  bool shouldRepaint(covariant _LiveTrackPainter oldDelegate) => true;
}

/// Modo Caseta:
/// - Tracking en vivo por WebSocket (/ws/track con ByteTrack) + auto-registro 1 vez por vehiculo en PHP/SQLite.
/// - Modo captura manual (1 foto) o polling clasico (2-5 fps).
class CasetaScreen extends StatefulWidget {
  const CasetaScreen({super.key});

  @override
  State<CasetaScreen> createState() => _CasetaScreenState();
}

class _CasetaScreenState extends State<CasetaScreen> {
  CameraController? _cam;
  int _sensorOrientation = 90;
  String? _camError;
  List<Map<String, dynamic>> _casetas = [];
  int? _casetaId;
  bool _sending = false;

  // Tracking en vivo (WebSocket + startImageStream)
  bool _liveTracking = false;
  bool _wsInFlight = false;
  WebSocketChannel? _ws;
  StreamSubscription? _wsSub;
  Uint8List? _lastSentJpeg;
  int _liveLatencyMs = 0;
  double _liveFps = 0;
  DateTime? _lastFrameTs;
  final Set<int> _registeredTrackIds = {};
  final Map<String, DateTime> _registeredPlatesAt = {};
  bool _autoRegisterBusy = false;

  Map<String, dynamic>? _ultimoPaso;
  Map<String, dynamic>? _liveData;
  Uint8List? _ultimaImagenAnotada;
  List<dynamic> _ultimasAlertas = [];
  Map<String, dynamic>? _resumenHoy;
  String? _error;

  @override
  void initState() {
    super.initState();
    _initCamera();
    _loadCasetas();
  }

  Future<void> _initCamera() async {
    try {
      final cams = await availableCameras();
      if (cams.isEmpty) {
        if (mounted) setState(() => _camError = 'No se detectó cámara en el dispositivo.');
        return;
      }
      final back = cams.firstWhere(
        (c) => c.lensDirection == CameraLensDirection.back,
        orElse: () => cams.first,
      );
      _sensorOrientation = back.sensorOrientation;
      final ctrl = CameraController(
        back,
        ResolutionPreset.medium,
        enableAudio: false,
        imageFormatGroup: ImageFormatGroup.yuv420,
      );
      await ctrl.initialize();
      if (mounted) setState(() => _cam = ctrl);
    } catch (e) {
      if (mounted) setState(() => _camError = 'Error de cámara: $e');
    }
  }

  Future<void> _loadCasetas() async {
    try {
      final list = await ApiService.listarCasetasActivas();
      if (!mounted) return;
      setState(() {
        _casetas = list;
        if (list.isNotEmpty) _casetaId = list.first['id'] as int;
      });
      if (_casetaId != null) _loadResumen(_casetaId!);
    } catch (e) {
      if (mounted) setState(() => _error = 'No se pudieron cargar casetas: $e');
    }
  }

  Future<void> _loadResumen(int id) async {
    try {
      final r = await ApiService.resumenCaseta(id);
      if (mounted) setState(() => _resumenHoy = r);
    } catch (_) {}
  }

  Future<void> _toggleLiveTracking() async {
    if (_liveTracking) {
      await _stopLiveTracking();
      return;
    }
    if (_cam == null || !_cam!.value.isInitialized || _casetaId == null) return;

    final infUrl = await AppConfig.getInferenceUrl();
    final infKey = await AppConfig.getInferenceApiKey();
    if (infUrl.isEmpty) {
      if (mounted) {
        setState(() => _error = 'Configura la URL de Inferencia (Colab/RunPod) en ⚙ Ajustes del Login para usar Tracking en vivo.');
      }
      return;
    }

    final wsBase = infUrl.replaceFirst(RegExp(r'^http'), 'ws');
    final wsUrl = '$wsBase/ws/track?api_key=${Uri.encodeQueryComponent(infKey)}';

    setState(() {
      _error = null;
      _liveTracking = true;
      _wsInFlight = false;
      _registeredTrackIds.clear();
    });

    try {
      _ws = WebSocketChannel.connect(Uri.parse(wsUrl));
      _wsSub = _ws!.stream.listen(
        (msg) {
          _wsInFlight = false;
          if (!mounted) return;
          try {
            final d = jsonDecode(msg.toString()) as Map<String, dynamic>;
            if (d['ok'] == true) {
              final now = DateTime.now();
              final fps = _lastFrameTs != null
                  ? 1000.0 / now.difference(_lastFrameTs!).inMilliseconds.clamp(1, 10000)
                  : 0.0;
              _lastFrameTs = now;
              setState(() {
                _liveData = d;
                _liveLatencyMs = (d['latencia_ms'] as num?)?.toInt() ?? 0;
                _liveFps = fps;
              });
              _maybeAutoRegister(d);
            }
          } catch (_) {}
        },
        onError: (e) {
          if (mounted) setState(() => _error = 'WebSocket error: $e');
          _stopLiveTracking();
        },
        onDone: () {
          if (_liveTracking && mounted) _stopLiveTracking();
        },
      );

      await _cam!.startImageStream((CameraImage img) async {
        if (!_liveTracking || _wsInFlight || _ws == null) return;
        if (img.planes.length < 3) return;
        _wsInFlight = true;
        try {
          final payload = RawFramePayload(
            width: img.width,
            height: img.height,
            sensorOrientation: _sensorOrientation,
            yPlane: Uint8List.fromList(img.planes[0].bytes),
            uPlane: Uint8List.fromList(img.planes[1].bytes),
            vPlane: Uint8List.fromList(img.planes[2].bytes),
            yRowStride: img.planes[0].bytesPerRow,
            uvRowStride: img.planes[1].bytesPerRow,
            uvPixelStride: img.planes[1].bytesPerPixel ?? 1,
          );
          final jpeg = await compute(encodeYuv420ToJpeg, payload);
          if (!_liveTracking || _ws == null) {
            _wsInFlight = false;
            return;
          }
          _lastSentJpeg = jpeg;
          _ws!.sink.add(jpeg);
        } catch (_) {
          _wsInFlight = false;
        }
      });
    } catch (e) {
      if (mounted) setState(() => _error = 'No se pudo iniciar tracking: $e');
      await _stopLiveTracking();
    }
  }

  DateTime? _lastAutoRegAt;

  /// Auto-registra directo en PHP/SQLite (Dashboard en tiempo real) apenas detecta
  /// un vehiculo nuevo (track_id) o una placa legible, evitando duplicados seguidos.
  Future<void> _maybeAutoRegister(Map<String, dynamic> d) async {
    if (_autoRegisterBusy || _casetaId == null || _lastSentJpeg == null) return;
    if (_lastAutoRegAt != null && DateTime.now().difference(_lastAutoRegAt!).inSeconds < 4) {
      return;
    }

    final vehs = (d['vehiculos'] as List?) ?? [];
    int? targetTid;
    String? placa;

    // 1. Prioridad: vehiculo con placa leida
    for (final raw in vehs) {
      final v = raw as Map<String, dynamic>;
      final tid = (v['track_id'] as num?)?.toInt();
      final p = v['placa'] as Map<String, dynamic>?;
      if (tid != null && !_registeredTrackIds.contains(tid) && p != null) {
        targetTid = tid;
        placa = p['texto']?.toString();
        break;
      }
    }

    // 2. Si hay placa suelta detectada por M1
    if (targetTid == null) {
      final ps = d['placa_suelta'] as Map<String, dynamic>?;
      if (ps != null && (ps['texto']?.toString().isNotEmpty ?? false)) {
        placa = ps['texto'].toString();
        targetTid = -1;
      }
    }

    // 3. Si detecto un vehiculo nuevo (aunque la placa no sea legible desde ese angulo), registrar igual!
    if (targetTid == null) {
      for (final raw in vehs) {
        final v = raw as Map<String, dynamic>;
        final tid = (v['track_id'] as num?)?.toInt();
        final conf = (v['confianza'] as num?)?.toDouble() ?? 0;
        if (tid != null && !_registeredTrackIds.contains(tid) && conf >= 0.40) {
          targetTid = tid;
          break;
        }
      }
    }

    if (targetTid == null) return;
    if (placa != null && placa.isNotEmpty) {
      final lastAt = _registeredPlatesAt[placa];
      if (lastAt != null && DateTime.now().difference(lastAt).inSeconds < 12) {
        if (targetTid > 0) _registeredTrackIds.add(targetTid);
        return;
      }
    }

    _autoRegisterBusy = true;
    _lastAutoRegAt = DateTime.now();
    if (targetTid > 0) _registeredTrackIds.add(targetTid);
    if (placa != null && placa.isNotEmpty) _registeredPlatesAt[placa] = DateTime.now();

    File? tmp;
    try {
      tmp = File('${Directory.systemTemp.path}/track_${DateTime.now().millisecondsSinceEpoch}.jpg');
      await tmp.writeAsBytes(_lastSentJpeg!, flush: true);
      final res = await ApiService.enviarFrame(casetaId: _casetaId!, imagen: tmp);
      if (!mounted) return;
      Uint8List? anotada;
      final b64 = res['imagen_anotada_base64'];
      if (b64 is String && b64.isNotEmpty) {
        try {
          anotada = base64Decode(b64);
        } catch (_) {}
      }
      setState(() {
        _error = null;
        _ultimoPaso = res['paso'] as Map<String, dynamic>?;
        if (anotada != null) _ultimaImagenAnotada = anotada;
        _ultimasAlertas = (res['alertas_generadas'] as List?) ?? [];
      });
      _loadResumen(_casetaId!);
    } catch (e) {
      if (targetTid > 0) _registeredTrackIds.remove(targetTid);
      if (mounted) setState(() => _error = 'Error al registrar en Dashboard: $e');
    } finally {
      try {
        await tmp?.delete();
      } catch (_) {}
      _autoRegisterBusy = false;
    }
  }

  Future<void> _stopLiveTracking() async {
    _liveTracking = false;
    _wsInFlight = false;
    try {
      if (_cam != null && _cam!.value.isStreamingImages) {
        await _cam!.stopImageStream();
      }
    } catch (_) {}
    await _wsSub?.cancel();
    _wsSub = null;
    try {
      await _ws?.sink.close();
    } catch (_) {}
    _ws = null;
    if (mounted) setState(() {});
  }

  Future<void> _captureSingle() async {
    if (_sending || _liveTracking || _cam == null || !_cam!.value.isInitialized || _casetaId == null) return;
    setState(() {
      _sending = true;
      _error = null;
    });
    File? tmpFile;
    try {
      final shot = await _cam!.takePicture();
      tmpFile = File(shot.path);
      final data = await ApiService.enviarFrame(casetaId: _casetaId!, imagen: tmpFile);
      if (!mounted) return;
      Uint8List? anotada;
      final b64 = data['imagen_anotada_base64'];
      if (b64 is String && b64.isNotEmpty) {
        try {
          anotada = base64Decode(b64);
        } catch (_) {}
      }
      setState(() {
        _ultimoPaso = data['paso'] as Map<String, dynamic>?;
        _liveData = data['overlay'] as Map<String, dynamic>?;
        if (anotada != null) _ultimaImagenAnotada = anotada;
        _ultimasAlertas = (data['alertas_generadas'] as List?) ?? [];
      });
      _loadResumen(_casetaId!);
      if (mounted && (_ultimoPaso != null || anotada != null)) {
        _mostrarModalEvidencia();
      }
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      try {
        await tmpFile?.delete();
      } catch (_) {}
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  void dispose() {
    _stopLiveTracking();
    _cam?.dispose();
    super.dispose();
  }

  Color _estadoColor(String? es) {
    switch (es) {
      case 'REGISTRADO':
        return const Color(0xFF34C759);
      case 'OBSERVADO':
        return const Color(0xFFFF9500);
      default:
        return const Color(0xFFFF3B30);
    }
  }

  void _mostrarModalEvidencia() {
    if (_ultimoPaso == null && _ultimaImagenAnotada == null) return;
    final p = _ultimoPaso ?? {};
    final placa = (p['placa_normalizada'] ?? p['placa_leida'] ?? 'N/A').toString();
    final cat = (p['categoria_detectada'] ?? '-').toString();
    final ejes = (p['ejes_detectados'] ?? '-').toString();
    final pasaj = (p['pasajeros'] ?? '-').toString();
    final monto = (p['monto'] as num? ?? 0).toStringAsFixed(2);
    final estado = (p['estado'] ?? '-').toString();

    showDialog(
      context: context,
      builder: (ctx) => Dialog(
        backgroundColor: Colors.white,
        insetPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 24),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        decoration: BoxDecoration(
                          color: const Color(0xFFF5F5F7),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Text(
                          placa,
                          style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800, letterSpacing: 0.5),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        decoration: BoxDecoration(
                          color: _estadoColor(estado),
                          borderRadius: BorderRadius.circular(999),
                        ),
                        child: Text(
                          estado,
                          style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700),
                        ),
                      ),
                    ],
                  ),
                  IconButton(
                    icon: const Icon(Icons.close_rounded),
                    onPressed: () => Navigator.pop(ctx),
                  ),
                ],
              ),
              const SizedBox(height: 10),
              Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  _metaChip('Categoría: ${cat.toUpperCase()}'),
                  _metaChip('Ejes: $ejes'),
                  _metaChip('Pasajeros: $pasaj'),
                  _metaChip('Tarifa: Bs $monto'),
                ],
              ),
              const SizedBox(height: 12),
              if (_ultimaImagenAnotada != null)
                ClipRRect(
                  borderRadius: BorderRadius.circular(16),
                  child: Container(
                    color: Colors.black,
                    constraints: const BoxConstraints(maxHeight: 420),
                    width: double.infinity,
                    child: InteractiveViewer(
                      child: Image.memory(_ultimaImagenAnotada!, fit: BoxFit.contain),
                    ),
                  ),
                )
              else
                const Padding(
                  padding: EdgeInsets.all(24),
                  child: Center(child: Text('Sin imagen anotada disponible.')),
                ),
              if (_ultimasAlertas.isNotEmpty) ...[
                const SizedBox(height: 10),
                ..._ultimasAlertas.map((a) => Padding(
                      padding: const EdgeInsets.only(top: 4),
                      child: Text(
                        '⚠ ${a['tipo']}: ${a['descripcion']}',
                        style: const TextStyle(color: Color(0xFFD70015), fontSize: 12, fontWeight: FontWeight.w500),
                      ),
                    )),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _metaChip(String text) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: const Color(0xFFF5F5F7),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(text, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 8, 12, 10),
      child: Column(
        children: [
          // Barra superior compacta: selector de caseta + métricas del día
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: Colors.black.withValues(alpha: 0.06)),
            ),
            child: Row(
              children: [
                const Icon(Icons.location_on_rounded, color: Color(0xFF0071E3), size: 18),
                const SizedBox(width: 6),
                Expanded(
                  child: DropdownButtonHideUnderline(
                    child: DropdownButton<int>(
                      isDense: true,
                      isExpanded: true,
                      value: _casetaId,
                      hint: const Text('Seleccionar caseta', style: TextStyle(fontSize: 13.5)),
                      style: const TextStyle(
                        color: Color(0xFF1D1D1F),
                        fontSize: 13.5,
                        fontWeight: FontWeight.w600,
                      ),
                      items: _casetas
                          .map((c) => DropdownMenuItem<int>(
                                value: c['id'] as int,
                                child: Text(c['nombre'].toString(), overflow: TextOverflow.ellipsis),
                              ))
                          .toList(),
                      onChanged: _liveTracking
                          ? null
                          : (v) {
                              setState(() => _casetaId = v);
                              if (v != null) _loadResumen(v);
                            },
                    ),
                  ),
                ),
                if (_resumenHoy != null) ...[
                  const SizedBox(width: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
                    decoration: BoxDecoration(
                      color: const Color(0xFFF5F5F7),
                      borderRadius: BorderRadius.circular(999),
                    ),
                    child: Text(
                      '${_resumenHoy!['pasos_hoy']} pasos • Bs ${(_resumenHoy!['recaudacion_hoy'] as num).toStringAsFixed(0)}',
                      style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700, color: Color(0xFF1D1D1F)),
                    ),
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(height: 8),

          // VISOR DE CÁMARA GRANDE (ocupa todo el espacio central disponible)
          Expanded(
            child: ClipRRect(
              borderRadius: BorderRadius.circular(24),
              child: Container(
                width: double.infinity,
                color: const Color(0xFF0F0F12),
                child: _cam != null && _cam!.value.isInitialized
                    ? Stack(
                        fit: StackFit.expand,
                        children: [
                          FittedBox(
                            fit: BoxFit.cover,
                            clipBehavior: Clip.hardEdge,
                            child: SizedBox(
                              width: _cam!.value.previewSize?.height ?? 480,
                              height: _cam!.value.previewSize?.width ?? 640,
                              child: CameraPreview(_cam!),
                            ),
                          ),
                          if (_liveData != null)
                            CustomPaint(painter: _LiveTrackPainter(_liveData!, _registeredTrackIds)),

                          // Píldora LIVE superior izquierda
                          if (_liveTracking)
                            Positioned(
                              top: 12,
                              left: 12,
                              child: Container(
                                padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 6),
                                decoration: BoxDecoration(
                                  color: Colors.black.withValues(alpha: 0.72),
                                  borderRadius: BorderRadius.circular(999),
                                ),
                                child: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    Container(
                                      width: 8,
                                      height: 8,
                                      decoration: const BoxDecoration(
                                        color: Color(0xFF34C759),
                                        shape: BoxShape.circle,
                                      ),
                                    ),
                                    const SizedBox(width: 6),
                                    Text(
                                      'LIVE ${_liveFps.toStringAsFixed(1)} fps • ${_liveLatencyMs}ms',
                                      style: const TextStyle(
                                        color: Colors.white,
                                        fontSize: 11.5,
                                        fontWeight: FontWeight.w700,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ),

                          // Indicador de envío superior derecha
                          if (_sending || _autoRegisterBusy)
                            Positioned(
                              top: 12,
                              right: 12,
                              child: Container(
                                padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 6),
                                decoration: BoxDecoration(
                                  color: Colors.black.withValues(alpha: 0.72),
                                  borderRadius: BorderRadius.circular(999),
                                ),
                                child: const Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    SizedBox(
                                      width: 12,
                                      height: 12,
                                      child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                                    ),
                                    SizedBox(width: 6),
                                    Text(
                                      'Procesando IA...',
                                      style: TextStyle(color: Colors.white, fontSize: 11.5, fontWeight: FontWeight.w600),
                                    ),
                                  ],
                                ),
                              ),
                            ),

                          // Tarjeta flotante inferior (HUD Apple) con el último vehículo y botón para abrir modal de foto
                          if (_ultimoPaso != null)
                            Positioned(
                              left: 12,
                              right: 12,
                              bottom: 12,
                              child: GestureDetector(
                                onTap: _mostrarModalEvidencia,
                                child: Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 11),
                                  decoration: BoxDecoration(
                                    color: Colors.white.withValues(alpha: 0.94),
                                    borderRadius: BorderRadius.circular(18),
                                    boxShadow: [
                                      BoxShadow(
                                        color: Colors.black.withValues(alpha: 0.22),
                                        blurRadius: 20,
                                        offset: const Offset(0, 6),
                                      ),
                                    ],
                                  ),
                                  child: Row(
                                    children: [
                                      if (_ultimaImagenAnotada != null)
                                        Padding(
                                          padding: const EdgeInsets.only(right: 11),
                                          child: ClipRRect(
                                            borderRadius: BorderRadius.circular(10),
                                            child: Image.memory(
                                              _ultimaImagenAnotada!,
                                              width: 54,
                                              height: 44,
                                              fit: BoxFit.cover,
                                            ),
                                          ),
                                        ),
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment: CrossAxisAlignment.start,
                                          mainAxisSize: MainAxisSize.min,
                                          children: [
                                            Row(
                                              children: [
                                                Text(
                                                  '${_ultimoPaso!['placa_normalizada'] ?? _ultimoPaso!['placa_leida'] ?? 'SIN PLACA'}',
                                                  style: const TextStyle(
                                                    fontSize: 16,
                                                    fontWeight: FontWeight.w800,
                                                    color: Color(0xFF1D1D1F),
                                                  ),
                                                ),
                                                const SizedBox(width: 8),
                                                Container(
                                                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                                                  decoration: BoxDecoration(
                                                    color: _estadoColor(_ultimoPaso!['estado']?.toString()),
                                                    borderRadius: BorderRadius.circular(999),
                                                  ),
                                                  child: Text(
                                                    '${_ultimoPaso!['estado']}',
                                                    style: const TextStyle(
                                                      color: Colors.white,
                                                      fontSize: 10,
                                                      fontWeight: FontWeight.w700,
                                                    ),
                                                  ),
                                                ),
                                              ],
                                            ),
                                            const SizedBox(height: 2),
                                            Text(
                                              '${(_ultimoPaso!['categoria_detectada'] ?? '-').toString().toUpperCase()} • '
                                              '${_ultimoPaso!['ejes_detectados'] ?? '-'} ejes • '
                                              'Pasaj: ${_ultimoPaso!['pasajeros'] ?? '-'} • '
                                              'Bs ${(_ultimoPaso!['monto'] as num? ?? 0).toStringAsFixed(2)}',
                                              style: const TextStyle(
                                                fontSize: 12,
                                                fontWeight: FontWeight.w600,
                                                color: Color(0xFF424245),
                                              ),
                                            ),
                                          ],
                                        ),
                                      ),
                                      const Icon(Icons.open_in_full_rounded, size: 18, color: Color(0xFF0071E3)),
                                    ],
                                  ),
                                ),
                              ),
                            ),
                        ],
                      )
                    : Center(
                        child: Padding(
                          padding: const EdgeInsets.all(20),
                          child: Text(
                            _camError ?? 'Iniciando cámara...',
                            style: const TextStyle(color: Colors.white70, fontSize: 14),
                            textAlign: TextAlign.center,
                          ),
                        ),
                      ),
              ),
            ),
          ),

          if (_error != null) ...[
            const SizedBox(height: 6),
            Text(
              _error!,
              style: const TextStyle(color: Color(0xFFD70015), fontSize: 12, fontWeight: FontWeight.w500),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
          ],

          const SizedBox(height: 10),
          // Barra inferior de controles estilo iOS Camera
          Row(
            children: [
              Expanded(
                child: SizedBox(
                  height: 48,
                  child: FilledButton.icon(
                    style: FilledButton.styleFrom(
                      backgroundColor: _liveTracking ? const Color(0xFFFF3B30) : const Color(0xFF0071E3),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(999)),
                    ),
                    onPressed: _cam == null ? null : _toggleLiveTracking,
                    icon: Icon(_liveTracking ? Icons.stop_rounded : Icons.radar_rounded, size: 20),
                    label: Text(
                      _liveTracking ? 'Detener Tracking' : 'Tracking en Vivo',
                      style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14),
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 8),
              SizedBox(
                height: 48,
                child: OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(
                    backgroundColor: Colors.white,
                    side: BorderSide(color: Colors.black.withValues(alpha: 0.12)),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(999)),
                  ),
                  onPressed: (_cam == null || _sending || _liveTracking) ? null : _captureSingle,
                  icon: const Icon(Icons.camera_alt_rounded, size: 19),
                  label: const Text('Capturar', style: TextStyle(fontWeight: FontWeight.w600)),
                ),
              ),
              if (_ultimoPaso != null) ...[
                const SizedBox(width: 6),
                IconButton.filledTonal(
                  onPressed: _mostrarModalEvidencia,
                  tooltip: 'Ver foto segmentada en modal',
                  icon: const Icon(Icons.photo_size_select_actual_rounded, size: 20),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}
