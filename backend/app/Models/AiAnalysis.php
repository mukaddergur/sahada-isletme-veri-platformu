<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAnalysis extends Model
{
    protected $fillable = [
        'business_id',
        'overall_score',
        'corporate_score',
        'seo_score',
        'digital_marketing_score',
        'web_quality_score',
        'potential_score',
        'digital_maturity',
        'estimated_employees',
        'summary',
        'strengths',
        'weaknesses',
        'opportunities',
        'marketing_suggestions',
        'positive_review_ratio',
        'provider',
    ];

    protected function casts(): array
    {
        return [
            'strengths' => 'array',
            'weaknesses' => 'array',
            'opportunities' => 'array',
            'marketing_suggestions' => 'array',
            'positive_review_ratio' => 'float',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
