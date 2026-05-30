<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->string('type');                          // short | long | mcq
            $table->unsignedInteger('marks');
            $table->string('difficulty')->nullable();        // easy | medium | hard
            $table->longText('text');
            $table->string('source')->default('manual');     // extracted | ai | manual
            $table->string('status')->default('pending');    // pending | approved | rejected
            $table->json('attributes')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamps();

            $table->index(['subject_id', 'unit_id', 'type', 'marks', 'status'], 'questions_candidate_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
