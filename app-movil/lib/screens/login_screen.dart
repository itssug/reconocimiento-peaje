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
      await gsi.signOut();
      final account = await gsi.signIn();
      if (account == null) {
        setState(() => _loading = false);
        return;
      }
      final auth = await account.authentication;
      final idToken = auth.idToken;
      if (idToken == null || idToken.isEmpty) {
        throw Exception(
          'Google no devolvió idToken. Configura el Web Client ID en Ajustes.',
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
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(22)),
        title: const Text('Ajustes de Conexión', style: TextStyle(fontWeight: FontWeight.w700)),
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
                  labelText: 'API Key de Inferencia',
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
        backgroundColor: Colors.transparent,
        actions: [
          IconButton(
            icon: const Icon(Icons.tune_rounded),
            tooltip: 'Configurar servidor',
            onPressed: _openSettings,
          ),
        ],
      ),
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 380),
            child: Container(
              padding: const EdgeInsets.all(28),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(26),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withValues(alpha: 0.05),
                    blurRadius: 30,
                    offset: const Offset(0, 10),
                  ),
                ],
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Container(
                    width: 64,
                    height: 64,
                    decoration: BoxDecoration(
                      gradient: const LinearGradient(
                        colors: [Color(0xFF0071E3), Color(0xFF42A1EC)],
                        begin: Alignment.topLeft,
                        end: Alignment.bottomRight,
                      ),
                      borderRadius: BorderRadius.circular(18),
                      boxShadow: [
                        BoxShadow(
                          color: const Color(0xFF0071E3).withValues(alpha: 0.28),
                          blurRadius: 16,
                          offset: const Offset(0, 6),
                        ),
                      ],
                    ),
                    child: const Icon(Icons.camera_alt_rounded, size: 32, color: Colors.white),
                  ),
                  const SizedBox(height: 18),
                  const Text(
                    'Peaje AI',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontSize: 26,
                      fontWeight: FontWeight.w800,
                      letterSpacing: -0.6,
                      color: Color(0xFF1D1D1F),
                    ),
                  ),
                  const SizedBox(height: 6),
                  const Text(
                    'Control inteligente de peaje en tiempo real.\nAcceso exclusivo con Google.',
                    textAlign: TextAlign.center,
                    style: TextStyle(color: Color(0xFF86868B), fontSize: 13.5, height: 1.35),
                  ),
                  const SizedBox(height: 24),
                  if (_error != null)
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.all(12),
                      margin: const EdgeInsets.only(bottom: 16),
                      decoration: BoxDecoration(
                        color: const Color(0xFFFF3B30).withValues(alpha: 0.08),
                        borderRadius: BorderRadius.circular(14),
                      ),
                      child: Text(
                        _error!,
                        style: const TextStyle(color: Color(0xFFD70015), fontSize: 12.5),
                      ),
                    ),
                  SizedBox(
                    width: double.infinity,
                    height: 50,
                    child: FilledButton.icon(
                      style: FilledButton.styleFrom(
                        backgroundColor: const Color(0xFF0071E3),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(999)),
                      ),
                      onPressed: _loading ? null : _signInWithGoogle,
                      icon: _loading
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                            )
                          : const Icon(Icons.login_rounded, size: 20),
                      label: Text(
                        _loading ? 'Verificando...' : 'Continuar con Google',
                        style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600),
                      ),
                    ),
                  ),
                  const SizedBox(height: 18),
                  Text(
                    _apiUrl,
                    style: const TextStyle(fontSize: 11.5, color: Color(0xFF86868B)),
                    textAlign: TextAlign.center,
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
