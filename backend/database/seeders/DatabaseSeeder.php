<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\Scan;
use App\Models\User;
use App\Services\CrawlerService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@maplead.local'],
            [
                'name' => 'Platform Admin',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'operator@maplead.local'],
            [
                'name' => 'Operatör Kullanıcı',
                'password' => Hash::make('password'),
                'role' => 'operator',
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'guest@maplead.local'],
            [
                'name' => 'Misafir Kullanıcı',
                'password' => Hash::make('password'),
                'role' => 'guest',
                'is_active' => true,
            ]
        );

        $user = User::updateOrCreate(
            ['email' => 'demo@maplead.local'],
            [
                'name' => 'Demo Ajans',
                'password' => Hash::make('password'),
                'role' => 'user',
                'is_active' => true,
            ]
        );

        $project = Project::updateOrCreate(
            [
                'user_id' => $user->id,
                'name' => 'İstanbul Kadıköy Kafeleri (OSM)',
            ],
            [
                'description' => 'OpenStreetMap Overpass API ile ücretsiz çekilen gerçek Kadıköy kafe verisi',
                'maps_url' => 'https://www.google.com/maps/search/kafe+kadikoy',
                'search_query' => 'kafe kadikoy',
                'status' => 'queued',
                'settings' => ['limit' => 50],
            ]
        );

        $scan = Scan::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'provider' => 'openstreetmap',
        ]);

        app(CrawlerService::class)->run($scan);

        $project2 = Project::updateOrCreate(
            [
                'user_id' => $admin->id,
                'name' => 'Beşiktaş Kafe & Restoran (OSM)',
            ],
            [
                'description' => 'OpenStreetMap üzerinden Beşiktaş bölgesi',
                'maps_url' => 'https://www.google.com/maps/search/kafe+besiktas',
                'search_query' => 'kafe besiktas',
                'status' => 'queued',
                'settings' => ['limit' => 40],
            ]
        );

        $scan2 = Scan::create([
            'project_id' => $project2->id,
            'user_id' => $admin->id,
            'status' => 'pending',
            'provider' => 'openstreetmap',
        ]);

        app(CrawlerService::class)->run($scan2);
    }
}
