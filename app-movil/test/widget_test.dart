import 'package:flutter_test/flutter_test.dart';
import 'package:peaje_movil/main.dart';

void main() {
  testWidgets('Renderiza pantalla inicial de PeajeApp', (WidgetTester tester) async {
    await tester.pumpWidget(const PeajeApp());
    expect(find.byType(PeajeApp), findsOneWidget);
  });
}
