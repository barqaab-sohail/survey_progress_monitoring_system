<?php

namespace App\Console\Commands;

use App\Services\ManagementDashboardDemoService;
use Illuminate\Console\Command;

class ManageDashboardDemo extends Command
{
    protected $signature = 'hazeco:dashboard-demo {action=load : load or remove}';

    protected $description = 'Load or remove clearly marked sample progress data for the management dashboard';

    public function handle(ManagementDashboardDemoService $service): int
    {
        $action = strtolower((string) $this->argument('action'));
        if (! in_array($action, ['load', 'remove'], true)) {
            $this->error('Action must be load or remove.');

            return self::INVALID;
        }

        $result = $action === 'load' ? $service->load() : $service->remove();
        $this->info($result['message']);
        $this->table(
            ['Demo feeders', 'Survey entries', 'MDB entries'],
            [[$result['demo_feeders'], $result['survey_entries'], $result['mdb_entries']]],
        );

        return self::SUCCESS;
    }
}
