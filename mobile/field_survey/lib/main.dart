import 'dart:async';
import 'package:flutter/material.dart';
import 'api_client.dart';
import 'field_controller.dart';
import 'local_store.dart';
import 'models.dart';
import 'survey_editor.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  try {
    final controller = FieldController(await LocalStore.open());
    await controller.initialize();
    runApp(FieldSurveyApp(controller: controller));
  } catch (_) {
    runApp(
      const MaterialApp(
        home: Scaffold(
          body: SafeArea(
            child: Padding(
              padding: EdgeInsets.all(24),
              child: Center(
                child: Text(
                  'Unable to open the phone storage. Restart the app and check available storage. Existing surveys have not been deleted.',
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class FieldSurveyApp extends StatefulWidget {
  const FieldSurveyApp({super.key, required this.controller});
  final FieldController controller;
  @override
  State<FieldSurveyApp> createState() => _FieldSurveyAppState();
}

class _FieldSurveyAppState extends State<FieldSurveyApp>
    with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    widget.controller.resumed(state == AppLifecycleState.resumed);
  }

  @override
  Widget build(BuildContext context) => MaterialApp(
    title: 'HAZECO Field Survey',
    debugShowCheckedModeBanner: false,
    theme: ThemeData(
      useMaterial3: true,
      colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xff075b71)),
      scaffoldBackgroundColor: const Color(0xfff3f6fa),
      inputDecorationTheme: const InputDecorationTheme(
        border: OutlineInputBorder(),
        filled: true,
        fillColor: Colors.white,
        contentPadding: EdgeInsets.all(14),
      ),
      appBarTheme: const AppBarTheme(
        backgroundColor: Color(0xff0b213a),
        foregroundColor: Colors.white,
      ),
      cardTheme: CardThemeData(
        elevation: 0,
        margin: const EdgeInsets.only(bottom: 12),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      ),
    ),
    home: ListenableBuilder(
      listenable: widget.controller,
      builder: (context, _) => widget.controller.session == null
          ? LoginPage(controller: widget.controller)
          : SurveyHome(controller: widget.controller),
    ),
  );
}

class LoginPage extends StatefulWidget {
  const LoginPage({super.key, required this.controller});
  final FieldController controller;
  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> {
  final url = TextEditingController(),
      email = TextEditingController(),
      password = TextEditingController();
  final form = GlobalKey<FormState>();
  String? error;
  bool hide = true;
  @override
  void initState() {
    super.initState();
    widget.controller.store.setting('last_url').then((value) {
      if (mounted && value != null) url.text = value;
    });
  }

  @override
  void dispose() {
    url.dispose();
    email.dispose();
    password.dispose();
    super.dispose();
  }

  Future<void> signIn() async {
    if (!form.currentState!.validate()) return;
    FocusScope.of(context).unfocus();
    setState(() => error = null);
    try {
      await widget.controller.login(
        normalizeServerUrl(url.text),
        email.text,
        password.text,
      );
    } on ApiFailure catch (e) {
      if (mounted) setState(() => error = e.message);
    } on FormatException catch (e) {
      if (mounted) setState(() => error = e.message);
    } on Object {
      if (mounted) {
        setState(
          () => error =
              'Unable to connect. Check the application URL and internet connection.',
        );
      }
    } finally {
      if (mounted) password.clear();
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    body: SafeArea(
      child: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 460),
            child: Form(
              key: form,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Align(
                    alignment: Alignment.centerLeft,
                    child: CircleAvatar(
                      radius: 30,
                      backgroundColor: Color(0xff0b213a),
                      child: Icon(
                        Icons.electrical_services,
                        color: Colors.white,
                        size: 32,
                      ),
                    ),
                  ),
                  const SizedBox(height: 20),
                  Text(
                    'HAZECO\nField Survey',
                    style: Theme.of(context).textTheme.headlineLarge?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  const SizedBox(height: 12),
                  const Text(
                    'Collect LT survey sheets in the field. Save offline and sync when you have a connection.',
                  ),
                  const SizedBox(height: 28),
                  TextFormField(
                    controller: url,
                    keyboardType: TextInputType.url,
                    autocorrect: false,
                    decoration: const InputDecoration(
                      labelText: 'Application URL',
                      hintText: 'https://survey.example.org',
                    ),
                    validator: (value) {
                      try {
                        normalizeServerUrl(value ?? '');
                        return null;
                      } on FormatException catch (e) {
                        return e.message;
                      }
                    },
                  ),
                  const SizedBox(height: 14),
                  TextFormField(
                    controller: email,
                    keyboardType: TextInputType.emailAddress,
                    autocorrect: false,
                    decoration: const InputDecoration(labelText: 'Email'),
                    validator: (value) => value == null || !value.contains('@')
                        ? 'Enter your account email.'
                        : null,
                  ),
                  const SizedBox(height: 14),
                  TextFormField(
                    controller: password,
                    obscureText: hide,
                    autocorrect: false,
                    enableSuggestions: false,
                    onFieldSubmitted: (_) =>
                        widget.controller.busy ? null : signIn(),
                    decoration: InputDecoration(
                      labelText: 'Password',
                      suffixIcon: IconButton(
                        onPressed: () => setState(() => hide = !hide),
                        icon: Icon(
                          hide ? Icons.visibility : Icons.visibility_off,
                        ),
                      ),
                    ),
                    validator: (value) => value == null || value.isEmpty
                        ? 'Enter your password.'
                        : null,
                  ),
                  const SizedBox(height: 18),
                  if (error != null)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 14),
                      child: Text(
                        error!,
                        style: TextStyle(
                          color: Theme.of(context).colorScheme.error,
                        ),
                      ),
                    ),
                  if (widget.controller.startupMessage.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 14),
                      child: Text(widget.controller.startupMessage),
                    ),
                  FilledButton.icon(
                    onPressed: widget.controller.busy ? null : signIn,
                    icon: widget.controller.busy
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.login),
                    label: const Padding(
                      padding: EdgeInsets.all(12),
                      child: Text('Sign in and download assignments'),
                    ),
                  ),
                  const SizedBox(height: 16),
                  Text(
                    'First sign-in needs internet and an assigned survey team. Your password is never saved.',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    ),
  );
}

const stateLabels = {
  'draft': 'On-phone draft',
  'queued': 'Waiting to sync',
  'synced': 'Synced',
  'error': 'Needs attention',
  'conflict': 'Version conflict',
};
Color stateColor(String state) => switch (state) {
  'synced' => const Color(0xff16724c),
  'error' || 'conflict' => const Color(0xffad372c),
  'queued' => const Color(0xff8b5f11),
  _ => const Color(0xff075b71),
};

class SurveyHome extends StatefulWidget {
  const SurveyHome({super.key, required this.controller});
  final FieldController controller;
  @override
  State<SurveyHome> createState() => _SurveyHomeState();
}

class _SurveyHomeState extends State<SurveyHome> {
  String filter = 'all', search = '';
  bool opening = false;
  FieldController get controller => widget.controller;
  void notice(String message) {
    if (mounted) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(message)));
    }
  }

  Future<void> refresh() async {
    try {
      await controller.refreshReference();
      notice('Assignments updated. Existing survey details are preserved.');
    } on ApiFailure catch (e) {
      notice(e.message);
    } on Object {
      notice(
        'Unable to update assignments. Last downloaded assignments remain available.',
      );
    }
  }

  Future<void> open(SurveyRecord record) async {
    if (opening || controller.busy) return;
    opening = true;
    try {
      final current = await controller.store.survey(record.scope, record.id);
      if (!mounted ||
          current == null ||
          controller.session?.scope != current.scope) {
        return;
      }
      await Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) =>
              SurveyEditor(controller: controller, record: current.clone()),
        ),
      );
      await controller.reload();
      unawaited(controller.sync(manual: false));
    } finally {
      opening = false;
    }
  }

  Future<void> create() async {
    if (opening || controller.busy) return;
    final eligible = controller.teams
        .where((team) => controller.feedersFor(team['id']).isNotEmpty)
        .toList();
    if (eligible.isEmpty) {
      notice(
        'No feeder assignments are available. Ask the administrator to assign your team.',
      );
      return;
    }
    Json selectedTeam = eligible.first;
    Json selectedFeeder = controller.feedersFor(selectedTeam['id']).first;
    final accepted = await showDialog<bool>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, update) => AlertDialog(
          title: const Text('Start a field survey'),
          content: SizedBox(
            width: 440,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                DropdownButtonFormField<int>(
                  initialValue: selectedTeam['id'],
                  isExpanded: true,
                  decoration: const InputDecoration(
                    labelText: 'Assigned survey team',
                  ),
                  items: eligible
                      .map(
                        (t) => DropdownMenuItem<int>(
                          value: t['id'],
                          child: Text(
                            t['name'],
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                      )
                      .toList(),
                  onChanged: (value) => update(() {
                    selectedTeam = eligible.firstWhere((t) => t['id'] == value);
                    selectedFeeder = controller.feedersFor(value!).first;
                  }),
                ),
                const SizedBox(height: 16),
                DropdownButtonFormField<int>(
                  key: ValueKey(selectedTeam['id']),
                  initialValue: selectedFeeder['id'],
                  isExpanded: true,
                  decoration: const InputDecoration(
                    labelText: 'Assigned feeder',
                  ),
                  items: controller
                      .feedersFor(selectedTeam['id'])
                      .map(
                        (f) => DropdownMenuItem<int>(
                          value: f['id'],
                          child: Text(
                            '${f['feeder_code']} · ${f['feeder_name']}',
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                      )
                      .toList(),
                  onChanged: (value) => update(
                    () => selectedFeeder = controller.feeders.firstWhere(
                      (f) => f['id'] == value,
                    ),
                  ),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Create survey'),
            ),
          ],
        ),
      ),
    );
    if (accepted != true || !mounted) return;
    final record = SurveyRecord.create(
      controller.session!,
      selectedTeam,
      selectedFeeder,
    );
    await controller.store.save(record);
    if (mounted) await open(record);
  }

  Future<void> signOut() async {
    final yes = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Sign out of this phone?'),
        content: const Text(
          'Saved surveys and photos stay on this phone. Sign in to the same account and application to continue them.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Sign out'),
          ),
        ],
      ),
    );
    if (yes == true) {
      try {
        await controller.logout();
      } catch (e) {
        notice(e.toString());
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final records = controller.records
        .where(
          (r) =>
              (filter == 'all' ||
                  r.state == filter ||
                  filter == 'attention' &&
                      ['error', 'conflict'].contains(r.state)) &&
              ('${r.data['transformer_code']} ${controller.feeder(r.data['feeder_id'])?['feeder_name'] ?? ''} ${r.data['survey_date']}')
                  .toLowerCase()
                  .contains(search.toLowerCase()),
        )
        .toList();
    return Scaffold(
      appBar: AppBar(
        title: const Text('HAZECO Field Survey'),
        actions: [
          IconButton(
            tooltip: 'Download assignments',
            onPressed: controller.syncing ? null : refresh,
            icon: const Icon(Icons.cloud_download_outlined),
          ),
          PopupMenuButton<String>(
            onSelected: (value) => value == 'logout' ? signOut() : refresh(),
            itemBuilder: (_) => [
              const PopupMenuItem(value: 'logout', child: Text('Sign out')),
            ],
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: create,
        icon: const Icon(Icons.add),
        label: const Text('New survey'),
      ),
      body: RefreshIndicator(
        onRefresh: controller.reload,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(18, 20, 18, 100),
          children: [
            Text(
              'Field notebook',
              style: Theme.of(
                context,
              ).textTheme.headlineMedium?.copyWith(fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 6),
            Text(
              '${controller.session!.name} · ${controller.teams.length} assigned team${controller.teams.length == 1 ? '' : 's'}',
            ),
            const SizedBox(height: 16),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const Icon(Icons.cloud_sync_outlined),
                        const SizedBox(width: 10),
                        const Expanded(
                          child: Text(
                            'Saved here. Sync when connected.',
                            style: TextStyle(fontWeight: FontWeight.w700),
                          ),
                        ),
                        if (controller.syncing)
                          const SizedBox(
                            width: 20,
                            height: 20,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    Text(
                      controller.syncMessage.isEmpty
                          ? 'Drafts and photos stay on this phone until submitted and uploaded.'
                          : controller.syncMessage,
                    ),
                    const SizedBox(height: 10),
                    FilledButton.tonalIcon(
                      onPressed: controller.syncing
                          ? null
                          : controller.needsLogin
                          ? signOut
                          : () => controller.sync(),
                      icon: Icon(
                        controller.needsLogin ? Icons.login : Icons.sync,
                      ),
                      label: Text(
                        controller.needsLogin ? 'Sign in again' : 'Sync now',
                      ),
                    ),
                    Text(
                      'Automatic retry runs while the app is open. Keep the app open until photos finish.',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
            ),
            TextField(
              onChanged: (value) => setState(() => search = value),
              decoration: const InputDecoration(
                prefixIcon: Icon(Icons.search),
                hintText: 'Find transformer, feeder or date',
              ),
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children:
                  {
                        'all': 'All',
                        'draft': 'Drafts',
                        'queued': 'Waiting',
                        'synced': 'Synced',
                        'attention': 'Attention',
                      }.entries
                      .map(
                        (entry) => FilterChip(
                          label: Text(
                            '${entry.value} (${controller.records.where((r) => entry.key == 'all' || r.state == entry.key || entry.key == 'attention' && ['error', 'conflict'].contains(r.state)).length})',
                          ),
                          selected: filter == entry.key,
                          onSelected: (_) => setState(() => filter = entry.key),
                        ),
                      )
                      .toList(),
            ),
            const SizedBox(height: 18),
            if (records.isEmpty)
              const Card(
                child: Padding(
                  padding: EdgeInsets.all(24),
                  child: Column(
                    children: [
                      Icon(
                        Icons.assignment_outlined,
                        size: 44,
                        color: Color(0xff075b71),
                      ),
                      SizedBox(height: 12),
                      Text(
                        'No surveys in this view',
                        style: TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      SizedBox(height: 8),
                      Text('Start a new survey using your assigned feeder.'),
                    ],
                  ),
                ),
              ),
            ...records.map(
              (record) => Card(
                child: ListTile(
                  contentPadding: const EdgeInsets.all(16),
                  onTap: () => open(record),
                  title: Text(
                    record.data['transformer_code'].toString().isEmpty
                        ? 'New transformer survey'
                        : record.data['transformer_code'],
                    style: const TextStyle(
                      fontWeight: FontWeight.w800,
                      fontSize: 18,
                    ),
                  ),
                  subtitle: Padding(
                    padding: const EdgeInsets.only(top: 8),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '${controller.feeder(record.data['feeder_id'])?['feeder_name'] ?? 'Assigned feeder'} · ${record.data['survey_date']}',
                        ),
                        const SizedBox(height: 6),
                        Text(
                          '${record.rows.length} S/E row${record.rows.length == 1 ? '' : 's'} · ${stateLabels[record.state] ?? record.state}',
                          style: TextStyle(
                            color: stateColor(record.state),
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                        if (record.error.isNotEmpty)
                          Padding(
                            padding: const EdgeInsets.only(top: 6),
                            child: Text(
                              record.error,
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(color: stateColor(record.state)),
                            ),
                          ),
                      ],
                    ),
                  ),
                  trailing: const Icon(Icons.chevron_right),
                ),
              ),
            ),
            if (controller.reference?['_downloaded_at'] != null)
              Text(
                'Assignments downloaded ${controller.reference!['_downloaded_at'].toString().replaceFirst('T', ' ').substring(0, 16)}',
                style: Theme.of(context).textTheme.bodySmall,
              ),
          ],
        ),
      ),
    );
  }
}
