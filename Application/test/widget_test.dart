import 'package:flutter_test/flutter_test.dart';

import 'package:eaes_scanner/app/eaes_app.dart';

void main() {
  testWidgets('shows scanner home shell', (WidgetTester tester) async {
    await tester.pumpWidget(const EaesApp());

    expect(find.text('USM EAES Scanner'), findsOneWidget);
    expect(find.text('No Active Session'), findsOneWidget);
  });
}
