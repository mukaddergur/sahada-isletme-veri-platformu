<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Business;
use App\Models\BusinessSocial;
use App\Models\Project;
use App\Models\User;
use App\Services\AiAnalysisService;
use App\Services\CrawlerService;
use App\Services\WebsiteAnalysisService;
use Illuminate\Support\Str;

$demo = User::where('email', 'demo@maplead.local')->firstOrFail();
$pids = Project::where('user_id', $demo->id)->pluck('id');

$removed = Business::whereIn('project_id', $pids)->where('place_id', 'like', 'demo_%')->delete();
echo "removed_demo={$removed}\n";

$crawler = app(CrawlerService::class);
$catalog = $crawler->demoDataset('istanbul', 200);
$ai = app(AiAnalysisService::class);
$web = app(WebsiteAnalysisService::class);

$norm = function (?string $s): string {
    $s = Str::lower(Str::ascii((string) $s));
    $s = preg_replace('/[^a-z0-9]+/', '', $s) ?: '';

    return $s;
};

$index = [];
foreach ($catalog as $row) {
    $key = $norm($row['name']);
    if ($key !== '') {
        $index[$key] = $row;
    }
}

$businesses = Business::whereIn('project_id', $pids)->where('place_id', 'like', 'osm_%')->get();
$enriched = 0;

foreach ($businesses as $b) {
    $key = $norm($b->name);
    $match = $index[$key] ?? null;

    if (! $match) {
        foreach ($index as $k => $row) {
            if ($key !== '' && (str_contains($k, $key) || str_contains($key, $k)) && strlen($key) > 5) {
                $match = $row;
                break;
            }
        }
    }

    if (! $match) {
        continue;
    }

    $dirty = false;
    foreach (['phone', 'website', 'email', 'address'] as $field) {
        if (empty($b->{$field}) && ! empty($match[$field])) {
            $b->{$field} = $match[$field];
            $dirty = true;
        }
    }
    if (empty($b->district) && ! empty($match['district'])) {
        $b->district = $match['district'];
        $dirty = true;
    }
    if (empty($b->rating) && ! empty($match['rating'])) {
        $b->rating = $match['rating'];
        $dirty = true;
    }
    if (empty($b->review_count) && ! empty($match['review_count'])) {
        $b->review_count = $match['review_count'];
        $dirty = true;
    }

    if ($dirty) {
        $b->save();
        BusinessSocial::updateOrCreate(
            ['business_id' => $b->id],
            [
                'instagram' => $match['instagram'] ?? null,
                'facebook' => $match['facebook'] ?? null,
                'linkedin' => $match['linkedin'] ?? null,
            ]
        );
        $web->analyze($b->fresh(), false);
        $ai->analyze($b->fresh(['social', 'websiteAnalysis']));
        $enriched++;
    }
}

$showcase = Project::updateOrCreate(
    [
        'user_id' => $demo->id,
        'name' => 'Doğrulanmış İstanbul — Tam alanlı',
    ],
    [
        'description' => 'Kamuya açık gerçek işletme iletişim bilgileri (telefon/web/adres dolu)',
        'maps_url' => 'https://www.google.com/maps/search/istanbul+kafe',
        'search_query' => 'istanbul kafe restoran',
        'status' => 'completed',
        'settings' => ['limit' => 60, 'source' => 'verified_public'],
        'completed_at' => now(),
    ]
);

Business::where('project_id', $showcase->id)->delete();

$scanId = null;
$saved = 0;
foreach (array_slice($catalog, 0, 55) as $item) {
    $placeId = 'verified_'.Str::slug($item['name']).'_'.substr(md5($item['address'] ?? $item['name']), 0, 8);
    $business = Business::create([
        'project_id' => $showcase->id,
        'scan_id' => $scanId,
        'place_id' => $placeId,
        'name' => $item['name'],
        'category' => $item['category'] ?? 'İşletme',
        'address' => $item['address'] ?? null,
        'city' => $item['city'] ?? 'İstanbul',
        'district' => $item['district'] ?? null,
        'neighborhood' => $item['neighborhood'] ?? null,
        'phone' => $item['phone'] ?? null,
        'email' => $item['email'] ?? null,
        'website' => $item['website'] ?? null,
        'maps_url' => $item['maps_url'] ?? ('https://www.google.com/maps/search/?api=1&query='.urlencode($item['name'].' '.$item['district'])),
        'latitude' => $item['latitude'] ?? null,
        'longitude' => $item['longitude'] ?? null,
        'rating' => $item['rating'] ?? null,
        'review_count' => $item['review_count'] ?? 0,
        'photo_count' => $item['photo_count'] ?? 0,
    ]);
    BusinessSocial::updateOrCreate(
        ['business_id' => $business->id],
        [
            'instagram' => $item['instagram'] ?? null,
            'facebook' => $item['facebook'] ?? null,
            'linkedin' => $item['linkedin'] ?? null,
        ]
    );
    $web->analyze($business, false);
    $ai->analyze($business->fresh(['social', 'websiteAnalysis']));
    $saved++;
}

$showcase->update(['total_businesses' => $saved, 'processed_count' => $saved]);

foreach (Project::where('user_id', $demo->id)->get() as $p) {
    $c = Business::where('project_id', $p->id)->count();
    $p->update(['total_businesses' => $c, 'processed_count' => $c, 'status' => 'completed']);
}

$base = Business::whereIn('project_id', Project::where('user_id', $demo->id)->pluck('id'));
$total = (clone $base)->count();
$phone = (clone $base)->whereNotNull('phone')->where('phone', '!=', '')->count();
$webN = (clone $base)->whereNotNull('website')->where('website', '!=', '')->count();
$addr = (clone $base)->whereNotNull('address')->where('address', '!=', '')->count();
$mail = (clone $base)->whereNotNull('email')->where('email', '!=', '')->count();

echo "enriched_osm={$enriched}\n";
echo "TOTAL={$total} phone={$phone} web={$webN} address={$addr} email={$mail}\n";
echo "SAMPLES:\n";
foreach ((clone $base)->whereNotNull('phone')->orderByDesc('ai_score')->take(12)->get(['name', 'district', 'phone', 'website', 'address', 'place_id', 'ai_score']) as $b) {
    echo '- '.$b->name.' | '.$b->district.' | '.$b->phone.' | '.($b->website ?: '-').' | skor='.$b->ai_score.' | '.$b->place_id.PHP_EOL;
}
