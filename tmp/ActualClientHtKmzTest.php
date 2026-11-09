<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\FeederAssignment;
use App\Models\FieldSurvey;
use App\Models\FieldSurveyAttachment;
use App\Models\GridStation;
use App\Models\MobileDeviceToken;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\SurveyDailyEntry;
use App\Models\SurveyorAssignment;
use App\Models\SurveyTeam;
use App\Models\Transformer;
use App\Models\User;
use App\Services\Mdb\EntryLookups;
use App\Support\SurveyTeamGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use App\Models\HtNetworkUpdate;
use App\Models\TransformerKmzImport;
use App\Services\HtNetworkWorkflow;
use App\Services\HtKmzService;

class ActualClientHtKmzTest extends TestCase
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
            'header' => ['substation' => 'Substation', 'capacity_kva' => 50, 'inspectors' => 'Field inspector', 'location' => 'Street 1', 'mounting' => 'D.Pole', 'phase' => 'R-Y-B', 'duty' => 'General Duty'],
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
        if ($existing) { return $existing; }
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

    public function test_actual_client_files_preserve_features_and_export_new_ht_assets(): void
    {
        $root = 'W:/2120 HAZECO T&D Losses Project/HAZECO Data/Client Data';
        $names = ['BAGNOTER (000412).kmz', 'CANTT HT (000402).kmz', 'DHAMTOUR (000401).kmz'];
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (in_array($file->getFilename(), $names, true)) { $found[$file->getFilename()] = $file->getPathname(); }
        }
        foreach ($names as $name) {
            HtNetworkUpdate::query()->delete(); Transformer::query()->delete();
            $import = $this->baseline(); $path = Storage::disk('local')->path($import->stored_path);
            copy($found[$name], $path); $hash = hash_file('sha256', $path); $import->update(['file_sha256' => $hash]);
            $network = app(HtKmzService::class)->network($this->feeder->id);
            $feature = collect($network['features'])->firstWhere('type', 'line');
            [$lon, $lat] = $feature['coordinates'][0];
            $payload = $this->htPayload();
            $payload['upstream_feature_id'] = $feature['id']; $payload['baseline_sha256'] = $hash;
            $payload['nodes'][0]['latitude'] = $lat; $payload['nodes'][0]['longitude'] = $lon;
            $payload['nodes'][1]['latitude'] = $lat + 0.0001; $payload['nodes'][1]['longitude'] = $lon + 0.0001;
            $this->postJson('/api/v1/field/ht-updates/sync', $payload)->assertOk();
            $this->verify(HtNetworkUpdate::first());
            $output = app(HtKmzService::class)->export($this->feeder);
            $before = app(\App\Services\TransformerKmzImportService::class)->inspect($path);
            $after = app(\App\Services\TransformerKmzImportService::class)->inspect($output);
            $this->assertEquals($before['transformer_count'] + 1, $after['transformer_count'], $name);
            $this->assertEquals($before['total_placemarks'] + 2, $after['total_placemarks'], $name);
            $this->assertCount(1, array_filter($after['transformers'], fn ($t) => $t['transformer_code'] === 'NEW-001'));
            $this->assertSame($hash, hash_file('sha256', $path));
            $oldZip = new \ZipArchive; $newZip = new \ZipArchive; $oldZip->open($path); $newZip->open($output);
            $this->assertSame($oldZip->numFiles, $newZip->numFiles);
            for ($i = 0; $i < $oldZip->numFiles; $i++) {
                $entry = $oldZip->getNameIndex($i);
                if (! str_ends_with(strtolower($entry), '.kml')) { $this->assertSame($oldZip->getFromName($entry), $newZip->getFromName($entry)); }
            }
            $oldZip->close(); $newZip->close(); unlink($output);
            echo "\nActual client roundtrip passed: ".$name."\n";
        }
    }
}
