<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GeocodeService
{

    private const FALLBACKS = [
        'kadikoy' => ['lat' => 40.9901, 'lon' => 29.0290, 'label' => 'Kadıköy, İstanbul'],
        'moda' => ['lat' => 40.9845, 'lon' => 29.0260, 'label' => 'Moda, Kadıköy'],
        'caferaga' => ['lat' => 40.9888, 'lon' => 29.0255, 'label' => 'Caferağa, Kadıköy'],
        'besiktas' => ['lat' => 41.0422, 'lon' => 29.0067, 'label' => 'Beşiktaş, İstanbul'],
        'ortakoy' => ['lat' => 41.0472, 'lon' => 29.0258, 'label' => 'Ortaköy, Beşiktaş'],
        'bebek' => ['lat' => 41.0768, 'lon' => 29.0439, 'label' => 'Bebek, Beşiktaş'],
        'beyoglu' => ['lat' => 41.0325, 'lon' => 28.9774, 'label' => 'Beyoğlu, İstanbul'],
        'karakoy' => ['lat' => 41.0235, 'lon' => 28.9745, 'label' => 'Karaköy, Beyoğlu'],
        'cihangir' => ['lat' => 41.0315, 'lon' => 28.9835, 'label' => 'Cihangir, Beyoğlu'],
        'galata' => ['lat' => 41.0256, 'lon' => 28.9742, 'label' => 'Galata, Beyoğlu'],
        'taksim' => ['lat' => 41.0370, 'lon' => 28.9850, 'label' => 'Taksim, Beyoğlu'],
        'sisli' => ['lat' => 41.0602, 'lon' => 28.9877, 'label' => 'Şişli, İstanbul'],
        'nisantasi' => ['lat' => 41.0505, 'lon' => 28.9945, 'label' => 'Nişantaşı, Şişli'],
        'uskudar' => ['lat' => 41.0255, 'lon' => 29.0150, 'label' => 'Üsküdar, İstanbul'],
        'atasehir' => ['lat' => 40.9833, 'lon' => 29.1278, 'label' => 'Ataşehir, İstanbul'],
        'maltepe' => ['lat' => 40.9355, 'lon' => 29.1312, 'label' => 'Maltepe, İstanbul'],
        'bakirkoy' => ['lat' => 40.9802, 'lon' => 28.8721, 'label' => 'Bakırköy, İstanbul'],
        'fatih' => ['lat' => 41.0186, 'lon' => 28.9397, 'label' => 'Fatih, İstanbul'],
        'eminonu' => ['lat' => 41.0170, 'lon' => 28.9705, 'label' => 'Eminönü, Fatih'],
        'sariyer' => ['lat' => 41.1082, 'lon' => 29.0551, 'label' => 'Sarıyer, İstanbul'],
        'levent' => ['lat' => 41.0814, 'lon' => 29.0119, 'label' => 'Levent, Beşiktaş'],
        'maslak' => ['lat' => 41.1085, 'lon' => 29.0208, 'label' => 'Maslak, Sarıyer'],
        'istanbul' => ['lat' => 41.0082, 'lon' => 28.9784, 'label' => 'İstanbul'],
        'ankara' => ['lat' => 39.9334, 'lon' => 32.8597, 'label' => 'Ankara'],
        'izmir' => ['lat' => 38.4192, 'lon' => 27.1287, 'label' => 'İzmir'],
        'elazig' => ['lat' => 38.6810, 'lon' => 39.2264, 'label' => 'Elazığ'],
        'van' => ['lat' => 38.5012, 'lon' => 43.3730, 'label' => 'Van'],
        'erzurum' => ['lat' => 39.9043, 'lon' => 41.2679, 'label' => 'Erzurum'],
        'gaziantep' => ['lat' => 37.0662, 'lon' => 37.3833, 'label' => 'Gaziantep'],
        'antep' => ['lat' => 37.0662, 'lon' => 37.3833, 'label' => 'Gaziantep'],
    ];


    public function geocode(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new \InvalidArgumentException('Arama metni boş olamaz.');
        }

        $key = Str::lower(Str::ascii($query));
        $key = preg_replace('/\s+/', '', $key) ?: $key;


        foreach (self::FALLBACKS as $token => $meta) {
            if ($key === $token || preg_match('/(^|[^a-z])'.preg_quote($token, '/').'([^a-z]|$)/', $key)) {
                try {
                    $live = $this->nominatim($query);

                    return $live;
                } catch (\Throwable) {
                    return [
                        'lat' => $meta['lat'],
                        'lon' => $meta['lon'],
                        'label' => $meta['label'],
                        'source' => 'local_fallback',
                    ];
                }
            }
        }

        return $this->nominatim($query);
    }


    private function nominatim(string $query): array
    {
        $q = $query;
        $lower = Str::lower($query);
        if (! str_contains($lower, 'türkiye') && ! str_contains($lower, 'turkey') && ! str_contains(Str::ascii($lower), 'turkiye')) {
            $q = $query.', Türkiye';
        }

        $response = Http::timeout(20)
            ->withHeaders([
                'User-Agent' => 'Sahada/1.0 (education thesis; contact=local)',
                'Accept-Language' => 'tr',
            ])
            ->get('https://nominatim.openstreetmap.org/search', [
                'q' => $q,
                'format' => 'json',
                'limit' => 1,
                'countrycodes' => 'tr',
                'addressdetails' => 1,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Konum servisi yanıt vermedi.');
        }

        $row = $response->json('0');
        if (! is_array($row) || ! isset($row['lat'], $row['lon'])) {

            $token = Str::lower(Str::ascii(explode(' ', $query)[0] ?? ''));
            foreach (self::FALLBACKS as $k => $meta) {
                if (str_contains($token, $k) || str_contains($k, $token)) {
                    return [
                        'lat' => $meta['lat'],
                        'lon' => $meta['lon'],
                        'label' => $meta['label'],
                        'source' => 'local_fallback',
                    ];
                }
            }

            throw new \RuntimeException("“{$query}” için konum bulunamadı. Örn: Moda, Kadıköy, Nişantaşı");
        }

        return [
            'lat' => (float) $row['lat'],
            'lon' => (float) $row['lon'],
            'label' => (string) ($row['display_name'] ?? $query),
            'source' => 'nominatim',
        ];
    }
}
