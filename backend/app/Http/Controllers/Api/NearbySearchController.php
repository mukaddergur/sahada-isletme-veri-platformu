<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NearbySearchService;
use Illuminate\Http\Request;

class NearbySearchController extends Controller
{
    public function __construct(
        private readonly NearbySearchService $nearbySearchService,
    ) {}

    public function search(Request $request)
    {
        abort_if($request->user()->role === 'guest', 403, 'Misafir hesap arama başlatamaz.');

        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'category' => ['nullable', 'string', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:5', 'max:60'],
            'radius_m' => ['nullable', 'integer', 'min:500', 'max:5000'],
        ]);

        try {
            $result = $this->nearbySearchService->search(
                $request->user(),
                $data['q'],
                $data['category'] ?? 'kafe restoran',
                $data['limit'] ?? 30,
                $data['radius_m'] ?? 2000,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $businesses = collect($result['businesses'])->map(function ($b) {
            $arr = $b->toArray();
            $arr['distance_m'] = $b->getAttribute('distance_m');
            $arr['ai_score'] = $b->ai_score;
            $arr['google_maps_url'] = $b->latitude && $b->longitude
                ? 'https://www.google.com/maps/search/?api=1&query='.$b->latitude.'%2C'.$b->longitude
                : null;
            $arr['osm_url'] = null;
            if (preg_match('/^osm_(node|way|relation)_(\d+)$/', (string) $b->place_id, $m)) {
                $arr['osm_url'] = 'https://www.openstreetmap.org/'.$m[1].'/'.$m[2];
            }

            return $arr;
        })->values();

        return response()->json([
            'center' => $result['center'],
            'project' => $result['project'],
            'count' => $result['count'],
            'businesses' => $businesses,
            'message' => $result['count'].' yakın işletme bulundu.',
        ]);
    }
}
