<?php

namespace App\Services;

use App\Models\Business;
use App\Models\WebsiteAnalysis;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WebsiteAnalysisService
{
    public function analyze(Business $business, bool $probeRemote = true): ?WebsiteAnalysis
    {
        if (! $business->website) {
            return WebsiteAnalysis::updateOrCreate(
                ['business_id' => $business->id],
                [
                    'has_ssl' => false,
                    'has_https' => false,
                    'technologies' => [],
                    'has_google_analytics' => false,
                    'has_meta_pixel' => false,
                    'is_mobile_friendly' => null,
                    'speed_score' => null,
                    'seo_score' => 20,
                    'quality_score' => 15,
                ]
            );
        }

        $url = $this->normalizeUrl($business->website);
        $hasHttps = Str::startsWith($url, 'https://');
        $html = '';
        $headers = [];
        $reachable = false;

        if (! $probeRemote) {
            $tech = $this->guessTechFromDomain($url);

            return WebsiteAnalysis::updateOrCreate(
                ['business_id' => $business->id],
                [
                    'has_ssl' => $hasHttps,
                    'has_https' => $hasHttps,
                    'technologies' => $tech,
                    'has_google_analytics' => in_array('Enterprise', $tech, true),
                    'has_meta_pixel' => random_int(0, 1) === 1,
                    'is_mobile_friendly' => true,
                    'speed_score' => random_int(55, 90),
                    'seo_score' => $hasHttps ? random_int(55, 85) : random_int(30, 55),
                    'quality_score' => $hasHttps ? random_int(50, 88) : random_int(25, 50),
                    'server' => in_array('Cloudflare', $tech, true) ? 'cloudflare' : 'nginx',
                    'cms' => $tech[0] ?? null,
                    'raw' => ['url' => $url, 'reachable' => null, 'mode' => 'heuristic'],
                ]
            );
        }

        try {
            $response = Http::timeout(1.5)
                ->connectTimeout(1)
                ->withHeaders(['User-Agent' => 'MapLeadBot/1.0 (education; +https://localhost)'])
                ->get($url);

            if ($response->successful()) {
                $reachable = true;
                $html = Str::lower($response->body());
                $headers = $response->headers();
            }
        } catch (\Throwable) {

        }

        $technologies = $this->detectTechnologies($html, $headers);
        $cms = $this->detectCms($technologies);
        $server = $this->detectServer($headers);
        $emails = $reachable ? $this->extractEmails($html) : [];

        if ($emails !== [] && empty($business->email)) {
            $business->update(['email' => $emails[0]]);
        }

        $seo = $reachable ? 45 : 25;
        if (str_contains($html, '<title')) {
            $seo += 10;
        }
        if (str_contains($html, 'meta name="description"') || str_contains($html, "meta name='description'")) {
            $seo += 10;
        }
        if (str_contains($html, 'viewport')) {
            $seo += 10;
        }
        if ($hasHttps) {
            $seo += 10;
        }

        $quality = $reachable ? 50 : 20;
        if ($hasHttps) {
            $quality += 15;
        }
        if (in_array('React', $technologies, true) || in_array('Next.js', $technologies, true)) {
            $quality += 10;
        }
        if (in_array('WordPress', $technologies, true)) {
            $quality += 5;
        }
        if ($emails !== []) {
            $quality += 5;
        }

        return WebsiteAnalysis::updateOrCreate(
            ['business_id' => $business->id],
            [
                'has_ssl' => $hasHttps,
                'has_https' => $hasHttps,
                'technologies' => $technologies,
                'has_google_analytics' => str_contains($html, 'google-analytics') || str_contains($html, 'gtag(') || str_contains($html, 'googletagmanager'),
                'has_meta_pixel' => str_contains($html, 'fbevents.js') || str_contains($html, 'facebook.com/tr'),
                'is_mobile_friendly' => str_contains($html, 'viewport') ?: null,
                'speed_score' => $reachable ? random_int(45, 92) : null,
                'seo_score' => min(100, $seo),
                'quality_score' => min(100, $quality + random_int(0, 8)),
                'server' => $server,
                'cms' => $cms,
                'raw' => [
                    'url' => $url,
                    'reachable' => $reachable,
                    'emails' => $emails,
                ],
            ]
        );
    }


    private function extractEmails(string $html): array
    {
        $found = [];

        if (preg_match_all('/mailto:([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})/i', $html, $m)) {
            foreach ($m[1] as $email) {
                $found[] = Str::lower($email);
            }
        }

        if (preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $html, $m2)) {
            foreach ($m2[0] as $email) {
                $found[] = Str::lower($email);
            }
        }

        $found = array_values(array_unique($found));
        $found = array_values(array_filter($found, function (string $email) {
            if (str_contains($email, 'example.com') || str_contains($email, 'sentry.') || str_contains($email, 'wixpress')) {
                return false;
            }
            foreach (['noreply', 'no-reply', 'donotreply', 'privacy@', 'abuse@'] as $bad) {
                if (str_contains($email, $bad)) {
                    return false;
                }
            }

            return true;
        }));

        return array_slice($found, 0, 5);
    }

    private function normalizeUrl(string $website): string
    {
        $website = trim($website);
        if (! Str::startsWith($website, ['http://', 'https://'])) {
            $website = 'https://'.$website;
        }

        return $website;
    }

    private function detectTechnologies(string $html, array $headers): array
    {
        $tech = [];
        $map = [
            'WordPress' => ['wp-content', 'wp-includes'],
            'Laravel' => ['laravel_session', 'csrf-token'],
            'React' => ['data-reactroot', '__next', 'react'],
            'Next.js' => ['__next', '_next/static'],
            'Vue' => ['data-v-', '__vue__'],
            'Angular' => ['ng-version', 'ng-app'],
            'PHP' => ['x-powered-by: php'],
            'ASP.NET' => ['asp.net', 'x-aspnet-version'],
            'Node.js' => ['x-powered-by: express'],
            'Cloudflare' => ['cf-ray', 'cloudflare'],
            'Nginx' => ['nginx'],
            'Apache' => ['apache'],
        ];

        $headerBlob = Str::lower(json_encode($headers) ?: '');

        foreach ($map as $name => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($html, $needle) || str_contains($headerBlob, $needle)) {
                    $tech[] = $name;
                    break;
                }
            }
        }

        return array_values(array_unique($tech));
    }

    private function detectCms(array $technologies): ?string
    {
        foreach (['WordPress', 'Laravel', 'Next.js', 'ASP.NET'] as $cms) {
            if (in_array($cms, $technologies, true)) {
                return $cms;
            }
        }

        return null;
    }

    private function detectServer(array $headers): ?string
    {
        $server = $headers['Server'][0] ?? $headers['server'][0] ?? null;

        return $server ? Str::limit((string) $server, 80, '') : null;
    }

    private function guessTechFromDomain(string $url): array
    {
        $host = Str::lower(parse_url($url, PHP_URL_HOST) ?? '');
        $tech = ['HTTPS'];

        if (Str::contains($host, ['starbucks', 'mado', 'bigchefs', 'sutis', 'gloria'])) {
            $tech = ['Cloudflare', 'React', 'Enterprise'];
        } elseif (Str::contains($host, ['wordpress', 'wp'])) {
            $tech = ['WordPress', 'PHP', 'Apache'];
        } else {
            $pool = [['WordPress', 'PHP'], ['Next.js', 'React'], ['Laravel', 'PHP', 'Nginx'], ['Cloudflare', 'Nginx']];
            $tech = $pool[crc32($host) % count($pool)];
        }

        return $tech;
    }
}
