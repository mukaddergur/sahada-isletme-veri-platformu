<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('district')->nullable();
            $table->string('neighborhood')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('maps_url')->nullable();
            $table->string('place_id')->nullable()->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('rating', 3, 1)->nullable();
            $table->unsignedInteger('review_count')->default(0);
            $table->unsignedInteger('photo_count')->default(0);
            $table->json('opening_hours')->nullable();
            $table->string('price_level')->nullable();
            $table->boolean('is_open_now')->nullable();
            $table->date('opened_at')->nullable();
            $table->unsignedTinyInteger('ai_score')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'city', 'district']);
            $table->index(['project_id', 'rating']);
            $table->index(['project_id', 'category']);
            $table->unique(['project_id', 'place_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
