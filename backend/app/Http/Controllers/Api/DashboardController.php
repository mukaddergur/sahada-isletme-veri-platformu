<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Project;
use App\Models\Scan;
use App\Services\ExcelExportService;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardController extends Controller
{
    public function __construct(private readonly LogService $logService) {}

    public function overview(Request $request)
    {
        $user = $request->user();
        $projectIds = Project::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->pluck('id');

        $businessQuery = Business::query()
            ->whereIn('project_id', $projectIds)
            ->where(function ($q) {
                $q->whereNull('place_id')->orWhere('place_id', 'not like', 'demo_%');
            });

        $total = (clone $businessQuery)->count();
        $withWebsite = (clone $businessQuery)->whereNotNull('website')->count();
        $withPhone = (clone $businessQuery)->whereNotNull('phone')->count();
        $withEmail = (clone $businessQuery)->whereNotNull('email')->count();
        $avgRating = round((float) (clone $businessQuery)->avg('rating'), 2);
        $avgAi = round((float) (clone $businessQuery)->avg('ai_score'), 1);

        $byCategory = (clone $businessQuery)
            ->select(
                DB::raw("COALESCE(NULLIF(TRIM(category), ''), 'Belirtilmemiş') as category"),
                DB::raw('count(*) as total'),
                DB::raw("sum(case when phone is not null and phone != '' then 1 else 0 end) as with_phone"),
                DB::raw("sum(case when website is not null and website != '' then 1 else 0 end) as with_website"),
                DB::raw("sum(case when email is not null and email != '' then 1 else 0 end) as with_email"),
                DB::raw("sum(case when address is not null and address != '' then 1 else 0 end) as with_address"),
                DB::raw('sum(case when latitude is not null and longitude is not null then 1 else 0 end) as with_coords'),
                DB::raw('round(avg(ai_score), 1) as avg_ai_score')
            )
            ->groupBy(DB::raw("COALESCE(NULLIF(TRIM(category), ''), 'Belirtilmemiş')"))
            ->orderByDesc('total')
            ->get();

        $byDistrict = (clone $businessQuery)
            ->select(
                DB::raw("COALESCE(NULLIF(TRIM(district), ''), 'Belirtilmemiş') as district"),
                DB::raw('count(*) as total'),
                DB::raw("sum(case when phone is not null and phone != '' then 1 else 0 end) as with_phone"),
                DB::raw("sum(case when website is not null and website != '' then 1 else 0 end) as with_website"),
                DB::raw("sum(case when email is not null and email != '' then 1 else 0 end) as with_email"),
                DB::raw("sum(case when address is not null and address != '' then 1 else 0 end) as with_address"),
                DB::raw('sum(case when latitude is not null and longitude is not null then 1 else 0 end) as with_coords'),
                DB::raw('round(avg(ai_score), 1) as avg_ai_score')
            )
            ->groupBy(DB::raw("COALESCE(NULLIF(TRIM(district), ''), 'Belirtilmemiş')"))
            ->orderByDesc('total')
            ->get();

        $districtCategory = (clone $businessQuery)
            ->select(
                DB::raw("COALESCE(NULLIF(TRIM(district), ''), 'Belirtilmemiş') as district"),
                DB::raw("COALESCE(NULLIF(TRIM(category), ''), 'Belirtilmemiş') as category"),
                DB::raw('count(*) as total'),
                DB::raw("sum(case when phone is not null and phone != '' then 1 else 0 end) as with_phone"),
                DB::raw("sum(case when website is not null and website != '' then 1 else 0 end) as with_website")
            )
            ->groupBy(
                DB::raw("COALESCE(NULLIF(TRIM(district), ''), 'Belirtilmemiş')"),
                DB::raw("COALESCE(NULLIF(TRIM(category), ''), 'Belirtilmemiş')")
            )
            ->orderBy('district')
            ->orderByDesc('total')
            ->get();

        $withAddress = (clone $businessQuery)->whereNotNull('address')->where('address', '!=', '')->count();
        $withCoords = (clone $businessQuery)->whereNotNull('latitude')->whereNotNull('longitude')->count();
        $citiesCount = (int) (clone $businessQuery)
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->select(DB::raw('count(distinct city) as c'))
            ->value('c');
        $reviewSum = (int) (clone $businessQuery)->sum('review_count');
        $fieldSlots = max(1, $total * 4);
        $fieldFilled = $withPhone + $withWebsite + $withAddress + $withCoords;
        $accuracyRate = $total > 0 ? (int) round(100 * $fieldFilled / $fieldSlots) : 0;

        $topRated = (clone $businessQuery)
            ->with('social')
            ->orderByDesc('rating')
            ->orderByDesc('review_count')
            ->limit(8)
            ->get(['id', 'name', 'category', 'district', 'rating', 'review_count', 'website', 'ai_score', 'latitude', 'longitude']);

        $socialStats = [
            'instagram' => Business::whereIn('project_id', $projectIds)->whereHas('social', fn ($s) => $s->whereNotNull('instagram'))->count(),
            'linkedin' => Business::whereIn('project_id', $projectIds)->whereHas('social', fn ($s) => $s->whereNotNull('linkedin'))->count(),
            'facebook' => Business::whereIn('project_id', $projectIds)->whereHas('social', fn ($s) => $s->whereNotNull('facebook'))->count(),
        ];

        $recentScans = Scan::query()
            ->with('project:id,name,status')
            ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->latest()
            ->limit(8)
            ->get();

        $queue = [
            'pending' => Scan::when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))->where('status', 'pending')->count(),
            'running' => Scan::when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))->where('status', 'running')->count(),
            'failed' => Scan::when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))->where('status', 'failed')->count(),
            'completed' => Scan::when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))->where('status', 'completed')->count(),
        ];

        $mapPoints = (clone $businessQuery)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->limit(300)
            ->get(['id', 'name', 'latitude', 'longitude', 'rating', 'category', 'city', 'district']);

        return response()->json([
            'stats' => [
                'total_businesses' => $total,
                'cities_count' => $citiesCount,
                'review_sum' => $reviewSum,
                'accuracy_rate' => $accuracyRate,
                'with_website' => $withWebsite,
                'without_website' => max(0, $total - $withWebsite),
                'with_phone' => $withPhone,
                'missing_phone' => max(0, $total - $withPhone),
                'with_email' => $withEmail,
                'missing_email' => max(0, $total - $withEmail),
                'with_address' => $withAddress,
                'with_coords' => $withCoords,
                'avg_rating' => $avgRating,
                'avg_ai_score' => $avgAi,
                'projects' => $projectIds->count(),
            ],
            'by_category' => $byCategory,
            'by_district' => $byDistrict,
            'district_category' => $districtCategory,
            'market_insights' => app(\App\Services\MarketInsightService::class)->build($projectIds, 1000),
            'top_rated' => $topRated,
            'social_stats' => $socialStats,
            'recent_scans' => $recentScans,
            'queue' => $queue,
            'map_points' => $mapPoints,
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        abort_if($request->user()->role === 'guest', 403, 'Misafir hesap export yapamaz.');

        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'columns' => ['nullable', 'array'],
            'columns.*' => ['string'],
        ]);

        $project = Project::findOrFail($data['project_id']);
        abort_unless($request->user()->isAdmin() || $project->user_id === $request->user()->id, 403);

        $columns = $data['columns'] ?? [
            'name',
            'category',
            'district',
            'phone',
            'email',
            'website',
            'address',
            'rating',
            'review_count',
            'ai_score',
            'place_id',
            'source_label',
            'data_source',
            'collected_at',
            'latitude',
            'longitude',
            'maps_url',
            'instagram',
        ];

        $businesses = Business::with('social')
            ->where('project_id', $project->id)
            ->orderByDesc('rating')
            ->get();

        $this->logService->notify($request->user(), 'export_ready', 'CSV / Sheets hazır', "{$project->name} dışa aktarımı hazır.", $project->id);
        $this->logService->log('export.csv', 'CSV exported (Sheets-ready)', $request->user()->id, $project->id);

        $filename = 'sahada_sheets_'.$project->id.'.csv';

        return response()->streamDownload(function () use ($businesses, $columns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns, ',');

            foreach ($businesses as $business) {
                $row = [];
                foreach ($columns as $column) {
                    $value = match ($column) {
                        'instagram' => $business->social?->instagram,
                        'facebook' => $business->social?->facebook,
                        'linkedin' => $business->social?->linkedin,
                        'source_label' => $business->source_label,
                        'collected_at' => optional($business->collected_at)?->toIso8601String(),
                        default => data_get($business, $column),
                    };
                    $row[] = is_bool($value) ? ($value ? '1' : '0') : $value;
                }
                fputcsv($out, $row, ',');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportExcel(Request $request, ExcelExportService $excelExportService)
    {
        abort_if($request->user()->role === 'guest', 403, 'Misafir hesap export yapamaz.');

        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'columns' => ['nullable', 'array'],
            'columns.*' => ['string'],
        ]);

        $project = Project::findOrFail($data['project_id']);
        abort_unless($request->user()->isAdmin() || $project->user_id === $request->user()->id, 403);

        $columns = $data['columns'] ?? [
            'name',
            'category',
            'district',
            'phone',
            'email',
            'website',
            'address',
            'rating',
            'review_count',
            'ai_score',
            'instagram',
            'latitude',
            'longitude',
            'maps_url',
            'source_label',
            'collected_at',
        ];

        $businesses = Business::with('social')
            ->where('project_id', $project->id)
            ->where(function ($q) {
                $q->whereNull('place_id')->orWhere('place_id', 'not like', 'demo_%');
            })
            ->orderByDesc('rating')
            ->get();

        $binary = $excelExportService->buildXlsx($columns, $businesses);

        $this->logService->notify($request->user(), 'export_ready', 'Excel hazır', "{$project->name} Excel dışa aktarımı hazır.", $project->id);
        $this->logService->log('export.excel', 'Excel exported', $request->user()->id, $project->id);

        $filename = 'sahada_'.$project->id.'.xlsx';

        return response($binary, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
