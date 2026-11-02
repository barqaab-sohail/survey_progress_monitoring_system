<?php

namespace Tests\Unit;

use App\Models\Mdb\Revision;
use App\Models\Mdb\SurveyBatch;
use App\Services\Mdb\ExportService;
use App\Services\Mdb\SurveyPhaseConsistency;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SurveyPhaseConsistencyTest extends TestCase
{
    public function test_intermediate_data_entry_exports_also_reject_conflicting_phase_observations(): void
    {
        $snapshot = ['transformers' => [['id' => 1, 'code' => 'T-data-entry', 'entry_rows' => [
            ['pair_number' => 1, 'designation' => 'S', 'conductors' => ['B' => '']],
            ['pair_number' => 1, 'designation' => 'E', 'waypoint_reference' => '021', 'consumer_phases' => ['st' => ['B' => 2]]],
        ]]]];
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('ST B-phase customer count 2');
        app(\App\Services\Mdb\DataEntryPayload::class)->build($snapshot);
    }
    public function test_old_approved_revisions_cannot_bypass_the_rule_through_cached_export_or_download(): void
    {
        $batch = (new SurveyBatch)->forceFill(['id' => 1, 'status' => 'approved']);
        $revision = (new Revision)->forceFill(['id' => 2, 'batch_id' => 1, 'snapshot' => ['transformers' => [[
            'id' => 1, 'code' => 'T-old', 'entry_rows' => [
                ['pair_number' => 1, 'designation' => 'S', 'conductors' => ['R' => '']],
                ['pair_number' => 1, 'designation' => 'E', 'waypoint_reference' => '021', 'consumer_phases' => ['rs' => ['R' => 2]]],
            ],
        ]]]]);
        $batch->setRelation('approvedRevision', $revision);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('R-phase customer count 2');
        app(ExportService::class)->assertApproved($batch);
    }

    public function test_phase_counts_use_the_matching_s_row_and_never_modify_observations(): void
    {
        $rows = [
            ['pair_number' => 1, 'designation' => 'S', 'conductors' => ['R' => '', 'Y' => 'A', 'B' => 'W', 'N' => 'A']],
            ['pair_number' => 1, 'designation' => 'E', 'waypoint_reference' => '021', 'conductors' => ['R' => 'A'],
                'original_entry' => ['consumer_phases' => ['rs' => ['R' => 2, 'Y' => 1, 'B' => 0], 'st' => ['R' => 3]]]],
            ['pair_number' => 2, 'designation' => 'S', 'conductors' => ['R' => 'A']],
            ['pair_number' => 2, 'designation' => 'E', 'consumer_phases' => ['sc' => ['R' => 4]]],
        ];
        $original = $rows;
        $issues = app(SurveyPhaseConsistency::class)->rows($rows, 'T-11230617182');
        $this->assertCount(2, $issues);
        $this->assertStringContainsString('T-11230617182, pair 1 E 021', $issues[0]['message']);
        $this->assertStringContainsString('RS R-phase customer count 2', $issues[0]['message']);
        $this->assertSame('consumer_phases.st.R', $issues[1]['field']);
        $this->assertSame($original, $rows);
    }

    public function test_unknown_draft_conductors_and_zero_counts_are_allowed_but_known_absence_is_rejected(): void
    {
        $rule = app(SurveyPhaseConsistency::class);
        foreach ([null, 'A', 'ANT'] as $conductor) {
            $this->assertSame([], $rule->counts(['R' => $conductor], ['rs' => ['R' => 2]], 'Draft'));
        }
        foreach (['', '__none__', '-', 'x'] as $conductor) {
            $this->assertCount(1, $rule->counts(['R' => $conductor], ['rs' => ['R' => 2]], 'Draft'));
            $this->assertSame([], $rule->counts(['R' => $conductor], ['rs' => ['R' => 0, 'Y' => null]], 'Draft'));
        }
        $this->assertCount(1, $rule->counts(['R' => null], ['rs' => ['R' => 2]], 'Submission', ['R']));
        $this->assertSame([], $rule->rows([['pair_number' => 1, 'designation' => 'E', 'consumer_phases' => ['rs' => ['R' => 2]]]], 'Incomplete'));
    }

    public function test_advanced_phase_demand_and_embedded_source_rows_are_also_validated(): void
    {
        $rule = app(SurveyPhaseConsistency::class);
        $section = ['id' => 3, 'conductors' => ['R' => '', 'Y' => 'A'],
            'consumers' => [['category' => 'sc', 'demand' => ['phase_values' => ['R' => ['customers' => 3]]]]]];
        $this->assertCount(1, $rule->section($section, [], 'Section 3'));
        $rows = [['designation' => 'S', 'conductors' => ['R' => 'A']]];
        $this->assertSame([], $rule->section($section, $rows, 'Section 3'));
    }

    public function test_mobile_pair_ids_and_legacy_adjacency_use_the_same_s_conductor(): void
    {
        $rule = app(SurveyPhaseConsistency::class);
        $rows = [
            ['pair_id' => 'a', 'se' => 'E', 'gps_waypoint' => '021', 'conductor_r' => 'A', 'consumer_phases' => ['sc' => ['R' => 2]]],
            ['se' => 'S', 'conductor_r' => 'A'], ['se' => 'E', 'consumer_phases' => ['rs' => ['R' => 2]]],
            ['pair_id' => 'a', 'se' => 'S', 'conductor_r' => '-', 'conductor_y' => 'A'],
        ];
        $errors = $rule->fieldErrors($rows, 'T-mobile');
        $this->assertArrayHasKey('rows.0.consumer_phases.sc.R', $errors);
        $this->assertArrayHasKey('rows.3.conductor_r', $errors);
        $this->assertArrayNotHasKey('rows.2.consumer_phases.rs.R', $errors);
        $rows[3]['conductor_r'] = '';
        $this->assertSame([], $rule->fieldErrors($rows, 'T-mobile'));
        $this->assertNotEmpty($rule->fieldErrors($rows, 'T-mobile', true));
    }
}
