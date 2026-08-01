<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Business extends Model
{
    protected $fillable = [
        'project_id',
        'scan_id',
        'name',
        'category',
        'address',
        'city',
        'district',
        'neighborhood',
        'phone',
        'email',
        'website',
        'maps_url',
        'place_id',
        'latitude',
        'longitude',
        'rating',
        'review_count',
        'photo_count',
        'opening_hours',
        'price_level',
        'is_open_now',
        'opened_at',
        'ai_score',
        'collected_at',
        'data_source',
    ];

    protected function casts(): array
    {
        return [
            'opening_hours' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
            'rating' => 'float',
            'is_open_now' => 'boolean',
            'opened_at' => 'date',
            'collected_at' => 'datetime',
        ];
    }

    protected $appends = ['source_label'];

    public function getSourceLabelAttribute(): string
    {
        $placeId = (string) ($this->place_id ?? '');
        $explicit = (string) ($this->data_source ?? '');

        if ($explicit !== '') {
            return match ($explicit) {
                'openstreetmap', 'osm' => 'OpenStreetMap',
                'places_api' => 'Google Places',
                'inventory' => 'OSM envanter',
                'crawler' => 'Crawler',
                default => Str::title(str_replace('_', ' ', $explicit)),
            };
        }

        if (str_starts_with($placeId, 'osm_')) {
            return 'OpenStreetMap';
        }
        if (str_starts_with($placeId, 'demo_')) {
            return 'Örnek';
        }
        if (str_starts_with($placeId, 'ChIJ')) {
            return 'Google Places';
        }

        return 'Harita';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    public function social(): HasOne
    {
        return $this->hasOne(BusinessSocial::class);
    }

    public function websiteAnalysis(): HasOne
    {
        return $this->hasOne(WebsiteAnalysis::class);
    }

    public function aiAnalysis(): HasOne
    {
        return $this->hasOne(AiAnalysis::class);
    }
}
