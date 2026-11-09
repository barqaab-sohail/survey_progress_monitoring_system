import 'feeder_picker.dart';
import 'dart:async';
import 'package:flutter/material.dart';
import 'api_client.dart';
import 'field_controller.dart';
import 'local_store.dart';
import 'models.dart';
import 'survey_editor.dart';
import 'ht_update_page.dart';
import 'sign_in_error.dart';
import 'voice_input.dart';

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
  final voiceRecognizer = DeviceVoiceRecognizer();
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    voiceRecognizer.dispose();
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
    builder: (context, child) => VoiceInputScope(
      controller: widget.controller,
      recognizer: voiceRecognizer,
      child: child!,
    ),
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
  final email = TextEditingController(), password = TextEditingController();
  final form = GlobalKey<FormState>();
  String? error;
  bool hide = true,
      rememberCredentials = false,
      loadingRememberedCredentials = true;

  @override
  void initState() {
    super.initState();
    unawaited(loadRememberedCredentials());
  }

  Future<void> loadRememberedCredentials() async {
    try {
      final saved = await widget.controller.rememberedCredentials();
      if (mounted && saved != null && email.text.isEmpty) {
        email.text = saved['email']!;
        password.text = saved['password']!;
        rememberCredentials = true;
      }
    } catch (_) {
      // Sign-in remains available if secure credential storage is unavailable.
    } finally {
      if (mounted) setState(() => loadingRememberedCredentials = false);
    }
  }

  @override
  void dispose() {
    email.dispose();
    password.dispose();
    super.dispose();
  }

  Future<void> signIn() async {
    if (widget.controller.busy || loadingRememberedCredentials) return;
    if (!form.currentState!.validate()) return;
    FocusScope.of(context).unfocus();
    setState(() => error = null);
    try {
      await widget.controller.login(
        email.text,
        password.text,
        rememberCredentials: rememberCredentials,
      );
    } on Object catch (e) {
      if (mounted) setState(() => error = signInErrorMessage(e));
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
                    'Collect HT Network Updates and LT Transformer Surveys. Save offline and sync when connected.',
                  ),
                  const SizedBox(height: 28),
                  TextFormField(
                    controller: email,
                    keyboardType: TextInputType.emailAddress,
                    autofillHints: const [AutofillHints.username],
                    textCapitalization: TextCapitalization.none,
                    enableSuggestions: false,
                    autocorrect: false,
                    decoration: const InputDecoration(labelText: 'Email'),
                    validator: (value) => value == null || !value.contains('@')
                        ? 'Enter your account email.'
                        : null,
                  ),
                  const SizedBox(height: 14),
                  TextFormField(
                    controller: password,
                    autofillHints: const [AutofillHints.password],
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
                  CheckboxListTile(
                    contentPadding: EdgeInsets.zero,
                    controlAffinity: ListTileControlAffinity.leading,
                    value: rememberCredentials,
                    onChanged:
                        widget.controller.busy || loadingRememberedCredentials
                        ? null
                        : (value) => setState(
                            () => rememberCredentials = value ?? false,
                          ),
                    title: const Text(
                      'Remember email and password on this phone',
                    ),
                  ),
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
                    onPressed:
                        widget.controller.busy || loadingRememberedCredentials
                        ? null
                        : signIn,
                    icon: widget.controller.busy
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.login),
                    label: const Padding(
                      padding: EdgeInsets.all(12),
                      child: Text('Sign in and download feeder data'),
                    ),
                  ),
                  const SizedBox(height: 16),
                  Text(
                    'First sign-in needs internet and an assigned survey team. Credentials are saved securely only when Remember is selected.',
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
    var createInSameFeeder = false;
    try {
      final current = await controller.store.survey(record.scope, record.id);
      if (!mounted ||
          current == null ||
          controller.session?.scope != current.scope) {
        return;
      }
      createInSameFeeder =
          await Navigator.of(context).push<bool>(
            MaterialPageRoute(
              builder: (_) =>
                  SurveyEditor(controller: controller, record: current.clone()),
            ),
          ) ??
          false;
    } finally {
      opening = false;
    }
    if (!mounted) return;
    await controller.reload();
    if (controller.autoSync) unawaited(controller.sync(manual: false));
    if (createInSameFeeder) {
      await createFor(record.data['survey_team_id'], record.data['feeder_id']);
    }
  }

  Future<void> deleteSurvey(SurveyRecord record) async {
    if (controller.syncing || controller.busy) {
      notice('Wait for sync to finish before deleting a survey.');
      return;
    }
    final accepted = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Delete this survey from this phone?'),
        content: const Text(
          'This permanently removes the unsynced survey and its attached photos from this phone. A synced survey cannot be deleted here.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Keep survey'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Delete survey'),
          ),
        ],
      ),
    );
    if (accepted != true || !mounted) return;
    try {
      await controller.deleteUnsentSurvey(record);
      notice('Survey and its local photos were deleted.');
    } catch (error) {
      notice(error.toString());
    }
  }

  Future<void> editSurvey(SurveyRecord record) async {
    if (record.revision > 0 || record.state == 'synced') {
      notice('Synced surveys cannot be edited on this phone.');
      return;
    }
    if (record.state == 'conflict') {
      notice(
        'This survey conflicts with a newer server revision. Ask the administrator to reconcile it before editing.',
      );
      return;
    }
    if (record.state == 'queued' || record.outbound != null) {
      if (controller.syncing || controller.busy) {
        notice('Wait for sync to finish before editing this survey.');
        return;
      }
      try {
        await controller.editWaitingSurvey(record);
      } catch (error) {
        notice(error.toString());
        return;
      }
    }
    final current = await controller.store.survey(record.scope, record.id);
    if (current != null && mounted) await open(current);
  }

  Future<void> create() async {
    if (opening || controller.busy) return;
    final eligibleTeams = controller.teams
        .where((team) => teamGroupIdentifier(team) != null)
        .toList();
    final teamsByFeeder = <String, List<Json>>{
      for (final feeder in controller.feeders)
        '${feeder['id']}': eligibleTeams
            .where(
              (team) => (feeder['team_ids'] as List? ?? const []).any(
                (id) => '$id' == '${team['id']}',
              ),
            )
            .toList(),
    };
    List<Json> teamsFor(Json feeder) => teamsByFeeder['${feeder['id']}'] ?? [];
    final availableFeeders = controller.feeders
        .where((feeder) => teamsFor(feeder).isNotEmpty)
        .toList();
    if (availableFeeders.isEmpty) {
      notice(
        'No feeder data is available. Download feeder data or contact the administrator.',
      );
      return;
    }
    Json selectedFeeder = availableFeeders.first;
    final initialTeams = teamsFor(selectedFeeder);
    Json selectedTeam = surveyTeamForUser(controller.reference, initialTeams)!;
    if (controller.syncing) {
      notice('Wait for sync to finish before starting a survey.');
      return;
    }
    final accepted = await showDialog<bool>(
      context: context,
      builder: (context) => StatefulBuilder(
        builder: (context, update) => AlertDialog(
          title: const Text('Start LT Transformer Survey'),
          content: SizedBox(
            width: 440,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                FeederPicker(
                  key: const ValueKey('all-available-feeders'),
                  selectedId: selectedFeeder['id'],
                  feeders: availableFeeders,
                  onSelected: (value) => update(() {
                    selectedFeeder = availableFeeders.firstWhere(
                      (feeder) => '${feeder['id']}' == '$value',
                    );
                    selectedTeam = surveyTeamForUser(
                      controller.reference,
                      teamsFor(selectedFeeder),
                    )!;
                  }),
                ),
                if (teamsFor(selectedFeeder).length > 1) ...[
                  const SizedBox(height: 12),
                  DropdownButtonFormField<int>(
                    initialValue: parseIntegerId(selectedTeam['id']),
                    decoration: const InputDecoration(labelText: 'Survey team'),
                    items: teamsFor(selectedFeeder)
                        .map(
                          (team) => DropdownMenuItem<int>(
                            value: parseIntegerId(team['id']),
                            child: Text(
                              '${team['name']} (Group ${teamGroupIdentifier(team)})',
                            ),
                          ),
                        )
                        .toList(),
                    onChanged: (value) {
                      if (value == null) return;
                      update(() {
                        selectedTeam = teamsFor(selectedFeeder).firstWhere(
                          (team) => parseIntegerId(team['id']) == value,
                        );
                      });
                    },
                  ),
                ] else
                  Padding(
                    padding: const EdgeInsets.only(top: 12),
                    child: Align(
                      alignment: Alignment.centerLeft,
                      child: Text(
                        'Assigned team: ${selectedTeam['name']} · Group ${teamGroupIdentifier(selectedTeam)}',
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
    await createFor(selectedTeam['id'], selectedFeeder['id']);
  }

  Future<void> createFor(dynamic teamValue, dynamic feederValue) async {
    final teamId = teamValue is int ? teamValue : int.tryParse('$teamValue');
    final feederId = feederValue is int
        ? feederValue
        : int.tryParse('$feederValue');
    if (teamId == null || feederId == null) {
      notice(
        'The selected team or feeder is invalid. Download assignments and try again.',
      );
      return;
    }
    final team = controller.team(teamId);
    final feeder = controller.feeder(feederId);
    final session = controller.session;
    if (team == null || feeder == null || session == null) return;
    if (teamGroupIdentifier(team) == null) {
      notice(
        'This team has no valid group number. Ask the administrator to correct it.',
      );
      return;
    }
    final record = SurveyRecord.create(session, team, feeder);
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
    final assignedMobileTeam = surveyTeamForUser(
      controller.reference,
      controller.teams,
    );
    final signedInGroup = teamGroupIdentifier(assignedMobileTeam);
    return Scaffold(
      appBar: AppBar(
        title: const Text('HAZECO Field Survey'),
        actions: [
          IconButton(
            tooltip: 'Download feeder data',
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
        label: const Text('New LT Transformer Survey'),
      ),
      body: RefreshIndicator(
        onRefresh: controller.reload,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(18, 20, 18, 100),
          children: [
            Text(
              controller.canUpdateHt
                  ? 'HT Updates & LT Surveys'
                  : 'LT Transformer Surveys',
              style: Theme.of(
                context,
              ).textTheme.headlineMedium?.copyWith(fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 6),
            Text(
              '${controller.session!.name}${signedInGroup == null ? '' : ' · Code $signedInGroup'} · ${controller.teams.length} assigned team${controller.teams.length == 1 ? '' : 's'}',
            ),
            const SizedBox(height: 16),
            if (controller.canUpdateHt)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const Text(
                        'HT Network Update',
                        style: TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                      const Text(
                        'Missing transformer or new HT extension? Save HT first, then begin LT Transformer Survey immediately.',
                      ),
                      OutlinedButton.icon(
                        onPressed: controller.busy
                            ? null
                            : () async {
                                await Navigator.push(
                                  context,
                                  MaterialPageRoute(
                                    builder: (_) =>
                                        HtUpdatesPage(controller: controller),
                                  ),
                                );
                                await controller.reload();
                              },
                        icon: const Icon(Icons.electrical_services),
                        label: const Text('Open HT Network Updates'),
                      ),
                    ],
                  ),
                ),
              ),
            const Text(
              'LT Transformer Surveys',
              style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
            ),
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
                    SwitchListTile.adaptive(
                      contentPadding: EdgeInsets.zero,
                      value: controller.autoSync,
                      onChanged: controller.syncing || controller.busy
                          ? null
                          : controller.setAutoSync,
                      title: const Text('Sync automatically'),
                      subtitle: Text(
                        controller.autoSync
                            ? 'Submitted surveys and photos sync when connected.'
                            : 'Surveys stay on this phone until you tap Sync now.',
                      ),
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
                      Text('Select any available feeder to start a survey.'),
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
                    record.surveyName,
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
                  trailing: record.revision == 0 && record.state != 'synced'
                      ? PopupMenuButton<String>(
                          tooltip: 'Survey actions',
                          enabled: !controller.syncing && !controller.busy,
                          onSelected: (value) {
                            if (value == 'edit') {
                              unawaited(editSurvey(record));
                            } else {
                              unawaited(deleteSurvey(record));
                            }
                          },
                          itemBuilder: (_) => [
                            if (record.state != 'conflict')
                              const PopupMenuItem(
                                value: 'edit',
                                child: Text('Edit survey'),
                              ),
                            const PopupMenuItem(
                              value: 'delete',
                              child: Text('Delete survey'),
                            ),
                          ],
                        )
                      : const Icon(Icons.chevron_right),
                ),
              ),
            ),
            if (controller.reference?['_downloaded_at'] != null)
              Text(
                'Feeder data downloaded ${controller.reference!['_downloaded_at'].toString().replaceFirst('T', ' ').substring(0, 16)}',
                style: Theme.of(context).textTheme.bodySmall,
              ),
          ],
        ),
      ),
    );
  }
}
