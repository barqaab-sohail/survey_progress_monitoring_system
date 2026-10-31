<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\BackupGuard;
use App\Services\Audit\Storage;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class AuditLogManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'password' => 'Admin-password-123']);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        foreach ($this->tokens as $token) {
            $path = storage_path('app/private/audit-maintenance/web/webclear-'.$token.'.json');
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_super_admin_sees_storage_statistics_and_existing_filtering(): void
    {
        $undated = $this->event('user.created');
        DB::table('audit_logs')->where('id', $undated)->update(['created_at' => null]);
        $this->event('mdb.entry_row_saved');
        $this->get(route('admin.audit.index', ['action' => 'user.created']))->assertOk()
            ->assertSee('Total database size')->assertSee('Audit log table size')->assertSee('matching filter')
            ->assertSee('Clear audit records')->assertSee('user.created')->assertSee('Not recorded')->assertDontSee('mdb.entry_row_saved');
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_database_statistics_measure_allocation_without_fetching_payloads(): void
    {
        DB::shouldReceive('getDriverName')->once()->andReturn('mysql');
        DB::shouldReceive('selectOne')->once()->withArgs(function ($sql, $bindings) {
            $this->assertStringContainsString('information_schema.TABLES', $sql);
            $this->assertStringNotContainsString('old_values', $sql);
            $this->assertSame(['audit_logs', 'audit_logs', 'BASE TABLE'], $bindings);

            return true;
        })->andReturn((object) ['database_bytes' => 2147483648, 'audit_data_bytes' => 1073741824, 'audit_index_bytes' => 65536]);
        $stats = (new Storage)->statistics();
        $this->assertTrue($stats['available']);
        $this->assertSame(2147483648, $stats['database_bytes']);
        $this->assertSame(1073807360, $stats['audit_bytes']);
        $this->assertSame('2.00 GiB', Storage::formatBytes($stats['database_bytes']));
        $this->assertSame('0 B', Storage::formatBytes(0));
        $this->assertSame('Not available', Storage::formatBytes(null));
    }

    public function test_statistics_permission_failure_does_not_break_the_viewer(): void
    {
        DB::shouldReceive('getDriverName')->once()->andReturn('mysql');
        DB::shouldReceive('selectOne')->once()->andThrow(new RuntimeException('Do not expose connection details'));
        $this->assertFalse((new Storage)->statistics()['available']);
    }

    public function test_non_super_admin_and_guests_cannot_access_cleanup_endpoints(): void
    {
        $this->event('user.created');
        $this->actingAs(User::factory()->create(['role' => UserRole::Surveyor]));
        $this->get(route('admin.audit.index'))->assertForbidden();
        $this->post(route('admin.audit.preview'), ['scope' => 'all'])->assertForbidden();
        $this->get(route('admin.audit.records'))->assertForbidden();
        $this->post(route('admin.audit.clear'))->assertForbidden();
        $this->assertDatabaseCount('audit_logs', 1);
        auth()->forgetGuards();
        $this->postJson(route('admin.audit.clear'))->assertUnauthorized();
    }

    public function test_preview_and_csv_list_exact_records_without_exposing_payloads_or_deleting(): void
    {
        $id = $this->event('mdb.entry_row_saved', 0, ['secret' => 'NEVER EXPORT PAYLOAD']);
        $this->event('mdb.workflow_task_assigned');
        $token = $this->preview();
        $page = $this->get(route('admin.audit.index'))->assertOk()->assertSee('1 records selected.')->assertSee('1 records protected.');
        if (getenv('AUDIT_CAPTURE_UI') === '1') {
            file_put_contents(base_path('output/audit-review/web-preview.html'), $page->getContent());
        }
        $csv = $this->get(route('admin.audit.records'))->assertOk()->streamedContent();
        $this->assertStringContainsString((string) $id, $csv);
        $this->assertStringContainsString('mdb.entry_row_saved', $csv);
        $this->assertStringNotContainsString('mdb.workflow_task_assigned', $csv);
        $this->assertStringNotContainsString('NEVER EXPORT PAYLOAD', $csv);
        $this->assertSame($token, session('audit_clear_token'));
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_clearing_requires_password_typed_confirmation_and_backup_acknowledgement(): void
    {
        $this->event('mdb.entry_row_saved');
        $token = $this->preview();
        foreach ([['password' => 'wrong'], ['confirmation' => 'DELETE'], ['backup_confirmed' => 0]] as $invalid) {
            $this->postJson(route('admin.audit.clear'), array_replace($this->confirmation($token), $invalid))->assertUnprocessable();
        }
        $this->post(route('admin.audit.clear'), array_replace($this->confirmation($token), ['password' => 'Never-flash-this-password']))
            ->assertSessionHasErrors('password');
        $this->assertNull(session('_old_input.password'));
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_missing_verified_backup_blocks_deletion_even_after_checkbox_confirmation(): void
    {
        $id = $this->event('user.password_reset');
        $token = $this->preview();
        $this->mock(BackupGuard::class)->shouldReceive('verify')->once()->with($id)->andThrow(new RuntimeException('PRIVATE-API-ERROR'));
        $this->postJson(route('admin.audit.clear'), $this->confirmation($token))->assertUnprocessable()
            ->assertJsonValidationErrors('cleanup')->assertDontSee('PRIVATE-API-ERROR');
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_clear_runs_in_batches_keeps_business_history_and_new_logs_and_records_one_summary(): void
    {
        config(['audit.batch_size' => 2]);
        $ids = [$this->event('mdb.entry_row_saved'), $this->event('user.password_reset'), $this->event('mdb.source_uploaded')];
        $assignment = $this->event('mdb.workflow_task_assigned', 300, ['entry_assigned_to' => 99]);
        $drive = $this->event('drive.duplicate_cleanup_requested', 300);
        $existingSummary = $this->event('audit.logs_cleared', 0);
        $actorBefore = $this->admin->fresh()->getRawOriginal();
        $token = $this->preview();
        if (getenv('AUDIT_CAPTURE_UI') === '1') {
            file_put_contents(base_path('output/audit-review/web-preview.html'), $this->get(route('admin.audit.index'))->assertOk()->getContent());
        }
        $new = $this->event('mdb.entry_row_saved');
        $this->mock(BackupGuard::class)->shouldReceive('verify')->once()->with(max($ids));
        $this->postJson(route('admin.audit.clear'), $this->confirmation($token))->assertOk()
            ->assertJsonPath('completed', false)->assertJsonPath('cleared', 2)->assertJsonPath('processed', 2);
        $this->assertDatabaseMissing('audit_logs', ['id' => $ids[0]]);
        $this->assertDatabaseHas('audit_logs', ['id' => $ids[2]]);
        $this->get(route('admin.audit.index'))->assertOk(); // Refresh/interruption does not discard progress.
        $this->postJson(route('admin.audit.clear'), $this->confirmation($token))->assertOk()
            ->assertJsonPath('completed', true)->assertJsonPath('cleared', 3)->assertJsonPath('processed', 3)->assertSessionHas('success');
        foreach ([$assignment, $drive, $existingSummary, $new] as $id) {
            $this->assertDatabaseHas('audit_logs', ['id' => $id]);
        }
        $summary = AuditLog::where('action', 'audit.logs_cleared')->where('new_values->operation_id', $token)->sole();
        $this->assertSame($this->admin->id, $summary->user_id);
        $this->assertSame(3, $summary->new_values['cleared']);
        $this->assertSame('completed', $summary->new_values['status']);
        $this->assertLessThan(2048, strlen(json_encode($summary->new_values)));
        $this->assertSame($actorBefore, $this->admin->fresh()->getRawOriginal());
        $this->postJson(route('admin.audit.clear'), $this->confirmation($token))->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['id' => $new]);
        $this->assertDatabaseCount('audit_logs', 5);
        $this->assertNull(session('audit_clear_token'));
    }

    public function test_expired_scope_preserves_recent_and_important_records(): void
    {
        $expired = $this->event('mdb.pdf_line_drawn', 31);
        $important = $this->event('user.password_reset', 100);
        $recent = $this->event('mdb.pdf_line_drawn', 10);
        $token = $this->preview('expired');
        $this->mock(BackupGuard::class)->shouldReceive('verify')->once()->with($expired);
        $this->postJson(route('admin.audit.clear'), $this->confirmation($token))->assertOk()->assertJsonPath('cleared', 1);
        $this->assertDatabaseMissing('audit_logs', ['id' => $expired]);
        $this->assertDatabaseHas('audit_logs', ['id' => $important]);
        $this->assertDatabaseHas('audit_logs', ['id' => $recent]);
    }

    public function test_preview_limit_is_explicit_and_clears_only_that_window(): void
    {
        config(['audit.max_records' => 2]);
        $this->event('user.created');
        $this->event('user.created');
        $remaining = $this->event('user.created');
        $token = $this->preview();
        $this->get(route('admin.audit.index'))->assertOk()->assertSee('This pass covers 2 of 3 removable records.');
        $this->mock(BackupGuard::class)->shouldReceive('verify')->once();
        $this->postJson(route('admin.audit.clear'), $this->confirmation($token))->assertOk()->assertJsonPath('cleared', 2);
        $this->assertDatabaseHas('audit_logs', ['id' => $remaining]);
    }

    public function test_other_super_admin_and_expired_previews_cannot_clear_selected_records(): void
    {
        $this->event('user.created');
        $token = $this->preview();
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'password' => 'Admin-password-123']));
        $this->postJson(route('admin.audit.clear'), $this->confirmation($token))->assertUnprocessable();
        $this->actingAs($this->admin);
        $this->travel(31)->minutes();
        $this->postJson(route('admin.audit.clear'), $this->confirmation($token))->assertUnprocessable();
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_changed_records_are_skipped_and_audit_failure_rolls_back_the_batch(): void
    {
        $id = $this->event('user.created');
        $token = $this->preview();
        $this->mock(BackupGuard::class)->shouldReceive('verify')->once();
        $this->mock(AuditService::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Simulated audit failure'));
        $this->postJson(route('admin.audit.clear'), $this->confirmation($token))->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['id' => $id]);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_record_changed_to_a_protected_action_after_preview_is_preserved(): void
    {
        $id = $this->event('user.created');
        $token = $this->preview();
        DB::table('audit_logs')->where('id', $id)->update(['action' => 'mdb.workflow_task_assigned']);
        $this->mock(BackupGuard::class)->shouldReceive('verify')->once();
        $this->postJson(route('admin.audit.clear'), $this->confirmation($token))->assertOk()
            ->assertJsonPath('cleared', 0)->assertJsonPath('skipped', 1);
        $this->assertDatabaseHas('audit_logs', ['id' => $id]);
    }

    public function test_resuming_after_a_pause_rechecks_the_verified_backup(): void
    {
        config(['audit.batch_size' => 1]);
        $this->event('user.created');
        $last = $this->event('user.created');
        $token = $this->preview();
        $this->mock(BackupGuard::class)->shouldReceive('verify')->twice()->with($last);
        $this->postJson(route('admin.audit.clear'), $this->confirmation($token))->assertOk()->assertJsonPath('completed', false);
        $this->travel(6)->minutes();
        $this->postJson(route('admin.audit.clear'), $this->confirmation($token))->assertOk()->assertJsonPath('completed', true);
    }

    public function test_another_maintenance_process_blocks_web_cleanup(): void
    {
        $id = $this->event('user.created');
        $token = $this->preview();
        $lock = fopen(storage_path('framework/audit-maintenance.lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            $this->postJson(route('admin.audit.clear'), $this->confirmation($token))->assertUnprocessable()->assertJsonValidationErrors('cleanup');
            $this->assertDatabaseHas('audit_logs', ['id' => $id]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function preview(string $scope = 'all'): string
    {
        $this->post(route('admin.audit.preview'), ['scope' => $scope])->assertRedirect(route('admin.audit.index'))->assertSessionHasNoErrors();
        $token = session('audit_clear_token');
        $this->assertIsString($token);
        $this->tokens[] = $token;

        return $token;
    }

    private function confirmation(string $token): array
    {
        return ['plan_token' => $token, 'confirmation' => 'CLEAR LOGS', 'backup_confirmed' => 1, 'password' => 'Admin-password-123'];
    }

    private function event(string $action, int $days = 0, array $payload = []): int
    {
        return DB::table('audit_logs')->insertGetId(['action' => $action, 'auditable_type' => User::class,
            'auditable_id' => $this->admin->id, 'new_values' => $payload ? json_encode($payload) : null,
            'created_at' => now()->subDays($days), 'updated_at' => now()->subDays($days)]);
    }
}
