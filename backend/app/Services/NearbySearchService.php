<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessSocial;
use App\Models\Project;
use App\Models\Scan;
use App\Models\User;
use Illuminate\Support\Str;

class NearbySearchService
{
    public function __construct(
        private readonly GeocodeService $geocodeService,
        private readonly OverpassOsmService $overpassOsmService,
        private readonly AiAnalysisService $aiAnalysisService,
        private readonly WebsiteAnalysisService $websiteAnalysisService,
        private readonly LogService $logService,
    ) {}


    public function search(
        User $user,
        string $placeQuery,
        string $category = 'kafe restoran',
        int $limit = 30,
        int $radiusMeters = 2000,
    ): array {
        $center = $this->geocodeService->geocode($placeQuery);
        $districtHint = $this->guessDistrict($placeQuery, $center['label']);

        $rows = $this->overpassOsmService->searchAround(
            $center['lat'],
            $center['lon'],
            $radiusMeters,
            $category,
            $limit,
            $districtHint,
        );

        if (count($rows) === 0) {
            throw new \RuntimeException('Bu konuma yakın işletme bulunamadı. Semt adını veya yarıçapı değiştirin.');
        }

        $project = Project::create([
            'user_id' => $user->id,
            'name' => 'Yakın arama: '.Str::limit($placeQuery, 80),
            'description' => 'Nominatim + OpenStreetMap Overpass — mesafe sıralı gerçek POI',
            'maps_url' => 'https://www.google.com/maps/search/?api=1&query='.$center['lat'].'%2C'.$center['lon'],
            'search_query' => trim($placeQuery.' '.$category),
            'status' => 'completed',
            'settings' => [
                'limit' => $limit,
                'radius_m' => $radiusMeters,
                'center' => $center,
                'mode' => 'nearby',
            ],
            'total_businesses' => 0,
            'processed_count' => 0,
            'completed_at' => now(),
        ]);

        $scan = Scan::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'completed',
            'provider' => 'openstreetmap_nearby',
            'found_count' => count($rows),
            'saved_count' => 0,
            'progress' => 100,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $saved = [];
        foreach ($rows as $item) {
            $business = Business::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'place_id' => $item['place_id'],
                ],
                [
                    'scan_id' => $scan->id,
                    'name' => $item['name'],
                    'category' => $item['category'] ?? 'İşletme',
                    'address' => $item['address'] ?? null,
                    'city' => $item['city'] ?? 'Türkiye',
                    'district' => $item['district'] ?? $districtHint,
                    'neighborhood' => $item['neighborhood'] ?? null,
                    'phone' => $item['phone'] ?? null,
                    'email' => $item['email'] ?? null,
                    'website' => $item['website'] ?? null,
                    'maps_url' => $item['maps_url'] ?? null,
                    'latitude' => $item['latitude'] ?? null,
                    'longitude' => $item['longitude'] ?? null,
                    'rating' => $item['rating'] ?? null,
                    'review_count' => $item['review_count'] ?? 0,
                    'photo_count' => $item['photo_count'] ?? 0,
                    'opening_hours' => $item['opening_hours'] ?? null,
                    'data_source' => $item['source'] ?? 'openstreetmap',
                    'collected_at' => now(),
                ]
            );

            BusinessSocial::updateOrCreate(
                ['business_id' => $business->id],
                [
                    'instagram' => $item['instagram'] ?? null,
                    'facebook' => $item['facebook'] ?? null,
                    'twitter' => $item['twitter'] ?? null,
                ]
            );

            $this->websiteAnalysisService->analyze($business->fresh(), false);
            $this->aiAnalysisService->analyze($business->fresh(['social', 'websiteAnalysis']));

            $fresh = $business->fresh(['social', 'aiAnalysis', 'websiteAnalysis']);
            $fresh->setAttribute('distance_m', $item['distance_m'] ?? null);
            $saved[] = $fresh;
        }

        $project->update([
            'total_businesses' => count($saved),
            'processed_count' => count($saved),
        ]);
        $scan->update(['saved_count' => count($saved)]);

        $this->logService->notify(
            $user,
            'nearby_search',
            'Yakın arama tamamlandı',
            count($saved).' işletme bulundu: '.$center['label'],
            $project->id,
            ['count' => count($saved), 'center' => $center]
        );

        return [
            'center' => $center,
            'project' => $project->fresh(),
            'businesses' => $saved,
            'count' => count($saved),
        ];
    }

    private function guessDistrict(string $query, string $label): ?string
    {
        $hay = $query.' '.$label;
        foreach (['Kadıköy', 'Beşiktaş', 'Beyoğlu', 'Şişli', 'Üsküdar', 'Ataşehir', 'Maltepe', 'Bakırköy', 'Fatih', 'Sarıyer'] as $d) {
            if (Str::contains(Str::lower(Str::ascii($hay)), Str::lower(Str::ascii($d)))) {
                return $d;
            }
        }

        return null;
    }
}
