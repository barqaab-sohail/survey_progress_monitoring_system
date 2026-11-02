<?php

namespace App\Services\Mdb;

use App\Support\FieldSurveyPairs;
use Illuminate\Validation\ValidationException;

/** Checks observations only: null is unknown; an explicit absence is a contradiction. */
class SurveyPhaseConsistency
{
    public function availability(mixed $conductor): ?bool
    {
        if ($conductor === null) {
            return null;
        }

        return ! in_array(strtolower(trim((string) $conductor)), ['', '__none__', '+', '-', '–', '—', 'x', '×'], true);
    }

    public function counts(array $conductors, array $counts, string $label, array $requiredPhases = []): array
    {
        $issues = [];
        foreach (EntryLookups::SINGLE_PHASE_CONSUMERS as $category) {
            foreach (['R', 'Y', 'B'] as $phase) {
                $count = $counts[$category][$phase] ?? null;
                $available = $this->availability($conductors[$phase] ?? null);
                if (is_numeric($count) && $count > 0 && ($available === false || ($available === null && in_array($phase, $requiredPhases, true)))) {
                    $issues[] = ['phase' => $phase, 'category' => $category, 'count' => $count,
                        'field' => 'consumer_phases.'.$category.'.'.$phase,
                        'message' => $label.': '.strtoupper($category).' '.$phase.'-phase customer count '.$count.' conflicts with the missing '.$phase.' conductor on the section S row. Check the recorded conductor and customer count; no values were changed.'];
                }
            }
        }

        return $issues;
    }

    /** The S row is authoritative for both endpoints, including while editing E. */
    public function rows(array $rows, string $transformer, array $requiredPhases = []): array
    {
        $issues = [];
        foreach (collect($rows)->groupBy('pair_number') as $pair => $entries) {
            $start = $entries->firstWhere('designation', 'S');
            if (! $start) {
                continue;
            }
            foreach ($entries as $row) {
                $label = 'Transformer '.$transformer.', pair '.$pair.' '.($row['designation'] ?? '').' '.($row['waypoint_reference'] ?? '(waypoint unrecorded)');
                foreach ($this->counts($start['conductors'] ?? [], $row['consumer_phases'] ?? $row['original_entry']['consumer_phases'] ?? [], $label, $requiredPhases) as $issue) {
                    $issues[] = $issue + ['row' => $row, 'start' => $start];
                }
            }
        }

        return $issues;
    }

    public function fieldErrors(array $rows, string $transformer, bool $submitting = false, array $previousRows = []): array
    {
        $rows = array_values($rows);
        $previousRows = array_values($previousRows);
        $errors = [];
        foreach (array_values(FieldSurveyPairs::groups($rows)) as $number => $indices) {
            $canonical = [];
            foreach ($indices as $index) {
                $row = $rows[$index];
                $conductors = [];
                foreach (['R' => 'r', 'Y' => 'y', 'B' => 'b', 'N' => 'neutral'] as $phase => $key) {
                    // Blank free-text mobile fields are unrecorded, not explicit absence.
                    $value = $row['conductor_'.$key] ?? null;
                    $conductors[$phase] = $value === '' ? null : $value;
                }
                $canonical[] = ['pair_number' => $number + 1, 'designation' => $row['se'] ?? null, 'conductors' => $conductors,
                    'waypoint_reference' => $row['gps_waypoint'] ?? null, 'consumer_phases' => $row['consumer_phases'] ?? [], '_index' => $index];
            }
            $required = $submitting ? ['R', 'Y', 'B'] : [];
            $start = collect($canonical)->firstWhere('designation', 'S');
            if ($start) {
                $source = $rows[$start['_index']];
                $previous = collect($previousRows)->first(fn ($old) => ! empty($source['row_id'])
                    ? ($old['row_id'] ?? null) === $source['row_id']
                    : (! empty($source['pair_id']) && ($old['pair_id'] ?? null) === $source['pair_id'] && ($old['se'] ?? null) === 'S'));
                if (! $previous && empty($source['row_id']) && empty($source['pair_id'])) {
                    $previous = $previousRows[$start['_index']] ?? null;
                }
                foreach (['R', 'Y', 'B'] as $phase) {
                    if ($previous && $this->availability($previous['conductor_'.strtolower($phase)] ?? null) === true
                        && $this->availability($start['conductors'][$phase] ?? null) !== true) {
                        $required[] = $phase;
                    }
                }
            }
            foreach ($this->rows($canonical, $transformer, $required) as $issue) {
                $errors['rows.'.$issue['row']['_index'].'.'.$issue['field']] = $issue['message'];
                $errors['rows.'.$issue['start']['_index'].'.conductor_'.strtolower($issue['phase'])] = $issue['message'];
            }
        }

        return $errors;
    }

    /** Also protects legacy/advanced sections and frozen transfer packages. */
    public function section(array $section, array $rows, string $label, bool $includeRows = true, array $requiredPhases = ['R', 'Y', 'B']): array
    {
        $conductors = $section['conductors'] ?? [];
        $start = collect($rows)->firstWhere('designation', 'S');
        if ($start) {
            $conductors = $start['conductors'] ?? [];
        }
        $issues = [];
        foreach ($includeRows ? $rows : [] as $row) {
            array_push($issues, ...$this->counts($conductors, $row['consumer_phases'] ?? $row['original_entry']['consumer_phases'] ?? [], $label, $requiredPhases));
        }
        foreach ($section['consumers'] ?? [] as $consumer) {
            $category = $consumer['category'] ?? '';
            $counts = [];
            foreach (['R', 'Y', 'B'] as $phase) {
                $counts[$category][$phase] = $consumer['demand']['phase_values'][$phase]['customers'] ?? null;
            }
            array_push($issues, ...$this->counts($conductors, $counts, $label, $requiredPhases));
        }

        return $issues;
    }

    public function transformer(array $transformer): array
    {
        $rows = $transformer['entry_rows'] ?? [];
        $label = 'Transformer '.($transformer['code'] ?? '(unrecorded)');
        $issues = $this->rows($rows, $transformer['code'] ?? '(unrecorded)', ['R', 'Y', 'B']);
        foreach ($transformer['sections'] ?? [] as $section) {
            $entries = array_values(array_filter($rows ?: ($section['original_entry']['entry_rows'] ?? []), fn ($row) => isset($row['section_id'])
                ? $row['section_id'] == $section['id']
                : ($section['original_entry']['entry_pair_number'] ?? null) == ($row['pair_number'] ?? null)));
            foreach ($this->section($section, $entries, $label.', section '.($section['id'] ?? '?').', E '.($section['end_reference'] ?? '?'), $rows === []) as $issue) {
                $issues[] = $issue + ['section' => $section];
            }
        }

        return $issues;
    }

    public function assertValid(array $issues): void
    {
        if ($issues) {
            throw ValidationException::withMessages(['rows' => array_values(array_unique(array_column($issues, 'message')))]);
        }
    }
}
