<?php

namespace Tests\Feature;

use App\Services\Mdb\ApprovedSchema;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ApprovedMdbSchemaTest extends TestCase
{
    public function test_schema_manifest_records_the_identical_structure_of_all_fifteen_approved_samples(): void
    {
        $manifest = json_decode(file_get_contents(resource_path('mdb/approved-schema.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(15, $manifest['references']);
        $this->assertCount(50, $manifest['schema']['tables']);
        $this->assertCount(41, $manifest['schema']['relationships']);
        $this->assertCount(1, array_unique(array_column($manifest['references'], 'schema_sha256')));
        $this->assertSame(ApprovedSchema::fingerprint(), $manifest['references'][0]['schema_sha256']);
        foreach ($manifest['schema']['tables'] as $table) {
            $this->assertFalse(str_starts_with($table['name'], 'Survey'));
        }
    }

    public function test_readback_must_prove_the_approved_sample_schema_and_all_relationships(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('schema differs');
        ApprovedSchema::assertReadback(['schema_sha256' => ApprovedSchema::fingerprint(), 'schema_table_count' => 50, 'schema_relationship_count' => 40]);
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
                $this->assertCount(50, $tables);
                $this->assertCount(41, $database['relationships']);
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
                $this->assertStringContainsString('schema does not match the 15 approved samples', $process->getErrorOutput());
                $this->assertFileDoesNotExist($output);
            }
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
        $shell = (getenv('WINDIR') ?: 'C:\\Windows').'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        if (PHP_OS_FAMILY !== 'Windows' || ! is_file($shell)) {
            $this->markTestSkipped('Windows DAO/ACE is unavailable.');
        }
        $probe = new Process([$shell, '-NoProfile', '-NonInteractive', '-Command', '$ErrorActionPreference="Stop";$engine=New-Object -ComObject DAO.DBEngine.120']);
        $probe->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('64-bit DAO/ACE is unavailable.');
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
