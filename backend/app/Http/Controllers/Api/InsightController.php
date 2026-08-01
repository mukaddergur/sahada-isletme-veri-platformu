<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\MarketInsightService;
use Illuminate\Http\Request;

class InsightController extends Controller
{
    public function __construct(private readonly MarketInsightService $marketInsightService) {}

    public function market(Request $request)
    {
        $data = $request->validate([
            'radius_m' => ['nullable', 'integer', 'min:500', 'max:3000'],
            'project_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $projectIds = Project::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->when(! empty($data['project_id']), function ($q) use ($data, $user) {
                $project = Project::findOrFail($data['project_id']);
                abort_unless($user->isAdmin() || $project->user_id === $user->id, 403);
                $q->where('id', $project->id);
            })
            ->pluck('id');

        $insights = $this->marketInsightService->build(
            $projectIds,
            (int) ($data['radius_m'] ?? 1000)
        );

        return response()->json($insights);
    }
}
