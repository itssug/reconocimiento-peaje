import 'package:flutter/material.dart';
import 'package:google_sign_in/google_sign_in.dart';
import '../config/app_config.dart';
import '../services/api_service.dart';
import 'home_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  bool _loading = false;
  String? _error;
  String _apiUrl = '';

  @override
  void initState() {
    super.initState();
    _loadConfig();
  }

  Future<void> _loadConfig() async {
    final u = await AppConfig.getApiUrl();
    if (mounted) setState(() => _apiUrl = u);
  }

  Future<void> _signInWithGoogle() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final serverClientId = await AppConfig.getGoogleClientId();
      final gsi = GoogleSignIn(
        scopes: ['email', 'profile'],
        serverClientId: serverClientId.isEmpty ? null : serverClientId,
      );
      await gsi.signOut(); // permite elegir cuenta limpia
      final account = await gsi.signIn();
      if (account == null) {
        setState(() => _loading = false);
        return;
      }
      final auth = await account.authentication;
      final idToken = auth.idToken;
      if (idToken == null || idToken.isEmpty) {
        throw Exception(
          'Google no devolvio idToken. Configura el Web Client ID (serverClientId) en Ajustes.',
        );
      }

      await ApiService.loginWithGoogle(idToken);
      if (!mounted) return;
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(builder: (_) => const HomeScreen()),
      );
    } catch (e) {
      if (mounted) {
        setState(() => _error = e.toString());
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _openSettings() async {
    final urlCtrl = TextEditingController(text: await AppConfig.getApiUrl());
    final cidCtrl = TextEditingController(text: await AppConfig.getGoogleClientId());
    final infUrlCtrl = TextEditingController(text: await AppConfig.getInferenceUrl());
    final infKeyCtrl = TextEditingController(text: await AppConfig.getInferenceApiKey());
    if (!mounted) return;

    await showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Configuración del servidor'),
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: urlCtrl,
                decoration: const InputDecoration(
                  labelText: 'URL de la API PHP',
                  hintText: 'https://peaje.tu-dominio.com',
                ),
                keyboardType: TextInputType.url,
              ),
              const SizedBox(height: 12),
              TextField(
                controller: cidCtrl,
                decoration: const InputDecoration(
                  labelText: 'Google Web Client ID (OAuth 2.0)',
                  hintText: '123456.apps.googleusercontent.com',
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: infUrlCtrl,
                decoration: const InputDecoration(
                  labelText: 'URL Inferencia Colab/RunPod (Tracking WS)',
                  hintText: 'https://xxxx.trycloudflare.com',
                ),
                keyboardType: TextInputType.url,
              ),
              const SizedBox(height: 12),
              TextField(
                controller: infKeyCtrl,
                decoration: const InputDecoration(
                  labelText: 'API Key de Inferencia (Tracking WS)',
                  hintText: 'peaje-clave-segura-cambiar-2026',
                ),
              ),
            ],
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancelar')),
          FilledButton(
            onPressed: () async {
              await AppConfig.setApiUrl(urlCtrl.text);
              await AppConfig.setGoogleClientId(cidCtrl.text);
              await AppConfig.setInferenceUrl(infUrlCtrl.text);
              await AppConfig.setInferenceApiKey(infKeyCtrl.text);
              if (ctx.mounted) Navigator.pop(ctx);
              _loadConfig();
            },
            child: const Text('Guardar'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Control de Peaje'),
        actions: [
          IconButton(
            icon: const Icon(Icons.settings),
            tooltip: 'Configurar servidor',
            onPressed: _openSettings,
          ),
        ],
      ),
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 400),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.directions_car_filled, size: 72, color: Color(0xFF0D6EFD)),
                const SizedBox(height: 16),
                Text(
                  'Sistema Inteligente de Control de Peaje',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.bold),
                ),
                const SizedBox(height: 8),
                const Text(
                  'Acceso exclusivo con cuentas Google autorizadas.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.black54),
                ),
                const SizedBox(height: 24),
                if (_error != null)
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(12),
                    margin: const EdgeInsets.only(bottom: 16),
                    decoration: BoxDecoration(
                      color: Colors.red.shade50,
                      border: Border.all(color: Colors.red.shade300),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Text(_error!, style: TextStyle(color: Colors.red.shade900)),
                  ),
                SizedBox(
                  width: double.infinity,
                  height: 48,
                  child: FilledButton.icon(
                    onPressed: _loading ? null : _signInWithGoogle,
                    icon: _loading
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                          )
                        : const Icon(Icons.login),
                    label: Text(_loading ? 'Verificando...' : 'Iniciar sesión con Google'),
                  ),
                ),
                const SizedBox(height: 20),
                Text(
                  'Servidor: $_apiUrl',
                  style: const TextStyle(fontSize: 12, color: Colors.black45),
                  textAlign: TextAlign.center,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
