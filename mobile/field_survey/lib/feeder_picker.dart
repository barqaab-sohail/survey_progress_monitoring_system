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
  Widget build(BuildContext context) => DropdownMenu<int>(
    initialSelection: selectedId,
    expandedInsets: EdgeInsets.zero,
    enableFilter: true,
    requestFocusOnTap: true,
    label: const Text('Search feeder code or name'),
    dropdownMenuEntries: feeders
        .map(
          (f) => DropdownMenuEntry<int>(
            value: f['id'],
            label: '${f['feeder_code']} · ${f['feeder_name']}',
          ),
        )
        .toList(),
    onSelected: (value) {
      if (value != null) onSelected(value);
    },
  );
}
