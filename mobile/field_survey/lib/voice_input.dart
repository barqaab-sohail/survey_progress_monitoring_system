import 'dart:async';
import 'package:flutter/material.dart';
import 'package:speech_to_text/speech_to_text.dart';
import 'api_client.dart';
import 'field_controller.dart';
import 'voice_value.dart';

class VoiceLanguage {
  const VoiceLanguage(this.id, this.name);
  final String id, name;
}

abstract class VoiceRecognizer extends ChangeNotifier {
  String get words;
  String get error;
  bool get listening;
  List<VoiceLanguage> get languages;
  String? get defaultLanguage;
  Future<bool> initialize();
  Future<void> start(String? language);
  Future<void> stop();
  Future<void> cancel();
}

/// One recognizer per app. No microphone access until the surveyor starts voice entry.
class DeviceVoiceRecognizer extends VoiceRecognizer {
  final _speech = SpeechToText();
  Future<bool>? _initializing;
  bool _ready = false, _listening = false, _disposed = false;
  String _words = '', _error = '';
  List<VoiceLanguage> _languages = [];
  String? _defaultLanguage;
  @override
  String get words => _words;
  @override
  String get error => _error;
  @override
  bool get listening => _listening;
  @override
  List<VoiceLanguage> get languages => _languages;
  @override
  String? get defaultLanguage => _defaultLanguage;
  void _notify() {
    if (!_disposed) notifyListeners();
  }

  @override
  Future<bool> initialize() {
    if (_ready) return Future.value(true);
    return _initializing ??= _initializeDevice().whenComplete(
      () => _initializing = null,
    );
  }

  Future<bool> _initializeDevice() async {
    _ready = await _speech.initialize(
      options: [SpeechToText.androidNoBluetooth],
      onStatus: (status) {
        _listening = status == SpeechToText.listeningStatus;
        _notify();
      },
      onError: (error) {
        _listening = false;
        unawaited(_speech.cancel());
        _error = switch (error.errorMsg) {
          'error_permission' || 'error_permission_denied' =>
            'Microphone access was denied. Enable it in Android app settings to use voice entry.',
          'error_network' || 'error_network_timeout' =>
            'The speech service could not connect. Check the connection and try again.',
          'error_no_match' || 'error_speech_timeout' =>
            'No clear speech was recognized. Tap Speak again and try a short phrase.',
          _ => 'Speech recognition stopped. Try again or type the value.',
        };
        _notify();
      },
    );
    if (!_ready) return false;
    _languages = (await _speech.locales())
        .map((l) => VoiceLanguage(l.localeId, l.name))
        .toList();
    _defaultLanguage = (await _speech.systemLocale())?.localeId;
    return true;
  }

  @override
  Future<void> start(String? language) async {
    _words = '';
    _error = '';
    _listening = true;
    _notify();
    try {
      await _speech.listen(
        onResult: (result) {
          _words = result.recognizedWords;
          if (result.finalResult) {
            _listening = false;
            unawaited(_speech.stop());
          }
          _notify();
        },
        listenOptions: SpeechListenOptions(
          localeId: language,
          partialResults: true,
          cancelOnError: true,
          listenMode: ListenMode.dictation,
          listenFor: const Duration(seconds: 30),
          pauseFor: const Duration(seconds: 4),
        ),
      );
      _listening = _speech.isListening;
      _notify();
    } catch (_) {
      _listening = false;
      _notify();
      rethrow;
    }
  }

  @override
  Future<void> stop() async {
    await _speech.stop();
    _listening = false;
    _notify();
  }

  @override
  Future<void> cancel() async {
    await _speech.cancel();
    _listening = false;
    _notify();
  }

  @override
  void dispose() {
    _disposed = true;
    unawaited(_speech.cancel());
    super.dispose();
  }
}

class VoiceInputScope extends InheritedNotifier<FieldController> {
  const VoiceInputScope({
    super.key,
    required FieldController controller,
    required this.recognizer,
    required super.child,
  }) : super(notifier: controller);
  final VoiceRecognizer recognizer;
  FieldController get controller => notifier!;
  static VoiceInputScope? maybeOf(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<VoiceInputScope>();
  static bool allowed(BuildContext context) =>
      maybeOf(context)?.controller.canUseVoiceInput ?? false;
}

class VoiceInputButton extends StatefulWidget {
  const VoiceInputButton({
    super.key,
    required this.field,
    required this.onApplied,
  });
  final VoiceFieldSpec field;
  final ValueChanged<String> onApplied;
  @override
  State<VoiceInputButton> createState() => _VoiceInputButtonState();
}

class _VoiceInputButtonState extends State<VoiceInputButton> {
  bool opening = false;
  @override
  Widget build(BuildContext context) {
    final scope = VoiceInputScope.maybeOf(context);
    if (scope == null || !scope.controller.canUseVoiceInput) {
      return const SizedBox.shrink();
    }
    return IconButton(
      tooltip: 'Speak ${widget.field.label}',
      icon: const Icon(Icons.mic_none),
      onPressed: opening
          ? null
          : () async {
              setState(() => opening = true);
              FocusScope.of(context).unfocus();
              final session = scope.controller.session;
              try {
                final value = await showDialog<String>(
                  context: context,
                  barrierDismissible: false,
                  builder: (_) => VoiceEntryDialog(
                    controller: scope.controller,
                    recognizer: scope.recognizer,
                    field: widget.field,
                  ),
                );
                if (mounted &&
                    value != null &&
                    scope.controller.canUseVoiceInput &&
                    scope.controller.session?.scope == session?.scope &&
                    scope.controller.session?.token == session?.token) {
                  widget.onApplied(value);
                }
              } finally {
                if (mounted) setState(() => opening = false);
              }
            },
    );
  }
}

class VoiceEntryDialog extends StatefulWidget {
  const VoiceEntryDialog({
    super.key,
    required this.controller,
    required this.recognizer,
    required this.field,
  });
  final FieldController controller;
  final VoiceRecognizer recognizer;
  final VoiceFieldSpec field;
  @override
  State<VoiceEntryDialog> createState() => _VoiceEntryDialogState();
}

class _VoiceEntryDialogState extends State<VoiceEntryDialog>
    with WidgetsBindingObserver {
  final draft = TextEditingController();
  bool working = true, available = false, blocked = false, suspended = false;
  String message = '', lastWords = '';
  String? language;
  late final String? sessionToken = widget.controller.session?.token;
  VoiceRecognizer get recognizer => widget.recognizer;
  bool get permitted =>
      !blocked &&
      widget.controller.canUseVoiceInput &&
      widget.controller.session?.token == sessionToken;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    widget.controller.addListener(accessChanged);
    recognizer.addListener(recognized);
    unawaited(prepare());
  }

  void accessChanged() {
    if (mounted && !permitted) {
      unawaited(recognizer.cancel());
      setState(() {
        blocked = true;
        message =
            'Voice access is no longer enabled for this account. Close this window and refresh your permissions.';
      });
    }
  }

  void recognized() {
    if (!mounted) return;
    if (recognizer.words != lastWords) {
      lastWords = recognizer.words;
      draft.text = widget.field.draft(lastWords);
    }
    setState(() {});
  }

  String failure(Object error) => error is ApiFailure
      ? error.message
      : 'Voice entry could not connect. Check your internet connection or enter the value by typing.';

  Future<void> prepare() async {
    try {
      await widget.controller.authorizeVoiceInput();
      if (!mounted || !permitted || suspended) return;
      available = await recognizer.initialize();
      if (!mounted || !permitted || suspended) return;
      if (!available) {
        setState(
          () => message =
              'Speech recognition is unavailable or microphone permission was denied. Check the phone’s speech service and microphone settings.',
        );
        return;
      }
      final saved = await widget.controller.store.setting('voice_language');
      if (!mounted || !permitted || suspended) return;
      language = recognizer.languages.any((l) => l.id == saved)
          ? saved
          : recognizer.defaultLanguage;
      if (!recognizer.languages.any((l) => l.id == language)) {
        language = recognizer.languages.firstOrNull?.id;
      }
      await recognizer.start(language);
    } catch (error) {
      if (mounted) setState(() => message = failure(error));
    } finally {
      if (mounted) setState(() => working = false);
    }
  }

  Future<void> listen() async {
    if (working || !permitted || suspended) return;
    if (!available) {
      setState(() {
        working = true;
        message = '';
      });
      await prepare();
      return;
    }
    setState(() {
      working = true;
      message = '';
    });
    try {
      await widget.controller.authorizeVoiceInput();
      if (!mounted || !permitted || suspended) return;
      await recognizer.start(language);
    } catch (error) {
      if (mounted) setState(() => message = failure(error));
    } finally {
      if (mounted) setState(() => working = false);
    }
  }

  Future<void> apply() async {
    final value = draft.text.trim();
    if (!permitted ||
        working ||
        recognizer.listening ||
        widget.field.error(value) != null) {
      return;
    }
    setState(() => working = true);
    try {
      await widget.controller.authorizeVoiceInput();
      if (mounted && permitted && !suspended) Navigator.pop(context, value);
    } catch (error) {
      if (mounted) setState(() => message = failure(error));
    } finally {
      if (mounted) setState(() => working = false);
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    suspended =
        state == AppLifecycleState.paused ||
        state == AppLifecycleState.hidden ||
        state == AppLifecycleState.detached;
    if (state != AppLifecycleState.resumed && recognizer.listening) {
      unawaited(recognizer.cancel());
    }
  }

  @override
  void dispose() {
    widget.controller.removeListener(accessChanged);
    recognizer.removeListener(recognized);
    WidgetsBinding.instance.removeObserver(this);
    unawaited(recognizer.cancel());
    draft.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final field = widget.field;
    final error = draft.text.isEmpty ? null : field.error(draft.text.trim());
    return AlertDialog(
      title: Text('Voice entry: ${field.label}'),
      content: SizedBox(
        width: 440,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text(
                'Speak the value for this field, then review and correct it before applying. The phone’s speech service may require internet.',
              ),
              const SizedBox(height: 12),
              if (recognizer.languages.isNotEmpty)
                DropdownButtonFormField<String>(
                  initialValue: language,
                  isExpanded: true,
                  decoration: const InputDecoration(
                    labelText: 'Speech language',
                  ),
                  items: recognizer.languages
                      .map(
                        (l) =>
                            DropdownMenuItem(value: l.id, child: Text(l.name)),
                      )
                      .toList(),
                  onChanged: working || recognizer.listening || !permitted
                      ? null
                      : (value) {
                          setState(() => language = value);
                          if (value != null) {
                            unawaited(
                              widget.controller.store.setSetting(
                                'voice_language',
                                value,
                              ),
                            );
                          }
                        },
                ),
              const SizedBox(height: 12),
              if (working) const LinearProgressIndicator(),
              if (recognizer.listening)
                const Text(
                  'Listening… Speak clearly, then tap Stop.',
                  style: TextStyle(fontWeight: FontWeight.bold),
                ),
              if (message.isNotEmpty || recognizer.error.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 8),
                  child: Text(
                    message.isNotEmpty ? message : recognizer.error,
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.error,
                    ),
                  ),
                ),
              if (lastWords.isNotEmpty) ...[
                const SizedBox(height: 10),
                Text('Heard: $lastWords'),
              ],
              const SizedBox(height: 12),
              if (field.kind == VoiceValueKind.choice)
                DropdownButtonFormField<String>(
                  key: ValueKey(draft.text),
                  initialValue: field.choices.containsKey(draft.text)
                      ? draft.text
                      : null,
                  isExpanded: true,
                  decoration: const InputDecoration(
                    labelText: 'Value to apply',
                    helperText: 'Check or choose the intended option.',
                  ),
                  items: field.choices.entries
                      .where((e) => e.key.isNotEmpty)
                      .map(
                        (e) => DropdownMenuItem(
                          value: e.key,
                          child: Text(e.value),
                        ),
                      )
                      .toList(),
                  onChanged: recognizer.listening || working || !permitted
                      ? null
                      : (value) => setState(() => draft.text = value ?? ''),
                )
              else
                TextField(
                  controller: draft,
                  enabled: !recognizer.listening && !working && permitted,
                  maxLines: field.kind == VoiceValueKind.text ? 3 : 1,
                  decoration: InputDecoration(
                    labelText: 'Value to apply',
                    errorText: error,
                    helperText: field.kind == VoiceValueKind.date
                        ? 'Speak six digits in DDMMYY order.'
                        : 'Check every digit in codes and numbers.',
                  ),
                  onChanged: (_) => setState(() {}),
                ),
              const SizedBox(height: 12),
              OutlinedButton.icon(
                onPressed: working || !permitted
                    ? null
                    : recognizer.listening
                    ? () async {
                        await recognizer.stop();
                      }
                    : listen,
                icon: Icon(recognizer.listening ? Icons.stop : Icons.mic_none),
                label: Text(recognizer.listening ? 'Stop' : 'Speak again'),
              ),
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed:
              working ||
                  recognizer.listening ||
                  !permitted ||
                  field.error(draft.text.trim()) != null
              ? null
              : apply,
          child: const Text('Use value'),
        ),
      ],
    );
  }
}
