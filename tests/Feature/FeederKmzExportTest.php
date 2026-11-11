<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\FeederAssignment;
use App\Models\GridStation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\TransformerKmzImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FeederKmzExportTest extends TestCase
{
    use RefreshDatabase;

    private User $leader;

    private User $admin;

    private Organization $organization;

    private Project $project;

    private SurveyTeam $team;

    private Feeder $feeder;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->organization = Organization::create(['name' => 'Survey Organization', 'type' => 'internal', 'status' => 'active']);
        $this->leader = User::factory()->create(['organization_id' => $this->organization->id, 'role' => UserRole::MobileSurveyUser, 'allow_ht_network_updates' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => UserRole::SuperAdmin]);
        $this->project = Project::create(['code' => 'FIELD', 'name' => 'Field project', 'timezone' => 'Asia/Karachi', 'status' => 'active']);
        $circle = Circle::create(['project_id' => $this->project->id, 'code' => 'C', 'name' => 'Circle']);
        $division = Division::create(['project_id' => $this->project->id, 'circle_id' => $circle->id, 'code' => 'D', 'name' => 'Division']);
        $subDivision = SubDivision::create(['project_id' => $this->project->id, 'division_id' => $division->id, 'code' => 'SD', 'name' => 'Sub division']);
        $grid = GridStation::create(['project_id' => $this->project->id, 'sub_division_id' => $subDivision->id, 'code' => 'G', 'name' => 'Substation']);
        $this->feeder = Feeder::create(['project_id' => $this->project->id, 'circle_id' => $circle->id, 'division_id' => $division->id, 'sub_division_id' => $subDivision->id, 'grid_station_id' => $grid->id, 'feeder_code' => '0012', 'feeder_name' => 'Assigned feeder', 'total_transformers' => 0, 'baseline_pending' => true, 'status' => 'active']);
        $this->team = SurveyTeam::create(['project_id' => $this->project->id, 'code' => 'ST', 'name' => 'Survey Team 01', 'status' => 'active']);
        $this->team->members()->attach($this->leader->id, ['is_leader' => true]);
        FeederAssignment::create(['feeder_id' => $this->feeder->id, 'survey_team_id' => $this->team->id, 'assigned_by' => $this->admin->id, 'start_date' => today()->subDay(), 'status' => 'active']);
    }

    private function baseline(): TransformerKmzImport
    {
        $existing = TransformerKmzImport::where('feeder_id', $this->feeder->id)->first();
        if ($existing) {
            return $existing;
        }
        $xml = '<?xml version="1.0"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document><Style id="existing-style"><LineStyle><color>ff0000ff</color></LineStyle></Style><Folder><name>Original client assets</name><Placemark id="old-transformer"><styleUrl>#existing-style</styleUrl><description><![CDATA[<table><tr><td>Feeder</td><td>FEEDER</td></tr><tr><td>Equipment Type</td><td>Transformer</td></tr><tr><td>Equipment Number</td><td>OLD-001</td></tr><tr><td>Equipment Size</td><td>50</td></tr><tr><td>Equipment Make</td><td>OLD-MAKE</td></tr><tr><td>GPS Waypoint Number</td><td>OLD</td></tr><tr><td>Line Voltage</td><td>11kV</td></tr><tr><td>Transformer_Picture_Path</td><td>Z:/old.jpg</td></tr></table>]]></description><Point><coordinates>73,34,0</coordinates></Point></Placemark><Placemark id="ht-line"><styleUrl>#existing-style</styleUrl><description><![CDATA[<table><tr><td>Line Voltage</td><td>11kV</td></tr><tr><td>Section ID</td><td>J1</td></tr><tr><td>Line Type</td><td>Primary</td></tr><tr><td>Conductor Size Phase R</td><td>DOG</td></tr><tr><td>Start X</td><td>12345</td></tr><tr><td>shape_Length</td><td>100</td></tr></table>]]></description><LineString><coordinates>73,34,0 73.0005,34.0005,0</coordinates></LineString></Placemark></Folder></Document></kml>';
        $path = 'ht-baseline.kmz';
        $zip = new \ZipArchive;
        $zip->open(Storage::disk('local')->path($path), \ZipArchive::CREATE);
        $zip->addFromString('doc.kml', $xml);
        $zip->addFromString('files/icon.png', 'original-icon-bytes');
        $zip->close();

        return TransformerKmzImport::create(['feeder_id' => $this->feeder->id, 'imported_by' => $this->admin->id,
            'file_name' => 'client.kmz', 'stored_path' => $path, 'status' => 'completed',
            'file_sha256' => hash_file('sha256', Storage::disk('local')->path($path))]);
    }

    private function approvedLtBatch(string $code = 'OLD-001'): \App\Models\Mdb\SurveyBatch
    {
        $this->baseline();
        $registered = \App\Models\Transformer::firstOrCreate(['feeder_id' => $this->feeder->id, 'transformer_code' => $code],
            ['capacity_kva' => 50, 'longitude' => 73, 'latitude' => 34]);
        $batch = \App\Models\Mdb\SurveyBatch::create(['project_id' => $this->project->id, 'feeder_id' => $this->feeder->id,
            'survey_team_id' => $this->team->id, 'survey_date' => today(), 'created_by' => $this->admin->id, 'status' => 'approved', 'revision' => 1]);
        $snapshot = \Tests\Unit\MdbInstSectionGenerationTest::snapshot();
        $snapshot['batch']['feeder_id'] = $this->feeder->id;
        $snapshot['transformers'][0]['code'] = $code;
        $snapshot['transformers'][0]['header']['service_category'] = 'General Duty';
        $snapshot['transformers'][0]['header']['registered_transformer_id'] = $registered->id;
        $revision = \App\Models\Mdb\Revision::create(['batch_id' => $batch->id, 'number' => 1, 'snapshot' => $snapshot,
            'sha256' => app(\App\Services\Mdb\SnapshotService::class)->hash($snapshot), 'created_by' => $this->admin->id, 'frozen_at' => now()]);
        $batch->update(['approved_revision_id' => $revision->id]);
        \App\Models\Mdb\NetworkTransformer::create(['batch_id' => $batch->id, 'code' => $code, 'capacity_kva' => 50,
            'header' => ['registered_transformer_id' => $registered->id, 'service_category' => 'General Duty']]);
        \App\Models\Mdb\VerificationDecision::create(['batch_id' => $batch->id, 'revision_id' => $revision->id,
            'decided_by' => $this->admin->id, 'decision' => 'approve']);
        return $batch;
    }

    public function test_complete_feeder_export_combines_approved_lt_with_original_ht_and_preserves_assets(): void
    {
        $batch = $this->approvedLtBatch();
        $service = app(\App\Services\FeederKmzService::class);
        $prepared = $service->prepare($this->feeder);
        $this->assertTrue($prepared['ready'], implode(' ', $prepared['issues']));
        $path = $service->export($this->feeder);
        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path));
            $xml = $zip->getFromName('doc.kml');
            $this->assertStringContainsString('Verified LT networks', $xml);
            $this->assertStringContainsString('old-transformer', $xml);
            $this->assertStringContainsString('ht-line', $xml);
            $doc = new \DOMDocument; $doc->loadXML($xml); $xp = new \DOMXPath($doc);
            $balloon = $xp->query('//*[@id="old-transformer"]/*[local-name()="description"]')->item(0)->textContent;
            $this->assertStringContainsString('Equipment Size', $balloon);
            $this->assertStringContainsString('Approved LT included', $balloon);
            $this->assertStringContainsString('Approval date (UTC)', $balloon);
            $this->assertSame('original-icon-bytes', $zip->getFromName('files/icon.png'));
            $zip->close();
        } finally { @unlink($path); }
        $this->actingAs($this->admin)->get(route('feeder-kmz.index', ['feeder_id' => $this->feeder->id]))->assertOk()->assertSee('Export current HT + approved LT');
        $download = $this->actingAs($this->admin)->get(route('feeder-kmz.export', $this->feeder));
        $download->assertOk()->assertDownload();
        @unlink($download->baseResponse->getFile()->getPathname());
        $this->actingAs($this->leader)->get(route('feeder-kmz.export', $this->feeder))->assertForbidden();
        $batch->update(['status' => 'draft', 'approved_revision_id' => null]);
        $prepared = $service->prepare($this->feeder);
        $this->assertTrue($prepared['ready']);
        $this->assertCount(0, $prepared['networks']);
        $this->assertSame(1, $prepared['counts']['pending_surveys']);
        $download = $this->actingAs($this->admin)->get(route('feeder-kmz.export', $this->feeder));
        $download->assertOk()->assertDownload();
        @unlink($download->baseResponse->getFile()->getPathname());
    }

    public function test_duplicate_general_duty_lt_surveys_are_excluded_without_blocking_ht(): void
    {
        $this->approvedLtBatch();
        $this->approvedLtBatch();
        $service = app(\App\Services\FeederKmzService::class);
        $prepared = $service->prepare($this->feeder);
        $this->assertTrue($prepared['ready']);
        $this->assertCount(0, $prepared['networks']);
        $this->assertSame(1, $prepared['counts']['excluded_lt']);
        $this->assertStringContainsString('Multiple approved LT surveys', implode(' ', $prepared['warnings']));
    }

    public function test_dedicated_and_unsurveyed_transformers_do_not_require_lt_geometry(): void
    {
        $this->approvedLtBatch();
        \App\Models\Transformer::create(['feeder_id' => $this->feeder->id, 'transformer_code' => 'DEDICATED',
            'equipment_use' => 'Dedicated', 'longitude' => 73, 'latitude' => 34]);
        \App\Models\Transformer::create(['feeder_id' => $this->feeder->id, 'transformer_code' => 'UNSURVEYED',
            'equipment_use' => 'General Duty', 'longitude' => 73, 'latitude' => 34]);
        $prepared = app(\App\Services\FeederKmzService::class)->prepare($this->feeder);
        $this->assertTrue($prepared['ready'], implode(' ', $prepared['issues']));
        $this->assertCount(1, $prepared['networks']);
        $this->assertSame('Dedicated - HT only', collect($prepared['coverage'])->firstWhere('code', 'DEDICATED')['status']);
    }

    public function test_dedicated_survey_geometry_is_not_added_to_lt_export(): void
    {
        $this->approvedLtBatch();
        $batch = $this->approvedLtBatch();
        $snapshot = $batch->approvedRevision->snapshot;
        $snapshot['transformers'][0]['header']['service_category'] = 'Dedicated';
        $revision = \App\Models\Mdb\Revision::create(['batch_id' => $batch->id, 'number' => 2, 'snapshot' => $snapshot,
            'sha256' => app(\App\Services\Mdb\SnapshotService::class)->hash($snapshot), 'created_by' => $this->admin->id, 'frozen_at' => now()]);
        $batch->update(['revision' => 2, 'approved_revision_id' => $revision->id]);
        $prepared = app(\App\Services\FeederKmzService::class)->prepare($this->feeder);
        $this->assertTrue($prepared['ready'], implode(' ', $prepared['issues']));
        $this->assertCount(1, $prepared['networks']);
    }

    public function test_outdated_approval_excludes_lt_but_changed_ht_baseline_blocks_export(): void
    {
        $batch = $this->approvedLtBatch();
        $service = app(\App\Services\FeederKmzService::class);
        $batch->update(['revision' => 2]);
        $prepared = $service->prepare($this->feeder);
        $this->assertTrue($prepared['ready']);
        $this->assertCount(0, $prepared['networks']);
        $this->assertStringContainsString('outdated', implode(' ', $prepared['warnings']));
        $batch->update(['revision' => 1]);
        Storage::disk('local')->put($this->baseline()->stored_path, 'changed baseline');
        $prepared = $service->prepare($this->feeder);
        $this->assertFalse($prepared['ready']);
        $this->assertStringContainsString('checksum changed', implode(' ', $prepared['issues']));
    }

    public function test_verified_ht_updates_are_included_and_pending_updates_do_not_block_release(): void
    {
        $this->approvedLtBatch();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $payload = ['client_uuid' => $uuid, 'kind' => 'ht_extension', 'transformer_code' => 'OLD-001',
            'phase' => 'RYB', 'capacity_kva' => 50, 'make' => 'Recorded make', 'location' => 'Recorded location',
            'mounting' => 'D.Pole', 'remarks' => 'Verified field addition', 'upstream_feature_id' => 'ht-line', 'upstream_reference' => 'J1',
            'nodes' => [['waypoint' => '001', 'latitude' => 34, 'longitude' => 73],
                ['waypoint' => '002', 'latitude' => 34.0005, 'longitude' => 73.0005]]];
        $update = \App\Models\HtNetworkUpdate::create(['client_uuid' => $uuid, 'feeder_id' => $this->feeder->id,
            'survey_team_id' => $this->team->id, 'collected_by' => $this->leader->id,
            'transformer_code' => 'OLD-001', 'transformer_key' => 'old-001', 'status' => 'pending',
            'payload_hash' => hash('sha256', json_encode($payload)), 'payload' => $payload]);
        $service = app(\App\Services\FeederKmzService::class);
        $this->assertTrue($service->prepare($this->feeder)['ready']);
        $this->assertStringContainsString('HT updates are excluded', implode(' ', $service->prepare($this->feeder)['warnings']));
        $update->update(['status' => 'verified', 'reviewed_by' => $this->admin->id, 'reviewed_at' => now()]);
        $path = $service->export($this->feeder);
        try {
            $zip = new \ZipArchive; $zip->open($path);
            $xml = $zip->getFromName('doc.kml'); $zip->close();
            $this->assertStringContainsString('Verified HT update '.$uuid, $xml);
            $this->assertStringContainsString('Verified LT networks', $xml);
        } finally { @unlink($path); }
    }

    public function test_progressive_exports_keep_161_ht_transformers_as_approved_lt_grows(): void
    {
        $baseline = $this->baseline();
        $path = Storage::disk('local')->path($baseline->stored_path);
        $zip = new \ZipArchive; $zip->open($path);
        $xml = $zip->getFromName('doc.kml');
        $extra = '';
        for ($i = 1; $i <= 161; $i++) {
            $code = $i === 1 ? 'OLD-001' : sprintf('T%03d', $i);
            \App\Models\Transformer::create(['feeder_id' => $this->feeder->id, 'transformer_code' => $code,
                'equipment_use' => $i <= 99 ? 'General Duty' : 'Dedicated', 'longitude' => 73, 'latitude' => 34]);
            if ($i > 1) $extra .= '<Placemark id="ht-'.$i.'"><description><![CDATA[<table><tr><td>Equipment Type</td><td>Transformer</td></tr><tr><td>Equipment Number</td><td>'.$code.'</td></tr></table>]]></description><Point><coordinates>73,34,0</coordinates></Point></Placemark>';
        }
        $zip->addFromString('doc.kml', str_replace('</Folder></Document>', $extra.'</Folder></Document>', $xml));
        $zip->close();
        $baseline->update(['file_sha256' => hash_file('sha256', $path)]);
        $service = app(\App\Services\FeederKmzService::class);
        $check = function (int $lt) use ($service): void {
            $prepared = $service->prepare($this->feeder);
            $this->assertTrue($prepared['ready']);
            $this->assertSame(161, $prepared['counts']['total_transformers']);
            $this->assertSame(99, $prepared['counts']['general_duty']);
            $this->assertSame(62, $prepared['counts']['dedicated']);
            $this->assertSame($lt, $prepared['counts']['approved_lt']);
            $export = $service->export($this->feeder);
            try {
                $zip = new \ZipArchive; $zip->open($export);
                $doc = new \DOMDocument; $doc->loadXML($zip->getFromName('doc.kml')); $zip->close();
                $xp = new \DOMXPath($doc);
                $this->assertSame(161 + 3 * $lt, $xp->query('//*[local-name()="Point"]')->length);
                $this->assertSame(1 + 2 * $lt, $xp->query('//*[local-name()="LineString"]')->length);
                $this->assertSame('73,34,0', $xp->query('//*[@id="ht-161"]//*[local-name()="coordinates"]')->item(0)->textContent);
            } finally { @unlink($export); }
        };
        $check(0);
        $this->approvedLtBatch(); $check(1);
        $this->approvedLtBatch('T002');
        $third = $this->approvedLtBatch('T003'); $check(3); $check(3);
        $third->update(['status' => 'draft', 'approved_revision_id' => null]); $check(2);
    }

    public function test_bad_lt_geometry_is_excluded_while_other_approved_networks_remain_exportable(): void
    {
        $this->approvedLtBatch();
        $batch = $this->approvedLtBatch('BROKEN');
        $snapshot = $batch->approvedRevision->snapshot;
        $snapshot['waypoints'][1]['latitude'] = null;
        $revision = \App\Models\Mdb\Revision::create(['batch_id' => $batch->id, 'number' => 2, 'snapshot' => $snapshot,
            'sha256' => app(\App\Services\Mdb\SnapshotService::class)->hash($snapshot), 'created_by' => $this->admin->id, 'frozen_at' => now()]);
        $batch->update(['revision' => 2, 'approved_revision_id' => $revision->id]);
        $prepared = app(\App\Services\FeederKmzService::class)->prepare($this->feeder);
        $this->assertTrue($prepared['ready']);
        $this->assertSame(1, $prepared['counts']['approved_lt']);
        $this->assertSame(1, $prepared['counts']['excluded_lt']);
        $this->assertStringContainsString('BROKEN', implode(' ', $prepared['warnings']));
    }

    public function test_coverage_counts_distinguish_approval_pending_unsurveyed_dedicated_and_unknown(): void
    {
        $this->approvedLtBatch();
        $pending = $this->approvedLtBatch('PENDING');
        $pending->update(['status' => 'submitted', 'approved_revision_id' => null]);
        foreach (['UNSURVEYED' => 'General Duty', 'DEDICATED' => 'Dedicated', 'UNKNOWN' => null] as $code => $duty) {
            \App\Models\Transformer::create(['feeder_id' => $this->feeder->id, 'transformer_code' => $code,
                'equipment_use' => $duty, 'longitude' => 73, 'latitude' => 34]);
        }
        $prepared = app(\App\Services\FeederKmzService::class)->prepare($this->feeder);
        $this->assertTrue($prepared['ready']);
        $counts = $prepared['counts'];
        foreach (['total_transformers'=>5, 'general_duty'=>3, 'dedicated'=>1, 'unclassified'=>1,
            'approved_lt'=>1, 'pending_surveys'=>1, 'unsurveyed_general'=>1, 'excluded_lt'=>0] as $key=>$expected) {
            $this->assertSame($expected, $counts[$key], $key);
        }
        $this->assertSame(33.3, $counts['completion_percent']);
        $this->assertCount(1, $prepared['networks']);
        $this->actingAs($this->admin)->get(route('feeder-kmz.index', ['feeder_id' => $this->feeder->id]))
            ->assertOk()->assertSee('33.3%')->assertSee('Duty not classified')->assertSee('Export current HT + approved LT');
    }

    public function test_current_map_preview_matches_export_geometry_and_does_not_create_history(): void
    {
        $this->approvedLtBatch();
        $this->actingAs($this->admin);
        $preview = $this->getJson(route('feeder-kmz.preview', $this->feeder))->assertOk()->assertJsonPath('counts.approved_lt', 1)
            ->assertJsonPath('type', 'FeatureCollection');
        $features = $preview->json('features');
        $this->assertCount(2, array_filter($features, fn ($f) => $f['properties']['kind'] === 'ht'));
        $this->assertCount(5, array_filter($features, fn ($f) => $f['properties']['kind'] === 'lt'));
        $this->assertDatabaseCount('feeder_kmz_exports', 0);
        $this->actingAs($this->leader)->getJson(route('feeder-kmz.preview', $this->feeder))->assertForbidden();
    }

    public function test_export_history_retains_exact_files_and_tracks_added_and_removed_lt(): void
    {
        $firstBatch = $this->approvedLtBatch();
        $this->actingAs($this->admin);
        $this->get(route('feeder-kmz.export', $this->feeder))->assertOk()->assertDownload();
        $first = \App\Models\FeederKmzExport::firstOrFail();
        $this->assertSame(1, $first->counts['approved_lt']);
        $this->assertSame(['OLD-001'], $first->changes['added']);
        $this->assertSame($this->admin->id, $first->created_by);
        $this->assertSame($firstBatch->approvedRevision->sha256, $first->networks[0]['revision_sha256']);
        $savedPath = Storage::disk('local')->path($first->path);
        $this->assertFileExists($savedPath);
        $this->assertSame($first->sha256, hash_file('sha256', $savedPath));
        $this->approvedLtBatch('SECOND');
        $this->get(route('feeder-kmz.export', $this->feeder))->assertOk();
        $second = \App\Models\FeederKmzExport::latest('id')->first();
        $this->assertSame(['SECOND'], $second->changes['added']);
        $this->assertSame(2, $second->counts['approved_lt']);
        $firstBatch->update(['status'=>'draft', 'approved_revision_id'=>null]);
        $this->get(route('feeder-kmz.export', $this->feeder))->assertOk();
        $third = \App\Models\FeederKmzExport::latest('id')->first();
        $this->assertSame(['OLD-001'], $third->changes['removed']);
        $this->get(route('feeder-kmz.history.download', $first))->assertOk()->assertDownload($first->filename);
        $this->assertSame($first->sha256, hash_file('sha256', $savedPath));
        $this->get(route('feeder-kmz.history', $first))->assertOk()->assertSee('Saved export map preview')->assertSee('OLD-001');
        $this->getJson(route('feeder-kmz.history.preview', $first))->assertOk()->assertJsonPath('counts.approved_lt', 1);
        $this->assertDatabaseCount('feeder_kmz_exports', 3);
        $this->get(route('feeder-kmz.index',['feeder_id'=>$this->feeder->id]))->assertOk()->assertSee('Export history');
        $this->actingAs($this->leader)->get(route('feeder-kmz.history', $first))->assertForbidden();
        $this->get(route('feeder-kmz.history.download', $first))->assertForbidden();
        $this->getJson(route('feeder-kmz.history.preview', $first))->assertForbidden();
    }

    public function test_history_detects_changed_files_and_keeps_the_record_when_archive_is_missing(): void
    {
        $this->approvedLtBatch(); $this->actingAs($this->admin);
        $this->get(route('feeder-kmz.export', $this->feeder))->assertOk();
        $record = \App\Models\FeederKmzExport::firstOrFail();
        Storage::disk('local')->put($record->path, 'altered');
        $this->getJson(route('feeder-kmz.history.download', $record))->assertUnprocessable()->assertJsonFragment(['message'=>'The archived KMZ checksum changed. Restore the original file from backup.']);
        Storage::disk('local')->delete($record->path);
        $this->getJson(route('feeder-kmz.history.preview', $record))->assertUnprocessable();
        $this->assertDatabaseCount('feeder_kmz_exports', 1);
    }

}
