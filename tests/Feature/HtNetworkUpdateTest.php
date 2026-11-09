<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\FeederAssignment;
use App\Models\FieldSurvey;
use App\Models\GridStation;
use App\Models\HtNetworkUpdate;
use App\Models\MobileDeviceToken;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyTeam;
use App\Models\TransformerKmzImport;
use App\Models\User;
use App\Services\HtKmzService;
use App\Services\HtNetworkWorkflow;
use App\Services\TransformerKmzImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HtNetworkUpdateTest extends TestCase
{
    use RefreshDatabase;

    private User $leader;

    private User $admin;

    private Organization $organization;

    private Project $project;

    private SurveyTeam $team;

    private Feeder $feeder;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->organization = Organization::create(['name' => 'Survey Organization', 'type' => 'internal', 'status' => 'active']);
        $this->leader = User::factory()->create(['organization_id' => $this->organization->id, 'role' => UserRole::MobileSurveyUser]);
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
        $this->token = $this->tokenFor($this->leader);
        $this->withHeader('Authorization', 'Bearer '.$this->token);
    }

    private function tokenFor(User $user): string
    {
        $plain = bin2hex(random_bytes(64));
        MobileDeviceToken::create(['user_id' => $user->id, 'device_name' => 'Test phone', 'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addDays(30)]);

        return $plain;
    }

    private function payload(string $status = 'submitted'): array
    {
        $startId = (string) Str::uuid();
        $endId = (string) Str::uuid();

        return [
            'client_uuid' => (string) Str::uuid(), 'base_revision' => 0,
            'survey_team_id' => $this->team->id, 'feeder_id' => $this->feeder->id,
            'transformer_id' => null, 'transformer_code' => 'B2308',
            'survey_date' => today()->toDateString(), 'status' => $status,
            'header' => ['substation' => 'Substation', 'capacity_kva' => 50, 'inspectors' => 'Field inspector', 'location' => 'Street 1', 'mounting' => 'D.Pole', 'duty' => 'General Duty'],
            'rows' => [['row_id' => $startId, 'se' => 'S', 'group' => '01', 'date' => today()->toDateString(), 'gps_waypoint' => '0008', 'phase' => '3', 'conductor_r' => 'A', 'equipment_type' => 'TR', 'pole_class' => 'PCO', 'pole_height_ft' => 36, 'consumers' => ['rs' => 5, 'lc' => 1], 'intersection' => true],
                ['row_id' => $endId, 'se' => 'E', 'group' => '01', 'date' => today()->toDateString(), 'gps_waypoint' => '0009', 'phase' => 'R', 'conductor_r' => 'A', 'equipment_type' => 'TR', 'pole_class' => 'PCO', 'pole_height_ft' => 0]],
            'solar' => [['row_id' => $startId, 'consumer_reference' => '0000123', 'installed_pv_kw' => 5.5, 'remarks' => 'Rooftop']], 'remarks' => 'Field visit',
        ];
    }

    private function htPayload(): array
    {
        return ['client_uuid' => (string) Str::uuid(), 'base_revision' => 0,
            'survey_team_id' => $this->team->id, 'feeder_id' => $this->feeder->id,
            'transformer_code' => 'NEW-001', 'kind' => 'ht_extension', 'capacity_kva' => 100,
            'make' => 'PEL', 'location' => 'New street', 'mounting' => 'D.Pole', 'phase' => 'R-Y-B',
            'upstream_feature_id' => 'ht-line', 'baseline_sha256' => $this->baseline()->file_sha256,
            'upstream_reference' => 'Existing HT junction J1', 'observed_on' => today()->toDateString(),
            'nodes' => [['waypoint' => 'J1', 'latitude' => 34, 'longitude' => 73],
                ['waypoint' => 'TR1', 'latitude' => 34.001, 'longitude' => 73.001, 'conductor_r' => 'RABBIT', 'conductor_y' => 'RABBIT', 'conductor_b' => 'RABBIT']],
            'remarks' => 'New extension beyond existing HT junction; connection confirmed in field',
            'photos' => [base64_encode(UploadedFile::fake()->image('ht.jpg', 10, 10)->get())]];
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

    private function verify(HtNetworkUpdate $update, array $overrides = []): HtNetworkUpdate
    {
        return app(HtNetworkWorkflow::class)->review($this->admin, $update, $overrides + ['revision' => $update->revision,
            'decision' => 'verified', 'review_note' => 'Route, coordinates and upstream connection checked', 'nodes' => $update->payload['nodes']]);
    }

    public function test_pending_ht_can_link_lt_and_blocks_final_release_until_independently_verified(): void
    {
        $ht = $this->htPayload();
        $this->postJson('/api/v1/field/ht-updates/sync', $ht)->assertOk()->assertJsonPath('status', 'pending');
        $this->postJson('/api/v1/field/ht-updates/sync', $ht)->assertOk()->assertJsonPath('revision', 1);
        $payload = $this->payload('draft');
        $payload['transformer_code'] = $ht['transformer_code'];
        $payload['ht_update_uuid'] = $ht['client_uuid'];
        $this->postJson('/api/v1/field/surveys/sync', $payload)->assertCreated();
        $survey = FieldSurvey::firstOrFail();
        $update = HtNetworkUpdate::firstOrFail();
        $this->assertEquals($update->id, $survey->ht_network_update_id);
        $this->assertDatabaseCount('transformers', 0);
        try {
            app(HtNetworkWorkflow::class)->assertVerified($survey);
            $this->fail('Pending HT must block final release');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('ht_update', $e->errors());
        }
        $verified = $this->verify($update);
        app(HtNetworkWorkflow::class)->assertVerified($survey->fresh());
        $this->assertNotNull($verified->transformer_id);
        $this->assertDatabaseCount('field_surveys', 1);
        $this->assertDatabaseCount('transformers', 1);
        $this->assertEquals(1, $this->feeder->fresh()->total_transformers);
        $this->postJson('/api/v1/field/ht-updates/sync', $ht)->assertOk()->assertJsonPath('status', 'verified');
        $this->postJson('/api/v1/field/surveys/sync', $payload)->assertOk()->assertJsonPath('revision', 1);
    }

    public function test_return_and_resubmit_preserve_lt_identity_and_reject_stale_changes(): void
    {
        $ht = $this->htPayload();
        $this->postJson('/api/v1/field/ht-updates/sync', $ht)->assertOk();
        $update = HtNetworkUpdate::firstOrFail();
        app(HtNetworkWorkflow::class)->review($this->admin, $update, ['revision' => 1, 'decision' => 'returned', 'review_note' => 'Please clarify pole type']);
        $stale = $ht;
        $stale['remarks'] = 'Changed';
        $this->postJson('/api/v1/field/ht-updates/sync', $stale)->assertConflict();
        $stale['base_revision'] = 2;
        $this->postJson('/api/v1/field/ht-updates/sync', $stale)->assertOk()->assertJsonPath('revision', 3)->assertJsonPath('status', 'pending');
        $this->assertEquals($update->client_uuid, HtNetworkUpdate::first()->client_uuid);
    }

    public function test_duplicate_cross_account_and_self_verification_are_rejected(): void
    {
        $ht = $this->htPayload();
        $this->postJson('/api/v1/field/ht-updates/sync', $ht)->assertOk();
        $duplicate = $ht;
        $duplicate['client_uuid'] = (string) Str::uuid();
        $this->postJson('/api/v1/field/ht-updates/sync', $duplicate)->assertUnprocessable();
        $other = User::factory()->create(['organization_id' => $this->organization->id, 'role' => UserRole::MobileSurveyUser]);
        $this->team->members()->attach($other->id, ['is_leader' => false]);
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($other))
            ->postJson('/api/v1/field/ht-updates/sync', $ht)->assertConflict();
        $this->actingAs($other)->get(route('ht-updates.photo', [HtNetworkUpdate::first(), 0]))->assertNotFound();
        $this->leader->assignRole('super_admin');
        $this->actingAs($this->leader)->post(route('ht-updates.review', HtNetworkUpdate::first()), ['revision' => 1, 'decision' => 'returned', 'review_note' => 'Self review'])->assertForbidden();
    }

    public function test_verified_kmz_preserves_baseline_and_includes_only_verified_updates(): void
    {
        $ht = $this->htPayload();
        $this->postJson('/api/v1/field/ht-updates/sync', $ht)->assertOk();
        $baselinePath = Storage::disk('local')->path($this->baseline()->stored_path);
        $hash = hash_file('sha256', $baselinePath);
        $pending = app(HtKmzService::class)->export($this->feeder);
        $zip = new \ZipArchive;
        $zip->open($pending);
        $this->assertStringNotContainsString('field-'.$ht['client_uuid'], $zip->getFromName('doc.kml'));
        $zip->close();
        unlink($pending);
        $this->verify(HtNetworkUpdate::first());
        $path = app(HtKmzService::class)->export($this->feeder);
        $zip->open($path);
        $xml = $zip->getFromName('doc.kml');
        $this->assertSame('original-icon-bytes', $zip->getFromName('files/icon.png'));
        $zip->close();
        unlink($path);
        $this->assertSame($hash, hash_file('sha256', $baselinePath));
        $doc = new \DOMDocument;
        $this->assertTrue($doc->loadXML($xml));
        $xp = new \DOMXPath($doc);
        $this->assertEquals(4, $xp->query('//*[local-name()="Placemark"]')->length);
        $this->assertEquals(1, $xp->query('//*[@id="old-transformer"]')->length);
        $this->assertStringContainsString('NEW-001', $xml);
        $this->assertStringContainsString('73.001,34.001,0', $xml);
        $this->assertStringNotContainsString('Z:/old.jpg', $xp->query('//*[@id="field-'.$ht['client_uuid'].'-p1"]')->item(0)->textContent);
    }

    public function test_approval_rejects_unresolved_coordinates_and_wrong_upstream_connection(): void
    {
        $ht = $this->htPayload();
        $this->postJson('/api/v1/field/ht-updates/sync', $ht)->assertOk();
        $update = HtNetworkUpdate::first();
        $nodes = $ht['nodes'];
        $nodes[0]['latitude'] = 35;
        $this->actingAs($this->admin)->post(route('ht-updates.review', $update), ['revision' => 1, 'decision' => 'verified', 'review_note' => 'Check', 'connection_confirmed' => 1, 'nodes' => $nodes])->assertSessionHasErrors('nodes');
        $this->assertSame('pending', $update->fresh()->status);
        $this->assertDatabaseCount('transformers', 0);
    }

    public function test_web_review_and_mobile_status_are_distinct_and_keep_photos_private(): void
    {
        $payload = $this->htPayload();
        $this->postJson('/api/v1/field/ht-updates/sync', $payload)->assertOk();
        $update = HtNetworkUpdate::first();
        $this->getJson('/api/v1/field/ht-updates')->assertOk()->assertJsonPath('ht_updates.0.status', 'pending')->assertJsonMissingPath('ht_updates.0.payload.photos');
        $lt = $this->payload('draft');
        $lt['transformer_code'] = $payload['transformer_code'];
        $lt['ht_update_uuid'] = $payload['client_uuid'];
        $this->postJson('/api/v1/field/surveys/sync', $lt)->assertCreated();
        $this->actingAs($this->admin)->get(route('ht-updates.index'))->assertOk()->assertSee('HT Network Updates')->assertSee('LT Transformer Surveys');
        $this->get(route('field-surveys.index'))->assertOk()->assertSee('LT Transformer Surveys')->assertSee('HT Network Update:')->assertSee('View HT connection');
        $this->get(route('ht-updates.show', $update))->assertOk()->assertSee('Independent HT verification')->assertSee('Return for correction');
        $this->get(route('ht-updates.photo', [$update, 0]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_missing_ht_link_cannot_bypass_pending_verification(): void
    {
        $ht = $this->htPayload();
        $this->postJson('/api/v1/field/ht-updates/sync', $ht)->assertOk();
        $payload = $this->payload('draft');
        $payload['transformer_code'] = $ht['transformer_code'];
        $this->postJson('/api/v1/field/surveys/sync', $payload)->assertUnprocessable()->assertJsonValidationErrors('ht_update_uuid');
        $payload['ht_update_uuid'] = $ht['client_uuid'];
        $this->postJson('/api/v1/field/surveys/sync', $payload)->assertCreated();
    }

    public function test_client_baseline_replacement_is_blocked_but_first_import_can_resolve_missing_reference(): void
    {
        $ht = $this->htPayload();
        $this->postJson('/api/v1/field/ht-updates/sync', $ht)->assertOk();
        $baseline = $this->baseline();
        $replacement = TransformerKmzImport::create(['feeder_id' => $this->feeder->id, 'file_name' => 'replacement.kmz',
            'stored_path' => $baseline->stored_path, 'status' => 'pending']);
        $path = Storage::disk('local')->path($baseline->stored_path);
        try {
            app(TransformerKmzImportService::class)->import($replacement, $path);
            $this->fail('Replacement must protect HT field additions');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Reconcile', $e->getMessage());
        }
        $this->assertSame('pending', HtNetworkUpdate::first()->status);
        $this->assertDatabaseCount('transformers', 0);
        $baseline->update(['status' => 'failed']);
        $first = TransformerKmzImport::create(['feeder_id' => $this->feeder->id, 'file_name' => 'first-client.kmz',
            'stored_path' => $baseline->stored_path, 'status' => 'pending']);
        $result = app(TransformerKmzImportService::class)->import($first, $path);
        $this->assertSame('completed', $result->status);
        $this->assertSame('pending', HtNetworkUpdate::first()->status);
    }
}
