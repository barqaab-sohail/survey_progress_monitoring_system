<?php

namespace App\Services\Mdb;

use Illuminate\Validation\ValidationException;

/** Rebuild only the section table, resolving every endpoint against the already generated Nodes. */
class InstSectionGeneration
{
    private const FIXED = [
        'PhaseToPhaseSpacing_MUL' => 121.9, 'PhaseToNeutralSpacing_MUL' => 91.4, 'AveHeightAboveGround_MUL' => 9.1,
        'PercentDistLoadConstImpedance' => 40, 'PercentDistLoadConstCurrent' => 10,
        'PercentSpotLoadConstImpedance' => 40, 'PercentSpotLoadConstCurrent' => 30,
        'PercentLoadGrowthRate' => 2, 'MappingLink' => 0, 'UseEquivSpacing' => 0, 'IdenticalPhaseConductors' => 0,
        'PositionCode' => 0, 'ConfigurationId' => '12.5/7.2 kV cross arm C2-2', 'IsContingency' => 0,
        'UseSectAmpRating' => 0, 'AmpRating' => 100, 'UseDistCustZone' => 0, 'UseSpotCustZone' => 0,
        'UseCustClassDistLoadIzpq' => 0, 'UseCustClassSpotLoadIzpq' => 0, 'GrowthZone' => 'Unknown',
        'FromElbowOrBayStatus' => 'N', 'ToElbowOrBayStatus' => 'N', 'ElbowOrBayAmpRating' => 100,
        'DuctBankCircuit' => 1, 'UseDuctBankAmpRating' => 0, 'UseDuctBankConfiguration' => 0, 'SpotLoadIsOff' => 0,
        'EnergizeYear' => 0, 'RetireYear' => 100, 'LoadCategory' => 0, 'NeutIsGrounded' => 1, 'HideMapResults' => 0,
        'LocationLink' => '*', 'ExpFactPermFail' => 1, 'ExpFactTempFail' => 1, 'ExpFactRepair' => 0,
    ];

    public function apply(array $tables, array $contexts, array $provenance, array $loadProvenance): array
    {
        $nodes = array_column($tables['Node'], null, 'NodeId');
        $original = array_column($tables['InstSection'], null, 'SectionId');
        $sections = $original;
        $remap = $staticLengths = $primarySections = [];
        $rules = new SectionGenerationRules;
        foreach ($contexts as $context) {
            $transformer = $context['transformer'];
            $entries = $transformer['entry_rows'] ?? [];
            $label = 'Transformer '.$transformer['code'];
            $sourceIds = $context['source_section_ids'];
            $primaryOld = $sourceIds[$context['network']['transformer_section'] ?? ''] ?? null;
            $this->check($primaryOld !== null && isset($original[$primaryOld]),
                $label.': the existing transformer relationship must resolve to an InstSection record.');
            $primarySections[$primaryOld] = ['SectionId' => $primaryOld, 'code' => $transformer['code']];
            if (! collect($entries)->contains(fn ($entry) => isset($entry['composite_identifier']))) {
                continue;
            }
            $code = $transformer['header']['feeder_identifier'] ?? null;
            $this->check(is_string($code), $label.': verify the numeric feeder code from the PDF.');
            $pair = (new SurveyWaypoint)->firstPair($transformer);
            $this->check($pair['start'] && $pair['end'], $label.': a unique first S/E pair is required.');
            $this->check(count($sourceIds) === 2 && $primaryOld !== null,
                $label.': explicit source wiring differs from the workbook chain. Review this network before section generation.');
            $supply = $original[$primaryOld];
            $otherSource = array_values(array_diff(array_values($sourceIds), [$primaryOld]))[0];
            $surveyIds = [];
            foreach ($transformer['sections'] as $section) {
                $matches = array_values(array_filter($provenance, fn ($p) => $p['survey_section_id'] == $section['id']));
                $this->check(count($matches) === 1, $label.': section provenance is missing or ambiguous.');
                $surveyIds[$section['id']] = $matches[0]['section_id'];
            }
            $firstSection = $this->surveySection($transformer, $pair['end']);
            $firstSection = $this->withSourceConductors($firstSection, $entries);
            $firstPole = $original[$surveyIds[$firstSection['id']]];
            $feeder = $supply['FeederId'];
            $s = $this->node($nodes, $firstPole['FromNodeId'], $label);
            $terminalIds = array_values(array_diff([$supply['FromNodeId'], $supply['ToNodeId']], [$s['NodeId'], $firstPole['ToNodeId'], $feeder]));
            $this->check(count($terminalIds) === 1, $label.': transformer terminal Node is ambiguous.');
            $terminal = $this->node($nodes, $terminalIds[0], $label);
            $this->check(isset($nodes[$feeder]) && $terminal['NodeId'] !== $s['NodeId'], $label.': feeder or transformer Node is unresolved.');
            $firstConnections = (new ConsumerPhaseConnections)->build($firstSection, $entries, $transformer['pv_records'] ?? [], $label);
            $phaseSet = $this->phases($firstSection, $label, $this->yOnlyPv($firstSection, $firstConnections));
            $feederId = $this->id($rules, $code, $pair['start']);
            $transformerId = $this->id($rules, $code, $pair['start'], 1);
            $firstPoleId = $this->id($rules, $code, $pair['end']);
            foreach (array_merge(array_values($sourceIds), array_values($surveyIds)) as $old) {
                unset($sections[$old]);
            }
            $this->put($sections, $this->row($supply, $feederId, $feeder, $feeder, $s['NodeId'],
                $this->phaseCode(array_values(array_diff($phaseSet, ['N']))), 'Feeder', null, $this->conductors('500 MCM', null)));
            $this->put($sections, $this->row($supply, $transformerId, $feeder, $s['NodeId'], $terminal['NodeId'],
                $this->phaseCode($phaseSet), 'Transformer', 0.0, $this->conductors('4/C 07/.052', '4/C 07/.052'), 0));
            $remap[$primaryOld] = $transformerId;
            // Reuse the section created for this relationship; never format a separate device ID.
            $primarySections[$primaryOld]['SectionId'] = $transformerId;
            $remap[$otherSource] = $firstPoleId;
            foreach ($transformer['sections'] as $survey) {
                $survey = $this->withSourceConductors($survey, $entries);
                $endRows = array_values(array_filter($entries, fn ($r) => ($r['designation'] ?? '') === 'E'
                    && $this->belongs($survey, $r)));
                $this->check(count($endRows) === 1, $label.': survey section must have one verified E row.');
                $entry = $endRows[0];
                $oldId = $surveyIds[$survey['id']];
                $old = $original[$oldId];
                $to = $this->node($nodes, $old['ToNodeId'], $label);
                $from = $this->node($nodes, $old['FromNodeId'], $label);
                $id = $this->id($rules, $code, $entry);
                $isFirst = $survey['id'] == $firstSection['id'];
                $length = $isFirst ? 10.0 : ($old['SectionLength_MUL'] ?? null);
                $elevation = collect($provenance)->firstWhere('section_id', $oldId)['elevation_status'] ?? null;
                $this->check($isFirst || ($length !== null && ($elevation === 'elevation_adjusted' || ($survey['measured_length_m'] ?? null) !== null)),
                    $label.', E '.$entry['waypoint_reference'].': pole span requires recorded elevation or an approved measured length.');
                $assignments = (new ConsumerPhaseConnections)->build($survey, $entries, $transformer['pv_records'] ?? [], $label.', E '.$entry['waypoint_reference']);
                $yOnlyPv = $this->yOnlyPv($survey, $assignments);
                $phases = $this->phases($survey, $label, $yOnlyPv);
                $conductors = [];
                foreach (['R' => 'PhaseConductorId', 'Y' => 'PhaseConductor2Id', 'B' => 'PhaseConductor3Id', 'N' => 'NeutralConductorId'] as $phase => $field) {
                    $value = $survey['conductors'][$phase] ?? null;
                    $this->check(! in_array($phase, $phases, true) || (is_string($value) && $value !== ''), $label.': missing PDF conductor '.$phase.' at E '.$entry['waypoint_reference'].'.');
                    $conductors[$field] = $value;
                }
                $this->put($sections, $this->row($old, $id, $feeder, $isFirst ? $terminal['NodeId'] : $from['NodeId'], $to['NodeId'],
                    $this->phaseCode($phases), 'Secondary Pole', $length, $conductors));
                $remap[$oldId] = $id;
                if ($isFirst) {
                    $staticLengths[$oldId] = 10.0;
                }
                if ($assignments !== []) {
                    $this->check(in_array('N', $phases, true), $label.': consumer sections require confirmed neutral availability.');
                    $cable = $rules->consumerCableLength($entry, $label.', E '.$entry['waypoint_reference']);
                    foreach ($assignments as $connection) {
                        $suffix = $connection['suffix'];
                        // Resolve the stored Node rather than manufacturing an endpoint from the workbook.
                        $customer = $this->node($nodes, $to['NodeId'].'.'.$suffix, $label);
                        $customerId = $this->id($rules, $code, $entry, $suffix);
                        $cableConductors = $this->conductors('PVC 07/0.052', 'PVC 07/0.052');
                        if ($yOnlyPv && $connection['kind'] === 'pv') {
                            $cableConductors['PhaseConductorId'] = null;
                            $cableConductors['PhaseConductor3Id'] = null;
                        }
                        $this->put($sections, $this->row($old, $customerId, $feeder, $to['NodeId'], $customer['NodeId'],
                            $connection['phase'].'N', 'Secondary Pole', $cable, $cableConductors));
                    }
                }
            }
        }
        $tables['InstSection'] = array_values($sections);
        // Only foreign identifiers change in later tables; demand/device values remain untouched.
        foreach ($tables['Loads'] ?? [] as $index => $row) {
            $sectionId = $remap[$row['SectionId']] ?? $row['SectionId'];
            $section = $sections[$sectionId] ?? null;
            $this->check(is_array($section) && ($section['SectionId'] ?? null) === $sectionId,
                'Loads has no matching InstSection.SectionId for '.(string) $row['SectionId'].'.');
            $tables['Loads'][$index]['SectionId'] = $section['SectionId'];
            $tables['Loads'][$index]['Description'] = '';
        }
        foreach (['InstPrimaryTransformers', 'InstMeterPoints', 'InstDGens', 'InstMymDGens'] as $table) {
            foreach ($tables[$table] ?? [] as $index => $row) {
                if ($table === 'InstPrimaryTransformers') {
                    $primary = $primarySections[$row['SectionId']] ?? null;
                    $this->check($primary !== null && ($row['UniqueDeviceId'] ?? null) === $primary['code']
                        && isset($sections[$primary['SectionId']]),
                        'InstPrimaryTransformers: transformer '.($row['UniqueDeviceId'] ?? '(unknown)').' has no matching transformer section relationship.');
                    $tables[$table][$index]['SectionId'] = $sections[$primary['SectionId']]['SectionId'];
                    foreach (['TertIncidentSectId1', 'TertIncidentSectId2', 'TertIncidentSectId3'] as $field) {
                        if (isset($row[$field], $remap[$row[$field]])) {
                            $tables[$table][$index][$field] = $remap[$row[$field]];
                        }
                    }
                } else {
                    $tables[$table][$index]['SectionId'] = $remap[$row['SectionId']] ?? $row['SectionId'];
                }
            }
        }
        foreach ($provenance as &$row) {
            $old = $row['section_id'];
            $row['section_id'] = $remap[$row['section_id']] ?? $row['section_id'];
            if (isset($staticLengths[$old])) {
                $row['survey_length_source'] = $row['length_source'];
                $row['length_source'] = 'workbook_static';
                $row['model_length_m'] = $staticLengths[$old];
            }
        }
        unset($row);
        foreach ($loadProvenance as &$row) {
            $row['section_id'] = $remap[$row['section_id']] ?? $row['section_id'];
        }
        unset($row);
        $this->validate($tables);

        return compact('tables', 'provenance', 'loadProvenance');
    }

    private function id(SectionGenerationRules $rules, string $code, array $row, ?int $suffix = null): string
    {
        return $rules->identifier($code, $row['group_number'], $row['row_date'], $row['waypoint_reference'], $suffix);
    }

    private function surveySection(array $transformer, array $row): array
    {
        $matches = array_values(array_filter($transformer['sections'], fn ($s) => $this->belongs($s, $row)));
        $this->check(count($matches) === 1, 'The first E row must identify one survey section.');

        return $matches[0];
    }

    private function belongs(array $section, array $row): bool
    {
        return isset($row['section_id']) ? $row['section_id'] == $section['id']
            : ($section['original_entry']['entry_pair_number'] ?? null) == $row['pair_number'];
    }

    private function withSourceConductors(array $section, array $entries): array
    {
        $section['conductors'] = is_array($section['conductors'] ?? null) ? $section['conductors'] : [];
        $starts = array_values(array_filter($entries, fn ($r) => ($r['designation'] ?? '') === 'S' && $this->belongs($section, $r)));
        if (count($starts) === 1) {
            foreach ($starts[0]['conductors'] ?? [] as $phase => $value) {
                if (! array_key_exists($phase, $section['conductors']) && is_string($value)) {
                    $section['conductors'][$phase] = EntryLookups::libraryCode($value);
                }
            }
        }

        return $section;
    }

    private function yOnlyPv(array $section, array $connections): bool
    {
        $phases = $section['phases'] ?? [];
        if (is_string($phases)) {
            $phases = str_split($phases);
        }

        return is_array($phases) && array_values(array_intersect(['R', 'Y', 'B'], $phases)) === ['Y']
            && in_array('N', $phases, true) && in_array('pv', array_column($connections, 'kind'), true);
    }

    private function phases(array $section, string $label, bool $yOnlyPv = false): array
    {
        $phases = $section['phases'] ?? [];
        if (is_string($phases)) {
            $phases = array_values(array_filter(str_split($phases), fn ($p) => $p !== ' '));
        }
        $this->check(is_array($phases), $label.': PDF phase availability is missing or ambiguous.');
        $this->check(array_intersect($phases, ['R', 'Y', 'B']) !== [] && array_diff($phases, ['R', 'Y', 'B', 'N']) === [],
            $label.': PDF phase availability is missing or ambiguous.');
        foreach (['R', 'Y', 'B'] as $phase) {
            if ($yOnlyPv && $phase !== 'Y') {
                continue;
            }
            $value = $section['conductors'][$phase] ?? null;
            $this->check(is_string($value), $label.': PDF phase '.$phase.' availability is unknown; confirm its conductor or explicit absence.');
        }

        return $phases;
    }

    private function phaseCode(array $phases): string
    {
        return rtrim(implode('', array_map(fn ($p) => in_array($p, $phases, true) ? $p : ' ', ['R', 'Y', 'B', 'N'])));
    }

    private function conductors(string $phase, ?string $neutral): array
    {
        return ['PhaseConductorId' => $phase, 'PhaseConductor2Id' => $phase, 'PhaseConductor3Id' => $phase, 'NeutralConductorId' => $neutral];
    }

    private function node(array $nodes, string $id, string $label): array
    {
        $this->check(isset($nodes[$id]) && $nodes[$id]['NodeId'] === $id, $label.': Node '.$id.' is missing; no endpoint was invented.');

        return $nodes[$id];
    }

    private function row(array $base, string $id, string $feeder, string $from, string $to, string $phases, string $description, ?float $length, array $conductors, int $connection = 1): array
    {
        return array_replace($base, self::FIXED, $conductors, ['SectionId' => $id, 'FeederId' => $feeder, 'FromNodeId' => $from,
            'ToNodeId' => $to, 'SectionPhases' => $phases, 'Description' => $description, 'SectionLength_MUL' => $length, 'LoadConnectionPhaseGnd' => $connection]);
    }

    private function put(array &$sections, array $row): void
    {
        foreach ($sections as $existing) {
            $this->check(strcasecmp($existing['SectionId'], $row['SectionId']) !== 0, 'SectionId '.$row['SectionId'].' is duplicated after workbook mapping.');
        }
        $sections[$row['SectionId']] = $row;
    }

    private function validate(array $tables): void
    {
        $nodes = array_column($tables['Node'], null, 'NodeId');
        $feeders = array_column($tables['InstFeeders'], null, 'FeederId');
        $sections = array_column($tables['InstSection'], null, 'SectionId');
        $parent = [];
        foreach ($sections as $section) {
            $from = $section['FromNodeId'];
            $to = $section['ToNodeId'];
            $this->check(isset($nodes[$from], $nodes[$to], $feeders[$section['FeederId']]) && $from !== $to, 'InstSection has an orphaned reference or self connection.');
            foreach ([$from, $to] as $node) {
                $parent[$node] ??= $node;
            }
            $a = $from;
            $b = $to;
            while ($parent[$a] !== $a) {
                $a = $parent[$a];
            }
            while ($parent[$b] !== $b) {
                $b = $parent[$b];
            }
            $this->check($a !== $b, 'InstSection mapping creates a duplicate edge or cycle.');
            $parent[$b] = $a;
        }
        foreach (['Loads', 'InstPrimaryTransformers', 'InstMeterPoints', 'InstDGens', 'InstMymDGens'] as $table) {
            foreach ($tables[$table] ?? [] as $row) {
                $this->check(isset($sections[$row['SectionId']]), $table.' has an orphaned SectionId.');
            }
        }
        foreach ($sections as $section) {
            $roots = [];
            foreach ([$section['FeederId'], $section['FromNodeId'], $section['ToNodeId']] as $node) {
                $this->check(isset($parent[$node]), 'A section is disconnected from its named feeder Node.');
                while ($parent[$node] !== $node) {
                    $node = $parent[$node];
                }
                $roots[] = $node;
            }
            $this->check(count(array_unique($roots)) === 1, 'A section is disconnected from its named feeder Node.');
        }
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['export' => $message]);
        }
    }
}
