<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('overall_score')->default(0);
            $table->unsignedTinyInteger('corporate_score')->default(0);
            $table->unsignedTinyInteger('seo_score')->default(0);
            $table->unsignedTinyInteger('digital_marketing_score')->default(0);
            $table->unsignedTinyInteger('web_quality_score')->default(0);
            $table->unsignedTinyInteger('potential_score')->default(0);
            $table->string('digital_maturity')->nullable();
            $table->string('estimated_employees')->nullable();
            $table->text('summary')->nullable();
            $table->json('strengths')->nullable();
            $table->json('weaknesses')->nullable();
            $table->json('opportunities')->nullable();
            $table->json('marketing_suggestions')->nullable();
            $table->decimal('positive_review_ratio', 5, 2)->nullable();
            $table->string('provider')->default('local');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_analyses');
    }
};
