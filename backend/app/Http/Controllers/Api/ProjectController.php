<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessScanJob;
use App\Models\Project;
use App\Models\Scan;
use App\Services\LogService;
use App\Services\MapsUrlParserService;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function __construct(
        private readonly MapsUrlParserService $mapsUrlParserService,
        private readonly LogService $logService,
    ) {}

    public function index(Request $request)
    {
        $query = Project::query()->withCount('businesses')->latest();

        if (! $request->user()->isAdmin()) {
            $query->where('user_id', $request->user()->id);
        }

        return response()->json($query->paginate(12));
    }

    public function store(Request $request)
    {
        abort_if($request->user()->role === 'guest', 403, 'Misafir hesap proje oluşturamaz.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'maps_url' => ['required', 'url', 'max:1000'],
            'limit' => ['nullable', 'integer', 'min:10', 'max:200'],
            'start_now' => ['nullable', 'boolean'],
        ]);

        $parsed = $this->mapsUrlParserService->parse($data['maps_url']);

        $project = Project::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'maps_url' => $data['maps_url'],
            'search_query' => $parsed['search_query'],
            'status' => 'draft',
            'settings' => [
                'limit' => $data['limit'] ?? 60,
            ],
        ]);

        $this->logService->log('project.created', "Project {$project->name} created", $request->user()->id, $project->id, 'info', [], $request->ip());

        if ($request->boolean('start_now', true)) {
            return $this->start($request, $project);
        }

        return response()->json($project, 201);
    }

    public function show(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $project->load(['scans' => fn ($q) => $q->latest()->limit(10)])
            ->loadCount('businesses');

        return response()->json($project);
    }

    public function start(Request $request, Project $project)
    {
        @ini_set('max_execution_time', '0');
        @set_time_limit(300);

        abort_if($request->user()->role === 'guest', 403, 'Misafir hesap tarama başlatamaz.');

        $this->authorizeProject($request, $project);

        if (in_array($project->status, ['queued', 'running'], true)) {
            $active = Scan::query()
                ->where('project_id', $project->id)
                ->whereIn('status', ['pending', 'running'])
                ->where('updated_at', '>', now()->subSeconds(90))
                ->exists();

            if ($active) {
                return response()->json(['message' => 'Proje zaten taranıyor.'], 422);
            }

            Scan::query()
                ->where('project_id', $project->id)
                ->whereIn('status', ['pending', 'running'])
                ->update([
                    'status' => 'failed',
                    'error_message' => 'Takılı tarama otomatik kapatıldı. Yeni Launch başlatılıyor.',
                    'finished_at' => now(),
                ]);
        }

        $scan = Scan::create([
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
            'status' => 'pending',
            'provider' => 'pending',
        ]);

        $project->update(['status' => 'queued', 'error_message' => null]);

        ProcessScanJob::dispatch($scan->id);

        $scan->refresh();
        $project->refresh();

        $queued = $scan->status === 'pending';
        $this->logService->notify(
            $request->user(),
            $queued ? 'scan_queued' : 'scan_finished',
            $queued ? 'Tarama kuyruğa alındı' : 'Tarama tamamlandı',
            $queued
                ? "{$project->name}: tarama başlatıldı (queue)."
                : "{$project->name}: {$scan->saved_count} işletme.",
            $project->id
        );

        return response()->json([
            'project' => $project,
            'scan' => $scan,
            'message' => $queued
                ? 'Tarama kuyruğa alındı. queue:work çalışıyor olmalı.'
                : 'Tarama tamamlandı.',
        ], $queued ? 202 : 200);
    }

    public function cancel(Request $request, Project $project)
    {
        abort_if($request->user()->role === 'guest', 403, 'Misafir hesap iptal edemez.');

        $this->authorizeProject($request, $project);

        $scan = Scan::query()
            ->where('project_id', $project->id)
            ->whereIn('status', ['pending', 'running'])
            ->latest()
            ->first();

        if (! $scan) {
            return response()->json(['message' => 'İptal edilecek aktif tarama yok.'], 422);
        }

        $scan->update([
            'status' => 'cancelled',
            'finished_at' => now(),
            'error_message' => 'Kullanıcı tarafından iptal edildi.',
        ]);

        $project->update([
            'status' => 'cancelled',
            'error_message' => null,
        ]);

        $this->logService->notify(
            $request->user(),
            'scan_cancelled',
            'Tarama iptal',
            "{$project->name} taraması iptal edildi.",
            $project->id
        );

        return response()->json([
            'project' => $project->fresh(),
            'scan' => $scan->fresh(),
            'message' => 'Tarama iptal edildi.',
        ]);
    }

    public function schedule(Request $request, Project $project)
    {
        abort_if($request->user()->role === 'guest', 403, 'Misafir hesap zamanlama ayarlayamaz.');

        $this->authorizeProject($request, $project);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'frequency' => ['nullable', 'in:daily,weekly'],
            'hour' => ['nullable', 'integer', 'min:0', 'max:23'],
        ]);

        $settings = $project->settings ?? [];
        $settings['schedule'] = [
            'enabled' => $data['enabled'],
            'frequency' => $data['frequency'] ?? 'daily',
            'hour' => $data['hour'] ?? 3,
            'updated_at' => now()->toIso8601String(),
            'last_run_at' => data_get($settings, 'schedule.last_run_at'),
        ];

        $project->update(['settings' => $settings]);

        return response()->json([
            'project' => $project->fresh(),
            'schedule' => $settings['schedule'],
            'message' => $data['enabled']
                ? 'Zamanlama açıldı. Sunucuda `php artisan schedule:work` çalışmalı.'
                : 'Zamanlama kapatıldı.',
        ]);
    }

    public function destroy(Request $request, Project $project)
    {
        abort_if($request->user()->role === 'guest', 403, 'Misafir hesap silemez.');

        $this->authorizeProject($request, $project);
        $project->delete();

        return response()->json(['message' => 'Proje silindi.']);
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        if ($request->user()->isAdmin()) {
            return;
        }

        abort_unless($project->user_id === $request->user()->id, 403);
    }
}
