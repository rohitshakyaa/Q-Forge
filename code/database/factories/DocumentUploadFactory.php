<?php

namespace Database\Factories;

use App\Models\DocumentUpload;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentUpload>
 */
class DocumentUploadFactory extends Factory
{
    protected $model = DocumentUpload::class;

    public function definition(): array
    {
        $filename = $this->faker->slug(3).'.pdf';

        return [
            'uploader_id' => User::factory()->state(['role' => 'admin']),
            'subject_id' => Subject::factory(),
            'type' => DocumentUpload::TYPE_PAST_PAPER,
            'original_filename' => $filename,
            'stored_path' => 'uploads/'.$this->faker->uuid().'.pdf',
            'status' => DocumentUpload::STATUS_UPLOADED,
            'error' => null,
            'meta' => null,
        ];
    }

    public function parsed(): static
    {
        return $this->state(['status' => DocumentUpload::STATUS_PARSED]);
    }

    public function failed(string $error = 'extraction failed'): static
    {
        return $this->state([
            'status' => DocumentUpload::STATUS_FAILED,
            'error' => $error,
        ]);
    }
}
