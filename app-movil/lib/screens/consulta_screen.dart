import 'dart:io';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import '../services/api_service.dart';

class _Msg {
  final bool isUser;
  final String text;
  final String? estadoPlaca;
  _Msg({required this.isUser, required this.text, this.estadoPlaca});
}

/// Modo Consulta estilo Apple iMessage: envía placa por texto o foto al agente (/api/v1/agente/chat).
class ConsultaScreen extends StatefulWidget {
  const ConsultaScreen({super.key});

  @override
  State<ConsultaScreen> createState() => _ConsultaScreenState();
}

class _ConsultaScreenState extends State<ConsultaScreen> {
  final _ctrl = TextEditingController();
  final _picker = ImagePicker();
  final List<_Msg> _messages = [
    _Msg(
      isUser: false,
      text: 'Escribe una placa (ej. 1001DEM), pregunta por la recaudación o adjunta una foto de placa para consultarla con M1 + OCR.',
    ),
  ];
  bool _sending = false;

  Future<void> _send({String? texto, File? imagen}) async {
    final msg = (texto ?? _ctrl.text).trim();
    if (msg.isEmpty && imagen == null) return;
    _ctrl.clear();
    setState(() {
      _messages.add(_Msg(
        isUser: true,
        text: imagen != null ? (msg.isEmpty ? '📷 Foto de placa enviada' : '$msg • 📷 Foto') : msg,
      ));
      _sending = true;
    });
    try {
      final data = await ApiService.chatAgente(mensaje: msg, imagen: imagen);
      if (!mounted) return;
      setState(() {
        _messages.add(_Msg(
          isUser: false,
          text: data['respuesta']?.toString() ?? 'Sin respuesta.',
          estadoPlaca: data['estado_placa']?.toString(),
        ));
      });
    } catch (e) {
      if (mounted) {
        setState(() => _messages.add(_Msg(isUser: false, text: 'Error: $e')));
      }
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  Future<void> _pickImage(ImageSource src) async {
    final x = await _picker.pickImage(source: src, maxWidth: 1280, imageQuality: 85);
    if (x != null) {
      await _send(imagen: File(x.path));
    }
  }

  Color _badgeColor(String? e) {
    switch (e) {
      case 'REGISTRADA_CON_PASO':
        return const Color(0xFF34C759);
      case 'REGISTRADA_SIN_PASO':
        return const Color(0xFF0071E3);
      case 'IDENTIFICADA_NO_REGISTRADA':
        return const Color(0xFFFF3B30);
      default:
        return const Color(0xFF86868B);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        // Píldoras de consulta rápida
        Container(
          color: Colors.white,
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: [
                for (final item in const [
                  ('1001DEM', '1001DEM'),
                  ('7777DEM (Evasión)', '7777DEM'),
                  ('Recaudación hoy', 'Recaudacion del dia'),
                  ('Últimos pasos', 'Ultimos pasos'),
                  ('Alertas', 'Alertas pendientes'),
                ])
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: ActionChip(
                      label: Text(item.$1, style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w500)),
                      backgroundColor: const Color(0xFFF5F5F7),
                      side: BorderSide.none,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(999)),
                      onPressed: () => _send(texto: item.$2),
                    ),
                  ),
              ],
            ),
          ),
        ),
        Expanded(
          child: ListView.builder(
            padding: const EdgeInsets.all(14),
            itemCount: _messages.length,
            itemBuilder: (_, i) {
              final m = _messages[i];
              return Align(
                alignment: m.isUser ? Alignment.centerRight : Alignment.centerLeft,
                child: Container(
                  margin: const EdgeInsets.only(bottom: 10),
                  padding: const EdgeInsets.symmetric(horizontal: 15, vertical: 11),
                  constraints: const BoxConstraints(maxWidth: 320),
                  decoration: BoxDecoration(
                    color: m.isUser ? const Color(0xFF0071E3) : Colors.white,
                    borderRadius: BorderRadius.circular(18),
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withValues(alpha: 0.04),
                        blurRadius: 10,
                        offset: const Offset(0, 2),
                      ),
                    ],
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        m.text,
                        style: TextStyle(
                          color: m.isUser ? Colors.white : const Color(0xFF1D1D1F),
                          fontSize: 14,
                          height: 1.35,
                        ),
                      ),
                      if (m.estadoPlaca != null) ...[
                        const SizedBox(height: 8),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                          decoration: BoxDecoration(
                            color: _badgeColor(m.estadoPlaca),
                            borderRadius: BorderRadius.circular(999),
                          ),
                          child: Text(
                            m.estadoPlaca!,
                            style: const TextStyle(color: Colors.white, fontSize: 10.5, fontWeight: FontWeight.w700),
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
              );
            },
          ),
        ),
        if (_sending) const LinearProgressIndicator(minHeight: 2),
        Container(
          color: Colors.white,
          padding: const EdgeInsets.fromLTRB(10, 8, 10, 10),
          child: SafeArea(
            top: false,
            child: Row(
              children: [
                IconButton(
                  icon: const Icon(Icons.camera_alt_rounded, color: Color(0xFF0071E3)),
                  tooltip: 'Foto de placa',
                  onPressed: _sending ? null : () => _pickImage(ImageSource.camera),
                ),
                IconButton(
                  icon: const Icon(Icons.photo_library_rounded, color: Color(0xFF0071E3)),
                  tooltip: 'Elegir de galería',
                  onPressed: _sending ? null : () => _pickImage(ImageSource.gallery),
                ),
                Expanded(
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 14),
                    decoration: BoxDecoration(
                      color: const Color(0xFFF5F5F7),
                      borderRadius: BorderRadius.circular(999),
                    ),
                    child: TextField(
                      controller: _ctrl,
                      textCapitalization: TextCapitalization.characters,
                      decoration: const InputDecoration(
                        hintText: 'Placa o consulta al agente...',
                        border: InputBorder.none,
                        isDense: true,
                        contentPadding: EdgeInsets.symmetric(vertical: 10),
                      ),
                      onSubmitted: (_) => _send(),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                IconButton.filled(
                  style: IconButton.styleFrom(backgroundColor: const Color(0xFF0071E3)),
                  icon: const Icon(Icons.arrow_upward_rounded, size: 20),
                  onPressed: _sending ? null : () => _send(),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
