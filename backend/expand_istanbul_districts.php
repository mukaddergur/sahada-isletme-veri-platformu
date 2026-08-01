<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Business;
use App\Models\Project;
use App\Models\Scan;
use App\Models\User;
use App\Services\CrawlerService;

$demo = User::where('email', 'demo@maplead.local')->firstOrFail();
$crawler = app(CrawlerService::class);

$queries = [
    ['name' => 'Sarıyer Kafe — OSM', 'url' => 'https://www.google.com/maps/search/kafe+sariyer', 'query' => 'kafe sariyer', 'limit' => 40],
    ['name' => 'Fatih Restoran — OSM', 'url' => 'https://www.google.com/maps/search/restoran+fatih', 'query' => 'restoran fatih', 'limit' => 40],
    ['name' => 'Bakırköy Kafe — OSM', 'url' => 'https://www.google.com/maps/search/kafe+bakirkoy', 'query' => 'kafe bakirkoy', 'limit' => 35],
    ['name' => 'Maltepe Kafe — OSM', 'url' => 'https://www.google.com/maps/search/kafe+maltepe', 'query' => 'kafe maltepe', 'limit' => 35],
    ['name' => 'Ataşehir Restoran — OSM', 'url' => 'https://www.google.com/maps/search/restoran+atasehir', 'query' => 'restoran atasehir', 'limit' => 35],
    ['name' => 'Bebek Kafe — OSM', 'url' => 'https://www.google.com/maps/search/kafe+bebek', 'query' => 'kafe bebek besiktas', 'limit' => 30],
    ['name' => 'Karaköy Kafe — OSM', 'url' => 'https://www.google.com/maps/search/kafe+karakoy', 'query' => 'kafe karakoy beyoglu', 'limit' => 35],
    ['name' => 'Kadıköy Restoran — OSM', 'url' => 'https://www.google.com/maps/search/restoran+kadikoy', 'query' => 'restoran kadikoy', 'limit' => 45],
    ['name' => 'Üsküdar Kafe — OSM', 'url' => 'https://www.google.com/maps/search/kafe+uskudar', 'query' => 'kafe uskudar', 'limit' => 35],
];

foreach ($queries as $q) {
    echo ">>> {$q['name']}\n";
    $project = Project::updateOrCreate(
        ['user_id' => $demo->id, 'name' => $q['name']],
        [
            'description' => 'İstanbul geneli gerçek OSM esnaf / işletme',
            'maps_url' => $q['url'],
            'search_query' => $q['query'],
            'status' => 'queued',
            'settings' => ['limit' => $q['limit']],
        ]
    );
    Business::where('project_id', $project->id)->delete();
    $scan = Scan::create([
        'project_id' => $project->id,
        'user_id' => $demo->id,
        'status' => 'pending',
        'provider' => 'openstreetmap',
    ]);
    try {
        $crawler->run($scan);
        $scan->refresh();
        echo "saved={$scan->saved_count}\n";
    } catch (Throwable $e) {
        echo "FAIL {$e->getMessage()}\n";
    }
    usleep(700000);
}

$base = Business::whereHas('project', fn ($p) => $p->where('user_id', $demo->id));
echo 'TOTAL='.(clone $base)->count()
    .' districts='.(clone $base)->distinct('district')->count('district')
    ."\n";
foreach ((clone $base)->selectRaw('district, count(*) c')->groupBy('district')->orderByDesc('c')->get() as $row) {
    echo ($row->district ?: '?')." = {$row->c}\n";
}
