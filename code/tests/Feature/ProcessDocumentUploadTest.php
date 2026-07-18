<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocumentUpload;
use App\Models\DocumentUpload;
use App\Models\Question;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ProcessDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    private function subjectWithUnits(): Subject
    {
        $subject = Subject::factory()->create(['code' => 'CS301']);
        $subject->units()->create(['name' => 'Sorting', 'position' => 1]);
        $subject->units()->create(['name' => 'Hashing', 'position' => 2]);

        return $subject->fresh('units');
    }

    private function upload(Subject $subject, string $type = 'past_paper'): DocumentUpload
    {
        return DocumentUpload::factory()->create([
            'subject_id' => $subject->id,
            'type' => $type,
            'stored_path' => 'uploads/2026-06/paper.pdf',
            'meta' => ['exam_year' => '2023'],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  array<int, array<string, mixed>>  $courses
     */
    private function fakeExtract(array $candidates, int $pages = 2, int $ocrPages = 1, array $courses = []): void
    {
        Http::fake([
            '*/extract' => Http::response([
                'status' => 'success',
                'data' => [
                    'pages' => $pages,
                    'ocr_pages' => $ocrPages,
                    'candidates' => $candidates,
                    'courses' => $courses,
                    'meta' => [],
                ],
                'errors' => [],
            ]),
        ]);
    }

    private function candidate(array $overrides = []): array
    {
        return array_merge([
            'text' => 'Define a hash collision.',
            'type' => 'short',
            'marks' => 5,
            'unit_hint' => 'Unit 2',
            'number' => '1',
            'page' => 1,
            'ocr' => false,
        ], $overrides);
    }

    public function test_it_stores_candidates_as_pending_extracted_questions(): void
    {
        $subject = $this->subjectWithUnits();
        $upload = $this->upload($subject);
        $this->fakeExtract([$this->candidate()]);

        $this->runJob($upload);

        $question = Question::sole();
        $this->assertSame('extracted', $question->source);
        $this->assertSame('pending', $question->status);
        $this->assertSame('Define a hash collision.', $question->text);
        $this->assertSame(5, $question->marks);
        $this->assertSame($subject->id, $question->subject_id);
        $this->assertSame($subject->units->firstWhere('position', 2)->id, $question->unit_id);
        $this->assertSame($upload->id, $question->attributes['upload_id']);
        $this->assertSame('2023', $question->attributes['exam_year']);
    }

    public function test_it_sends_the_python_side_path_to_the_service(): void
    {
        config(['services.python.shared_root' => '/shared-storage/shared']);
        $upload = $this->upload($this->subjectWithUnits());
        $this->fakeExtract([]);

        $this->runJob($upload);

        Http::assertSent(fn ($request) => $request['path'] === '/shared-storage/shared/uploads/2026-06/paper.pdf'
            && $request['type'] === 'past_paper');
    }

    public function test_it_marks_the_upload_parsed_and_records_counts(): void
    {
        $upload = $this->upload($this->subjectWithUnits());
        $this->fakeExtract([$this->candidate(), $this->candidate(['text' => 'Explain BFS.', 'unit_hint' => null])]);

        $this->runJob($upload);

        $upload->refresh();
        $this->assertSame('parsed', $upload->status);
        $this->assertNull($upload->error);
        $this->assertSame(2, $upload->meta['pages']);
        $this->assertSame(1, $upload->meta['ocr_pages']);
        $this->assertSame(2, $upload->meta['questions_created']);
        $this->assertSame(0, $upload->meta['questions_duplicate']);
        $this->assertSame(1, $upload->meta['questions_unlinked']);
    }

    public function test_an_undetected_unit_leaves_the_question_unlinked_rather_than_guessing(): void
    {
        $upload = $this->upload($this->subjectWithUnits());
        $this->fakeExtract([$this->candidate(['unit_hint' => null])]);

        $this->runJob($upload);

        $this->assertNull(Question::sole()->unit_id);
    }

    public function test_a_group_heading_is_not_mistaken_for_a_unit(): void
    {
        // "Group B" divides the answer sheet, not the syllabus.
        $upload = $this->upload($this->subjectWithUnits());
        $this->fakeExtract([$this->candidate(['unit_hint' => 'Group B'])]);

        $this->runJob($upload);

        $question = Question::sole();
        $this->assertNull($question->unit_id);
        $this->assertSame('Group B', $question->attributes['unit_hint']);
    }

    public function test_a_roman_unit_heading_resolves(): void
    {
        $subject = $this->subjectWithUnits();
        $upload = $this->upload($subject);
        $this->fakeExtract([$this->candidate(['unit_hint' => 'Unit-II'])]);

        $this->runJob($upload);

        $this->assertSame($subject->units->firstWhere('position', 2)->id, Question::sole()->unit_id);
    }

    public function test_undetected_marks_are_left_null_rather_than_zero(): void
    {
        $upload = $this->upload($this->subjectWithUnits());
        $this->fakeExtract([$this->candidate(['marks' => null])]);

        $this->runJob($upload);

        $this->assertNull(Question::sole()->marks);
    }

    public function test_re_importing_the_same_paper_flags_duplicates_for_review(): void
    {
        $subject = $this->subjectWithUnits();
        $this->fakeExtract([$this->candidate()]);

        $this->runJob($this->upload($subject));
        $first = Question::sole();

        // Same question, reflowed line breaks and different case — an exact match.
        $this->fakeExtract([$this->candidate(['text' => "define  a HASH\ncollision."])]);
        $second = $this->upload($subject);
        $this->runJob($second);

        // No longer silently skipped: it imports and is flagged against the
        // original so a reviewer decides (docs/RAG-GUIDE.md Phase 1).
        $this->assertSame(2, Question::count());
        $this->assertSame(1, $second->refresh()->meta['questions_duplicate']);
        $this->assertSame(0, $second->meta['questions_skipped']);

        $dupe = Question::where('id', '!=', $first->id)->sole();
        $this->assertSame('pending', $dupe->status);
        $this->assertSame($first->id, $dupe->attributes['duplicate_of'][0]['question_id']);
        $this->assertSame('pending', $dupe->attributes['duplicate_of'][0]['status']);
    }

    public function test_a_syllabus_upload_is_parsed_but_creates_no_questions(): void
    {
        $upload = $this->upload($this->subjectWithUnits(), type: 'syllabus');
        // Python returns no candidates for a syllabus; Laravel must not invent any.
        $this->fakeExtract([]);

        $this->runJob($upload);

        $this->assertSame(0, Question::count());
        $this->assertSame('parsed', $upload->refresh()->status);
    }

    public function test_a_syllabus_upload_stores_the_parsed_courses_for_review(): void
    {
        $upload = $this->upload($this->subjectWithUnits(), type: 'syllabus');
        $courses = [[
            'code' => 'CSC365',
            'name' => 'Compiler Design',
            'description' => 'A course.',
            'markdown' => "# Compiler Design (CSC365)\n\n## Unit 1: Compiler Structure (3 Hrs.)",
            'units' => [[
                'number' => 1,
                'name' => 'Compiler Structure',
                'hours' => 3,
                'content' => '- **Compiler Structure:** Analysis',
                'name_guessed' => true,
            ]],
        ]];
        $this->fakeExtract([], courses: $courses);

        $this->runJob($upload);

        $meta = $upload->refresh()->meta;
        $this->assertSame('CSC365', $meta['courses'][0]['code']);
        $this->assertSame('Compiler Structure', $meta['courses'][0]['units'][0]['name']);
        $this->assertTrue($meta['courses'][0]['units'][0]['name_guessed']);
        // Nothing is created until an admin confirms it on the import screen.
        $this->assertNull($meta['imported_subject_id'] ?? null);
    }

    public function test_a_past_paper_stores_no_courses(): void
    {
        $upload = $this->upload($this->subjectWithUnits());
        $this->fakeExtract([$this->candidate()]);

        $this->runJob($upload);

        $this->assertSame([], $upload->refresh()->meta['courses']);
    }

    public function test_a_python_error_marks_the_upload_failed_with_the_reason(): void
    {
        $upload = $this->upload($this->subjectWithUnits());
        Http::fake([
            '*/extract' => Http::response([
                'status' => 'error',
                'data' => null,
                'errors' => ['no such file: /shared-storage/shared/uploads/2026-06/paper.pdf'],
            ]),
        ]);

        $job = new ProcessDocumentUpload($upload->id);

        try {
            $this->runJob($upload);
            $this->fail('expected the job to throw so the queue can retry');
        } catch (RuntimeException $exception) {
            $job->failed($exception);
        }

        $upload->refresh();
        $this->assertSame('failed', $upload->status);
        $this->assertStringContainsString('no such file', $upload->error);
        $this->assertSame(0, Question::count());
    }

    public function test_an_unreachable_python_service_fails_the_upload(): void
    {
        $upload = $this->upload($this->subjectWithUnits());
        Http::fake(['*/extract' => Http::response('', 500)]);

        $job = new ProcessDocumentUpload($upload->id);

        try {
            $this->runJob($upload);
            $this->fail('expected the job to throw');
        } catch (RuntimeException $exception) {
            $job->failed($exception);
        }

        $this->assertSame('failed', $upload->refresh()->status);
    }

    public function test_an_already_parsed_upload_is_not_reprocessed(): void
    {
        $upload = $this->upload($this->subjectWithUnits());
        $upload->update(['status' => 'parsed']);
        Http::fake();

        $this->runJob($upload);

        Http::assertNothingSent();
    }

    private function runJob(DocumentUpload $upload): void
    {
        (new ProcessDocumentUpload($upload->id))->handle(
            app(\App\Services\PythonService::class),
            app(\App\Services\Extraction\CandidateImporter::class),
        );
    }
}
