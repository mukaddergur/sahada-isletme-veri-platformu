<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MarketInsightService
{

    private const CATALOG = [
        'Kafe',
        'Restoran',
        'Pastane',
        'Kahve',
        'Kuaför',
        'Güzellik',
        'Berber',
        'Gurme',
        'Kahvaltı',
    ];

    public function __construct(private readonly OverpassOsmService $overpass) {}


    public function build(Collection $projectIds, int $radiusMeters = 1000): array
    {
        $rows = Business::query()
            ->whereIn('project_id', $projectIds)
            ->where(function ($q) {
                $q->whereNull('place_id')->orWhere('place_id', 'not like', 'demo_%');
            })
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get([
                'id', 'name', 'category', 'district', 'neighborhood',
                'phone', 'website', 'email', 'address',
                'latitude', 'longitude', 'ai_score', 'place_id',
            ]);

        if ($rows->isEmpty()) {
            return [
                'radius_m' => $radiusMeters,
                'summary' => [
                    'businesses_with_coords' => 0,
                    'avg_competitors_1km' => 0,
                    'low_competition_count' => 0,
                    'district_gap_count' => 0,
                ],
                'densest' => [],
                'opportunities' => [],
                'district_gaps' => [],
                'contact_gaps' => [],
                'messages' => ['Henüz konumlu gerçek işletme yok. Launch veya yakın arama çalıştırın.'],
                'density_map' => [],
                'density_peers' => [],
            ];
        }

        $density = $this->competitorDensity($rows, $radiusMeters);
        $stripPeers = static function (array $row): array {
            unset($row['peers']);

            return $row;
        };
        $densest = collect($density)
            ->sortByDesc('competitors_1km')
            ->take(12)
            ->map($stripPeers)
            ->values()
            ->all();

        $opportunities = collect($density)
            ->filter(fn ($r) => $r['competitors_1km'] <= 1)
            ->sortBy([
                ['competitors_1km', 'asc'],
                ['ai_score', 'desc'],
            ])
            ->take(12)
            ->map($stripPeers)
            ->values()
            ->all();

        $districtGaps = $this->districtGaps($rows);
        $contactGaps = $this->contactGaps($rows);
        $messages = $this->humanMessages($densest, $opportunities, $districtGaps, $contactGaps, $radiusMeters);

        $avg = round(collect($density)->avg('competitors_1km') ?? 0, 1);

        $densityMap = [];
        $densityPeers = [];
        foreach ($density as $row) {
            $densityMap[(string) $row['id']] = $row['competitors_1km'];
            $densityPeers[(string) $row['id']] = $row['peers'] ?? [];
        }

        return [
            'radius_m' => $radiusMeters,
            'summary' => [
                'businesses_with_coords' => $rows->count(),
                'avg_competitors_1km' => $avg,
                'low_competition_count' => collect($density)->where('competitors_1km', '<=', 1)->count(),
                'district_gap_count' => count($districtGaps),
            ],
            'densest' => $densest,
            'opportunities' => $opportunities,
            'district_gaps' => $districtGaps,
            'contact_gaps' => $contactGaps,
            'messages' => $messages,
            'density_map' => $densityMap,
            'density_peers' => $densityPeers,
        ];
    }


    private function competitorDensity(Collection $rows, int $radiusMeters): array
    {
        $byCategory = $rows->groupBy(fn (Business $b) => $this->normCategory($b->category));
        $out = [];

        foreach ($rows as $biz) {
            $cat = $this->normCategory($biz->category);
            $peers = $byCategory->get($cat, collect());
            $count = 0;
            $near = [];

            foreach ($peers as $peer) {
                if ($peer->id === $biz->id) {
                    continue;
                }
                $d = $this->overpass->haversineMeters(
                    (float) $biz->latitude,
                    (float) $biz->longitude,
                    (float) $peer->latitude,
                    (float) $peer->longitude
                );
                if ($d <= $radiusMeters) {
                    $count++;
                    $near[] = [
                        'id' => $peer->id,
                        'name' => $peer->name,
                        'distance_m' => (int) round($d),
                        'phone' => $peer->phone,
                        'address' => $peer->address,
                        'district' => $peer->district,
                        'website' => $peer->website,
                        'latitude' => $peer->latitude !== null ? (float) $peer->latitude : null,
                        'longitude' => $peer->longitude !== null ? (float) $peer->longitude : null,
                    ];
                }
            }

            usort($near, fn ($a, $b) => $a['distance_m'] <=> $b['distance_m']);

            $out[] = [
                'id' => $biz->id,
                'name' => $biz->name,
                'category' => $biz->category ?: 'Belirtilmemiş',
                'district' => $biz->district ?: 'Belirtilmemiş',
                'neighborhood' => $biz->neighborhood,
                'phone' => $biz->phone,
                'website' => $biz->website,
                'ai_score' => $biz->ai_score,
                'competitors_1km' => $count,
                'peers' => $near,
                'competition_level' => $this->level($count),
                'latitude' => (float) $biz->latitude,
                'longitude' => (float) $biz->longitude,
            ];
        }

        return $out;
    }


    private function districtGaps(Collection $rows): array
    {
        $byDistrict = $rows->groupBy(fn (Business $b) => trim((string) ($b->district ?: 'Belirtilmemiş')));
        $globalCats = $rows
            ->map(fn (Business $b) => $this->normCategory($b->category))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $catalog = array_values(array_unique(array_merge(self::CATALOG, $globalCats)));
        $gaps = [];

        foreach ($byDistrict as $district => $items) {
            if ($district === 'Belirtilmemiş' || $items->count() < 3) {
                continue;
            }

            $counts = [];
            foreach ($items as $item) {
                $c = $this->normCategory($item->category);
                $counts[$c] = ($counts[$c] ?? 0) + 1;
            }

            $missing = [];
            $sparse = [];
            foreach ($catalog as $cat) {
                $n = $counts[$cat] ?? 0;
                if ($n === 0) {
                    $missing[] = $cat;
                } elseif ($n <= 2) {
                    $sparse[] = ['category' => $cat, 'total' => $n];
                }
            }

            if ($missing === [] && $sparse === []) {
                continue;
            }

            $gaps[] = [
                'district' => $district,
                'total_businesses' => $items->count(),
                'missing_categories' => array_slice($missing, 0, 6),
                'sparse_categories' => array_slice($sparse, 0, 5),
                'message' => $this->gapMessage($district, $missing, $sparse),
            ];
        }

        usort($gaps, function ($a, $b) {
            $sa = count($a['missing_categories']) * 3 + count($a['sparse_categories']);
            $sb = count($b['missing_categories']) * 3 + count($b['sparse_categories']);

            return $sb <=> $sa;
        });

        return array_slice($gaps, 0, 10);
    }


    private function contactGaps(Collection $rows): array
    {
        $byDistrict = $rows->groupBy(fn (Business $b) => trim((string) ($b->district ?: 'Belirtilmemiş')));
        $out = [];

        foreach ($byDistrict as $district => $items) {
            if ($district === 'Belirtilmemiş' || $items->count() < 4) {
                continue;
            }
            $total = $items->count();
            $noPhone = $items->filter(fn (Business $b) => empty($b->phone))->count();
            $noWeb = $items->filter(fn (Business $b) => empty($b->website))->count();
            $phonePct = (int) round(($noPhone / $total) * 100);
            $webPct = (int) round(($noWeb / $total) * 100);

            if ($phonePct < 35 && $webPct < 35) {
                continue;
            }

            $out[] = [
                'district' => $district,
                'total' => $total,
                'missing_phone' => $noPhone,
                'missing_website' => $noWeb,
                'missing_phone_pct' => $phonePct,
                'missing_website_pct' => $webPct,
                'message' => "{$district}: işletmelerin %{$phonePct}’inde telefon, %{$webPct}’inde website yok — satış/iletişim fırsatı.",
            ];
        }

        usort($out, fn ($a, $b) => ($b['missing_phone_pct'] + $b['missing_website_pct']) <=> ($a['missing_phone_pct'] + $a['missing_website_pct']));

        return array_slice($out, 0, 8);
    }


    private function humanMessages(array $densest, array $opportunities, array $districtGaps, array $contactGaps, int $radius): array
    {
        $messages = [];

        if ($densest !== []) {
            $top = $densest[0];
            $messages[] = "{$top['district']} · {$top['name']} ({$top['category']}): {$radius} m içinde {$top['competitors_1km']} rakip — yüksek yoğunluk.";
        }

        if ($opportunities !== []) {
            $o = $opportunities[0];
            $messages[] = "{$o['district']} · {$o['category']}: “{$o['name']}” çevresinde yalnızca {$o['competitors_1km']} rakip — düşük rekabet / boşluk sinyali.";
        }

        foreach (array_slice($districtGaps, 0, 3) as $g) {
            $messages[] = $g['message'];
        }

        foreach (array_slice($contactGaps, 0, 2) as $c) {
            $messages[] = $c['message'];
        }

        return array_values(array_unique($messages));
    }


    private function gapMessage(string $district, array $missing, array $sparse): string
    {
        if ($missing !== []) {
            $list = implode(', ', array_slice($missing, 0, 3));

            return "{$district} semtinde şu kategoriler neredeyse yok: {$list}.";
        }

        $first = $sparse[0] ?? null;
        if ($first) {
            return "{$district} semtinde “{$first['category']}” çok seyrek ({$first['total']} kayıt) — fırsat alanı.";
        }

        return "{$district} için kategori boşluğu tespit edildi.";
    }

    private function normCategory(?string $category): string
    {
        $c = trim((string) $category);
        if ($c === '') {
            return 'Belirtilmemiş';
        }

        $lower = Str::lower(Str::ascii($c));
        if (str_contains($lower, 'kafe') || str_contains($lower, 'cafe') || str_contains($lower, 'coffee')) {
            return 'Kafe';
        }
        if (str_contains($lower, 'kahve')) {
            return 'Kahve';
        }
        if (str_contains($lower, 'restoran') || str_contains($lower, 'restaurant') || str_contains($lower, 'yemek')) {
            return 'Restoran';
        }
        if (str_contains($lower, 'pastane') || str_contains($lower, 'bakery')) {
            return 'Pastane';
        }
        if (str_contains($lower, 'kuafor') || str_contains($lower, 'hair')) {
            return 'Kuaför';
        }
        if (str_contains($lower, 'guzellik') || str_contains($lower, 'beauty')) {
            return 'Güzellik';
        }
        if (str_contains($lower, 'berber')) {
            return 'Berber';
        }

        return $c;
    }

    private function level(int $count): string
    {
        if ($count <= 1) {
            return 'düşük';
        }
        if ($count <= 4) {
            return 'orta';
        }

        return 'yüksek';
    }
}
