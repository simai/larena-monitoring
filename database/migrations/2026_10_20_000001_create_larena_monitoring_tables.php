<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('larena_monitoring_samples', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('metric_id', 160);
            $table->string('type', 16);
            $table->double('value');
            $table->string('tags_json', 1024);
            $table->char('tag_hash', 40);
            $table->dateTime('recorded_at');
            $table->index(['metric_id', 'recorded_at']);
            $table->index('recorded_at');
        });

        Schema::create('larena_monitoring_rollups', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('metric_id', 160);
            $table->char('tag_hash', 40);
            $table->string('tags_json', 1024);
            $table->char('period', 1);
            $table->dateTime('bucket_start');
            $table->unsignedBigInteger('count');
            $table->double('sum');
            $table->double('min')->nullable();
            $table->double('max')->nullable();
            $table->double('p50')->nullable();
            $table->double('p95')->nullable();
            $table->unsignedBigInteger('errors')->default(0);
            $table->unique(['metric_id', 'tag_hash', 'period', 'bucket_start'], 'larena_monitoring_rollups_bucket_unique');
            $table->index(['period', 'bucket_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_monitoring_rollups');
        Schema::dropIfExists('larena_monitoring_samples');
    }
};
