import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hazeco_field_survey/survey_editor.dart';

void main() {
  testWidgets('conductor shortcuts select the approved code in either case', (
    tester,
  ) async {
    var selected = '';
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: StatefulBuilder(
            builder: (context, update) => ConductorEntry(
              label: 'R conductor',
              value: selected,
              enabled: true,
              options: const {'ANT': 'ANT', 'GNAT': 'GNAT', 'WASP': 'WASP'},
              onChanged: (value) => update(() => selected = value),
            ),
          ),
        ),
      ),
    );
    for (final entry in {'a': 'ANT', 'G': 'GNAT', 'w': 'WASP'}.entries) {
      await tester.enterText(
        find.byKey(const ValueKey('R conductor-conductor')),
        entry.key,
      );
      await tester.pump();
      expect(selected, entry.value);
      expect(find.textContaining('Quick select'), findsNothing);
    }
    await tester.tap(find.byTooltip('Choose conductor'));
    await tester.pumpAndSettle();
    final items = tester.widgetList<ListTile>(find.byType(ListTile)).toList();
    expect(items.take(3).map((item) => (item.title as Text).data).toList(), [
      'ANT',
      'WASP',
      'GNAT',
    ]);
    await tester.tap(find.widgetWithText(ListTile, 'GNAT'));
    await tester.pumpAndSettle();
    expect(selected, 'GNAT');
  });
}
