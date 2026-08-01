<?php

namespace App\Services;

use App\Models\AiAnalysis;
use App\Models\Business;
use App\Models\WebsiteAnalysis;
use Illuminate\Support\Str;

class AiAnalysisService
{
    public function analyze(Business $business): AiAnalysis
    {
        $website = $business->websiteAnalysis;
        $social = $business->social;

        $corporate = $this->scoreCorporate($business, $website);
        $seo = $website?->seo_score ?? $this->estimateSeo($business);
        $digital = $this->scoreDigitalMarketing($business, $social, $website);
        $web = $website?->quality_score ?? ($business->website ? 55 : 15);
        $potential = $this->scorePotential($business, $digital, $corporate);

        $overall = (int) round(($corporate + $seo + $digital + $web + $potential) / 5);

        $analysis = AiAnalysis::updateOrCreate(
            ['business_id' => $business->id],
            [
                'overall_score' => $overall,
                'corporate_score' => $corporate,
                'seo_score' => $seo,
                'digital_marketing_score' => $digital,
                'web_quality_score' => $web,
                'potential_score' => $potential,
                'digital_maturity' => $this->maturityLabel($overall),
                'estimated_employees' => $this->estimateEmployees($business, $corporate),
                'summary' => $this->buildSummary($business, $overall, $potential),
                'strengths' => $this->buildStrengths($business, $social, $website),
                'weaknesses' => $this->buildWeaknesses($business, $social, $website),
                'opportunities' => $this->buildOpportunities($business, $social, $website),
                'marketing_suggestions' => $this->buildSuggestions($business, $social, $website),
                'positive_review_ratio' => $this->estimatePositiveRatio($business),
                'provider' => config('services.ai.provider', 'local'),
            ]
        );

        $business->update(['ai_score' => $overall]);

        return $analysis;
    }

    private function scoreCorporate(Business $business, ?WebsiteAnalysis $website): int
    {
        $score = 35;
        if ($business->website) {
            $score += 20;
        }
        if ($website?->has_https) {
            $score += 10;
        }
        if ($business->phone) {
            $score += 8;
        }
        if ($business->email) {
            $score += 7;
        }
        if (($business->review_count ?? 0) > 100) {
            $score += 10;
        } elseif (($business->review_count ?? 0) > 30) {
            $score += 5;
        }
        if (($business->rating ?? 0) >= 4.5) {
            $score += 10;
        } elseif (($business->rating ?? 0) >= 4.0) {
            $score += 5;
        }

        return min(100, $score);
    }

    private function estimateSeo(Business $business): int
    {
        $score = 30;
        if ($business->website) {
            $score += 25;
        }
        if ($business->category) {
            $score += 10;
        }
        if (($business->review_count ?? 0) > 50) {
            $score += 15;
        }
        if ($business->maps_url) {
            $score += 10;
        }

        return min(100, $score + random_int(0, 8));
    }

    private function scoreDigitalMarketing(Business $business, $social, ?WebsiteAnalysis $website): int
    {
        $score = 20;
        if ($social?->instagram) {
            $score += 18;
        }
        if ($social?->facebook) {
            $score += 10;
        }
        if ($social?->linkedin) {
            $score += 12;
        }
        if ($social?->tiktok) {
            $score += 8;
        }
        if ($website?->has_google_analytics) {
            $score += 12;
        }
        if ($website?->has_meta_pixel) {
            $score += 10;
        }
        if ($business->website) {
            $score += 8;
        }

        return min(100, $score);
    }

    private function scorePotential(Business $business, int $digital, int $corporate): int
    {
        $gap = 100 - $digital;
        $ratingBoost = (($business->rating ?? 3.5) - 3) * 12;
        $reviewBoost = min(20, ($business->review_count ?? 0) / 20);

        return (int) min(100, max(20, round(40 + ($gap * 0.35) + $ratingBoost + $reviewBoost + (($corporate < 60) ? 10 : 0))));
    }

    private function maturityLabel(int $score): string
    {
        return match (true) {
            $score >= 80 => 'İleri',
            $score >= 65 => 'Orta-İleri',
            $score >= 45 => 'Orta',
            $score >= 30 => 'Başlangıç',
            default => 'Düşük',
        };
    }

    private function estimateEmployees(Business $business, int $corporate): string
    {
        if ($corporate >= 80 || Str::contains(Str::lower($business->name), ['starbucks', 'gloria', 'mado', 'burger king', 'mcdonald'])) {
            return '50-200';
        }
        if ($corporate >= 60) {
            return '10-50';
        }
        if (($business->review_count ?? 0) > 200) {
            return '10-30';
        }

        return '1-10';
    }

    private function estimatePositiveRatio(Business $business): float
    {
        $rating = (float) ($business->rating ?? 3.5);

        return round(min(98, max(35, ($rating / 5) * 100 - random_int(0, 8))), 2);
    }

    private function buildSummary(Business $business, int $overall, int $potential): string
    {
        return sprintf(
            '%s için dijital skor %d/100. %s bölgesinde %s kategorisinde faaliyet gösteren işletmenin satış/pazarlama potansiyeli %d/100 olarak değerlendirildi. Google puanı %s (%d yorum).',
            $business->name,
            $overall,
            $business->district ?: ($business->city ?: 'İstanbul'),
            $business->category ?: 'İşletme',
            $potential,
            $business->rating ? number_format($business->rating, 1) : 'N/A',
            $business->review_count ?? 0
        );
    }

    private function buildStrengths(Business $business, $social, ?WebsiteAnalysis $website): array
    {
        $items = [];
        if (($business->rating ?? 0) >= 4.3) {
            $items[] = 'Yüksek müşteri memnuniyeti (Google puanı)';
        }
        if (($business->review_count ?? 0) > 100) {
            $items[] = 'Güçlü sosyal kanıt (yüksek yorum hacmi)';
        }
        if ($business->website && $website?->has_https) {
            $items[] = 'HTTPS destekli kurumsal web sitesi';
        }
        if ($social?->instagram) {
            $items[] = 'Aktif Instagram varlığı';
        }
        if (! $items) {
            $items[] = 'Google Maps üzerinde keşfedilebilir konum';
        }

        return $items;
    }

    private function buildWeaknesses(Business $business, $social, ?WebsiteAnalysis $website): array
    {
        $items = [];
        if (! $business->website) {
            $items[] = 'Web sitesi eksik';
        }
        if (! $business->email) {
            $items[] = 'E-posta bilgisi görünür değil';
        }
        if (! $social?->instagram && ! $social?->linkedin) {
            $items[] = 'Sosyal medya kanalları yetersiz';
        }
        if ($website && ! $website->has_google_analytics) {
            $items[] = 'Web analitikleri (GA) tespit edilmedi';
        }
        if (($business->rating ?? 5) < 3.8) {
            $items[] = 'Ortalama puan iyileştirme gerektiriyor';
        }

        return $items ?: ['Dijital varlık geliştirilebilir'];
    }

    private function buildOpportunities(Business $business, $social, ?WebsiteAnalysis $website): array
    {
        $items = [];
        if (! $business->website) {
            $items[] = 'Kurumsal web sitesi ile lead yakalama';
        }
        if (! $social?->instagram) {
            $items[] = 'Instagram içerik stratejisi ile yerel görünürlük';
        }
        if (! $website?->has_meta_pixel) {
            $items[] = 'Retargeting reklam altyapısı kurulumu';
        }
        $items[] = 'Google Ads / yerel SEO ile rakiplerden ayrışma';
        $items[] = 'Yorum yönetimi ve itibar pazarlaması';

        return array_slice($items, 0, 4);
    }

    private function buildSuggestions(Business $business, $social, ?WebsiteAnalysis $website): array
    {
        return [
            'Google Business Profile bilgisini güncelleyin (fotoğraf, saat, hizmetler).',
            $business->website ? 'Web sitesine net CTA ve iletişim formu ekleyin.' : 'Basit ama mobil uyumlu bir tanıtım sitesi oluşturun.',
            $social?->instagram ? 'Instagram’da haftalık 3-4 yerel içerik planı yapın.' : 'Instagram hesabı açıp işletme konum etiketini aktif kullanın.',
            'Olumsuz yorumlara 24-48 saat içinde yanıt verin.',
            'Haftalık lead raporu için telefon ve e-posta alanlarını tamamlayın.',
        ];
    }
}
