import 'package:flutter/material.dart';
import 'models.dart';

/// Searches the assigned feeders already cached on the phone; works offline.
class FeederPicker extends StatelessWidget {
  const FeederPicker({
    super.key,
    required this.feeders,
    required this.selectedId,
    required this.onSelected,
  });
  final List<Json> feeders;
  final int selectedId;
  final ValueChanged<int> onSelected;

  @override
  Widget build(BuildContext context) {
    final selected = feeders.where((f) => f['id'] == selectedId).firstOrNull;
    return OutlinedButton.icon(
      icon: const Icon(Icons.search),
      label: Text(
        selected == null
            ? 'Select feeder'
            : '${selected['feeder_code']} · ${selected['feeder_name']}',
      ),
      onPressed: () async {
        String query = '';
        final chosen = await showDialog<int>(
          context: context,
          builder: (context) => StatefulBuilder(
            builder: (context, update) {
              final matches = feeders
                  .where(
                    (f) => '${f['feeder_code']} ${f['feeder_name']}'
                        .toLowerCase()
                        .contains(query),
                  )
                  .toList();
              return AlertDialog(
                title: const Text('Assigned feeders'),
                content: SizedBox(
                  width: 460,
                  height: 370,
                  child: Column(
                    children: [
                      TextField(
                        autofocus: true,
                        decoration: const InputDecoration(
                          prefixIcon: Icon(Icons.search),
                          hintText: 'Search code or name',
                        ),
                        onChanged: (value) =>
                            update(() => query = value.trim().toLowerCase()),
                      ),
                      const SizedBox(height: 12),
                      Expanded(
                        child: matches.isEmpty
                            ? const Center(
                                child: Text('No matching assigned feeders.'),
                              )
                            : ListView(
                                children: matches
                                    .map(
                                      (f) => ListTile(
                                        title: Text(f['feeder_code']),
                                        subtitle: Text(f['feeder_name']),
                                        selected: f['id'] == selectedId,
                                        onTap: () => Navigator.pop(
                                          context,
                                          f['id'] as int,
                                        ),
                                      ),
                                    )
                                    .toList(),
                              ),
                      ),
                    ],
                  ),
                ),
                actions: [
                  TextButton(
                    onPressed: () => Navigator.pop(context),
                    child: const Text('Close'),
                  ),
                ],
              );
            },
          ),
        );
        if (context.mounted && chosen != null) onSelected(chosen);
      },
    );
  }
}
