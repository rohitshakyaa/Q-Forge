<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M6 Phase 2 (RAG) — the retrieval corpus, chunked.
 *
 * One row per chunk of course material: `units.content` split into retrievable
 * pieces (unit_id set), plus subject-level chunks of `subjects.syllabus`
 * (unit_id null). MySQL is the source of truth for chunk *text*; the matching
 * vector lives in Qdrant's `chunks` collection under this row's id and is
 * always rebuildable (docs/RAG-GUIDE.md §4).
 *
 * Rows are derived data: re-chunking a subject deletes and recreates them, so
 * nothing else may foreign-key into this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->cascadeOnDelete();
            // Reading order within the source document (unit content or syllabus).
            $table->unsignedSmallInteger('position');
            // Context path prefixed into the embedding ("CS301 > Unit 3: Transport
            // Layer") so a short chunk still knows what it belongs to (guide §5).
            $table->string('heading', 512)->nullable();
            $table->text('text');
            $table->timestamps();

            $table->index(['subject_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_chunks');
    }
};
