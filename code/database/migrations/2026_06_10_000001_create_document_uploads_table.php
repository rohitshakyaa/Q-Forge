<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploader_id')->constrained('users')->cascadeOnDelete();
            // Nullable: a syllabus may be filed before its subject exists. A
            // past_paper upload is validated to carry one, since its extracted
            // questions need a subject to belong to.
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');                          // syllabus | past_paper
            $table->string('original_filename');
            $table->string('stored_path');                   // relative to the `shared` disk
            $table->string('status')->default('uploaded');   // uploaded | processing | parsed | failed
            $table->text('error')->nullable();
            $table->json('meta')->nullable();                // exam year, page/OCR counts
            $table->timestamps();

            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_uploads');
    }
};
