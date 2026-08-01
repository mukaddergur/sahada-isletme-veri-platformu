<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->boolean('has_ssl')->default(false);
            $table->boolean('has_https')->default(false);
            $table->json('technologies')->nullable();
            $table->boolean('has_google_analytics')->default(false);
            $table->boolean('has_meta_pixel')->default(false);
            $table->boolean('is_mobile_friendly')->nullable();
            $table->unsignedTinyInteger('speed_score')->nullable();
            $table->unsignedTinyInteger('seo_score')->nullable();
            $table->unsignedTinyInteger('quality_score')->nullable();
            $table->string('server')->nullable();
            $table->string('cms')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_analyses');
    }
};
