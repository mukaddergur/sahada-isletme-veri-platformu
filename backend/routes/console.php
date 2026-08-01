<?php

use App\Jobs\ProcessScanJob;
use App\Models\Project;
use App\Models\Scan;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sahada:run-schedules', function () {
    $hour = (int) now()->format('G');
    $isMonday = now()->isMonday();
    $ran = 0;

    $projects = Project::query()->get()->filter(function (Project $project) {
        return (bool) data_get($project->settings, 'schedule.enabled');
    });

    foreach ($projects as $project) {
        $freq = data_get($project->settings, 'schedule.frequency', 'daily');
        $targetHour = (int) data_get($project->settings, 'schedule.hour', 3);
        $lastRun = data_get($project->settings, 'schedule.last_run_at');

        if ($targetHour !== $hour) {
            continue;
        }
        if ($freq === 'weekly' && ! $isMonday) {
            continue;
        }
        if ($lastRun && now()->parse($lastRun)->isToday()) {
            continue;
        }
        if (in_array($project->status, ['queued', 'running'], true)) {
            continue;
        }

        $scan = Scan::create([
            'project_id' => $project->id,
            'user_id' => $project->user_id,
            'status' => 'pending',
            'provider' => 'pending',
        ]);

        $project->update([
            'status' => 'queued',
            'error_message' => null,
            'settings' => array_replace_recursive($project->settings ?? [], [
                'schedule' => [
                    'last_run_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        ProcessScanJob::dispatch($scan->id);
        $ran++;
        $this->info("Scheduled scan #{$scan->id} for project #{$project->id}");
    }

    $this->info("Done. Launched {$ran} scheduled scan(s).");
})->purpose('Sahada zamanlanmış taramaları çalıştır');

Schedule::command('sahada:run-schedules')->hourly();
