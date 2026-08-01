<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteAnalysis extends Model
{
    protected $fillable = [
        'business_id',
        'has_ssl',
        'has_https',
        'technologies',
        'has_google_analytics',
        'has_meta_pixel',
        'is_mobile_friendly',
        'speed_score',
        'seo_score',
        'quality_score',
        'server',
        'cms',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'technologies' => 'array',
            'raw' => 'array',
            'has_ssl' => 'boolean',
            'has_https' => 'boolean',
            'has_google_analytics' => 'boolean',
            'has_meta_pixel' => 'boolean',
            'is_mobile_friendly' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
