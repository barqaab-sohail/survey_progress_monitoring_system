<?php

namespace Tests\Unit;

use App\Models\Mdb\{Revision, Template};
use App\Services\Mdb\{DataEntryPayload, ExportPayloadBuilder};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TransformerRegistryExportTest extends TestCase
{
    private function build(array $snapshot, ?int $id = 1): array
    {
        return app(ExportPayloadBuilder::class)->build(new Revision(['batch_id' => 1, 'number' => 1, 'sha256' => str_repeat('a', 64), 'snapshot' => $snapshot]),
            new Template($snapshot['template'] + ['active' => true]), $id, str_repeat('b', 64));
    }

    public function test_pending_client_identity_blocks_final_generation_even_with_positive_capacity(): void
    {
        $s = MdbInstSectionGenerationTest::snapshot();
        $s['transformers'][0]['registry_verification'] = ['code_status' => 'pending_client', 'capacity_status' => 'confirmed', 'confirmed_capacity_kva' => 100];
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Client confirmation');
        $this->build($s);
    }

    public function test_pending_capacity_blocks_final_generation_even_when_the_survey_has_an_unapproved_positive_rating(): void
    {
        $s = MdbInstSectionGenerationTest::snapshot();
        $s['transformers'][0]['registry_verification'] = ['code_status' => 'confirmed', 'confirmed_code' => $s['transformers'][0]['code'], 'capacity_status' => 'pending_field', 'confirmed_capacity_kva' => null];
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Field verification');
        $this->build($s);
    }

    public function test_a_pending_sibling_does_not_change_export_of_the_selected_resolved_transformer(): void
    {
        $s = MdbInstSectionGenerationTest::snapshot();
        $before = $this->build($s);
        $pending = $s['transformers'][0];$pending['id'] = 2;$pending['code'] = 'PENDING';
        $pending['registry_verification'] = ['code_status' => 'pending_client', 'capacity_status' => 'pending_field'];
        $s['transformers'][] = $pending;
        $this->assertSame($before, $this->build($s, 1));
    }

    public function test_legacy_data_entry_export_also_rejects_an_unresolved_reference(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Client confirmation');
        app(DataEntryPayload::class)->build(['transformers' => [['id' => 1, 'code' => 'PENDING',
            'registry_verification' => ['code_status' => 'pending_client', 'capacity_status' => 'pending_field']]]]);
    }
}
