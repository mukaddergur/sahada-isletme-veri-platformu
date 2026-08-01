<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OverpassOsmService
{
    private const ENDPOINTS = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
        'https://overpass.private.coffee/api/interpreter',
    ];


    public function search(string $query, int $limit = 60): array
    {
        [, $area] = $this->resolveQuery($query);
        $cityHint = $area['city'] ?? null;

        if ($this->isWeddingQuery($query)) {
            $named = $this->searchNominatimPlaces($query, $limit, $cityHint);
            if (count($named) > 0) {
                return $named;
            }
        }

        if ($area === null) {
            $placeHint = $this->extractPlaceHint($query);
            try {
                $geo = app(GeocodeService::class)->geocode($placeHint !== '' ? $placeHint : $query);
                $cityHint = Str::before((string) ($geo['label'] ?? $placeHint), ',');
                foreach ([12000, 20000] as $radius) {
                    $around = $this->searchAround(
                        (float) $geo['lat'],
                        (float) $geo['lon'],
                        $radius,
                        $query,
                        $limit,
                        $cityHint !== '' ? $cityHint : null,
                    );
                    if (count($around) > 0) {
                        return $around;
                    }
                }

                $named = $this->searchNominatimPlaces($query, $limit, $cityHint !== '' ? $cityHint : null);
                if (count($named) > 0) {
                    return $named;
                }

                return [];
            } catch (\Throwable) {
                return [];
            }
        }

        $bbox = $area['bbox'];
        $fetch = max($limit * 2, 40);
        $union = $this->unionForBbox($this->querySelectors($query), $bbox);

        $ql = <<<OVERPASS
[out:json][timeout:45];
(
{$union}
);
out center tags {$fetch};
OVERPASS;

        try {
            $payload = $this->queryOverpass($ql);
        } catch (\Throwable) {
            $payload = ['elements' => []];
        }

        $mapped = [];
        $seen = [];

        foreach ($payload['elements'] ?? [] as $el) {
            $row = $this->mapElement($el, $area, $query);
            if (! $row) {
                continue;
            }
            if (isset($seen[$row['place_id']])) {
                continue;
            }
            $seen[$row['place_id']] = true;
            $mapped[] = $row;
        }

        if (count($mapped) === 0) {
            $mapped = $this->searchNominatimPlaces(
                $query,
                $limit,
                $area['city'] ?? ($area['district'] ?? null)
            );
        }

        usort($mapped, fn ($a, $b) => $this->completenessScore($b) <=> $this->completenessScore($a));

        return array_slice($mapped, 0, $limit);
    }

    private function isWeddingQuery(string $query): bool
    {
        $q = Str::lower(Str::ascii($query));

        return (bool) preg_match('/dugun|wedding|nikah|organizasyon|ballroom|davet|banquet/', $q)
            || (str_contains($q, 'salon') && ! str_contains($q, 'kuafor'));
    }

    public function searchAround(
        float $lat,
        float $lon,
        int $radiusMeters = 1800,
        string $categoryHint = '',
        int $limit = 40,
        ?string $districtHint = null,
    ): array {
        $radius = max(400, min(25000, $radiusMeters));
        $fetch = max($limit * 2, 40);
        $union = $this->unionForAround($this->querySelectors($categoryHint), $radius, $lat, $lon);

        $ql = <<<OVERPASS
[out:json][timeout:45];
(
{$union}
);
out center tags {$fetch};
OVERPASS;

        $payload = $this->queryOverpass($ql);
        $area = [
            'bbox' => '',
            'district' => $districtHint,
            'city' => $this->guessCityFromLabel((string) ($districtHint ?: $categoryHint)),
        ];
        $mapped = [];
        $seen = [];

        foreach ($payload['elements'] ?? [] as $el) {
            $row = $this->mapElement($el, $area, $districtHint ?: $categoryHint);
            if (! $row) {
                continue;
            }
            if (isset($seen[$row['place_id']])) {
                continue;
            }
            $seen[$row['place_id']] = true;
            $row['distance_m'] = (int) round($this->haversineMeters(
                $lat,
                $lon,
                (float) $row['latitude'],
                (float) $row['longitude']
            ));
            $row['maps_url'] = 'https://www.google.com/maps/search/?api=1&query='
                .$row['latitude'].'%2C'.$row['longitude'];
            $mapped[] = $row;
        }

        if (count($mapped) === 0 && $categoryHint !== '') {
            return $this->searchNominatimPlaces($categoryHint, $limit, $districtHint);
        }

        usort($mapped, function ($a, $b) {
            $da = $a['distance_m'] ?? PHP_INT_MAX;
            $db = $b['distance_m'] ?? PHP_INT_MAX;
            if ($da === $db) {
                return $this->completenessScore($b) <=> $this->completenessScore($a);
            }

            return $da <=> $db;
        });

        $withInfo = array_values(array_filter(
            $mapped,
            fn ($r) => ! empty($r['phone']) || ! empty($r['website']) || ! empty($r['address']) || ! empty($r['email'])
        ));
        if (count($withInfo) >= max(6, (int) floor($limit * 0.4))) {
            $mapped = $withInfo;
        }

        return array_slice($mapped, 0, $limit);
    }

    public function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earth = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $earth * asin(min(1, sqrt($a)));
    }

    private function querySelectors(string $hint): array
    {
        $q = Str::lower(Str::ascii($hint));

        if (preg_match('/dershane|dersane|etut|etüt|egitim|kolej|college|dil\s*okul|yabanci\s*dil|ogretim|öğretim/', $q)
            || (str_contains($q, 'kurs') && ! str_contains($q, 'surucu') && ! str_contains($q, 'sürücü'))) {
            return [
                '["amenity"~"college|language_school|music_school"]',
                '["office"~"educational_institution|tutoring|coach"]',
                '["name"~"dershane|dersane|etüt|etut|kolej|akademi|öğretim|ogretim",i]',
            ];
        }
        if (preg_match('/dugun|wedding|nikah|organizasyon|ballroom|davet|banquet/', $q)
            || (str_contains($q, 'salon') && ! str_contains($q, 'kuafor'))) {
            return [
                '["name"~"düğün|dugun|wedding|nikah",i]',
            ];
        }
        if (str_contains($q, 'restoran') || str_contains($q, 'restaurant') || str_contains($q, 'yemek')) {
            return ['["amenity"~"restaurant|fast_food"]'];
        }
        if (str_contains($q, 'pastane') || str_contains($q, 'tatli') || str_contains($q, 'dessert')) {
            return ['["amenity"~"cafe|ice_cream|bakery"]'];
        }
        if (str_contains($q, 'guzellik') || str_contains($q, 'kuafor') || str_contains($q, 'berber') || str_contains($q, 'spa')) {
            return ['["shop"~"beauty|hairdresser"]'];
        }
        if (str_contains($q, 'kahve') || str_contains($q, 'kafe') || str_contains($q, 'cafe') || str_contains($q, 'coffee')) {
            return ['["amenity"~"cafe|coffee_shop|bakery"]'];
        }
        if (str_contains($q, 'otel') || str_contains($q, 'hotel') || str_contains($q, 'pansiyon')) {
            return ['["tourism"~"hotel|guest_house|hostel"]'];
        }
        if (str_contains($q, 'eczane') || str_contains($q, 'pharmacy')) {
            return ['["amenity"="pharmacy"]'];
        }
        if (str_contains($q, 'market') || str_contains($q, 'bakkal') || str_contains($q, 'supermarket')) {
            return ['["shop"~"supermarket|convenience|greengrocer"]'];
        }

        return ['["amenity"~"cafe|restaurant|fast_food|ice_cream|bakery|bar|pub"]'];
    }

    private function unionForBbox(array $selectors, string $bbox): string
    {
        $lines = [];
        foreach ($selectors as $sel) {
            $lines[] = "  node{$sel}({$bbox});";
            $lines[] = "  way{$sel}({$bbox});";
        }

        return implode("\n", $lines);
    }

    private function unionForAround(array $selectors, int $radius, float $lat, float $lon): string
    {
        $lines = [];
        foreach ($selectors as $sel) {
            $lines[] = "  node{$sel}(around:{$radius},{$lat},{$lon});";
            $lines[] = "  way{$sel}(around:{$radius},{$lat},{$lon});";
        }

        return implode("\n", $lines);
    }

    private function amenityFromHint(string $hint): string
    {
        $selectors = $this->querySelectors($hint);
        $first = $selectors[0] ?? '["amenity"~"cafe|restaurant"]';
        if (preg_match('/~"([^"]+)"/', $first, $m)) {
            return $m[1];
        }

        return 'cafe|restaurant|fast_food';
    }


    private function queryOverpass(string $ql): array
    {
        $lastError = null;

        foreach (self::ENDPOINTS as $endpoint) {
            try {
                $response = Http::timeout(22)
                    ->connectTimeout(6)
                    ->asForm()
                    ->withHeaders([
                        'User-Agent' => 'Sahada/1.0 (education; local thesis project)',
                    ])
                    ->post($endpoint, ['data' => $ql]);

                if ($response->successful()) {
                    $json = $response->json();
                    if (is_array($json) && array_key_exists('elements', $json) && is_array($json['elements'])) {
                        return $json;
                    }
                }

                $lastError = 'HTTP '.$response->status();
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }

            usleep(120000);
        }

        throw new \RuntimeException('Overpass OSM verisi alınamadı: '.($lastError ?: 'bilinmeyen hata'));
    }

    private function searchNominatimPlaces(string $query, int $limit = 30, ?string $cityHint = null): array
    {
        $city = $cityHint ?: $this->guessCityFromLabel($query);
        if ($city === 'Türkiye') {
            $city = null;
        }

        $category = $this->nominatimCategoryPhrase($query);
        $candidates = [];
        if ($city) {
            $candidates[] = trim($category.' '.$city);
            $ascii = Str::lower(Str::ascii($query));
            $candidates[] = trim(str_replace('antep', 'Gaziantep', $ascii));
        }
        $candidates[] = $query;
        $candidates = array_values(array_unique(array_filter($candidates)));

        $rows = [];
        foreach ($candidates as $candidate) {
            $q = $candidate;
            if (! preg_match('/türkiye|turkey|turkiye/iu', $q)) {
                $q .= ', Türkiye';
            }

            try {
                $response = Http::timeout(25)
                    ->withHeaders([
                        'User-Agent' => 'Sahada/1.0 (education thesis; contact=local)',
                        'Accept-Language' => 'tr',
                    ])
                    ->get('https://nominatim.openstreetmap.org/search', [
                        'q' => $q,
                        'format' => 'json',
                        'limit' => min(40, max(10, $limit)),
                        'countrycodes' => 'tr',
                        'addressdetails' => 1,
                    ]);
            } catch (\Throwable) {
                continue;
            }

            if (! $response->successful() || ! is_array($response->json())) {
                continue;
            }

            $rows = $response->json();
            if (count($rows) > 0) {
                break;
            }

            usleep(200000);
        }

        if ($rows === []) {
            return [];
        }

        $area = [
            'bbox' => '',
            'district' => $cityHint,
            'city' => $city ?: $this->guessCityFromLabel($query),
        ];

        $mapped = [];
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['lat'], $row['lon'])) {
                continue;
            }
            $name = $row['name'] ?? null;
            if (! $name && isset($row['display_name'])) {
                $name = Str::before((string) $row['display_name'], ',');
            }
            if (! $name) {
                continue;
            }

            $cls = Str::lower((string) ($row['class'] ?? ''));
            $typ = Str::lower((string) ($row['type'] ?? ''));
            if (in_array($cls, ['highway', 'place', 'boundary', 'waterway', 'natural'], true)) {
                continue;
            }

            $osmType = match ($row['osm_type'] ?? 'node') {
                'way' => 'way',
                'relation' => 'relation',
                default => 'node',
            };
            $placeId = 'osm_'.$osmType.'_'.($row['osm_id'] ?? md5($name.$row['lat'].$row['lon']));
            if (isset($seen[$placeId])) {
                continue;
            }
            $seen[$placeId] = true;

            $addr = is_array($row['address'] ?? null) ? $row['address'] : [];
            $mapped[] = [
                'name' => trim((string) $name),
                'category' => $this->categoryFromNominatim($cls, $typ, (string) $name),
                'address' => $row['display_name'] ?? null,
                'city' => $addr['city'] ?? $addr['town'] ?? $addr['province'] ?? ($area['city'] ?? null),
                'district' => $addr['county'] ?? $addr['suburb'] ?? $addr['district'] ?? ($area['district'] ?? null),
                'neighborhood' => $addr['neighbourhood'] ?? $addr['suburb'] ?? null,
                'phone' => null,
                'email' => null,
                'website' => null,
                'maps_url' => 'https://www.openstreetmap.org/'.$osmType.'/'.($row['osm_id'] ?? ''),
                'place_id' => $placeId,
                'latitude' => (float) $row['lat'],
                'longitude' => (float) $row['lon'],
                'rating' => null,
                'review_count' => 0,
                'photo_count' => 0,
                'instagram' => null,
                'facebook' => null,
                'twitter' => null,
                'opening_hours' => null,
                'source' => 'openstreetmap',
            ];
        }

        return array_slice($mapped, 0, $limit);
    }

    private function nominatimCategoryPhrase(string $query): string
    {
        $q = Str::lower(Str::ascii($query));
        if (preg_match('/dugun|wedding|nikah|organizasyon|davet|banquet/', $q)
            || (str_contains($q, 'salon') && ! str_contains($q, 'kuafor'))) {
            return 'düğün salonu';
        }
        if (preg_match('/dershane|dersane|etut|kurs|egitim|kolej/', $q)) {
            return 'dershane';
        }
        if (str_contains($q, 'restoran') || str_contains($q, 'restaurant')) {
            return 'restoran';
        }
        if (str_contains($q, 'kafe') || str_contains($q, 'cafe') || str_contains($q, 'kahve')) {
            return 'kafe';
        }

        $place = $this->extractPlaceHint($query);

        return trim(str_replace($place, '', $query)) ?: $query;
    }

    private function categoryFromNominatim(string $class, string $type, string $name): string
    {
        $n = Str::lower(Str::ascii($name));
        if (preg_match('/dugun|wedding|nikah|organizasyon|davet/', $n)) {
            return 'Düğün Salonu';
        }
        if ($class === 'tourism' && str_contains($type, 'hotel')) {
            return 'Otel';
        }
        if ($class === 'amenity' && str_contains($type, 'cafe')) {
            return 'Kafe';
        }
        if ($class === 'amenity' && str_contains($type, 'restaurant')) {
            return 'Restoran';
        }

        return $type !== '' ? Str::title(str_replace('_', ' ', $type)) : 'İşletme';
    }


    private function mapElement(array $el, array $area, string $query): ?array
    {
        $tags = $el['tags'] ?? [];
        $name = $tags['name'] ?? $tags['name:tr'] ?? $tags['brand'] ?? null;
        if (! $name) {
            return null;
        }

        $lat = $el['lat'] ?? ($el['center']['lat'] ?? null);
        $lon = $el['lon'] ?? ($el['center']['lon'] ?? null);
        if ($lat === null || $lon === null) {
            return null;
        }

        $address = $this->formatAddress($tags);
        $district = $tags['addr:district']
            ?? $tags['addr:suburb']
            ?? $area['district']
            ?? $this->guessDistrictFromName($query);

        $website = $this->normalizeUrl(
            $tags['website']
                ?? $tags['contact:website']
                ?? $tags['url']
                ?? null
        );
        $phone = $this->normalizePhone(
            $tags['phone']
                ?? $tags['contact:phone']
                ?? $tags['mobile']
                ?? $tags['contact:mobile']
                ?? null
        );
        $email = $tags['email'] ?? $tags['contact:email'] ?? null;
        $category = $this->mapCategory($tags);

        return [
            'name' => trim($name),
            'category' => $category,
            'address' => $address,
            'city' => $tags['addr:city'] ?? ($area['city'] ?? null) ?? $this->guessCityFromLabel((string) ($area['district'] ?? $query)),
            'district' => $district ? Str::title($district) : null,
            'neighborhood' => $tags['addr:neighbourhood'] ?? $tags['addr:suburb'] ?? null,
            'phone' => $phone,
            'email' => $email ? Str::lower(trim($email)) : null,
            'website' => $website,
            'maps_url' => 'https://www.openstreetmap.org/'.($el['type'] ?? 'node').'/'.$el['id'],
            'place_id' => 'osm_'.($el['type'] ?? 'node').'_'.$el['id'],
            'latitude' => (float) $lat,
            'longitude' => (float) $lon,
            'rating' => null,
            'review_count' => 0,
            'photo_count' => 0,
            'instagram' => $this->socialHandle($tags['contact:instagram'] ?? null),
            'facebook' => $tags['contact:facebook'] ?? null,
            'twitter' => $tags['contact:twitter'] ?? null,
            'opening_hours' => isset($tags['opening_hours']) ? ['raw' => $tags['opening_hours']] : null,
            'source' => 'openstreetmap',
        ];
    }


    private function completenessScore(array $row): int
    {
        $score = 0;
        if (! empty($row['phone'])) {
            $score += 3;
        }
        if (! empty($row['website'])) {
            $score += 3;
        }
        if (! empty($row['email'])) {
            $score += 2;
        }
        if (! empty($row['address'])) {
            $score += 2;
        }
        if (! empty($row['instagram']) || ! empty($row['facebook'])) {
            $score += 1;
        }

        return $score;
    }


    private function resolveQuery(string $query): array
    {
        $q = Str::lower(Str::ascii($query));

        $areas = [

            'sariyer' => ['bbox' => '41.0800,28.9900,41.1450,29.0900', 'district' => 'Sarıyer', 'city' => 'İstanbul'],
            'bebek' => ['bbox' => '41.0700,29.0300,41.0900,29.0550', 'district' => 'Beşiktaş', 'city' => 'İstanbul'],
            'karakoy' => ['bbox' => '41.0180,28.9680,41.0300,28.9850', 'district' => 'Beyoğlu', 'city' => 'İstanbul'],
            'nisantasi' => ['bbox' => '41.0450,28.9850,41.0580,29.0050', 'district' => 'Şişli', 'city' => 'İstanbul'],
            'moda' => ['bbox' => '40.9780,29.0180,40.9920,29.0350', 'district' => 'Kadıköy', 'city' => 'İstanbul'],
            'kadikoy' => ['bbox' => '40.9700,29.0000,41.0150,29.0850', 'district' => 'Kadıköy', 'city' => 'İstanbul'],
            'besiktas' => ['bbox' => '41.0300,28.9700,41.0950,29.0650', 'district' => 'Beşiktaş', 'city' => 'İstanbul'],
            'beyoglu' => ['bbox' => '41.0150,28.9550,41.0500,29.0000', 'district' => 'Beyoğlu', 'city' => 'İstanbul'],
            'sisli' => ['bbox' => '41.0450,28.9700,41.0800,29.0200', 'district' => 'Şişli', 'city' => 'İstanbul'],
            'uskudar' => ['bbox' => '41.0050,29.0000,41.0450,29.0550', 'district' => 'Üsküdar', 'city' => 'İstanbul'],
            'atasehir' => ['bbox' => '40.9700,29.0900,41.0150,29.1650', 'district' => 'Ataşehir', 'city' => 'İstanbul'],
            'maltepe' => ['bbox' => '40.9150,29.1000,40.9650,29.1650', 'district' => 'Maltepe', 'city' => 'İstanbul'],
            'bakirkoy' => ['bbox' => '40.9550,28.8150,41.0050,28.9050', 'district' => 'Bakırköy', 'city' => 'İstanbul'],
            'fatih' => ['bbox' => '41.0000,28.9250,41.0350,28.9850', 'district' => 'Fatih', 'city' => 'İstanbul'],
            'istanbul' => ['bbox' => '40.9650,28.9350,41.0900,29.0900', 'district' => null, 'city' => 'İstanbul'],

            'ankara' => ['bbox' => '39.8500,32.7500,40.0000,32.9500', 'district' => null, 'city' => 'Ankara'],
            'cankaya' => ['bbox' => '39.8600,32.8200,39.9300,32.9000', 'district' => 'Çankaya', 'city' => 'Ankara'],
            'izmir' => ['bbox' => '38.3500,26.9500,38.5200,27.3000', 'district' => null, 'city' => 'İzmir'],
            'karsiyaka' => ['bbox' => '38.4400,27.0900,38.4900,27.1600', 'district' => 'Karşıyaka', 'city' => 'İzmir'],
            'bornova' => ['bbox' => '38.4400,27.1800,38.5000,27.2600', 'district' => 'Bornova', 'city' => 'İzmir'],
            'alsancak' => ['bbox' => '38.4300,27.1300,38.4500,27.1600', 'district' => 'Konak', 'city' => 'İzmir'],
            'bursa' => ['bbox' => '40.1600,28.9500,40.2500,29.1500', 'district' => null, 'city' => 'Bursa'],
            'antalya' => ['bbox' => '36.8500,30.6500,36.9500,30.8000', 'district' => null, 'city' => 'Antalya'],
            'gaziantep' => ['bbox' => '36.9800,37.2800,37.1500,37.4800', 'district' => null, 'city' => 'Gaziantep'],
            'antep' => ['bbox' => '36.9800,37.2800,37.1500,37.4800', 'district' => null, 'city' => 'Gaziantep'],
            'konya' => ['bbox' => '37.8200,32.4300,37.9200,32.5600', 'district' => null, 'city' => 'Konya'],
            'adana' => ['bbox' => '36.9600,35.2800,37.0500,35.4000', 'district' => null, 'city' => 'Adana'],
            'mersin' => ['bbox' => '36.7500,34.5500,36.8500,34.7000', 'district' => null, 'city' => 'Mersin'],
            'trabzon' => ['bbox' => '40.9800,39.6800,41.0400,39.7800', 'district' => null, 'city' => 'Trabzon'],
            'eskisehir' => ['bbox' => '39.7400,30.4800,39.8100,30.5800', 'district' => null, 'city' => 'Eskişehir'],
            'diyarbakir' => ['bbox' => '37.8800,40.1500,37.9500,40.2500', 'district' => null, 'city' => 'Diyarbakır'],
            'samsun' => ['bbox' => '41.2500,36.2800,41.3200,36.4000', 'district' => null, 'city' => 'Samsun'],
            'kayseri' => ['bbox' => '38.6800,35.4300,38.7600,35.5400', 'district' => null, 'city' => 'Kayseri'],
            'malatya' => ['bbox' => '38.3200,38.2500,38.4000,38.3800', 'district' => null, 'city' => 'Malatya'],
            'denizli' => ['bbox' => '37.7400,29.0500,37.8100,29.1500', 'district' => null, 'city' => 'Denizli'],
            'sakarya' => ['bbox' => '40.7400,30.3500,40.8100,30.4500', 'district' => null, 'city' => 'Sakarya'],
            'kocaeli' => ['bbox' => '40.7300,29.8800,40.8200,30.0200', 'district' => null, 'city' => 'Kocaeli'],
            'gebze' => ['bbox' => '40.7700,29.4000,40.8500,29.5000', 'district' => 'Gebze', 'city' => 'Kocaeli'],
            'elazig' => ['bbox' => '38.6200,39.1000,38.7600,39.3200', 'district' => null, 'city' => 'Elazığ'],
            'van' => ['bbox' => '38.4600,43.3200,38.5400,43.4200', 'district' => null, 'city' => 'Van'],
            'erzurum' => ['bbox' => '39.8700,41.2200,39.9500,41.3200', 'district' => null, 'city' => 'Erzurum'],
            'sanliurfa' => ['bbox' => '37.1300,38.7500,37.2000,38.8500', 'district' => null, 'city' => 'Şanlıurfa'],
            'urfa' => ['bbox' => '37.1300,38.7500,37.2000,38.8500', 'district' => null, 'city' => 'Şanlıurfa'],
            'hatay' => ['bbox' => '36.1800,36.1000,36.2600,36.2200', 'district' => null, 'city' => 'Hatay'],
            'antakya' => ['bbox' => '36.1800,36.1000,36.2600,36.2200', 'district' => null, 'city' => 'Hatay'],
            'balikesir' => ['bbox' => '39.6100,27.8400,39.6800,27.9500', 'district' => null, 'city' => 'Balıkesir'],
            'manisa' => ['bbox' => '38.5800,27.4000,38.6500,27.5000', 'district' => null, 'city' => 'Manisa'],
            'aydin' => ['bbox' => '37.8200,27.8000,37.8800,27.9000', 'district' => null, 'city' => 'Aydın'],
            'mugla' => ['bbox' => '37.1800,28.3200,37.2500,28.4200', 'district' => null, 'city' => 'Muğla'],
            'tekirdag' => ['bbox' => '40.9500,27.4800,41.0200,27.5800', 'district' => null, 'city' => 'Tekirdağ'],
            'corum' => ['bbox' => '40.5200,34.9200,40.5800,35.0000', 'district' => null, 'city' => 'Çorum'],
            'afyon' => ['bbox' => '38.7300,30.5000,38.8000,30.6000', 'district' => null, 'city' => 'Afyonkarahisar'],
            'afyonkarahisar' => ['bbox' => '38.7300,30.5000,38.8000,30.6000', 'district' => null, 'city' => 'Afyonkarahisar'],
        ];


        uksort($areas, fn ($a, $b) => strlen($b) <=> strlen($a));

        $matched = null;
        $area = null;
        foreach ($areas as $key => $meta) {
            if (preg_match('/\b'.preg_quote($key, '/').'\b/u', $q) || str_contains($q, $key)) {
                $matched = $key;
                $area = $meta;
                break;
            }
        }


        if ($matched === null) {
            $area = null;
        }

        if (preg_match('/dugun|wedding|nikah|organizasyon|ballroom|davet|banquet/', $q)
            || (str_contains($q, 'salon') && ! str_contains($q, 'kuafor'))) {
            $filter = '["name"~"düğün|dugun|wedding|nikah|salon",i]';
        } elseif (str_contains($q, 'restoran') || str_contains($q, 'restaurant') || str_contains($q, 'yemek')) {
            $filter = '["amenity"~"restaurant|fast_food"]';
        } elseif (preg_match('/dershane|dersane|etut|kurs|egitim|kolej|college|dil\s*okul/', $q)) {
            $filter = '["name"~"dershane|dersane|etüt|etut|kurs",i]';
        } elseif (str_contains($q, 'pastane') || str_contains($q, 'dessert') || str_contains($q, 'tatli')) {
            $filter = '["amenity"~"cafe|ice_cream|bakery"]';
        } elseif (str_contains($q, 'guzellik') || str_contains($q, 'kuafor') || str_contains($q, 'berber') || str_contains($q, 'spa')) {
            $filter = '["shop"~"beauty|hairdresser"]';
        } elseif (str_contains($q, 'kahve') || str_contains($q, 'kafe') || str_contains($q, 'cafe') || str_contains($q, 'coffee')) {
            $filter = '["amenity"~"cafe|coffee_shop|bakery"]';
        } elseif (str_contains($q, 'esnaf') || str_contains($q, 'isletme')) {
            $filter = '["amenity"~"cafe|restaurant|fast_food|ice_cream|bakery|bar|pub"]';
        } else {
            $filter = '["amenity"~"cafe|restaurant|fast_food|ice_cream|bakery|bar"]';
        }

        return [$filter, $area];
    }

    private function extractPlaceHint(string $query): string
    {
        $q = Str::lower(Str::ascii($query));
        $words = [
            'restoran', 'restaurant', 'yemek', 'kafe', 'cafe', 'coffee', 'kahve',
            'pastane', 'tatli', 'guzellik', 'kuafor', 'berber', 'spa', 'esnaf', 'isletme',
            'merkezi', 'salon', 'salonu', 'salonlari', 'dugun', 'wedding', 'nikah',
            'organizasyon', 'davet', 'ballroom', 'banquet',
            'dershane', 'dershaneler', 'dersane', 'etut', 'etüt',
            'kurs', 'kurslar', 'egitim', 'eğitim', 'kolej', 'college', 'okul', 'okullar',
        ];
        foreach ($words as $w) {
            $q = preg_replace('/\b'.preg_quote($w, '/').'\b/', ' ', $q) ?? $q;
        }
        $q = trim(preg_replace('/\s+/', ' ', $q) ?? '');

        return $q;
    }

    private function guessCityFromLabel(string $text): string
    {
        $t = Str::lower(Str::ascii($text));
        foreach ([
            'ankara' => 'Ankara', 'izmir' => 'İzmir', 'bursa' => 'Bursa', 'antalya' => 'Antalya',
            'gaziantep' => 'Gaziantep', 'antep' => 'Gaziantep', 'konya' => 'Konya', 'adana' => 'Adana', 'mersin' => 'Mersin',
            'trabzon' => 'Trabzon', 'eskisehir' => 'Eskişehir', 'diyarbakir' => 'Diyarbakır',
            'samsun' => 'Samsun', 'kayseri' => 'Kayseri', 'malatya' => 'Malatya', 'denizli' => 'Denizli',
            'elazig' => 'Elazığ', 'van' => 'Van', 'erzurum' => 'Erzurum', 'sanliurfa' => 'Şanlıurfa',
            'istanbul' => 'İstanbul', 'kadikoy' => 'İstanbul', 'besiktas' => 'İstanbul',
        ] as $needle => $city) {
            if (str_contains($t, $needle)) {
                return $city;
            }
        }

        return 'Türkiye';
    }

    private function formatAddress(array $tags): ?string
    {
        if (! empty($tags['addr:full'])) {
            return trim((string) $tags['addr:full']);
        }

        $parts = array_filter([
            $tags['addr:street'] ?? null,
            isset($tags['addr:housenumber']) ? 'No:'.$tags['addr:housenumber'] : null,
            $tags['addr:neighbourhood'] ?? null,
            $tags['addr:suburb'] ?? null,
            $tags['addr:district'] ?? null,
            $tags['addr:city'] ?? null,
        ]);

        return $parts ? implode(', ', $parts) : null;
    }

    private function mapCategory(array $tags): string
    {
        $amenity = $tags['amenity'] ?? '';
        $shop = $tags['shop'] ?? '';
        $cuisine = Str::lower((string) ($tags['cuisine'] ?? ''));

        $nameLower = Str::lower(Str::ascii((string) ($tags['name'] ?? '')));
        $isWedding = preg_match('/dugun|wedding|nikah|organizasyon|davet/', $nameLower)
            || in_array($amenity, ['events_venue', 'community_centre', 'conference_centre'], true);

        return match (true) {
            $isWedding => 'Düğün Salonu',
            $shop === 'beauty', $shop === 'hairdresser' => 'Güzellik',
            $amenity === 'cafe', str_contains($cuisine, 'coffee') => 'Kafe',
            $amenity === 'restaurant' => 'Restoran',
            $amenity === 'fast_food' => 'Fast Food',
            $amenity === 'ice_cream', $amenity === 'bakery' => 'Pastane',
            $amenity === 'bar', $amenity === 'pub' => 'Kafe',
            in_array($amenity, ['college', 'language_school', 'music_school', 'driving_school', 'school'], true),
            ($tags['office'] ?? '') === 'educational_institution',
            ($tags['office'] ?? '') === 'tutoring' => 'Dershane',
            default => $amenity ?: ($shop ?: 'İşletme'),
        };
    }

    private function guessDistrictFromName(string $query): ?string
    {
        foreach (['Kadıköy', 'Beşiktaş', 'Beyoğlu', 'Şişli', 'Üsküdar', 'Ataşehir', 'Maltepe', 'Bakırköy', 'Fatih'] as $d) {
            if (Str::contains(Str::lower(Str::ascii($query)), Str::lower(Str::ascii($d)))) {
                return $d;
            }
        }

        return null;
    }

    private function normalizePhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $phone = trim(explode(';', $phone)[0]);
        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        if (str_starts_with($digits, '90') && strlen($digits) >= 12) {
            $rest = substr($digits, 2);

            return '+90 '.substr($rest, 0, 3).' '.substr($rest, 3, 3).' '.substr($rest, 6, 2).' '.substr($rest, 8, 2);
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return '+90 '.substr($digits, 1, 3).' '.substr($digits, 4, 3).' '.substr($digits, 7, 2).' '.substr($digits, 9, 2);
        }

        return $phone;
    }

    private function normalizeUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $url = trim(explode(';', $url)[0]);
        if ($url === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        return $url;
    }

    private function socialHandle(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        $value = trim($value);
        if (str_contains($value, 'instagram.com')) {
            $parts = explode('/', rtrim($value, '/'));

            return end($parts) ?: $value;
        }

        return ltrim($value, '@');
    }
}
