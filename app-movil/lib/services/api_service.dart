import 'dart:convert';
import 'dart:io';
import 'package:http/http.dart' as http;
import '../config/app_config.dart';

class ApiException implements Exception {
  final int statusCode;
  final String message;
  ApiException(this.statusCode, this.message);

  @override
  String toString() => message;
}

/// Cliente REST para todas las rutas /api/v1/* del backend PHP.
class ApiService {
  static Future<Map<String, String>> _headers({bool auth = true, bool json = true}) async {
    final h = <String, String>{'Accept': 'application/json'};
    if (json) h['Content-Type'] = 'application/json';
    if (auth) {
      final tok = await AppConfig.getToken();
      if (tok != null) h['Authorization'] = 'Bearer $tok';
    }
    return h;
  }

  static Map<String, dynamic> _parse(http.Response res) {
    Map<String, dynamic>? body;
    try {
      final decoded = jsonDecode(res.body);
      if (decoded is Map<String, dynamic>) body = decoded;
    } catch (_) {}
    if (res.statusCode < 200 || res.statusCode >= 300 || body == null || body['success'] == false) {
      final msg = body?['message']?.toString() ?? 'Error HTTP ${res.statusCode}';
      throw ApiException(res.statusCode, msg);
    }
    return body;
  }

  /// POST /api/v1/auth/google
  static Future<Map<String, dynamic>> loginWithGoogle(String idToken) async {
    final base = await AppConfig.getApiUrl();
    final res = await http
        .post(
          Uri.parse('$base/api/v1/auth/google'),
          headers: await _headers(auth: false),
          body: jsonEncode({'credential': idToken}),
        )
        .timeout(const Duration(seconds: 15));
    final body = _parse(res);
    final data = body['data'] as Map<String, dynamic>;
    final user = data['user'] as Map<String, dynamic>;
    await AppConfig.saveSession(
      token: data['token'].toString(),
      nombre: user['nombre']?.toString() ?? '',
      email: user['email']?.toString() ?? '',
      rol: user['rol']?.toString() ?? 'OPERADOR',
    );
    return data;
  }

  /// POST /api/v1/auth/logout
  static Future<void> logout() async {
    try {
      final base = await AppConfig.getApiUrl();
      await http
          .post(Uri.parse('$base/api/v1/auth/logout'), headers: await _headers())
          .timeout(const Duration(seconds: 5));
    } catch (_) {}
    await AppConfig.clearSession();
  }

  /// GET /api/v1/casetas?activas=1
  static Future<List<Map<String, dynamic>>> listarCasetasActivas() async {
    final base = await AppConfig.getApiUrl();
    final res = await http
        .get(Uri.parse('$base/api/v1/casetas?activas=1'), headers: await _headers())
        .timeout(const Duration(seconds: 10));
    final body = _parse(res);
    final list = (body['data'] as List?) ?? [];
    return list.cast<Map<String, dynamic>>();
  }

  /// GET /api/v1/casetas/{id}/resumen
  static Future<Map<String, dynamic>> resumenCaseta(int casetaId) async {
    final base = await AppConfig.getApiUrl();
    final res = await http
        .get(Uri.parse('$base/api/v1/casetas/$casetaId/resumen'), headers: await _headers())
        .timeout(const Duration(seconds: 10));
    return (_parse(res)['data'] as Map<String, dynamic>);
  }

  /// POST /api/v1/pasos/frame (multipart con imagen + caseta_id)
  static Future<Map<String, dynamic>> enviarFrame({
    required int casetaId,
    required File imagen,
  }) async {
    final base = await AppConfig.getApiUrl();
    final req = http.MultipartRequest('POST', Uri.parse('$base/api/v1/pasos/frame'));
    req.headers.addAll(await _headers(json: false));
    req.fields['caseta_id'] = casetaId.toString();
    req.files.add(await http.MultipartFile.fromPath('imagen', imagen.path));
    final streamed = await req.send().timeout(const Duration(seconds: 25));
    final res = await http.Response.fromStream(streamed);
    return (_parse(res)['data'] as Map<String, dynamic>);
  }

  /// POST /api/v1/agente/chat (texto y/o imagen opcional)
  static Future<Map<String, dynamic>> chatAgente({String? mensaje, File? imagen}) async {
    final base = await AppConfig.getApiUrl();
    if (imagen != null) {
      final req = http.MultipartRequest('POST', Uri.parse('$base/api/v1/agente/chat'));
      req.headers.addAll(await _headers(json: false));
      if (mensaje != null && mensaje.trim().isNotEmpty) {
        req.fields['mensaje'] = mensaje.trim();
      }
      req.files.add(await http.MultipartFile.fromPath('imagen', imagen.path));
      final streamed = await req.send().timeout(const Duration(seconds: 25));
      final res = await http.Response.fromStream(streamed);
      return (_parse(res)['data'] as Map<String, dynamic>);
    }

    final res = await http
        .post(
          Uri.parse('$base/api/v1/agente/chat'),
          headers: await _headers(),
          body: jsonEncode({'mensaje': mensaje ?? ''}),
        )
        .timeout(const Duration(seconds: 15));
    return (_parse(res)['data'] as Map<String, dynamic>);
  }
}
