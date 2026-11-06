<?php

namespace Tests\Unit;

use App\Models\Mdb\Revision;
use App\Models\Mdb\Template;
use App\Services\Mdb\AccessPowerShell;
use App\Services\Mdb\ExportPayloadBuilder;
use App\Services\Mdb\InstMymSwDevPosTableGeneration;
use App\Services\Mdb\ReadbackVerifier;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MdbInstMymSwDevPosGenerationTest extends TestCase
{
    public function test_literal_values_and_all_eleven_years_use_each_exact_transformer_section_id(): void
    {
        $transformers = [['SectionId' => '00040701031222104.1'], ['SectionId' => '00040701031222104.10']];
        $sections = [
            ['SectionId' => '00040701031222104.1', 'Description' => 'Transformer'],
            ['SectionId' => '00040701031222104.10', 'Description' => 'Transformer'],
            ['SectionId' => '00040701031222104', 'Description' => 'Secondary Pole'],
        ];
        $before = [$transformers, $sections];
        $rows = (new InstMymSwDevPosTableGeneration)->apply($transformers, $sections);
        $this->assertCount(22, $rows);
        $this->assertSame($before, [$transformers, $sections]);
        foreach ($transformers as $transformer) {
            $selected = array_values(array_filter($rows, fn ($row) => $row['SectionId'] === $transformer['SectionId']));
            $this->assertSame(range(0, 10), array_column($selected, 'YearNumber'));
            foreach ($selected as $year => $row) {
                $this->assertSame(['SectionId' => $transformer['SectionId'], 'DeviceCode' => 'M', 'YearNumber' => $year, 'IsOpen' => 0], $row);
            }
        }
        $this->assertSame([], (new InstMymSwDevPosTableGeneration)->apply([], $sections));
    }

    public function test_missing_non_transformer_and_duplicate_section_references_are_rejected(): void
    {
        foreach ([
            [[['SectionId' => 'missing']], []],
            [[['SectionId' => 123]], [['SectionId' => '123', 'Description' => 'Transformer']]],
            [[['SectionId' => 'pole']], [['SectionId' => 'pole', 'Description' => 'Secondary Pole']]],
            [[['SectionId' => 'tx'], ['SectionId' => 'tx']], [['SectionId' => 'tx', 'Description' => 'Transformer']]],
        ] as [$transformers, $sections]) {
            try {
                (new InstMymSwDevPosTableGeneration)->apply($transformers, $sections);
                $this->fail('Invalid transformer references must not produce switch-device rows.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('InstMymSwDevPos:', $exception->getMessage());
            }
        }
    }

    private function payload(?int $transformerId = null): array
    {
        $snapshot = MdbInstSectionGenerationTest::multipleTransformersSnapshot();
        $revision = new Revision(['batch_id' => 1, 'number' => 1, 'sha256' => str_repeat('a', 64), 'snapshot' => $snapshot]);
        $template = new Template($snapshot['template'] + ['active' => true]);

        return app(ExportPayloadBuilder::class)->build($revision, $template, $transformerId, str_repeat('b', 64));
    }

    public function test_export_uses_final_transformer_sections_and_respects_individual_or_feeder_scope(): void
    {
        foreach ([[null, 22], [1, 11]] as [$scope, $count]) {
            $payload = $this->payload($scope);
            $rows = $payload['tables']['InstMymSwDevPos'];
            $this->assertCount($count, $rows);
            $ids = array_column($payload['tables']['InstPrimaryTransformers'], 'SectionId');
            $this->assertSame($ids, array_values(array_unique(array_column($rows, 'SectionId'))));
            $this->assertSame('00040711131222104.1', $rows[0]['SectionId']);
            $this->assertNotContains('00040701031222104.1', array_column($rows, 'SectionId'));
            $this->assertSame(ExportPayloadBuilder::EXPORT_REVISION, $payload['settings']['export_revision']);
        }
        $manifest = json_decode(file_get_contents(resource_path('mdb/approved-schema.json')), true, 512, JSON_THROW_ON_ERROR);
        $definition = collect($manifest['schema']['tables'])->firstWhere('name', 'InstMymSwDevPos');
        $this->assertSame(['SectionId', 'DeviceCode', 'YearNumber', 'IsOpen'], array_column($definition['fields'], 'name'));
        $this->assertSame([10, 10, 4, 4], array_column($definition['fields'], 'type'));
    }

    public function test_native_mdb_preserves_the_schema_and_reads_back_all_static_transformer_rows(): void
    {
        $shell = AccessPowerShell::defaultPath();
        if (PHP_OS_FAMILY !== 'Windows' || ! is_file($shell)) {
            $this->markTestSkipped('Windows Access DAO is required.');
        }
        $probe = new Process([$shell, '-NoProfile', '-NonInteractive', '-Command', '$ErrorActionPreference="Stop";$engine=New-Object -ComObject DAO.DBEngine.120']);
        $probe->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('Windows Access DAO is unavailable.');
        }
        $directory = base_path('tmp/swdevpos-native-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($directory);
        $template = resource_path('mdb/synergee-empty.mdb');
        $originalHash = hash_file('sha256', $template);
        try {
            $payload = $this->payload();
            $input = $directory.'/payload.json';
            $output = $directory.'/generated.mdb';
            file_put_contents($input, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            $writer = new Process([$shell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File',
                base_path('scripts/mdb/export-reviewed-mdb.ps1'), '-InputPath', $input, '-TemplatePath', $template, '-OutputPath', $output]);
            $writer->setTimeout(90)->mustRun();
            $result = json_decode(trim($writer->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            $readback = app(ReadbackVerifier::class)->verify($output, $payload, $result);
            $this->assertSame(22, $readback['counts']['InstMymSwDevPos']);
            $this->assertSame(2, $readback['counts']['InstPrimaryTransformers']);
            $this->assertSame(51, $readback['schema_table_count']);
            $this->assertSame(42, $readback['schema_relationship_count']);
            $this->assertTrue($readback['mapped_values_verified']);
            $this->assertTrue($readback['references_verified']);
            $this->assertSame($originalHash, hash_file('sha256', $template));
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
