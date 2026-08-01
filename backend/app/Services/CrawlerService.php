<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessSocial;
use App\Models\Project;
use App\Models\Scan;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CrawlerService
{
    public function __construct(
        private readonly AiAnalysisService $aiAnalysisService,
        private readonly WebsiteAnalysisService $websiteAnalysisService,
        private readonly LogService $logService,
        private readonly MapsUrlParserService $mapsUrlParserService,
    ) {}

    public function run(Scan $scan): void
    {
        $scan->refresh();
        if ($scan->status === 'cancelled') {
            return;
        }

        $project = $scan->project()->with('user')->firstOrFail();
        $user = $project->user;
        $parsed = $this->mapsUrlParserService->parse($project->maps_url);

        $scan->update([
            'status' => 'running',
            'started_at' => now(),
            'provider' => $this->resolveProvider(),
            'progress' => 5,
        ]);

        $project->update([
            'status' => 'running',
            'started_at' => now(),
            'search_query' => $parsed['search_query'] ?? $project->search_query,
        ]);

        $this->logService->notify($user, 'scan_started', 'Tarama başladı', "{$project->name} taraması başlatıldı.", $project->id);
        $this->logService->log('scan.started', "Scan #{$scan->id} started", $user->id, $project->id);

        try {
            $items = $this->fetchBusinesses($project, $parsed);
            $scan->update([
                'found_count' => count($items),
                'progress' => 12,
            ]);

            if (count($items) >= 50) {
                $this->logService->notify($user, 'scan_progress', '50+ firma bulundu', count($items).' işletme adayı bulundu.', $project->id, ['count' => count($items)]);
            }

            $saved = 0;
            $failed = 0;
            $total = max(1, count($items));

            $emailProbesLeft = 0;

            foreach ($items as $index => $item) {
                if ($index % 5 === 0) {
                    $scan->refresh();
                    if ($scan->status === 'cancelled') {
                        $duration = $scan->started_at ? now()->diffInSeconds($scan->started_at) : null;
                        $scan->update([
                            'finished_at' => now(),
                            'duration_seconds' => $duration,
                            'progress' => (int) round((($index) / $total) * 100),
                        ]);
                        $project->update([
                            'status' => 'cancelled',
                            'processed_count' => $saved,
                            'total_businesses' => $saved,
                        ]);
                        $this->logService->notify($user, 'scan_cancelled', 'Tarama iptal edildi', "{$saved} işletme kaydedildikten sonra durduruldu.", $project->id);
                        $this->logService->log('scan.cancelled', "Scan #{$scan->id} cancelled after {$saved} saves", $user->id, $project->id);

                        return;
                    }
                }

                try {
                    $business = $this->persistBusiness($project, $scan, $item);
                    $this->attachSocial($business, $item);

                    $this->websiteAnalysisService->analyze($business, false);
                    $business->load(['social', 'websiteAnalysis']);
                    $this->aiAnalysisService->analyze($business);
                    $saved++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->logService->log('business.save_failed', $e->getMessage(), $user->id, $project->id, 'error', ['item' => $item['name'] ?? null]);
                }


                if ($saved % 3 === 0 || $index === count($items) - 1) {
                    $progress = 12 + (int) round((($index + 1) / $total) * 88);
                    $scan->update([
                        'saved_count' => $saved,
                        'failed_count' => $failed,
                        'progress' => min(99, $progress),
                    ]);
                    $project->update([
                        'processed_count' => $saved,
                        'total_businesses' => $saved,
                    ]);
                }
            }

            $scan->refresh();
            if ($scan->status === 'cancelled') {
                return;
            }

            $duration = $scan->started_at ? now()->diffInSeconds($scan->started_at) : null;
            $scan->update([
                'status' => 'completed',
                'progress' => 100,
                'finished_at' => now(),
                'duration_seconds' => $duration,
            ]);
            $project->update([
                'status' => 'completed',
                'completed_at' => now(),
                'total_businesses' => $saved,
                'processed_count' => $saved,
            ]);

            $this->logService->notify($user, 'scan_completed', 'Tarama tamamlandı', "{$saved} işletme başarıyla kaydedildi.", $project->id, [
                'saved' => $saved,
                'failed' => $failed,
            ]);
            $this->logService->log('scan.completed', "Scan #{$scan->id} completed with {$saved} businesses", $user->id, $project->id);
        } catch (\Throwable $e) {
            $scan->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);
            $project->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            $this->logService->notify($user, 'scan_failed', 'Tarama başarısız', $e->getMessage(), $project->id);
            $this->logService->log('scan.failed', $e->getMessage(), $user->id, $project->id, 'error');
            throw $e;
        }
    }

    private function resolveProvider(): string
    {
        if (config('services.crawler.prefer_osm', true)) {
            return 'openstreetmap';
        }

        if (config('services.google.places_api_key')) {
            return 'places_api';
        }

        return 'openstreetmap';
    }

    private function fetchBusinesses(Project $project, array $parsed): array
    {
        $limit = min(40, (int) data_get($project->settings, 'limit', 20));
        $query = trim((string) ($parsed['search_query'] ?? $project->search_query ?? ''));
        $coords = $parsed['coords'] ?? null;


        if ($coords && ($query === '' || ! preg_match('/[a-zA-ZçğıöşüÇĞİÖŞÜ]/u', $query))) {
            try {
                $around = app(OverpassOsmService::class)->searchAround(
                    (float) $coords['lat'],
                    (float) $coords['lng'],
                    2500,
                    'kafe restoran',
                    $limit,
                    null,
                );
                if (count($around) > 0) {
                    $this->logService->log(
                        'osm.around',
                        count($around).' işletme (harita merkezi)',
                        $project->user_id,
                        $project->id
                    );

                    return $around;
                }
            } catch (\Throwable $e) {
                $this->logService->log('osm.around_error', $e->getMessage(), $project->user_id, $project->id, 'warning');
            }
        }

        if ($query === '') {
            $query = 'kafe Türkiye';
        }


        try {
            $osm = app(OverpassOsmService::class)->search($query, $limit);
            if (count($osm) > 0) {
                $this->logService->log(
                    'osm.hit',
                    count($osm).' gerçek OSM işletmesi: '.$query,
                    $project->user_id,
                    $project->id
                );

                return $osm;
            }
        } catch (\Throwable $e) {
            $this->logService->log('osm.error', $e->getMessage(), $project->user_id, $project->id, 'warning');
        }


        try {
            $placeHint = trim((string) preg_replace(
                '/\b(restoran|restaurant|yemek|kafe|cafe|coffee|kahve|pastane|tatli|guzellik|kuafor|berber|spa|esnaf|isletme|dershane|dershaneler|dersane|etut|kurs|egitim|kolej|college|okul|dugun|wedding|nikah|organizasyon|davet|salon|salonu|salonlari)\b/iu',
                ' ',
                Str::lower(Str::ascii($query))
            ));
            if ($placeHint !== '') {
                $geo = app(GeocodeService::class)->geocode($placeHint);
                foreach ([12000, 20000] as $radius) {
                    $around = app(OverpassOsmService::class)->searchAround(
                        (float) $geo['lat'],
                        (float) $geo['lon'],
                        $radius,
                        $query,
                        $limit,
                        Str::before((string) ($geo['label'] ?? $placeHint), ','),
                    );
                    if (count($around) > 0) {
                        $this->logService->log(
                            'osm.geocode_around',
                            count($around)." işletme (geocode {$placeHint}, r={$radius})",
                            $project->user_id,
                            $project->id
                        );

                        return $around;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logService->log('osm.geocode_error', $e->getMessage(), $project->user_id, $project->id, 'warning');
        }


        $cachedOsm = $this->realOsmFromInventory($query, $limit);
        if (count($cachedOsm) > 0) {
            $this->logService->log(
                'osm.inventory',
                count($cachedOsm).' hazır OSM kaydı kullanıldı: '.$query,
                $project->user_id,
                $project->id
            );

            return $cachedOsm;
        }


        $apiKey = config('services.google.places_api_key');
        if ($apiKey) {
            $places = $this->fetchFromPlacesApi($query, $apiKey);
            if ($places) {
                return $places;
            }
        }


        $crawlerUrl = config('services.crawler.url');
        if ($crawlerUrl) {
            try {
                $response = Http::timeout(120)->post(rtrim($crawlerUrl, '/').'/crawl', [
                    'maps_url' => $project->maps_url,
                    'query' => $query,
                    'limit' => $limit,
                ]);

                if ($response->successful() && is_array($response->json('businesses')) && count($response->json('businesses')) > 0) {
                    return $response->json('businesses');
                }
            } catch (\Throwable $e) {
                $this->logService->log('crawler.fallback', $e->getMessage(), $project->user_id, $project->id, 'warning');
            }
        }


        if (config('services.crawler.allow_demo_fallback')) {
            $this->logService->log('demo.fallback', 'Demo katalog kullanıldı', $project->user_id, $project->id, 'warning');

            return $this->demoDataset($query, $limit);
        }

        throw new \RuntimeException(
            'Gerçek işletme verisi alınamadı. Google Maps’te “kafe kadıköy” gibi ara; sonuç sayfasının linkini yapıştır (/maps/search/...). Sorgu: '.$query
        );
    }


    private function realOsmFromInventory(string $query, int $limit): array
    {
        $q = Str::lower(Str::ascii($query));
        $districtMap = [
            'sariyer' => 'Sarıyer',
            'bebek' => 'Beşiktaş',
            'karakoy' => 'Beyoğlu',
            'nisantasi' => 'Şişli',
            'moda' => 'Kadıköy',
            'kadikoy' => 'Kadıköy',
            'besiktas' => 'Beşiktaş',
            'beyoglu' => 'Beyoğlu',
            'sisli' => 'Şişli',
            'uskudar' => 'Üsküdar',
            'atasehir' => 'Ataşehir',
            'maltepe' => 'Maltepe',
            'bakirkoy' => 'Bakırköy',
            'fatih' => 'Fatih',
        ];

        $district = null;
        foreach ($districtMap as $key => $label) {
            if (str_contains($q, $key)) {
                $district = $label;
                break;
            }
        }


        if ($district === null) {
            return [];
        }

        $builder = Business::query()
            ->where('place_id', 'like', 'osm_%')
            ->where('district', $district)
            ->where(function ($b) {
                $b->whereNotNull('phone')
                    ->orWhereNotNull('website')
                    ->orWhereNotNull('address');
            });

        if (str_contains($q, 'kafe') || str_contains($q, 'cafe') || str_contains($q, 'kahve') || str_contains($q, 'coffee')) {
            $builder->where(function ($b) {
                $b->where('category', 'like', '%Kafe%')
                    ->orWhere('category', 'like', '%Kahve%')
                    ->orWhere('category', 'like', '%Cafe%')
                    ->orWhere('category', 'like', '%Pastane%');
            });
        } elseif (str_contains($q, 'restoran') || str_contains($q, 'restaurant') || str_contains($q, 'yemek')) {
            $builder->where('category', 'like', '%Restoran%');
        } elseif (str_contains($q, 'kuafor') || str_contains($q, 'guzellik') || str_contains($q, 'berber')) {
            $builder->where(function ($b) {
                $b->where('category', 'like', '%Kuaför%')
                    ->orWhere('category', 'like', '%Güzellik%')
                    ->orWhere('category', 'like', '%Berber%');
            });
        }

        $rows = $builder->orderByRaw('(phone is not null) desc')
            ->orderByRaw('(website is not null) desc')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty() && $district) {
            $rows = Business::query()
                ->where('place_id', 'like', 'osm_%')
                ->where('district', $district)
                ->where(function ($b) {
                    $b->whereNotNull('phone')->orWhereNotNull('website')->orWhereNotNull('address');
                })
                ->orderByRaw('(phone is not null) desc')
                ->limit($limit)
                ->get();
        }

        return $rows->map(function (Business $b) {
            return [
                'name' => $b->name,
                'category' => $b->category,
                'address' => $b->address,
                'city' => $b->city ?? 'İstanbul',
                'district' => $b->district,
                'neighborhood' => $b->neighborhood,
                'phone' => $b->phone,
                'email' => $b->email,
                'website' => $b->website,
                'maps_url' => $b->maps_url,
                'place_id' => $b->place_id,
                'latitude' => $b->latitude,
                'longitude' => $b->longitude,
                'rating' => $b->rating,
                'review_count' => $b->review_count ?? 0,
                'photo_count' => $b->photo_count ?? 0,
                'source' => 'openstreetmap',
            ];
        })->all();
    }

    private function fetchFromPlacesApi(string $query, string $apiKey): array
    {
        $response = Http::get('https://maps.googleapis.com/maps/api/place/textsearch/json', [
            'query' => $query,
            'key' => $apiKey,
            'language' => 'tr',
            'region' => 'tr',
        ]);

        if (! $response->successful()) {
            return [];
        }

        $results = [];
        foreach ($response->json('results') ?? [] as $row) {
            $results[] = [
                'name' => $row['name'] ?? 'Bilinmeyen',
                'category' => $row['types'][0] ?? 'establishment',
                'address' => $row['formatted_address'] ?? null,
                'city' => 'İstanbul',
                'district' => $this->guessDistrict($row['formatted_address'] ?? ''),
                'phone' => null,
                'website' => null,
                'maps_url' => isset($row['place_id']) ? 'https://www.google.com/maps/place/?q=place_id:'.$row['place_id'] : null,
                'place_id' => $row['place_id'] ?? Str::uuid()->toString(),
                'latitude' => $row['geometry']['location']['lat'] ?? null,
                'longitude' => $row['geometry']['location']['lng'] ?? null,
                'rating' => $row['rating'] ?? null,
                'review_count' => $row['user_ratings_total'] ?? 0,
                'photo_count' => isset($row['photos']) ? count($row['photos']) : 0,
            ];
        }

        return $results;
    }

    public function demoDataset(string $query, int $limit = 60): array
    {
        $queryLower = Str::lower($query);
        $all = $this->istanbulBusinessCatalog();

        $filtered = array_values(array_filter($all, function (array $item) use ($queryLower) {
            if ($queryLower === '' || $queryLower === 'istanbul') {
                return true;
            }
            $hay = Str::lower($item['name'].' '.$item['category'].' '.$item['district'].' '.$item['neighborhood']);

            foreach (explode(' ', $queryLower) as $token) {
                $token = trim($token);
                if ($token !== '' && str_contains($hay, $token)) {
                    return true;
                }
            }

            return str_contains($hay, 'kafe') || str_contains($hay, 'cafe') || str_contains($queryLower, 'kafe');
        }));

        if (count($filtered) < 15) {
            $filtered = $all;
        }

        return array_slice($filtered, 0, max(10, min($limit, count($filtered))));
    }

    private function persistBusiness(Project $project, Scan $scan, array $item): Business
    {
        $placeId = $item['place_id'] ?? ('demo_'.Str::slug($item['name']).'_'.Str::substr(md5($item['address'] ?? $item['name']), 0, 8));
        $source = $item['source']
            ?? (str_starts_with((string) $placeId, 'osm_') ? 'openstreetmap' : ($scan->provider ?: 'harita'));

        return Business::updateOrCreate(
            [
                'project_id' => $project->id,
                'place_id' => $placeId,
            ],
            [
                'scan_id' => $scan->id,
                'name' => $item['name'],
                'category' => $item['category'] ?? 'Kafe',
                'address' => $item['address'] ?? null,
                'city' => $item['city'] ?? 'Türkiye',
                'district' => $item['district'] ?? null,
                'neighborhood' => $item['neighborhood'] ?? null,
                'phone' => $item['phone'] ?? null,
                'email' => $item['email'] ?? null,
                'website' => $item['website'] ?? null,
                'maps_url' => $item['maps_url']
                    ?? (isset($item['latitude'], $item['longitude'])
                        ? 'https://www.google.com/maps/search/?api=1&query='.$item['latitude'].'%2C'.$item['longitude']
                        : null),
                'data_source' => $source,
                'collected_at' => now(),
                'latitude' => $item['latitude'] ?? null,
                'longitude' => $item['longitude'] ?? null,
                'rating' => $item['rating'] ?? null,
                'review_count' => $item['review_count'] ?? 0,
                'photo_count' => $item['photo_count'] ?? 0,
                'opening_hours' => $item['opening_hours'] ?? null,
                'price_level' => $item['price_level'] ?? null,
                'is_open_now' => $item['is_open_now'] ?? null,
            ]
        );
    }

    private function attachSocial(Business $business, array $item): void
    {
        BusinessSocial::updateOrCreate(
            ['business_id' => $business->id],
            [
                'instagram' => $item['instagram'] ?? null,
                'facebook' => $item['facebook'] ?? null,
                'linkedin' => $item['linkedin'] ?? null,
                'tiktok' => $item['tiktok'] ?? null,
                'youtube' => $item['youtube'] ?? null,
                'twitter' => $item['twitter'] ?? null,
            ]
        );
    }

    private function guessDistrict(string $address): ?string
    {
        foreach (['Kadıköy', 'Beşiktaş', 'Şişli', 'Beyoğlu', 'Üsküdar', 'Fatih', 'Bakırköy', 'Ataşehir', 'Maltepe', 'Sarıyer'] as $d) {
            if (Str::contains($address, $d)) {
                return $d;
            }
        }

        return null;
    }


    private function istanbulBusinessCatalog(): array
    {
        return [
            ['name' => 'Fazıl Bey Türk Kahvesi', 'category' => 'Kahve', 'address' => 'Caferağa Mah. Serif Ahmet Sok. No:6, Kadıköy', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'neighborhood' => 'Caferağa', 'phone' => '+90 216 450 2870', 'website' => 'https://www.fazilbey.com', 'latitude' => 40.9902, 'longitude' => 29.0245, 'rating' => 4.6, 'review_count' => 4200, 'photo_count' => 1800, 'instagram' => 'https://instagram.com/fazilbeyturkkahvesi', 'email' => 'info@fazilbey.com'],
            ['name' => 'Walter\'s Coffee Roastery Kadıköy', 'category' => 'Kafe', 'address' => 'Caferağa Mah. Moda Cad. No:142, Kadıköy', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'neighborhood' => 'Moda', 'phone' => '+90 216 330 1122', 'website' => 'https://www.walterscoffee.com', 'latitude' => 40.9848, 'longitude' => 29.0259, 'rating' => 4.5, 'review_count' => 3100, 'photo_count' => 1400, 'instagram' => 'https://instagram.com/walterscoffee'],
            ['name' => 'Mandabatmaz', 'category' => 'Kahve', 'address' => 'Olivia Geçidi No:1/A, Beyoğlu', 'city' => 'İstanbul', 'district' => 'Beyoğlu', 'neighborhood' => 'Asmalımescit', 'phone' => '+90 212 243 3860', 'website' => null, 'latitude' => 41.0321, 'longitude' => 28.9774, 'rating' => 4.7, 'review_count' => 5600, 'photo_count' => 2200, 'instagram' => null],
            ['name' => 'Kronotrop Cihangir', 'category' => 'Kafe', 'address' => 'Firuzağa Mah. Defterdar Yokuşu No:47, Beyoğlu', 'city' => 'İstanbul', 'district' => 'Beyoğlu', 'neighborhood' => 'Cihangir', 'phone' => '+90 212 249 6590', 'website' => 'https://kronotrop.com', 'latitude' => 41.0315, 'longitude' => 28.9831, 'rating' => 4.5, 'review_count' => 2800, 'photo_count' => 1600, 'instagram' => 'https://instagram.com/kronotrop'],
            ['name' => 'Petra Roasting Co. Beşiktaş', 'category' => 'Kafe', 'address' => 'Sinanpaşa Mah. Köyiçi Çıkmazı No:2, Beşiktaş', 'city' => 'İstanbul', 'district' => 'Beşiktaş', 'neighborhood' => 'Sinanpaşa', 'phone' => '+90 212 259 5758', 'website' => 'https://petra.com.tr', 'latitude' => 41.0428, 'longitude' => 29.0048, 'rating' => 4.6, 'review_count' => 3500, 'photo_count' => 1900, 'instagram' => 'https://instagram.com/petraroastingco'],
            ['name' => 'Starbucks Moda', 'category' => 'Kafe', 'address' => 'Moda Cad. No:189, Kadıköy', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'neighborhood' => 'Moda', 'phone' => '+90 216 330 4545', 'website' => 'https://www.starbucks.com.tr', 'latitude' => 40.9839, 'longitude' => 29.0268, 'rating' => 4.2, 'review_count' => 2100, 'photo_count' => 900, 'instagram' => 'https://instagram.com/starbucksturkiye', 'linkedin' => 'https://linkedin.com/company/starbucks'],
            ['name' => 'Gloria Jean\'s Coffees Bağdat Caddesi', 'category' => 'Kafe', 'address' => 'Caddebostan Mah. Bağdat Cad. No:269, Kadıköy', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'neighborhood' => 'Caddebostan', 'phone' => '+90 216 368 8800', 'website' => 'https://www.gloriajeans.com.tr', 'latitude' => 40.9668, 'longitude' => 29.0621, 'rating' => 4.1, 'review_count' => 980, 'photo_count' => 420, 'instagram' => 'https://instagram.com/gloriajeanstr'],
            ['name' => 'Cup of Joy Karaköy', 'category' => 'Kafe', 'address' => 'Kemankeş Karamustafa Paşa Mah. Ali Paşa Değirmeni Sok., Beyoğlu', 'city' => 'İstanbul', 'district' => 'Beyoğlu', 'neighborhood' => 'Karaköy', 'phone' => '+90 212 243 0505', 'website' => null, 'latitude' => 41.0245, 'longitude' => 28.9789, 'rating' => 4.4, 'review_count' => 1600, 'photo_count' => 870, 'instagram' => 'https://instagram.com/cupofjoy'],
            ['name' => 'Story Coffee Roasters', 'category' => 'Kafe', 'address' => 'Caferağa Mah. Dr. Esat Işık Cad. No:34, Kadıköy', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'neighborhood' => 'Caferağa', 'phone' => '+90 216 418 2233', 'website' => 'https://storycoffee.co', 'latitude' => 40.9887, 'longitude' => 29.0274, 'rating' => 4.6, 'review_count' => 1900, 'photo_count' => 1100, 'instagram' => 'https://instagram.com/storycoffeeroasters'],
            ['name' => 'Federal Coffee Company Nişantaşı', 'category' => 'Kafe', 'address' => 'Teşvikiye Mah. Vali Konağı Cad. No:73, Şişli', 'city' => 'İstanbul', 'district' => 'Şişli', 'neighborhood' => 'Nişantaşı', 'phone' => '+90 212 232 4545', 'website' => 'https://federal.coffee', 'latitude' => 41.0502, 'longitude' => 28.9941, 'rating' => 4.5, 'review_count' => 2400, 'photo_count' => 1300, 'instagram' => 'https://instagram.com/federalcoffee'],
            ['name' => 'Gram Beşiktaş', 'category' => 'Kafe', 'address' => 'Sinanpaşa Mah. Köyiçi Çıkmazı, Beşiktaş', 'city' => 'İstanbul', 'district' => 'Beşiktaş', 'neighborhood' => 'Beşiktaş Merkez', 'phone' => '+90 212 258 4444', 'website' => null, 'latitude' => 41.0435, 'longitude' => 29.0055, 'rating' => 4.4, 'review_count' => 1200, 'photo_count' => 650, 'instagram' => 'https://instagram.com/gramcoffee'],
            ['name' => 'Karabatak Cafe', 'category' => 'Kafe', 'address' => 'Kara Ali Kaptan Sok. No:7, Beyoğlu', 'city' => 'İstanbul', 'district' => 'Beyoğlu', 'neighborhood' => 'Karaköy', 'phone' => '+90 212 243 6995', 'website' => null, 'latitude' => 41.0238, 'longitude' => 28.9798, 'rating' => 4.3, 'review_count' => 4500, 'photo_count' => 2800, 'instagram' => 'https://instagram.com/karabatakcafe'],
            ['name' => 'The House Cafe Ortaköy', 'category' => 'Kafe', 'address' => 'Mecidiye Köprüsü Sok. No:1, Beşiktaş', 'city' => 'İstanbul', 'district' => 'Beşiktaş', 'neighborhood' => 'Ortaköy', 'phone' => '+90 212 227 2699', 'website' => 'https://www.thehousecafe.com.tr', 'latitude' => 41.0472, 'longitude' => 29.0258, 'rating' => 4.2, 'review_count' => 3800, 'photo_count' => 2100, 'instagram' => 'https://instagram.com/thehousecafe', 'facebook' => 'https://facebook.com/thehousecafe'],
            ['name' => 'Midpoint Caddebostan', 'category' => 'Restoran', 'address' => 'Bağdat Cad. No:323, Kadıköy', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'neighborhood' => 'Caddebostan', 'phone' => '+90 216 360 7070', 'website' => 'https://www.midpoint.com.tr', 'latitude' => 40.9651, 'longitude' => 29.0655, 'rating' => 4.0, 'review_count' => 2700, 'photo_count' => 1200, 'instagram' => 'https://instagram.com/midpoint'],
            ['name' => 'Van Kahvaltı Evi Cihangir', 'category' => 'Kahvaltı', 'address' => 'Defterdar Yokuşu No:52/A, Beyoğlu', 'city' => 'İstanbul', 'district' => 'Beyoğlu', 'neighborhood' => 'Cihangir', 'phone' => '+90 212 249 7120', 'website' => null, 'latitude' => 41.0308, 'longitude' => 28.9838, 'rating' => 4.4, 'review_count' => 6200, 'photo_count' => 3500, 'instagram' => 'https://instagram.com/vankahvaltıevi'],
            ['name' => 'Çiya Sofrası', 'category' => 'Restoran', 'address' => 'Güneşlibahçe Sok. No:43, Kadıköy', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'neighborhood' => 'Caferağa', 'phone' => '+90 216 330 3190', 'website' => 'https://www.ciya.com.tr', 'latitude' => 40.9895, 'longitude' => 29.0251, 'rating' => 4.5, 'review_count' => 8900, 'photo_count' => 4100, 'instagram' => 'https://instagram.com/ciyasofasi'],
            ['name' => 'Kanaat Lokantası Üsküdar', 'category' => 'Restoran', 'address' => 'Selmani Pak Cad. No:25, Üsküdar', 'city' => 'İstanbul', 'district' => 'Üsküdar', 'neighborhood' => 'Mimar Sinan', 'phone' => '+90 216 341 5444', 'website' => 'https://www.kanaatlokantasi.com.tr', 'latitude' => 41.0251, 'longitude' => 29.0152, 'rating' => 4.3, 'review_count' => 5100, 'photo_count' => 1800, 'instagram' => null],
            ['name' => 'Noir Coffee & Tea', 'category' => 'Kafe', 'address' => 'Caferağa Mah. Moda Cad., Kadıköy', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'neighborhood' => 'Moda', 'phone' => '+90 216 450 9090', 'website' => null, 'latitude' => 40.9855, 'longitude' => 29.0249, 'rating' => 4.5, 'review_count' => 870, 'photo_count' => 510, 'instagram' => 'https://instagram.com/noircoffee'],
            ['name' => 'Brew Lab Specialty Coffee', 'category' => 'Kafe', 'address' => 'Osmanağa Mah. General Asım Gündüz Cad., Kadıköy', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'neighborhood' => 'Osmanağa', 'phone' => '+90 216 418 7788', 'website' => 'https://brewlab.com.tr', 'latitude' => 40.9908, 'longitude' => 29.0288, 'rating' => 4.7, 'review_count' => 1400, 'photo_count' => 920, 'instagram' => 'https://instagram.com/brewlab'],
            ['name' => 'Espressolab Beşiktaş', 'category' => 'Kafe', 'address' => 'Cihannüma Mah. Barbaros Bulvarı, Beşiktaş', 'city' => 'İstanbul', 'district' => 'Beşiktaş', 'neighborhood' => 'Cihannüma', 'phone' => '+90 212 258 1122', 'website' => 'https://www.espressolab.com', 'latitude' => 41.0439, 'longitude' => 29.0082, 'rating' => 4.3, 'review_count' => 2200, 'photo_count' => 980, 'instagram' => 'https://instagram.com/espressolab', 'facebook' => 'https://facebook.com/espressolab'],
            ['name' => 'Coffee Department', 'category' => 'Kafe', 'address' => 'Caferağa Mah. Dr. Esat Işık Cad., Kadıköy', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'neighborhood' => 'Caferağa', 'phone' => '+90 216 330 5566', 'website' => null, 'latitude' => 40.9879, 'longitude' => 29.0261, 'rating' => 4.6, 'review_count' => 1100, 'photo_count' => 740, 'instagram' => 'https://instagram.com/coffeedepartment'],
            ['name' => 'Arabica Coffee House Nişantaşı', 'category' => 'Kafe', 'address' => 'Teşvikiye Cad. No:31, Şişli', 'city' => 'İstanbul', 'district' => 'Şişli', 'neighborhood' => 'Nişantaşı', 'phone' => '+90 212 225 3030', 'website' => null, 'latitude' => 41.0511, 'longitude' => 28.9928, 'rating' => 4.4, 'review_count' => 760, 'photo_count' => 390, 'instagram' => 'https://instagram.com/arabicacoffee'],
            ['name' => 'Mado Bağdat Caddesi', 'category' => 'Pastane', 'address' => 'Bağdat Cad. No:297, Kadıköy', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'neighborhood' => 'Şaşkınbakkal', 'phone' => '+90 216 360 2020', 'website' => 'https://www.mado.com.tr', 'latitude' => 40.9679, 'longitude' => 29.0602, 'rating' => 4.1, 'review_count' => 4300, 'photo_count' => 1600, 'instagram' => 'https://instagram.com/mado', 'facebook' => 'https://facebook.com/mado'],
            ['name' => 'Baylan Pastanesi Kadıköy', 'category' => 'Pastane', 'address' => 'Osmanağa Mah. Mühürdar Cad. No:9, Kadıköy', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'neighborhood' => 'Osmanağa', 'phone' => '+90 216 336 2882', 'website' => 'https://www.baylanpastanesi.com', 'latitude' => 40.9891, 'longitude' => 29.0239, 'rating' => 4.5, 'review_count' => 6700, 'photo_count' => 2400, 'instagram' => 'https://instagram.com/baylanpastanesi'],
            ['name' => 'Pierre Loti Kahvesi', 'category' => 'Kahve', 'address' => 'Hasköy Cad., Eyüpsultan', 'city' => 'İstanbul', 'district' => 'Eyüpsultan', 'neighborhood' => 'Kariye', 'phone' => '+90 212 581 2696', 'website' => null, 'latitude' => 41.0539, 'longitude' => 28.9348, 'rating' => 4.3, 'review_count' => 11200, 'photo_count' => 8900, 'instagram' => null],
            ['name' => 'Bebek Kahve', 'category' => 'Kafe', 'address' => 'Cevdet Paşa Cad. No:26, Beşiktaş', 'city' => 'İstanbul', 'district' => 'Beşiktaş', 'neighborhood' => 'Bebek', 'phone' => '+90 212 263 5500', 'website' => null, 'latitude' => 41.0768, 'longitude' => 29.0439, 'rating' => 4.2, 'review_count' => 1500, 'photo_count' => 800, 'instagram' => 'https://instagram.com/bebekkahve'],
            ['name' => 'House of Coffee Emirgan', 'category' => 'Kafe', 'address' => 'Emirgan Sahil Yolu, Sarıyer', 'city' => 'İstanbul', 'district' => 'Sarıyer', 'neighborhood' => 'Emirgan', 'phone' => '+90 212 277 8899', 'website' => null, 'latitude' => 41.1082, 'longitude' => 29.0551, 'rating' => 4.4, 'review_count' => 920, 'photo_count' => 560, 'instagram' => 'https://instagram.com/houseofcoffee'],
            ['name' => 'Atölye Cafe Moda', 'category' => 'Kafe', 'address' => 'Moda Cad. No:104, Kadıköy', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'neighborhood' => 'Moda', 'phone' => '+90 216 349 1122', 'website' => null, 'latitude' => 40.9841, 'longitude' => 29.0252, 'rating' => 4.5, 'review_count' => 640, 'photo_count' => 310, 'instagram' => 'https://instagram.com/atolyecafe'],
            ['name' => 'Joker Coffee Kadıköy', 'category' => 'Kafe', 'address' => 'Yasa Cad. No:12, Kadıköy', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'neighborhood' => 'Caferağa', 'phone' => null, 'website' => null, 'latitude' => 40.9912, 'longitude' => 29.0241, 'rating' => 4.6, 'review_count' => 480, 'photo_count' => 220, 'instagram' => 'https://instagram.com/jokercoffee'],
            ['name' => 'RARE Coffee Ataşehir', 'category' => 'Kafe', 'address' => 'Barbaros Mah. Mor Sumbul Sok., Ataşehir', 'city' => 'İstanbul', 'district' => 'Ataşehir', 'neighborhood' => 'Barbaros', 'phone' => '+90 216 455 7788', 'website' => 'https://rarecoffee.com.tr', 'latitude' => 40.9925, 'longitude' => 29.1268, 'rating' => 4.5, 'review_count' => 1300, 'photo_count' => 700, 'instagram' => 'https://instagram.com/rarecoffee', 'linkedin' => 'https://linkedin.com/company/rarecoffee'],
            ['name' => 'Coffee Sapiens Maltepe', 'category' => 'Kafe', 'address' => 'Bağlarbaşı Mah. Bağdat Cad., Maltepe', 'city' => 'İstanbul', 'district' => 'Maltepe', 'neighborhood' => 'Bağlarbaşı', 'phone' => '+90 216 399 4455', 'website' => null, 'latitude' => 40.9355, 'longitude' => 29.1312, 'rating' => 4.4, 'review_count' => 710, 'photo_count' => 340, 'instagram' => 'https://instagram.com/coffeesapiens'],
            ['name' => 'Norm Coffee Bakırköy', 'category' => 'Kafe', 'address' => 'Cevizlik Mah. İstanbul Cad., Bakırköy', 'city' => 'İstanbul', 'district' => 'Bakırköy', 'neighborhood' => 'Cevizlik', 'phone' => '+90 212 542 9090', 'website' => null, 'latitude' => 40.9802, 'longitude' => 28.8721, 'rating' => 4.3, 'review_count' => 890, 'photo_count' => 410, 'instagram' => null],
            ['name' => 'Geyik Coffee Roastery', 'category' => 'Kafe', 'address' => 'Akarsu Yokuşu No:22, Beyoğlu', 'city' => 'İstanbul', 'district' => 'Beyoğlu', 'neighborhood' => 'Cihangir', 'phone' => '+90 212 243 1010', 'website' => null, 'latitude' => 41.0299, 'longitude' => 28.9845, 'rating' => 4.6, 'review_count' => 2100, 'photo_count' => 1250, 'instagram' => 'https://instagram.com/geyikcoffeeroastery'],
            ['name' => 'Journey Coffee & Kitchen', 'category' => 'Kafe', 'address' => 'Osmanağa Mah. Söğütlü Çeşme Cad., Kadıköy', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'neighborhood' => 'Osmanağa', 'phone' => '+90 216 418 3344', 'website' => null, 'latitude' => 40.9901, 'longitude' => 29.0295, 'rating' => 4.5, 'review_count' => 980, 'photo_count' => 520, 'instagram' => 'https://instagram.com/journeycoffee'],
            ['name' => 'Coffee Manifesto', 'category' => 'Kafe', 'address' => 'Caferağa Mah. Nazlı Sahil Yolu, Kadıköy', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'neighborhood' => 'Moda', 'phone' => null, 'website' => 'https://coffeemanifesto.com', 'latitude' => 40.9828, 'longitude' => 29.0235, 'rating' => 4.7, 'review_count' => 1600, 'photo_count' => 980, 'instagram' => 'https://instagram.com/coffeemanifesto'],
            ['name' => 'Ops Cafe Galata', 'category' => 'Kafe', 'address' => 'Galata Kulesi Sok., Beyoğlu', 'city' => 'İstanbul', 'district' => 'Beyoğlu', 'neighborhood' => 'Galata', 'phone' => '+90 212 243 7788', 'website' => null, 'latitude' => 41.0256, 'longitude' => 28.9742, 'rating' => 4.4, 'review_count' => 2200, 'photo_count' => 1400, 'instagram' => 'https://instagram.com/opscafe'],
            ['name' => 'Viyana Kahvesi Beşiktaş', 'category' => 'Kafe', 'address' => 'Sinanpaşa Mah. Ortabahçe Cad., Beşiktaş', 'city' => 'İstanbul', 'district' => 'Beşiktaş', 'neighborhood' => 'Sinanpaşa', 'phone' => '+90 212 261 2233', 'website' => null, 'latitude' => 41.0421, 'longitude' => 29.0069, 'rating' => 4.2, 'review_count' => 540, 'photo_count' => 210, 'instagram' => null, 'email' => null],
            ['name' => 'Pandeli Restaurant', 'category' => 'Restoran', 'address' => 'Mısır Çarşısı No:1, Fatih', 'city' => 'İstanbul', 'district' => 'Fatih', 'neighborhood' => 'Eminönü', 'phone' => '+90 212 527 3909', 'website' => 'https://www.pandeli.com.tr', 'latitude' => 41.0168, 'longitude' => 28.9705, 'rating' => 4.3, 'review_count' => 4800, 'photo_count' => 2700, 'instagram' => 'https://instagram.com/pandeli'],
            ['name' => 'Mikla Restaurant', 'category' => 'Restoran', 'address' => 'Meşrutiyet Cad. No:15, Beyoğlu', 'city' => 'İstanbul', 'district' => 'Beyoğlu', 'neighborhood' => 'Tepebaşı', 'phone' => '+90 212 293 5656', 'website' => 'https://www.miklarestaurant.com', 'latitude' => 41.0318, 'longitude' => 28.9749, 'rating' => 4.6, 'review_count' => 3200, 'photo_count' => 2100, 'instagram' => 'https://instagram.com/miklarestaurant', 'linkedin' => 'https://linkedin.com/company/mikla'],
            ['name' => 'Neolokal', 'category' => 'Restoran', 'address' => 'Sishane, Beyoğlu', 'city' => 'İstanbul', 'district' => 'Beyoğlu', 'neighborhood' => 'Şişhane', 'phone' => '+90 212 244 0016', 'website' => 'https://www.neolokal.com', 'latitude' => 41.0285, 'longitude' => 28.9728, 'rating' => 4.7, 'review_count' => 1800, 'photo_count' => 1500, 'instagram' => 'https://instagram.com/neolokal'],
            ['name' => 'Sunset Grill & Bar', 'category' => 'Restoran', 'address' => 'Yol Sok. No:2, Beşiktaş', 'city' => 'İstanbul', 'district' => 'Beşiktaş', 'neighborhood' => 'Ulus', 'phone' => '+90 212 287 0357', 'website' => 'https://www.sunsetgrillbar.com', 'latitude' => 41.0665, 'longitude' => 29.0338, 'rating' => 4.5, 'review_count' => 4100, 'photo_count' => 3200, 'instagram' => 'https://instagram.com/sunsetgrillbar'],
            ['name' => 'Kılıç Ali Paşa Hamamı Cafe', 'category' => 'Kafe', 'address' => 'Kemankeş Mah. Hamam Sok. No:1, Beyoğlu', 'city' => 'İstanbul', 'district' => 'Beyoğlu', 'neighborhood' => 'Tophane', 'phone' => '+90 212 393 8010', 'website' => 'https://www.kilicalipasahamami.com', 'latitude' => 41.0262, 'longitude' => 28.9815, 'rating' => 4.5, 'review_count' => 2900, 'photo_count' => 2400, 'instagram' => 'https://instagram.com/kilicalipasahamami'],
            ['name' => 'Moda Deniz Kulübü Cafe', 'category' => 'Kafe', 'address' => 'Moda İskelesi, Kadıköy', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'neighborhood' => 'Moda', 'phone' => '+90 216 336 9933', 'website' => null, 'latitude' => 40.9798, 'longitude' => 29.0221, 'rating' => 4.1, 'review_count' => 1800, 'photo_count' => 1100, 'instagram' => null],
            ['name' => 'Psikoloji Cafe', 'category' => 'Kafe', 'address' => 'Caferağa Mah. Sakızgülü Sok., Kadıköy', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'neighborhood' => 'Caferağa', 'phone' => '+90 216 450 1212', 'website' => null, 'latitude' => 40.9882, 'longitude' => 29.0258, 'rating' => 4.4, 'review_count' => 390, 'photo_count' => 180, 'instagram' => 'https://instagram.com/psikolojicafe'],
            ['name' => 'Birdy Coffee Kadıköy', 'category' => 'Kafe', 'address' => 'Moda Cad. No:68, Kadıköy', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'neighborhood' => 'Moda', 'phone' => null, 'website' => null, 'latitude' => 40.9859, 'longitude' => 29.0265, 'rating' => 4.6, 'review_count' => 520, 'photo_count' => 260, 'instagram' => 'https://instagram.com/birdycoffee'],
            ['name' => 'Coffee Internazionale', 'category' => 'Kafe', 'address' => 'Asmalımescit Mah. Sofyalı Sok., Beyoğlu', 'city' => 'İstanbul', 'district' => 'Beyoğlu', 'neighborhood' => 'Asmalımescit', 'phone' => '+90 212 245 5566', 'website' => null, 'latitude' => 41.0328, 'longitude' => 28.9755, 'rating' => 4.5, 'review_count' => 1400, 'photo_count' => 800, 'instagram' => 'https://instagram.com/coffeeinternazionale'],
            ['name' => 'Inci Pastanesi', 'category' => 'Pastane', 'address' => 'Beyoğlu, İstiklal Cad. yanı', 'city' => 'İstanbul', 'district' => 'Beyoğlu', 'neighborhood' => 'Asmalımescit', 'phone' => '+90 212 243 2412', 'website' => null, 'latitude' => 41.0312, 'longitude' => 28.9768, 'rating' => 4.4, 'review_count' => 7500, 'photo_count' => 3100, 'instagram' => null],
            ['name' => 'Duble Meze Lounge', 'category' => 'Restoran', 'address' => 'Asmalımescit Mah. Sofyalı Sok., Beyoğlu', 'city' => 'İstanbul', 'district' => 'Beyoğlu', 'neighborhood' => 'Asmalımescit', 'phone' => '+90 212 244 4422', 'website' => null, 'latitude' => 41.0325, 'longitude' => 28.9751, 'rating' => 4.3, 'review_count' => 2100, 'photo_count' => 1600, 'instagram' => 'https://instagram.com/dublemeze'],
            ['name' => 'Cibalikapı Balıkçısı', 'category' => 'Restoran', 'address' => 'Kadir Has Cad. No:5, Fatih', 'city' => 'İstanbul', 'district' => 'Fatih', 'neighborhood' => 'Cibali', 'phone' => '+90 212 533 2846', 'website' => null, 'latitude' => 41.0249, 'longitude' => 28.9588, 'rating' => 4.4, 'review_count' => 3600, 'photo_count' => 1900, 'instagram' => 'https://instagram.com/cibalikapibalikcisi'],
            ['name' => 'Karaköy Güllüoğlu', 'category' => 'Pastane', 'address' => 'Mumhane Cad. No:171, Beyoğlu', 'city' => 'İstanbul', 'district' => 'Beyoğlu', 'neighborhood' => 'Karaköy', 'phone' => '+90 212 293 0910', 'website' => 'https://www.karakoygulluoglu.com', 'latitude' => 41.0228, 'longitude' => 28.9802, 'rating' => 4.5, 'review_count' => 9800, 'photo_count' => 4200, 'instagram' => 'https://instagram.com/karakoygulluoglu', 'facebook' => 'https://facebook.com/karakoygulluoglu'],
            ['name' => 'Emirgan Sütiş', 'category' => 'Kafe', 'address' => 'Emirgan Sakıp Sabancı Cad., Sarıyer', 'city' => 'İstanbul', 'district' => 'Sarıyer', 'neighborhood' => 'Emirgan', 'phone' => '+90 212 277 7824', 'website' => 'https://www.sutis.com.tr', 'latitude' => 41.1088, 'longitude' => 29.0562, 'rating' => 4.2, 'review_count' => 5400, 'photo_count' => 2800, 'instagram' => 'https://instagram.com/sutis'],
            ['name' => 'BigChefs Bağdat Caddesi', 'category' => 'Restoran', 'address' => 'Bağdat Cad. No:251, Kadıköy', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'neighborhood' => 'Caddebostan', 'phone' => '+90 216 368 1212', 'website' => 'https://www.bigchefs.com.tr', 'latitude' => 40.9688, 'longitude' => 29.0588, 'rating' => 4.1, 'review_count' => 3100, 'photo_count' => 1400, 'instagram' => 'https://instagram.com/bigchefs', 'linkedin' => 'https://linkedin.com/company/bigchefs'],
            ['name' => 'Happy Moon\'s Ataşehir', 'category' => 'Restoran', 'address' => 'Ataşehir Bulvarı, Ataşehir', 'city' => 'İstanbul', 'district' => 'Ataşehir', 'neighborhood' => 'Küçükbakkalköy', 'phone' => '+90 216 576 0099', 'website' => 'https://www.happymoons.com', 'latitude' => 40.9921, 'longitude' => 29.1185, 'rating' => 4.0, 'review_count' => 2600, 'photo_count' => 1100, 'instagram' => 'https://instagram.com/happymoons'],
            ['name' => 'Kitchenette Nişantaşı', 'category' => 'Restoran', 'address' => 'Teşvikiye Cad., Şişli', 'city' => 'İstanbul', 'district' => 'Şişli', 'neighborhood' => 'Nişantaşı', 'phone' => '+90 212 224 0414', 'website' => 'https://www.kitchenette.com.tr', 'latitude' => 41.0508, 'longitude' => 28.9935, 'rating' => 4.2, 'review_count' => 2800, 'photo_count' => 1300, 'instagram' => 'https://instagram.com/kitchenette'],
            ['name' => 'Paper Moon Istanbul', 'category' => 'Restoran', 'address' => 'Akaretler, Beşiktaş', 'city' => 'İstanbul', 'district' => 'Beşiktaş', 'neighborhood' => 'Akaretler', 'phone' => '+90 212 236 4666', 'website' => null, 'latitude' => 41.0448, 'longitude' => 28.9992, 'rating' => 4.4, 'review_count' => 1900, 'photo_count' => 1200, 'instagram' => 'https://instagram.com/papermoon'],
            ['name' => 'Lucca Bebek', 'category' => 'Restoran', 'address' => 'Cevdet Paşa Cad. No:51/B, Beşiktaş', 'city' => 'İstanbul', 'district' => 'Beşiktaş', 'neighborhood' => 'Bebek', 'phone' => '+90 212 257 1255', 'website' => 'https://www.luccastyle.com', 'latitude' => 41.0775, 'longitude' => 29.0445, 'rating' => 4.3, 'review_count' => 2400, 'photo_count' => 1700, 'instagram' => 'https://instagram.com/luccabebek'],
            ['name' => 'House of Promise Cafe', 'category' => 'Kafe', 'address' => 'Serasker Cad., Kadıköy', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'neighborhood' => 'Caferağa', 'phone' => '+90 216 418 6677', 'website' => null, 'latitude' => 40.9915, 'longitude' => 29.0269, 'rating' => 4.5, 'review_count' => 430, 'photo_count' => 200, 'instagram' => 'https://instagram.com/houseofpromise'],
            ['name' => 'Tarihi Cumhuriyet Meyhanesi', 'category' => 'Restoran', 'address' => 'Sahne Sok. No:15, Beyoğlu', 'city' => 'İstanbul', 'district' => 'Beyoğlu', 'neighborhood' => 'Hüseyinağa', 'phone' => '+90 212 251 8874', 'website' => null, 'latitude' => 41.0342, 'longitude' => 28.9791, 'rating' => 4.2, 'review_count' => 3300, 'photo_count' => 1800, 'instagram' => null],
            ['name' => 'Namlı Gurme Karaköy', 'category' => 'Gurme', 'address' => 'Rıhtım Cad. No:1, Beyoğlu', 'city' => 'İstanbul', 'district' => 'Beyoğlu', 'neighborhood' => 'Karaköy', 'phone' => '+90 212 293 2323', 'website' => 'https://www.namligurme.com.tr', 'latitude' => 41.0221, 'longitude' => 28.9811, 'rating' => 4.4, 'review_count' => 6100, 'photo_count' => 3500, 'instagram' => 'https://instagram.com/namligurme', 'facebook' => 'https://facebook.com/namligurme'],
            ['name' => 'Kadıköy Çarşı Kahvecisi', 'category' => 'Kahve', 'address' => 'Güneşlibahçe Sok., Kadıköy', 'city' => 'İstanbul', 'district' => 'Kadıköy', 'neighborhood' => 'Caferağa', 'phone' => '+90 216 337 4455', 'website' => null, 'latitude' => 40.9898, 'longitude' => 29.0248, 'rating' => 4.5, 'review_count' => 860, 'photo_count' => 390, 'instagram' => null],
        ];
    }
}
