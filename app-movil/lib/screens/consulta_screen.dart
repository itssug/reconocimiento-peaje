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

/// Modo Consulta: envia placa por texto o por fotografia al agente conversacional (/api/v1/agente/chat).
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
      _messages.add(_Msg(isUser: true, text: imagen != null ? (msg.isEmpty ? '[Foto de placa]' : '$msg + [Foto]') : msg));
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
        return Colors.green.shade700;
      case 'REGISTRADA_SIN_PASO':
        return Colors.blue.shade700;
      case 'IDENTIFICADA_NO_REGISTRADA':
        return Colors.red.shade700;
      default:
        return Colors.grey.shade700;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        // Atajos rapidos
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
          child: Row(
            children: [
              ActionChip(label: const Text('1001DEM'), onPressed: () => _send(texto: '1001DEM')),
              const SizedBox(width: 6),
              ActionChip(label: const Text('Recaudación hoy'), onPressed: () => _send(texto: 'Recaudacion del dia')),
              const SizedBox(width: 6),
              ActionChip(label: const Text('Últimos pasos'), onPressed: () => _send(texto: 'Ultimos pasos')),
              const SizedBox(width: 6),
              ActionChip(label: const Text('Alertas'), onPressed: () => _send(texto: 'Alertas pendientes')),
            ],
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: ListView.builder(
            padding: const EdgeInsets.all(12),
            itemCount: _messages.length,
            itemBuilder: (_, i) {
              final m = _messages[i];
              return Align(
                alignment: m.isUser ? Alignment.centerRight : Alignment.centerLeft,
                child: Container(
                  margin: const EdgeInsets.only(bottom: 10),
                  padding: const EdgeInsets.all(12),
                  constraints: const BoxConstraints(maxWidth: 320),
                  decoration: BoxDecoration(
                    color: m.isUser ? const Color(0xFF0D6EFD) : Colors.grey.shade200,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        m.text,
                        style: TextStyle(color: m.isUser ? Colors.white : Colors.black87),
                      ),
                      if (m.estadoPlaca != null) ...[
                        const SizedBox(height: 6),
                        Chip(
                          label: Text(m.estadoPlaca!, style: const TextStyle(color: Colors.white, fontSize: 11)),
                          backgroundColor: _badgeColor(m.estadoPlaca),
                          visualDensity: VisualDensity.compact,
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
        SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(8),
            child: Row(
              children: [
                IconButton(
                  icon: const Icon(Icons.camera_alt),
                  tooltip: 'Foto de placa',
                  onPressed: _sending ? null : () => _pickImage(ImageSource.camera),
                ),
                IconButton(
                  icon: const Icon(Icons.photo_library),
                  tooltip: 'Elegir de galería',
                  onPressed: _sending ? null : () => _pickImage(ImageSource.gallery),
                ),
                Expanded(
                  child: TextField(
                    controller: _ctrl,
                    textCapitalization: TextCapitalization.characters,
                    decoration: const InputDecoration(
                      hintText: 'Placa o consulta...',
                      border: OutlineInputBorder(),
                      isDense: true,
                    ),
                    onSubmitted: (_) => _send(),
                  ),
                ),
                const SizedBox(width: 6),
                IconButton.filled(
                  icon: const Icon(Icons.send),
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
