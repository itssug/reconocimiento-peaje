import 'package:shared_preferences/shared_preferences.dart';

/// Configuracion persistente de la app movil (URL HTTPS del subdominio y Google Client ID).
class AppConfig {
  static const String defaultApiUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://peaje.tu-dominio.com',
  );

  static const String defaultGoogleClientId = String.fromEnvironment(
    'GOOGLE_CLIENT_ID',
    defaultValue: '',
  );

  static const _kApiUrl = 'api_base_url';
  static const _kGoogleClientId = 'google_client_id';
  static const _kInferenceUrl = 'inference_url';
  static const _kInferenceApiKey = 'inference_api_key';
  static const _kToken = 'session_token';
  static const _kUserName = 'user_name';
  static const _kUserEmail = 'user_email';
  static const _kUserRol = 'user_rol';

  static Future<String> getApiUrl() async {
    final p = await SharedPreferences.getInstance();
    final v = (p.getString(_kApiUrl) ?? defaultApiUrl).trim();
    return v.endsWith('/') ? v.substring(0, v.length - 1) : v;
  }

  static Future<void> setApiUrl(String url) async {
    final p = await SharedPreferences.getInstance();
    final clean = url.trim().replaceAll(RegExp(r'/+$'), '');
    await p.setString(_kApiUrl, clean);
  }

  static Future<String> getGoogleClientId() async {
    final p = await SharedPreferences.getInstance();
    return (p.getString(_kGoogleClientId) ?? defaultGoogleClientId).trim();
  }

  static Future<void> setGoogleClientId(String id) async {
    final p = await SharedPreferences.getInstance();
    await p.setString(_kGoogleClientId, id.trim());
  }

  static Future<String> getInferenceUrl() async {
    final p = await SharedPreferences.getInstance();
    final v = (p.getString(_kInferenceUrl) ?? '').trim();
    return v.endsWith('/') ? v.substring(0, v.length - 1) : v;
  }

  static Future<void> setInferenceUrl(String url) async {
    final p = await SharedPreferences.getInstance();
    await p.setString(_kInferenceUrl, url.trim().replaceAll(RegExp(r'/+$'), ''));
  }

  static Future<String> getInferenceApiKey() async {
    final p = await SharedPreferences.getInstance();
    return (p.getString(_kInferenceApiKey) ?? '').trim();
  }

  static Future<void> setInferenceApiKey(String key) async {
    final p = await SharedPreferences.getInstance();
    await p.setString(_kInferenceApiKey, key.trim());
  }

  static Future<String?> getToken() async {
    final p = await SharedPreferences.getInstance();
    final t = p.getString(_kToken);
    return (t != null && t.isNotEmpty) ? t : null;
  }

  static Future<void> saveSession({
    required String token,
    required String nombre,
    required String email,
    required String rol,
  }) async {
    final p = await SharedPreferences.getInstance();
    await p.setString(_kToken, token);
    await p.setString(_kUserName, nombre);
    await p.setString(_kUserEmail, email);
    await p.setString(_kUserRol, rol);
  }

  static Future<Map<String, String>?> getUser() async {
    final p = await SharedPreferences.getInstance();
    final token = p.getString(_kToken);
    if (token == null || token.isEmpty) return null;
    return {
      'nombre': p.getString(_kUserName) ?? '',
      'email': p.getString(_kUserEmail) ?? '',
      'rol': p.getString(_kUserRol) ?? 'OPERADOR',
    };
  }

  static Future<void> clearSession() async {
    final p = await SharedPreferences.getInstance();
    await p.remove(_kToken);
    await p.remove(_kUserName);
    await p.remove(_kUserEmail);
    await p.remove(_kUserRol);
  }
}
