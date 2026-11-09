enum VoiceValueKind { text, number, integer, digits, date, choice }

/// Only prepares a draft. The surveyor must review it before applying it.
class VoiceFieldSpec {
  const VoiceFieldSpec({
    required this.label,
    this.kind = VoiceValueKind.text,
    this.choices = const {},
    this.maxLength,
    this.validate,
  });

  final String label;
  final VoiceValueKind kind;
  final Map<String, String> choices;
  final int? maxLength;
  final String? Function(String)? validate;

  String draft(String speech) {
    final text = speech.trim();
    if (kind == VoiceValueKind.text) return text;
    if (kind == VoiceValueKind.choice) return matchChoice(text) ?? '';
    return spokenNumber(text) ?? text;
  }

  String? matchChoice(String speech) {
    String key(String text) =>
        text.toLowerCase().replaceAll(RegExp(r'[^a-z0-9\u0600-\u06ff]'), '');
    final query = key(speech);
    if (query.isEmpty) return null;
    final matches = <String>{};
    for (final entry in choices.entries) {
      if (entry.key.isEmpty) continue;
      final label = entry.value;
      final aliases = {entry.key, label};
      // "A — Ant" can be spoken as either "A" or "Ant".
      final parts = label.split(RegExp(r'\s+[—–-]\s+'));
      if (parts.length == 2) aliases.add(parts.last);
      if (aliases.any((alias) => key(alias) == query)) matches.add(entry.key);
    }
    return matches.length == 1 ? matches.single : null;
  }

  String? error(String value) {
    if (value.trim().isEmpty) return 'Enter or select a value to apply.';
    if (maxLength != null && value.length > maxLength!) {
      return 'Use at most $maxLength characters.';
    }
    if (kind == VoiceValueKind.choice && !choices.containsKey(value)) {
      return 'Choose one of the available options.';
    }
    if ([
          VoiceValueKind.digits,
          VoiceValueKind.integer,
          VoiceValueKind.date,
        ].contains(kind) &&
        !RegExp(r'^\d+$').hasMatch(value)) {
      return 'Check the value and use digits only.';
    }
    if (kind == VoiceValueKind.date && value.length != 6) {
      return 'Use six digits: day, month, year (DDMMYY).';
    }
    if (kind == VoiceValueKind.number &&
        (!RegExp(r'^-?\d+(?:\.\d+)?$').hasMatch(value) ||
            !(num.tryParse(value)?.isFinite ?? false))) {
      return 'Check the value and enter a valid number.';
    }
    return validate?.call(value);
  }
}

const _digits = {
  'zero': '0',
  'oh': '0',
  'one': '1',
  'two': '2',
  'three': '3',
  'four': '4',
  'five': '5',
  'six': '6',
  'seven': '7',
  'eight': '8',
  'nine': '9',
};
const _numbers = {
  'zero': 0,
  'one': 1,
  'two': 2,
  'three': 3,
  'four': 4,
  'five': 5,
  'six': 6,
  'seven': 7,
  'eight': 8,
  'nine': 9,
  'ten': 10,
  'eleven': 11,
  'twelve': 12,
  'thirteen': 13,
  'fourteen': 14,
  'fifteen': 15,
  'sixteen': 16,
  'seventeen': 17,
  'eighteen': 18,
  'nineteen': 19,
  'twenty': 20,
  'thirty': 30,
  'forty': 40,
  'fifty': 50,
  'sixty': 60,
  'seventy': 70,
  'eighty': 80,
  'ninety': 90,
};

/// Preserves digit sequences ("zero zero eight" -> "008"). Unrecognized
/// phrases remain available for manual correction instead of dropping words.
String? spokenNumber(String speech) {
  var text = speech.trim().toLowerCase();
  for (var i = 0; i < 10; i++) {
    text = text
        .replaceAll('٠١٢٣٤٥٦٧٨٩'[i], '$i')
        .replaceAll('۰۱۲۳۴۵۶۷۸۹'[i], '$i');
  }
  text = text.replaceAll('٫', '.');
  if (RegExp(r'^-?\d{1,3}(?:,\d{3})+(?:\.\d+)?$').hasMatch(text)) {
    text = text.replaceAll(',', '');
  }
  if (RegExp(r'^-?\d+(?:\.\d+)?$').hasMatch(text)) return text;
  var negative = false;
  if (text.startsWith('minus ') || text.startsWith('negative ')) {
    negative = true;
    text = text.substring(text.indexOf(' ') + 1);
  }
  final sections = text.split(RegExp(r'\s+(?:point|decimal)\s+'));
  if (sections.length > 2) return null;
  final tokens = sections.first.replaceAll('-', ' ').split(RegExp(r'\s+'));
  if (tokens.every((token) => token == 'and')) return null;
  String? digitSequence(List<String> words) {
    final values = <String>[];
    for (final word in words) {
      final digit =
          _digits[word] ?? (RegExp(r'^\d+$').hasMatch(word) ? word : null);
      if (digit == null) return null;
      values.add(digit);
    }
    return values.join();
  }

  var whole = digitSequence(tokens);
  if (whole == null) {
    var total = 0, group = 0;
    for (final token in tokens) {
      if (token == 'and') continue;
      final value = _numbers[token];
      if (value != null) {
        group += value;
      } else if (token == 'hundred') {
        group = (group == 0 ? 1 : group) * 100;
      } else if (token == 'thousand' || token == 'million') {
        total +=
            (group == 0 ? 1 : group) * (token == 'thousand' ? 1000 : 1000000);
        group = 0;
      } else {
        return null;
      }
    }
    whole = '${total + group}';
  }
  String fraction = '';
  if (sections.length == 2) {
    final decimal = digitSequence(sections.last.split(RegExp(r'\s+')));
    if (decimal == null || decimal.isEmpty) return null;
    fraction = '.$decimal';
  }
  return '${negative ? '-' : ''}$whole$fraction';
}
