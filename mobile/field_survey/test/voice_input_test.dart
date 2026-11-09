import 'dart:convert';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:sqflite/sqflite.dart';
import 'package:hazeco_field_survey/api_client.dart';
import 'package:hazeco_field_survey/field_controller.dart';
import 'package:hazeco_field_survey/local_store.dart';
import 'package:hazeco_field_survey/models.dart';
import 'package:hazeco_field_survey/survey_editor.dart';
import 'package:hazeco_field_survey/voice_input.dart';
import 'package:hazeco_field_survey/voice_value.dart';

class _UnusedDatabase implements Database {
  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _VoiceStore extends LocalStore {
  _VoiceStore() : super(_UnusedDatabase());
  Json? cached;
  @override
  Future<String?> setting(String key) async => null;
  @override
  Future<void> setSetting(String key, String? value) async {}
  @override
  Future<void> cacheBootstrap(String scope, Json data) async {
    cached = copyJson(data);
  }
}

class _Recognizer extends VoiceRecognizer {
  @override
  String words = '', error = '';
  @override
  bool listening = false;
  bool supported = true;
  int initialized = 0, starts = 0;
  @override
  List<VoiceLanguage> get languages => [
    const VoiceLanguage('en_PK', 'English'),
    const VoiceLanguage('ur_PK', 'Urdu'),
  ];
  @override
  String get defaultLanguage => 'en_PK';
  @override
  Future<bool> initialize() async {
    initialized++;
    return supported;
  }

  @override
  Future<void> start(String? language) async {
    starts++;
    words = '';
    listening = true;
    notifyListeners();
  }

  void result(String text, {bool finalResult = true}) {
    words = text;
    listening = !finalResult;
    notifyListeners();
  }

  @override
  Future<void> stop() async {
    listening = false;
    notifyListeners();
  }

  @override
  Future<void> cancel() async {
    listening = false;
    notifyListeners();
  }
}

class _VoiceController extends FieldController {
  _VoiceController(super.store, {required super.api});
  void changeAccount() {
    session = null;
    notifyListeners();
  }
}

void main() {
  test('speech numbers preserve identifiers and validate numeric drafts', () {
    expect(spokenNumber('zero zero eight'), '008');
    expect(spokenNumber('۰۰۸'), '008');
    expect(spokenNumber('٠٠٨'), '008');
    expect(spokenNumber('one hundred and fifty'), '150');
    expect(spokenNumber('minus thirty five point two'), '-35.2');
    expect(spokenNumber('one transformer'), isNull);
    expect(spokenNumber('and'), isNull);
    const digits = VoiceFieldSpec(
      label: 'Waypoint',
      kind: VoiceValueKind.digits,
    );
    expect(digits.error(digits.draft('one transformer')), isNotNull);
    expect(digits.draft('zero zero eight'), '008');
    const integer = VoiceFieldSpec(
      label: 'Customers',
      kind: VoiceValueKind.integer,
    );
    expect(integer.error('1.5'), isNotNull);
    expect(integer.error('-2'), isNotNull);
  });
  test(
    'choices match available codes and names and reject unknown or ambiguous speech',
    () {
      const field = VoiceFieldSpec(
        label: 'Conductor',
        kind: VoiceValueKind.choice,
        choices: {'A': 'A — Ant', 'W': 'W — Wasp'},
      );
      expect(field.draft('wasp'), 'W');
      expect(field.draft('A'), 'A');
      expect(field.draft('unknown conductor'), '');
      const ambiguous = VoiceFieldSpec(
        label: 'Choice',
        kind: VoiceValueKind.choice,
        choices: {'A': 'Same', 'B': 'Same'},
      );
      expect(ambiguous.draft('same'), '');
    },
  );

  late _VoiceController controller;
  late _Recognizer recognizer;
  late _VoiceStore store;
  var allowed = true, online = true, authorizations = 0;
  setUp(() {
    allowed = true;
    online = true;
    authorizations = 0;
    store = _VoiceStore();
    controller =
        _VoiceController(
            store,
            api: FieldApi(
              client: MockClient((request) async {
                expect(request.url.path, '/api/v1/field/voice/authorize');
                expect(request.method, 'POST');
                expect(request.headers['Authorization'], 'Bearer test-token');
                authorizations++;
                if (!online) throw const SocketException('Offline');
                return http.Response(
                  jsonEncode(
                    allowed
                        ? {'voice_form_filling': true}
                        : {'message': 'Voice permission revoked'},
                  ),
                  allowed ? 200 : 403,
                );
              }),
            ),
          )
          ..session = const FieldSession(
            url: 'https://example.test',
            token: 'test-token',
            userId: 1,
            name: 'Surveyor',
          )
          ..reference = {
            'capabilities': {'voice_form_filling': true},
          };
    recognizer = _Recognizer();
  });
  tearDown(() {
    controller.dispose();
    recognizer.dispose();
  });

  Widget app(Widget child) => MaterialApp(
    builder: (context, child) => VoiceInputScope(
      controller: controller,
      recognizer: recognizer,
      child: child!,
    ),
    home: Scaffold(body: child),
  );
  Widget entry(ValueChanged<String> changed, {bool readOnly = false}) =>
      EntryField(
        label: 'Waypoint',
        value: '003',
        enabled: true,
        readOnly: readOnly,
        digits: true,
        onChanged: changed,
      );
  Future<void> open(WidgetTester tester) async {
    await tester.tap(find.byTooltip('Speak Waypoint'));
    await tester.pumpAndSettle();
  }

  Future<void> close(WidgetTester tester) async {
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pumpAndSettle();
  }

  testWidgets(
    'voice is hidden without explicit permission and on read-only fields',
    (tester) async {
      controller.reference = {};
      await tester.pumpWidget(app(entry((_) {})));
      expect(find.byIcon(Icons.mic_none), findsNothing);
      controller.reference = {
        'capabilities': {'voice_form_filling': true},
      };
      await tester.pumpWidget(app(entry((_) {}, readOnly: true)));
      expect(find.byIcon(Icons.mic_none), findsNothing);
      expect(recognizer.initialized, 0);
      await close(tester);
    },
  );
  testWidgets(
    'review and correction apply only the selected field through its normal callback',
    (tester) async {
      final changes = <String>[];
      await tester.pumpWidget(app(entry(changes.add)));
      await open(tester);
      expect(recognizer.listening, true);
      recognizer.result('zero zero eight');
      await tester.pumpAndSettle();
      expect(changes, isEmpty);
      expect(find.text('008'), findsOneWidget);
      await tester.enterText(find.byType(TextField).last, '009');
      await tester.pump();
      await tester.tap(find.text('Use value'));
      await tester.pumpAndSettle();
      expect(changes, ['009']);
      expect(authorizations, 2);
      expect(find.text('009'), findsOneWidget);
      await close(tester);
    },
  );
  testWidgets('cancel preserves existing values and stops listening', (
    tester,
  ) async {
    final changes = <String>[];
    await tester.pumpWidget(app(entry(changes.add)));
    await open(tester);
    recognizer.result('zero zero eight', finalResult: false);
    await tester.pump();
    await tester.tap(find.text('Cancel'));
    await tester.pumpAndSettle();
    expect(changes, isEmpty);
    expect(find.text('003'), findsOneWidget);
    expect(recognizer.listening, false);
    await close(tester);
  });
  testWidgets(
    'revoked cached permission cannot start microphone or apply a draft',
    (tester) async {
      allowed = false;
      await tester.pumpWidget(app(entry((_) => fail('Must not apply'))));
      await open(tester);
      expect(recognizer.initialized, 0);
      expect(controller.canUseVoiceInput, false);
      expect(store.cached?['capabilities']['voice_form_filling'], false);
      await close(tester);
    },
  );
  testWidgets('revocation while reviewing prevents applying', (tester) async {
    final changes = <String>[];
    await tester.pumpWidget(app(entry(changes.add)));
    await open(tester);
    recognizer.result('eight');
    await tester.pumpAndSettle();
    allowed = false;
    await tester.tap(find.text('Use value'));
    await tester.pumpAndSettle();
    expect(changes, isEmpty);
    expect(controller.canUseVoiceInput, false);
    await close(tester);
  });
  testWidgets(
    'offline permission check leaves microphone unused and manual entry intact',
    (tester) async {
      online = false;
      await tester.pumpWidget(app(entry((_) => fail('Must not apply'))));
      await open(tester);
      expect(recognizer.initialized, 0);
      expect(
        find.textContaining('Check your internet connection'),
        findsOneWidget,
      );
      await tester.tap(find.text('Cancel'));
      await tester.pumpAndSettle();
      expect(find.text('003'), findsOneWidget);
      await close(tester);
    },
  );
  testWidgets(
    'unavailable recognition remains recoverable without modifying fields',
    (tester) async {
      recognizer.supported = false;
      await tester.pumpWidget(app(entry((_) => fail('Must not apply'))));
      await open(tester);
      expect(recognizer.starts, 0);
      expect(
        find.textContaining('Speech recognition is unavailable'),
        findsOneWidget,
      );
      await close(tester);
    },
  );
  testWidgets('leaving the foreground or account stops voice capture', (
    tester,
  ) async {
    await tester.pumpWidget(app(entry((_) => fail('Must not apply'))));
    await open(tester);
    expect(recognizer.listening, true);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    await tester.pump();
    expect(recognizer.listening, false);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pump();
    controller.changeAccount();
    await tester.pumpAndSettle();
    expect(
      find.textContaining('Voice access is no longer enabled'),
      findsOneWidget,
    );
    expect(
      tester
          .widget<FilledButton>(find.widgetWithText(FilledButton, 'Use value'))
          .onPressed,
      isNull,
    );
    await close(tester);
  });
  testWidgets(
    'voice dates use DDMMYY and retain the existing date validation path',
    (tester) async {
      String? saved;
      await tester.pumpWidget(
        app(
          DateEntry(
            label: 'Survey date',
            value: '2020-01-01',
            enabled: true,
            onChanged: (value) => saved = value,
          ),
        ),
      );
      await tester.tap(find.byTooltip('Speak Survey date (DDMMYY)'));
      await tester.pumpAndSettle();
      recognizer.result('zero two zero one two zero');
      await tester.pumpAndSettle();
      expect(saved, isNull);
      await tester.tap(find.text('Use value'));
      await tester.pumpAndSettle();
      expect(saved, '2020-01-02');
      await close(tester);
    },
  );
  testWidgets('dropdown voice selects an available option after review', (
    tester,
  ) async {
    var choice = 'A';
    await tester.pumpWidget(
      app(
        StatefulBuilder(
          builder: (context, setState) => ChoiceField(
            label: 'Conductor',
            value: choice,
            enabled: true,
            values: const ['A', 'W'],
            labels: const {'A': 'A — Ant', 'W': 'W — Wasp'},
            onChanged: (value) => setState(() => choice = value),
          ),
        ),
      ),
    );
    await tester.tap(find.byTooltip('Speak Conductor'));
    await tester.pumpAndSettle();
    recognizer.result('wasp');
    await tester.pumpAndSettle();
    expect(choice, 'A');
    await tester.tap(find.text('Use value'));
    await tester.pumpAndSettle();
    expect(choice, 'W');
    expect(find.text('W — Wasp'), findsOneWidget);
    await close(tester);
  });
}
