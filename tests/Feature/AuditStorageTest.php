<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\BackupGuard;
use App\Services\Audit\Payload;
use App\Services\AuditService;
use App\Services\FeederImportService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class AuditStorageTest extends TestCase
{
    use RefreshDatabase;

    private array $plans = [];

    protected function tearDown(): void
    {
        foreach ($this->plans as $path) {
            if (is_file($path) && dirname($path) === storage_path('app/private/audit-maintenance')) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_updates_keep_changed_old_new_values_and_ignore_timestamp_only_saves(): void
    {
        $actor = User::factory()->create();
        $service = app(AuditService::class);
        $old = ['id' => 7, 'status' => 'draft', 'header' => ['capacity' => 100, 'location' => 'Original'], 'updated_at' => 'yesterday'];
        $new = ['id' => 7, 'status' => 'draft', 'header' => ['capacity' => 100, 'location' => 'Corrected'], 'updated_at' => 'today'];
        $event = $service->record($actor, 'field_survey.updated', $actor, $old, $new);
        $this->assertSame(['header' => ['location' => 'Original']], $event->old_values);
        $this->assertSame(['header' => ['location' => 'Corrected']], $event->new_values);
        $this->assertSame($actor->id, $event->user_id);
        $this->assertNull($service->record($actor, 'field_survey.updated', $actor, $new, $new + ['created_at' => 'now']));
        $this->assertNull($service->record($actor, 'field_survey.updated', $actor, ['revision' => 1], ['revision' => 2]));
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertNotNull($service->record($actor, 'user.password_reset', $actor));
        $this->assertNull($service->record($actor, 'audit.deleted', $event));
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_secrets_binary_datasets_and_loaded_relationships_are_excluded(): void
    {
        $actor = User::factory()->create();
        $actor->setRelation('organization', collect(['name' => 'unnecessary relationship']));
        $event = app(AuditService::class)->record($actor, 'field_survey.created', $actor, [], [
            'status' => 'submitted', 'password' => 'NEVER-STORE-THIS', 'nested' => ['refresh_token' => 'ALSO-SECRET'],
            'pdf_base64' => base64_encode(str_repeat('PDF-CONTENT', 1000)),
            'rows' => [['waypoint' => 'not an entire dataset']], 'organization' => ['name' => 'unnecessary relationship'],
        ], 'Failed request access_token=PRIVATE Bearer HIDDEN');
        $json = json_encode($event->new_values);
        foreach (['NEVER-STORE-THIS', 'ALSO-SECRET', 'PRIVATE', 'HIDDEN', 'not an entire dataset', 'unnecessary relationship', base64_encode('PDF-CONTENT')] as $secret) {
            $this->assertStringNotContainsString($secret, $json.' '.$event->reason);
        }
        $this->assertSame('submitted', $event->new_values['status']);
        $this->assertTrue($event->new_values['_audit']['details_omitted']);
        $this->assertSame(1, $event->new_values['rows']['count']);
        [$before, $after] = (new Payload)->prepare([], $event->new_values);
        $this->assertSame($event->new_values, $after);
        $text = (new Payload)->text('password="spaces, punctuation; inside"; api_key=another-secret');
        $this->assertStringNotContainsString('inside', $text);
        $this->assertStringNotContainsString('spaces', $text);
        $this->assertStringNotContainsString('another-secret', $text);
    }

    public function test_payload_budget_is_valid_json_and_preserves_audit_identity(): void
    {
        config(['audit.payload_bytes' => 2048]);
        $actor = User::factory()->create();
        $values = array_fill(0, 50, str_repeat('large Unicode value آ', 120));
        $event = app(AuditService::class)->record($actor, 'import.completed', $actor, [], ['details' => $values, 'status' => 'complete', 'count' => 50]);
        $this->assertLessThanOrEqual(2048, strlen(json_encode([$event->old_values ?? [], $event->new_values])));
        $this->assertSame('complete', $event->new_values['status']);
        $this->assertSame(50, $event->new_values['count']);
        $this->assertSame('import.completed', $event->action);
        $this->assertSame($actor->id, $event->auditable_id);
        $this->assertNotNull($event->created_at);
        $this->assertTrue($event->new_values['_audit']['details_omitted']);
        [$old, $new] = (new Payload)->prepare([], [str_repeat('x', 20000) => str_repeat('y', 50000)]);
        $this->assertLessThanOrEqual(2048, strlen(json_encode([$old, $new])));
    }

    public function test_batch_snapshot_diff_size_reduction_is_measured(): void
    {
        $snapshot = ['batch' => ['id' => 17, 'revision' => 1], 'waypoints' => [], 'transformers' => []];
        for ($i = 0; $i < 1000; $i++) {
            $snapshot['waypoints'][] = ['id' => $i, 'latitude' => 34.1, 'longitude' => 73.2, 'description' => str_repeat('original GPX observation ', 5)];
        }
        for ($i = 0; $i < 100; $i++) {
            $snapshot['transformers'][] = ['id' => $i, 'code' => 'TEST-'.$i, 'header' => ['location' => 'Original'], 'sections' => []];
        }
        $changed = $snapshot;
        $changed['transformers'][7]['header']['location'] = 'Corrected';
        $before = strlen(json_encode([$snapshot, $changed]));
        [$old, $new] = (new Payload)->prepare($snapshot, $changed);
        $after = strlen(json_encode([$old, $new]));
        $this->assertGreaterThan(300000, $before);
        $this->assertLessThan(512, $after);
        $this->assertSame('Original', $old['transformers'][7]['header']['location']);
        $this->assertSame('Corrected', $new['transformers'][7]['header']['location']);
        $this->assertArrayNotHasKey('waypoints', $new);
        // Stable aggregate evidence, contains no production information.
        file_put_contents(base_path('output/audit-payload-example.json'), json_encode(['fixture' => '1000 GPX points, 100 transformers, one location correction', 'before_bytes' => $before, 'after_bytes' => $after], JSON_PRETTY_PRINT));
    }

    public function test_prune_dry_run_lists_exact_expired_ids_and_preserves_recent_important_and_protected_history(): void
    {
        $oldRoutine = $this->event('mdb.pdf_line_drawn', 31);
        $recentRoutine = $this->event('mdb.pdf_line_drawn', 29);
        $important = $this->event('user.password_reset', 90);
        $expiredImportant = $this->event('user.password_reset', 181);
        $unknown = $this->event('new.business.action', 90);
        $protected = $this->event('mdb.workflow_task_assigned', 500);
        $nullDate = $this->event('user.password_reset', 1000);
        DB::table('audit_logs')->where('id', $nullDate)->update(['created_at' => null]);
        $plan = $this->preview('prune');
        $details = json_decode(file_get_contents($plan['path']), true);
        $this->assertSame([$oldRoutine, $expiredImportant], array_column($details['records'], 'id'));
        $this->assertDatabaseCount('audit_logs', 7);
        $this->mock(BackupGuard::class)->shouldReceive('verify')->once()->with($expiredImportant);
        $this->execute('prune', $plan);
        foreach ([$recentRoutine, $important, $unknown, $protected, $nullDate] as $id) {
            $this->assertDatabaseHas('audit_logs', ['id' => $id]);
        }
        $this->assertDatabaseCount('audit_logs', 5); // No recursive cleanup events.
    }

    public function test_cleanup_refuses_missing_backup_modified_plan_and_database_mismatch(): void
    {
        $this->event('user.password_reset', 181);
        $plan = $this->preview('prune');
        $this->mock(BackupGuard::class)->shouldReceive('verify')->once()->andThrow(new RuntimeException('Verified backup required.'));
        $this->assertSame(1, $this->execute('prune', $plan, false));
        $this->assertDatabaseCount('audit_logs', 1);
        $plan['sha256'] = str_repeat('0', 64);
        $this->assertSame(1, $this->execute('prune', $plan, false));
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_invalid_retention_cannot_make_important_events_more_disposable_than_routine_events(): void
    {
        $this->event('user.password_reset', 40);
        config(['audit.routine_days' => 30, 'audit.important_days' => 5]);
        $this->artisan('audit:maintain', ['--dry-run' => true])->assertFailed();
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_cleanup_plan_rechecks_retention_and_is_bounded_and_resumable(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->event('mdb.pdf_line_drawn', 31);
        }
        $plan = $this->preview('prune', ['--limit' => 2]);
        $this->assertFalse($plan['complete_range']);
        $this->assertSame(2, $plan['eligible_in_scan']);
        $this->mock(BackupGuard::class)->shouldReceive('verify')->times(2);
        // A policy change after preview makes these records ineligible.
        config(['audit.routine_days' => 60]);
        $this->execute('prune', $plan);
        $this->assertDatabaseCount('audit_logs', 5);
        config(['audit.routine_days' => 30]);
        $this->execute('prune', $plan);
        $this->assertDatabaseCount('audit_logs', 3);
        $next = $this->preview('prune', ['--after-id' => $plan['next_after_id'], '--limit' => 2]);
        $this->assertSame([$ids[2], $ids[3]], array_column(json_decode(file_get_contents($next['path']), true)['records'], 'id'));
    }

    public function test_legacy_compaction_preserves_metadata_diffs_and_skips_huge_or_protected_records(): void
    {
        $old = ['status' => 'draft', 'settings' => ['voltage' => 0.4], 'waypoints' => array_fill(0, 1000, ['latitude' => 34.1, 'longitude' => 73.1])];
        $new = array_replace($old, ['status' => 'approved']);
        $id = $this->event('mdb.data_edited', 10, $old, $new);
        $protected = $this->event('mdb.workflow_task_assigned', 200, [], ['entry_assigned_to' => 123]);
        $huge = $this->event('mdb.data_edited', 10, [], ['huge' => str_repeat('x', 2200000)]);
        $original = DB::table('audit_logs')->find($id);
        $plan = $this->preview('compact');
        $this->assertSame(1, $plan['eligible_in_scan']);
        $this->assertSame($huge, $plan['skipped'][0]['id']);
        $this->mock(BackupGuard::class)->shouldReceive('verify')->twice();
        $this->execute('compact', $plan);
        $after = DB::table('audit_logs')->find($id);
        foreach (['id', 'action', 'auditable_type', 'auditable_id', 'user_id', 'created_at', 'updated_at'] as $field) {
            $this->assertSame($original->$field, $after->$field);
        }
        $this->assertSame(['status' => 'draft'], json_decode($after->old_values, true));
        $this->assertSame(['status' => 'approved'], json_decode($after->new_values, true));
        $this->assertLessThan(strlen($original->old_values), strlen($after->old_values));
        $this->execute('compact', $plan); // Idempotent: original fingerprint no longer matches.
        $this->assertEquals($after, DB::table('audit_logs')->find($id));
        $this->assertDatabaseHas('audit_logs', ['id' => $protected]);
        $this->assertDatabaseHas('audit_logs', ['id' => $huge]);
    }

    public function test_scheduler_is_daily_and_cleanup_is_disabled_by_default(): void
    {
        $id = $this->event('user.password_reset', 200);
        config(['audit.cleanup_enabled' => false]);
        $this->artisan('audit:maintain', ['--scheduled' => true])->expectsOutputToContain('disabled')->assertSuccessful();
        $this->assertDatabaseHas('audit_logs', ['id' => $id]);
        $events = app(Schedule::class)->events();
        $event = collect($events)->first(fn ($event) => str_contains($event->command ?? '', 'audit:maintain'));
        $this->assertSame('0 4 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame('Asia/Karachi', $event->timezone);
        config(['audit.cleanup_enabled' => true]);
        $this->artisan('audit:maintain', ['--scheduled' => true, '--dry-run' => true])->assertFailed();
        $this->assertDatabaseHas('audit_logs', ['id' => $id]);
    }

    public function test_report_is_bounded_and_never_prints_payloads(): void
    {
        $this->event('mdb.entry_row_saved', 0, [], ['secret' => str_repeat('DO-NOT-PRINT', 100)]);
        $this->event('user.password_reset', 0);
        $this->assertSame(0, Artisan::call('audit:report', ['--limit' => 1]));
        $output = Artisan::output();
        $this->assertStringNotContainsString('DO-NOT-PRINT', $output);
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $report['rows_measured']);
        $this->assertFalse($report['complete_range']);
        $this->assertGreaterThan(1000, $report['column_bytes']['new_values']);
    }

    public function test_repeated_feeder_import_has_one_summary_and_no_duplicate_unchanged_row_events(): void
    {
        $actor = User::factory()->create();
        $csv = "project_code,circle_code,division_code,sub_division_code,grid_station_code,feeder_code,feeder_name,total_transformers,survey_drive_url,mdb_drive_url,processing_drive_url\n";
        $csv .= "AUDIT,C,D,S,G,F,Test feeder,10,,,\n";
        $csv .= "AUDIT,C,D,S,G,F,Test feeder,10,,,\n";
        $result = app(FeederImportService::class)->import(UploadedFile::fake()->createWithContent('audit.csv', $csv), $actor);
        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['updated']); // Existing UI counting convention is unchanged.
        $this->assertSame(0, $result['rejected']);
        $this->assertSame(1, AuditLog::where('action', 'feeder.imported')->count());
        $this->assertSame(0, AuditLog::where('action', 'feeder.import_updated')->count());
        $this->assertSame(1, AuditLog::where('action', 'feeder.import_completed')->count());
        $this->assertSame(2, AuditLog::where('action', 'feeder.import_completed')->sole()->new_values['total']);
        $this->assertSame(1, AuditLog::where('action', 'feeder.import_completed')->sole()->new_values['unchanged']);
        $this->assertSame(0, AuditLog::where('action', 'feeder.import_completed')->sole()->new_values['existing_rows_changed']);
        $this->assertDatabaseCount('feeders', 1);
    }

    public function test_viewer_retains_filtering_and_bounds_redacts_legacy_details_without_writing_them(): void
    {
        $actor = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $small = $this->event('user.created', 0, [], ['name' => 'Visible name', 'password' => 'LEGACY-SECRET']);
        $big = $this->event('user.created', 0, [], ['large' => str_repeat('HUGE-PAYLOAD', 5000)]);
        $this->event('another.action', 0);
        $page = $this->actingAs($actor)->get(route('admin.audit.index', ['action' => 'user.created']))->assertOk();
        $page->assertSee('Visible name')->assertSee('Legacy payload exceeds display limit')->assertDontSee('LEGACY-SECRET')->assertDontSee('HUGE-PAYLOAD')->assertDontSee('another.action');
        $this->assertStringContainsString('LEGACY-SECRET', DB::table('audit_logs')->find($small)->new_values);
        $this->assertStringContainsString('HUGE-PAYLOAD', DB::table('audit_logs')->find($big)->new_values);
        $this->assertDatabaseCount('audit_logs', 3);
    }

    private function event(string $action, int $days, array $old = [], array $new = []): int
    {
        return DB::table('audit_logs')->insertGetId(['action' => $action, 'auditable_type' => User::class, 'auditable_id' => 1,
            'old_values' => $old ? json_encode($old) : null, 'new_values' => $new ? json_encode($new) : null,
            'created_at' => now()->subDays($days), 'updated_at' => now()->subDays($days)]);
    }

    private function preview(string $operation, array $options = []): array
    {
        $code = Artisan::call('audit:maintain', ['operation' => $operation, '--dry-run' => true] + $options);
        $output = Artisan::output();
        $this->assertSame(0, $code, $output);
        $plan = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->plans[] = $plan['path'];

        return $plan;
    }

    private function execute(string $operation, array $plan, bool $success = true): int
    {
        $code = Artisan::call('audit:maintain', ['operation' => $operation, '--execute' => true, '--plan' => $plan['plan'], '--plan-sha256' => $plan['sha256']]);
        if ($success) {
            $this->assertSame(0, $code, Artisan::output());
        }

        return $code;
    }
}
