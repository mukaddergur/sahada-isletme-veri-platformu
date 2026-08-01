<?php

namespace App\Services;

use Illuminate\Support\Str;

class MapsUrlParserService
{
    public function parse(string $url): array
    {
        $url = trim($url);
        $query = null;
        $coords = null;

        if (preg_match('#/maps/search/([^/@?]+)#', $url, $m)) {
            $query = urldecode(str_replace('+', ' ', $m[1]));
        } elseif (preg_match('#/maps/place/([^/@?]+)#', $url, $m)) {
            $query = urldecode(str_replace('+', ' ', $m[1]));
        } elseif (preg_match('#[?&]q=([^&]+)#', $url, $m)) {
            $query = urldecode(str_replace('+', ' ', $m[1]));
        } elseif (preg_match('#/maps/dir/([^/@?]+)#', $url, $m)) {
            $query = urldecode(str_replace('+', ' ', $m[1]));
        }

        if (preg_match('#@(-?\d+\.\d+),(-?\d+\.\d+)#', $url, $m)) {
            $coords = [
                'lat' => (float) $m[1],
                'lng' => (float) $m[2],
            ];
        }

        return [
            'url' => $url,
            'search_query' => $query,
            'coords' => $coords,
            'normalized_query' => $query ? Str::of($query)->lower()->trim()->toString() : null,
        ];
    }
}
