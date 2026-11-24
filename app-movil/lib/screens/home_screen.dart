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
    return Scaffold(
      appBar: AppBar(
        title: Text(_tab == 0 ? 'Modo Caseta' : 'Modo Consulta'),
        actions: [
          if (_user['rol']!.isNotEmpty)
            Center(
              child: Padding(
                padding: const EdgeInsets.only(right: 8),
                child: Chip(
                  label: Text(
                    _user['rol']!,
                    style: const TextStyle(fontSize: 11, color: Colors.white),
                  ),
                  backgroundColor: _user['rol'] == 'ADMIN' ? Colors.red.shade700 : Colors.blue.shade700,
                  visualDensity: VisualDensity.compact,
                ),
              ),
            ),
          IconButton(
            icon: const Icon(Icons.logout),
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
            selectedIcon: Icon(Icons.videocam),
            label: 'Modo Caseta',
          ),
          NavigationDestination(
            icon: Icon(Icons.chat_outlined),
            selectedIcon: Icon(Icons.chat),
            label: 'Modo Consulta',
          ),
        ],
      ),
    );
  }
}
