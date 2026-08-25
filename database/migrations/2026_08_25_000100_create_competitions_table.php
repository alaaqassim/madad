<?php

/**
 * Madad Phase 1 — competitions.
 *
 * One row holds the competition identity and its operational configuration.
 *
 * `status` is the SINGLE authoritative portal state. Portal access is authorised by
 * `status = 'open'` and nothing else. `starts_at` / `ends_at` are display metadata and
 * deliberately do NOT gate access — two authorities would be two readings.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 191);

            $table->enum('status', ['draft', 'ready', 'open', 'closed'])->default('draft');
            $table->boolean('show_result')->default(false);

            $table->unsignedSmallInteger('question_count')->default(75);
            $table->unsignedSmallInteger('seconds_per_question')->default(40);

            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitions');
    }
};
