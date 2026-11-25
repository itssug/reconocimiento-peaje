import 'package:flutter/material.dart';
import 'package:google_sign_in/google_sign_in.dart';
import '../config/app_config.dart';
import '../services/api_service.dart';
import 'caseta_screen.dart';
import 'consulta_screen.dart';
import 'login_screen.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int _tab = 0;
  Map<String, String> _user = {'nombre': '', 'email': '', 'rol': ''};

  @override
  void initState() {
    super.initState();
    AppConfig.getUser().then((u) {
      if (u != null && mounted) setState(() => _user = u);
    });
  }

  Future<void> _logout() async {
    await ApiService.logout();
    try {
      await GoogleSignIn().signOut();
    } catch (_) {}
    if (!mounted) return;
    Navigator.of(context).pushReplacement(
      MaterialPageRoute(builder: (_) => const LoginScreen()),
    );
  }

  @override
  Widget build(BuildContext context) {
    final pages = const [CasetaScreen(), ConsultaScreen()];
    final isAdmin = _user['rol'] == 'ADMIN';
    return Scaffold(
      appBar: AppBar(
        toolbarHeight: 52,
        title: Row(
          children: [
            Container(
              width: 28,
              height: 28,
              decoration: BoxDecoration(
                gradient: const LinearGradient(
                  colors: [Color(0xFF0071E3), Color(0xFF42A1EC)],
                ),
                borderRadius: BorderRadius.circular(8),
              ),
              child: const Icon(Icons.camera_alt_rounded, color: Colors.white, size: 16),
            ),
            const SizedBox(width: 10),
            Text(_tab == 0 ? 'Caseta AI' : 'Agente Peaje'),
          ],
        ),
        actions: [
          if (_user['rol']!.isNotEmpty)
            Center(
              child: Container(
                margin: const EdgeInsets.only(right: 6),
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: isAdmin
                      ? const Color(0xFFFF3B30).withValues(alpha: 0.12)
                      : const Color(0xFF0071E3).withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(999),
                ),
                child: Text(
                  _user['rol']!,
                  style: TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    color: isAdmin ? const Color(0xFFD70015) : const Color(0xFF0071E3),
                  ),
                ),
              ),
            ),
          IconButton(
            icon: const Icon(Icons.logout_rounded, size: 20),
            tooltip: 'Cerrar sesión',
            onPressed: _logout,
          ),
        ],
      ),
      body: IndexedStack(index: _tab, children: pages),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: (i) => setState(() => _tab = i),
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.videocam_outlined),
            selectedIcon: Icon(Icons.videocam_rounded, color: Color(0xFF0071E3)),
            label: 'Caseta',
          ),
          NavigationDestination(
            icon: Icon(Icons.chat_bubble_outline_rounded),
            selectedIcon: Icon(Icons.chat_bubble_rounded, color: Color(0xFF0071E3)),
            label: 'Agente',
          ),
        ],
      ),
    );
  }
}
