<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocumentUpload;
use App\Models\DocumentUpload;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentUploadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('shared');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('DBMS_Finals_2023.pdf', 120, 'application/pdf');
    }

    public function test_upload_stores_the_file_and_dispatches_the_extraction_job(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());
        $subject = Subject::factory()->create();

        $response = $this->postJson('/api/uploads', [
            'file' => $this->pdf(),
            'type' => 'past_paper',
            'subject_id' => $subject->id,
            'exam_year' => '2023',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'uploaded')
            ->assertJsonPath('data.original_filename', 'DBMS_Finals_2023.pdf')
            ->assertJsonPath('data.exam_year', '2023');

        $upload = DocumentUpload::sole();
        Storage::disk('shared')->assertExists($upload->stored_path);

        Queue::assertPushed(
            ProcessDocumentUpload::class,
            fn (ProcessDocumentUpload $job) => $job->uploadId === $upload->id,
        );
    }

    public function test_stored_path_is_resolved_against_the_python_mount(): void
    {
        config(['services.python.shared_root' => '/shared-storage/shared']);
        $upload = DocumentUpload::factory()->create(['stored_path' => 'uploads/2026-06/abc.pdf']);

        $this->assertSame('/shared-storage/shared/uploads/2026-06/abc.pdf', $upload->pythonPath());
    }

    public function test_a_past_paper_must_name_a_subject(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/uploads', ['file' => $this->pdf(), 'type' => 'past_paper'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subject_id');

        Queue::assertNothingPushed();
    }

    public function test_a_syllabus_may_be_filed_without_a_subject(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/uploads', ['file' => $this->pdf(), 'type' => 'syllabus'])
            ->assertStatus(202);
    }

    public function test_only_pdfs_are_accepted(): void
    {
        Sanctum::actingAs($this->admin());
        $subject = Subject::factory()->create();

        $this->postJson('/api/uploads', [
            'file' => UploadedFile::fake()->create('notes.docx', 10),
            'type' => 'past_paper',
            'subject_id' => $subject->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_teachers_cannot_upload(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher']));

        $this->postJson('/api/uploads', ['file' => $this->pdf(), 'type' => 'syllabus'])
            ->assertForbidden();
    }

    public function test_guests_cannot_upload(): void
    {
        $this->postJson('/api/uploads', ['file' => $this->pdf(), 'type' => 'syllabus'])
            ->assertUnauthorized();
    }

    public function test_show_reports_status_and_progress_for_polling(): void
    {
        Sanctum::actingAs($this->admin());
        $upload = DocumentUpload::factory()->parsed()->create([
            'meta' => ['pages' => 4, 'ocr_pages' => 2, 'questions_created' => 12],
        ]);

        $this->getJson("/api/uploads/{$upload->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'parsed')
            ->assertJsonPath('data.progress', 100)
            ->assertJsonPath('data.pages', 4)
            ->assertJsonPath('data.ocr_pages', 2)
            ->assertJsonPath('data.questions_created', 12);
    }

    public function test_a_processing_upload_reports_partial_progress(): void
    {
        Sanctum::actingAs($this->admin());
        $upload = DocumentUpload::factory()->create(['status' => 'processing']);

        $this->getJson("/api/uploads/{$upload->id}")
            ->assertOk()
            ->assertJsonPath('data.progress', 55);
    }

    public function test_a_failed_upload_surfaces_its_error(): void
    {
        Sanctum::actingAs($this->admin());
        $upload = DocumentUpload::factory()->failed('no such file')->create();

        $this->getJson("/api/uploads/{$upload->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'no such file');
    }

    public function test_destroy_removes_the_file_from_the_shared_volume(): void
    {
        Sanctum::actingAs($this->admin());
        Storage::disk('shared')->put('uploads/x.pdf', 'pdf bytes');
        $upload = DocumentUpload::factory()->create(['stored_path' => 'uploads/x.pdf']);

        $this->deleteJson("/api/uploads/{$upload->id}")->assertNoContent();

        Storage::disk('shared')->assertMissing('uploads/x.pdf');
        $this->assertDatabaseMissing('document_uploads', ['id' => $upload->id]);
    }
}
