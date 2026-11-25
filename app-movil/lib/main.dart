import 'package:flutter/material.dart';
import 'config/app_config.dart';
import 'screens/home_screen.dart';
import 'screens/login_screen.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const PeajeApp());
}

class PeajeApp extends StatelessWidget {
  const PeajeApp({super.key});

  static const appleBlue = Color(0xFF0071E3);
  static const appleBg = Color(0xFFF5F5F7);
  static const appleText = Color(0xFF1D1D1F);

  @override
  Widget build(BuildContext context) {
    final scheme = ColorScheme.fromSeed(
      seedColor: appleBlue,
      primary: appleBlue,
      surface: Colors.white,
    );
    return MaterialApp(
      title: 'Peaje AI',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorScheme: scheme,
        scaffoldBackgroundColor: appleBg,
        useMaterial3: true,
        appBarTheme: const AppBarTheme(
          backgroundColor: Colors.white,
          foregroundColor: appleText,
          elevation: 0,
          scrolledUnderElevation: 0.5,
          centerTitle: false,
          titleTextStyle: TextStyle(
            color: appleText,
            fontSize: 19,
            fontWeight: FontWeight.w700,
            letterSpacing: -0.4,
          ),
        ),
        cardTheme: CardThemeData(
          color: Colors.white,
          elevation: 0,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(20),
            side: BorderSide(color: Colors.black.withValues(alpha: 0.06)),
          ),
        ),
        navigationBarTheme: NavigationBarThemeData(
          backgroundColor: Colors.white,
          indicatorColor: appleBlue.withValues(alpha: 0.12),
          height: 64,
          labelTextStyle: WidgetStateProperty.all(
            const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
          ),
        ),
      ),
      home: FutureBuilder<String?>(
        future: AppConfig.getToken(),
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) {
            return const Scaffold(body: Center(child: CircularProgressIndicator()));
          }
          return snap.data != null ? const HomeScreen() : const LoginScreen();
        },
      ),
    );
  }
}
