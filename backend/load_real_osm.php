<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Business;
use App\Models\Project;
use App\Models\Scan;
use App\Models\User;
use App\Services\CrawlerService;
use Illuminate\Support\Facades\Hash;

$demo = User::updateOrCreate(
    ['email' => 'demo@maplead.local'],
    [
        'name' => 'Demo Ajans',
        'password' => Hash::make('password'),
        'role' => 'user',
        'is_active' => true,
    ]
);

User::updateOrCreate(
    ['email' => 'admin@maplead.local'],
    [
        'name' => 'Platform Admin',
        'password' => Hash::make('password'),
        'role' => 'admin',
        'is_active' => true,
    ]
);

$projectIds = Project::where('user_id', $demo->id)->pluck('id');
Business::whereIn('project_id', $projectIds)->delete();
Project::where('user_id', $demo->id)->delete();

$queries = [
    [
        'name' => 'Kadıköy Kafe — OSM',
        'url' => 'https://www.google.com/maps/search/kafe+kadikoy',
        'query' => 'kafe kadikoy',
        'limit' => 45,
    ],
    [
        'name' => 'Beşiktaş Kafe — OSM',
        'url' => 'https://www.google.com/maps/search/kafe+besiktas',
        'query' => 'kafe besiktas',
        'limit' => 40,
    ],
    [
        'name' => 'Beyoğlu Restoran — OSM',
        'url' => 'https://www.google.com/maps/search/restoran+beyoglu',
        'query' => 'restoran beyoglu',
        'limit' => 40,
    ],
    [
        'name' => 'Şişli Kafe — OSM',
        'url' => 'https://www.google.com/maps/search/kafe+sisli',
        'query' => 'kafe sisli',
        'limit' => 30,
    ],
    [
        'name' => 'Üsküdar Restoran — OSM',
        'url' => 'https://www.google.com/maps/search/restoran+uskudar',
        'query' => 'restoran uskudar',
        'limit' => 30,
    ],
];

$crawler = app(CrawlerService::class);

foreach ($queries as $q) {
    echo ">>> {$q['name']}\n";

    $project = Project::create([
        'user_id' => $demo->id,
        'name' => $q['name'],
        'description' => 'OpenStreetMap Overpass — gerçek POI (telefon/web öncelikli)',
        'maps_url' => $q['url'],
        'search_query' => $q['query'],
        'status' => 'queued',
        'settings' => ['limit' => $q['limit']],
    ]);

    $scan = Scan::create([
        'project_id' => $project->id,
        'user_id' => $demo->id,
        'status' => 'pending',
        'provider' => 'openstreetmap',
    ]);

    try {
        $crawler->run($scan);
        $scan->refresh();
        echo "status={$scan->status} saved={$scan->saved_count} provider={$scan->provider}\n";
    } catch (Throwable $e) {
        echo "FAILED: {$e->getMessage()}\n";
    }

    usleep(800000);
}

$base = Business::whereHas('project', fn ($p) => $p->where('user_id', $demo->id));
$total = (clone $base)->count();
$withPhone = (clone $base)->whereNotNull('phone')->where('phone', '!=', '')->count();
$withWeb = (clone $base)->whereNotNull('website')->where('website', '!=', '')->count();
$withAddr = (clone $base)->whereNotNull('address')->where('address', '!=', '')->count();
$withMail = (clone $base)->whereNotNull('email')->where('email', '!=', '')->count();

echo "TOTAL={$total} phone={$withPhone} web={$withWeb} address={$withAddr} email={$withMail}\n";
echo "RICH SAMPLES (phone or web):\n";
foreach (
    (clone $base)
        ->where(function ($q) {
            $q->whereNotNull('phone')->where('phone', '!=', '')
                ->orWhere(function ($qq) {
                    $qq->whereNotNull('website')->where('website', '!=', '');
                });
        })
        ->latest('id')
        ->take(15)
        ->get(['name', 'district', 'phone', 'website', 'email', 'address', 'category', 'place_id']) as $b
) {
    echo '- '.$b->name
        .' | '.$b->category
        .' | '.($b->district ?: '-')
        .' | '.($b->phone ?: '-')
        .' | '.($b->website ?: '-')
        .' | '.($b->email ?: '-')
        .' | '.($b->address ?: '-')
        .PHP_EOL;
}
