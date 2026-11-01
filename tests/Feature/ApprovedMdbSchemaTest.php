<?php

namespace Tests\Feature;

use App\Services\Mdb\ApprovedSchema;
use App\Services\Mdb\AccessPowerShell;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ApprovedMdbSchemaTest extends TestCase
{
    public function test_schema_manifest_records_the_approved_export_schema_and_meter_demand_extension(): void
    {
        $manifest = json_decode(file_get_contents(resource_path('mdb/approved-schema.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(15, $manifest['references']);
        $this->assertCount(51, $manifest['schema']['tables']);
        $this->assertCount(42, $manifest['schema']['relationships']);
        $this->assertCount(1, array_unique(array_column($manifest['references'], 'schema_sha256')));
        $this->assertSame('InstMeterDmd', $manifest['schema_extension']['table']);
        $meterDemand = collect($manifest['schema']['tables'])->firstWhere('name', 'InstMeterDmd');
        $this->assertCount(100, $meterDemand['fields']);
        $this->assertSame(['SectionId', 'Season', 'DayType'], array_column($meterDemand['indexes'][0]['fields'], 'name'));
        $this->assertSame('InstMeterPoints', collect($manifest['schema']['relationships'])->firstWhere('foreign_table', 'InstMeterDmd')['table']);
        foreach ($manifest['schema']['tables'] as $table) {
            $this->assertFalse(str_starts_with($table['name'], 'Survey'));
        }
    }

    public function test_readback_must_prove_the_approved_sample_schema_and_all_relationships(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('schema differs');
        ApprovedSchema::assertReadback(['schema_sha256' => ApprovedSchema::fingerprint(), 'schema_table_count' => 51, 'schema_relationship_count' => 41]);
    }

    public function test_both_native_writers_preserve_exact_schema_and_allow_missing_engineering_values(): void
    {
        $shell = $this->nativeShell();
        $directory = sys_get_temp_dir().'/hazeco-schema-test-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory);
        try {
            foreach (['export-reviewed-mdb.ps1', 'write-mdb.ps1'] as $index => $script) {
                $payload = $this->payload();
                $payload['tables']['SAI_Control'][0]['Frequency'] = null;
                $payload['tables']['SAI_Control'][0]['ProjectionFile'] = null;
                foreach ($payload['tables']['InstFeeders'] as &$feeder) {
                    $feeder['NominalKvll'] = null;
                }
                unset($feeder);
                foreach ($payload['tables']['InstPrimaryTransformers'] as &$transformer) {
                    $transformer['TransformerType'] = null;
                }
                unset($transformer);
                if ($index === 1) {
                    $payload = ['simple_mdb_generation' => true, 'tables' => $payload['tables'], 'survey_audit' => ['header' => ['fixture' => true], 'rows' => [], 'solar' => []]];
                }
                $input = $directory.'/input-'.$index.'.json';
                $output = $directory.'/result-'.$index.'.mdb';
                file_put_contents($input, json_encode($payload, JSON_THROW_ON_ERROR));
                $process = $this->write($shell, $script, $input, resource_path('mdb/synergee-empty.mdb'), $output);
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $result = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
                ApprovedSchema::assertReadback($result['readback']);
                $this->assertTrue($result['readback']['mapped_values_verified']);
                $this->assertTrue($result['readback']['references_verified']);
                $this->assertSame(hash_file('sha256', $input), $result['payload_sha256']);
                // Inspect actual saved values rather than relying on declared flags.
                $inspection = $directory.'/inspection-'.$index.'.json';
                $reader = new Process([$shell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', base_path('scripts/mdb/inspect-reference.ps1'), '-InputPath', $output, '-OutputPath', $inspection]);
                $reader->mustRun();
                $database = json_decode(ltrim(file_get_contents($inspection), "\xEF\xBB\xBF"), true, 512, JSON_THROW_ON_ERROR);
                $tables = array_column($database['tables'], null, 'name');
                $this->assertCount(51, $tables);
                $this->assertCount(42, $database['relationships']);
                $this->assertSame(0, $tables['InstMeterDmd']['count']);
                $this->assertNull($tables['SAI_Control']['rows'][0]['Frequency']);
                $this->assertNull($tables['InstFeeders']['rows'][0]['NominalKvll']);
                $this->assertSame('', $tables['InstPrimaryTransformers']['rows'][0]['TransformerType']);
                $this->assertArrayNotHasKey('SurveyRows', $tables);
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_even_an_approved_template_hash_cannot_authorize_schema_drift(): void
    {
        $shell = $this->nativeShell();
        $directory = sys_get_temp_dir().'/hazeco-schema-drift-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory);
        try {
            foreach (['extra_table', 'missing_relationship', 'changed_field_size'] as $index => $drift) {
                $template = $directory.'/template-'.$index.'.mdb';
                copy(resource_path('mdb/synergee-empty.mdb'), $template);
                $mutation = match ($drift) {
                    'extra_table' => '$db.Execute("CREATE TABLE SurveyRows (SourceJson MEMO)")',
                    'missing_relationship' => '$db.Relations.Delete($db.Relations.Item(0).Name)',
                    'changed_field_size' => '$db.Execute("ALTER TABLE Node ALTER COLUMN Description TEXT(99)")',
                };
                $command = '$ErrorActionPreference="Stop";$db=(New-Object -ComObject DAO.DBEngine.120).OpenDatabase('.$this->quote($template).');try{'.$mutation.'}finally{$db.Close()}';
                (new Process([$shell, '-NoProfile', '-NonInteractive', '-Command', $command]))->mustRun();
                $payload = $this->payload();
                $payload['template']['sha256'] = hash_file('sha256', $template);
                $input = $directory.'/payload-'.$index.'.json';
                $output = $directory.'/result-'.$index.'.mdb';
                file_put_contents($input, json_encode($payload, JSON_THROW_ON_ERROR));
                $process = $this->write($shell, 'export-reviewed-mdb.ps1', $input, $template, $output);
                $this->assertFalse($process->isSuccessful(), $drift);
                $this->assertStringContainsString('schema does not match the approved export template', $process->getErrorOutput());
                $this->assertFileDoesNotExist($output);
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_legacy_clean_template_is_extended_only_on_the_disposable_output_copy(): void
    {
        $shell = $this->nativeShell();
        $directory = sys_get_temp_dir().'/hazeco-meter-demand-upgrade-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory);
        $template = $directory.'/legacy-template.mdb';
        $output = $directory.'/result.mdb';
        try {
            copy(resource_path('mdb/synergee-empty.mdb'), $template);
            $command = '$ErrorActionPreference="Stop";$db=(New-Object -ComObject DAO.DBEngine.120).OpenDatabase('.$this->quote($template).');try{$db.Relations.Delete("{8295BFFE-7098-403E-A54D-DF88BD0BC8A2}");$db.TableDefs.Delete("InstMeterDmd")}finally{$db.Close()}';
            (new Process([$shell, '-NoProfile', '-NonInteractive', '-Command', $command]))->mustRun();
            $legacyHash = hash_file('sha256', $template);
            $payload = $this->payload();
            $payload['tables']['InstMeterDmd'] = [];
            $payload['template']['sha256'] = $legacyHash;
            $input = $directory.'/payload.json';
            file_put_contents($input, json_encode($payload, JSON_THROW_ON_ERROR));

            $process = $this->write($shell, 'export-reviewed-mdb.ps1', $input, $template, $output);

            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $result = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            ApprovedSchema::assertReadback($result['readback']);
            $this->assertSame(0, $result['readback']['counts']['InstMeterDmd']);
            $this->assertSame($legacyHash, hash_file('sha256', $template));
            $this->assertNotSame($legacyHash, hash_file('sha256', $output));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function payload(): array
    {
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/Mdb/noor-pur-writer.json')), true, 512, JSON_THROW_ON_ERROR);
        $payload['template']['sha256'] = hash_file('sha256', resource_path('mdb/synergee-empty.mdb'));

        return $payload;
    }

    private function nativeShell(): string
    {
        $shell = AccessPowerShell::defaultPath();
        if (PHP_OS_FAMILY !== 'Windows' || ! is_file($shell)) {
            $this->markTestSkipped('Windows DAO/ACE is unavailable.');
        }
        $probe = new Process([$shell, '-NoProfile', '-NonInteractive', '-Command', '$ErrorActionPreference="Stop";$engine=New-Object -ComObject DAO.DBEngine.120']);
        $probe->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('Access DAO is unavailable.');
        }

        return $shell;
    }

    private function write(string $shell, string $script, string $input, string $template, string $output): Process
    {
        $process = new Process([$shell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', base_path('scripts/mdb/'.$script), '-InputPath', $input, '-TemplatePath', $template, '-OutputPath', $output]);
        $process->setTimeout(60);
        $process->run();

        return $process;
    }

    private function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
