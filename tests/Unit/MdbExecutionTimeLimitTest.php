<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class MdbExecutionTimeLimitTest extends TestCase
{
    private function php(string $script, array $settings): array
    {
        $arguments = [PHP_BINARY];
        foreach ($settings as $setting) {
            array_push($arguments, '-d', $setting);
        }
        array_push($arguments, '-r', 'require $argv[1]; '.$script, dirname(__DIR__, 2).'/vendor/autoload.php');
        $process = new Process($arguments);
        $process->setTimeout(15)->mustRun();

        return json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
    }

    public function test_process_wait_can_exceed_the_original_php_limit_without_a_fatal_error(): void
    {
        $result = $this->php(<<<'PHP'
            $original = (int) ini_get('max_execution_time');
            (new App\Services\Mdb\ExecutionTimeLimit)->extend(3);
            $worker = new Symfony\Component\Process\Process([PHP_BINARY, '-r', 'usleep(1500000); echo "finished";']);
            $worker->setTimeout(3)->mustRun();
            echo json_encode(['original' => $original, 'limit' => (int) ini_get('max_execution_time'), 'output' => $worker->getOutput()]);
            PHP, ['max_execution_time=1']);
        $this->assertSame(1, $result['original']);
        $this->assertGreaterThanOrEqual(63, $result['limit']);
        $this->assertSame('finished', $result['output']);
    }

    public function test_unlimited_and_larger_limits_are_not_reduced(): void
    {
        foreach ([0, 1200] as $limit) {
            $result = $this->php('(new App\Services\Mdb\ExecutionTimeLimit)->extend(900); echo json_encode(["limit" => (int) ini_get("max_execution_time")]);', ['max_execution_time='.$limit]);
            $this->assertSame($limit, $result['limit']);
        }
    }

    public function test_a_server_that_blocks_time_limit_changes_gets_an_actionable_validation_error(): void
    {
        $result = $this->php(<<<'PHP'
            $container = new Illuminate\Container\Container;
            Illuminate\Support\Facades\Facade::setFacadeApplication($container);
            $translator = new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader, 'en');
            $container->instance('validator', new Illuminate\Validation\Factory($translator));
            try {
                (new App\Services\Mdb\ExecutionTimeLimit)->extend(900);
                echo json_encode(['errors' => []]);
            } catch (Illuminate\Validation\ValidationException $exception) {
                echo json_encode(['errors' => $exception->errors()]);
            }
            PHP, ['max_execution_time=120', 'disable_functions=set_time_limit']);
        $this->assertStringContainsString('max_execution_time to at least 960', $result['errors']['file'][0]);
        $this->assertStringContainsString('restart PHP/Apache', $result['errors']['file'][0]);
    }
}
