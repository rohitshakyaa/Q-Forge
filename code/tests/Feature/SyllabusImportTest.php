<?php

namespace Tests\Feature;

use App\Models\DocumentUpload;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SyllabusImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function syllabusUpload(): DocumentUpload
    {
        return DocumentUpload::factory()->parsed()->create([
            'type' => DocumentUpload::TYPE_SYLLABUS,
            'subject_id' => null,
            'meta' => ['pages' => 3, 'ocr_pages' => 0, 'courses' => []],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $units
     * @return array<string, mixed>
     */
    private function payload(array $overrides = [], ?array $units = null): array
    {
        return array_merge([
            'subject' => [
                'code' => 'CSC365',
                'name' => 'Compiler Design and Construction',
                'description' => 'Fundamental concepts of compiler design.',
                'syllabus' => "# Compiler Design and Construction (CSC365)\n\n## Unit 1: Compiler Structure (3 Hrs.)",
            ],
            'units' => $units ?? [
                ['number' => 1, 'name' => 'Compiler Structure', 'hours' => 3, 'content' => '- **Compiler Structure:** Analysis and Synthesis'],
                ['number' => 2, 'name' => 'Lexical Analysis', 'hours' => 22, 'content' => '- **Lexical Analysis:** Its role'],
            ],
        ], $overrides);
    }

    public function test_it_creates_the_subject_and_its_units(): void
    {
        $upload = $this->syllabusUpload();

        $this->postJson("/api/uploads/{$upload->id}/import", $this->payload())
            ->assertCreated()
            ->assertJsonPath('units_created', 2)
            ->assertJsonPath('units_skipped', 0)
            ->assertJsonPath('created_subject', true);

        $subject = Subject::sole();
        $this->assertSame('CSC365', $subject->code);
        $this->assertSame('Compiler Design and Construction', $subject->name);
        $this->assertStringContainsString('# Compiler Design and Construction', $subject->syllabus);
    }

    public function test_units_carry_position_hours_and_markdown_content(): void
    {
        $upload = $this->syllabusUpload();
        $this->postJson("/api/uploads/{$upload->id}/import", $this->payload())->assertCreated();

        $units = Subject::sole()->units;
        $this->assertSame([1, 2], $units->pluck('position')->all());
        $this->assertSame([3, 22], $units->pluck('hours')->all());
        $this->assertStringContainsString('**Compiler Structure:**', $units[0]->content);
    }

    public function test_a_new_subject_takes_its_positions_from_the_syllabus_numbering(): void
    {
        $upload = $this->syllabusUpload();

        // Compiler Design prints units 1, 3, 4 — the numbering has gaps.
        $this->postJson("/api/uploads/{$upload->id}/import", $this->payload(units: [
            ['number' => 1, 'name' => 'Compiler Structure', 'hours' => 3],
            ['number' => 3, 'name' => 'Symbol Table Design', 'hours' => 4],
        ]))->assertCreated();

        $this->assertSame([1, 3], Subject::sole()->units->pluck('position')->all());
    }

    public function test_the_upload_is_linked_to_the_subject_it_created(): void
    {
        $upload = $this->syllabusUpload();
        $this->postJson("/api/uploads/{$upload->id}/import", $this->payload())->assertCreated();

        $upload->refresh();
        $subject = Subject::sole();
        $this->assertSame($subject->id, $upload->subject_id);
        $this->assertSame($subject->id, $upload->meta['imported_subject_id']);
    }

    public function test_importing_onto_an_existing_subject_adds_only_the_missing_units(): void
    {
        $subject = Subject::factory()->create(['code' => 'CSC365']);
        $subject->units()->create(['name' => 'Compiler Structure', 'position' => 1]);
        $upload = $this->syllabusUpload();

        $this->postJson("/api/uploads/{$upload->id}/import", $this->payload())
            ->assertOk()
            ->assertJsonPath('units_created', 1)
            ->assertJsonPath('units_skipped', 1)
            ->assertJsonPath('created_subject', false);

        $this->assertSame(['Compiler Structure', 'Lexical Analysis'], $subject->units()->pluck('name')->all());
    }

    public function test_a_matching_unit_is_recognised_despite_case_and_punctuation(): void
    {
        $subject = Subject::factory()->create(['code' => 'CSC365']);
        $subject->units()->create(['name' => 'compiler  structure!', 'position' => 1]);
        $upload = $this->syllabusUpload();

        $this->postJson("/api/uploads/{$upload->id}/import", $this->payload())
            ->assertOk()
            ->assertJsonPath('units_skipped', 1);
    }

    public function test_an_existing_unit_is_never_deleted_so_its_questions_survive(): void
    {
        // questions.unit_id cascades on delete: losing a unit loses its whole bank.
        $subject = Subject::factory()->create(['code' => 'CSC365']);
        $unit = $subject->units()->create(['name' => 'Old Unit', 'position' => 1]);
        $question = Question::factory()->create([
            'subject_id' => $subject->id,
            'unit_id' => $unit->id,
            'status' => 'approved',
        ]);
        $upload = $this->syllabusUpload();

        $this->postJson("/api/uploads/{$upload->id}/import", $this->payload())->assertOk();

        $this->assertDatabaseHas('units', ['id' => $unit->id, 'name' => 'Old Unit']);
        $this->assertDatabaseHas('questions', ['id' => $question->id, 'unit_id' => $unit->id]);
        $this->assertSame(3, $subject->units()->count()); // Old Unit + the two imported.
    }

    public function test_new_units_append_after_the_existing_ones(): void
    {
        $subject = Subject::factory()->create(['code' => 'CSC365']);
        $subject->units()->create(['name' => 'Old Unit', 'position' => 7]);
        $upload = $this->syllabusUpload();

        $this->postJson("/api/uploads/{$upload->id}/import", $this->payload())->assertOk();

        $this->assertSame([7, 8, 9], $subject->units()->orderBy('position')->pluck('position')->all());
    }

    public function test_an_existing_units_content_is_not_overwritten(): void
    {
        $subject = Subject::factory()->create(['code' => 'CSC365']);
        $subject->units()->create([
            'name' => 'Compiler Structure',
            'position' => 1,
            'content' => 'A teacher wrote this.',
            'hours' => 99,
        ]);
        $upload = $this->syllabusUpload();

        $this->postJson("/api/uploads/{$upload->id}/import", $this->payload())->assertOk();

        $unit = Unit::where('name', 'Compiler Structure')->sole();
        $this->assertSame('A teacher wrote this.', $unit->content);
        $this->assertSame(99, $unit->hours);
    }

    public function test_an_existing_subjects_name_and_description_stay_but_its_syllabus_refreshes(): void
    {
        Subject::factory()->create([
            'code' => 'CSC365',
            'name' => 'Teacher-owned name',
            'description' => 'Teacher-owned description',
            'syllabus' => 'An old syllabus corpus.',
        ]);
        $upload = $this->syllabusUpload();

        $this->postJson("/api/uploads/{$upload->id}/import", $this->payload())->assertOk();

        $subject = Subject::sole();
        // Name and description are the teacher's catalog identity — untouched without opt-in.
        $this->assertSame('Teacher-owned name', $subject->name);
        $this->assertSame('Teacher-owned description', $subject->description);
        // The uploaded document is the syllabus, so its corpus always refreshes.
        $this->assertStringContainsString('# Compiler Design', $subject->syllabus);
    }

    public function test_update_existing_refreshes_the_subject_details(): void
    {
        Subject::factory()->create(['code' => 'CSC365', 'name' => 'Teacher-owned name', 'syllabus' => null]);
        $upload = $this->syllabusUpload();

        $this->postJson("/api/uploads/{$upload->id}/import", $this->payload(['update_existing' => true]))
            ->assertOk();

        $subject = Subject::sole();
        $this->assertSame('Compiler Design and Construction', $subject->name);
        $this->assertStringContainsString('# Compiler Design', $subject->syllabus);
    }

    public function test_re_importing_the_same_upload_is_idempotent(): void
    {
        $upload = $this->syllabusUpload();
        $this->postJson("/api/uploads/{$upload->id}/import", $this->payload())->assertCreated();

        $this->postJson("/api/uploads/{$upload->id}/import", $this->payload())
            ->assertOk()
            ->assertJsonPath('units_created', 0)
            ->assertJsonPath('units_skipped', 2);

        $this->assertSame(2, Unit::count());
        $this->assertSame(1, Subject::count());
    }

    public function test_a_unit_without_a_name_is_rejected(): void
    {
        $upload = $this->syllabusUpload();

        $this->postJson("/api/uploads/{$upload->id}/import", $this->payload(units: [
            ['number' => 1, 'name' => '', 'hours' => 3],
        ]))->assertUnprocessable()->assertJsonValidationErrors('units.0.name');

        $this->assertSame(0, Subject::count());
    }

    public function test_two_units_that_normalize_to_the_same_name_are_rejected(): void
    {
        $upload = $this->syllabusUpload();

        $this->postJson("/api/uploads/{$upload->id}/import", $this->payload(units: [
            ['number' => 1, 'name' => 'Lexical Analysis'],
            ['number' => 2, 'name' => 'lexical  analysis'],
        ]))->assertUnprocessable()->assertJsonValidationErrors('units.1.name');
    }

    public function test_at_least_one_unit_is_required(): void
    {
        $upload = $this->syllabusUpload();

        $this->postJson("/api/uploads/{$upload->id}/import", $this->payload(units: []))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('units');
    }

    public function test_a_past_paper_upload_cannot_create_a_subject(): void
    {
        $upload = DocumentUpload::factory()->parsed()->create(['type' => DocumentUpload::TYPE_PAST_PAPER]);

        $this->postJson("/api/uploads/{$upload->id}/import", $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('upload');
    }

    public function test_an_upload_still_extracting_cannot_be_imported(): void
    {
        $upload = DocumentUpload::factory()->create([
            'type' => DocumentUpload::TYPE_SYLLABUS,
            'status' => DocumentUpload::STATUS_PROCESSING,
        ]);

        $this->postJson("/api/uploads/{$upload->id}/import", $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('upload');
    }

    public function test_a_failed_import_leaves_no_half_built_subject(): void
    {
        $upload = $this->syllabusUpload();

        // The second unit duplicates the first, so validation rejects the whole payload.
        $this->postJson("/api/uploads/{$upload->id}/import", $this->payload(units: [
            ['number' => 1, 'name' => 'Lexical Analysis'],
            ['number' => 2, 'name' => 'Lexical Analysis'],
        ]))->assertUnprocessable();

        $this->assertSame(0, Subject::count());
        $this->assertSame(0, Unit::count());
    }

    public function test_teachers_cannot_import_a_syllabus(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher']));
        $upload = $this->syllabusUpload();

        $this->postJson("/api/uploads/{$upload->id}/import", $this->payload())->assertForbidden();
    }

    public function test_the_import_route_is_not_shadowed_by_the_show_route(): void
    {
        $upload = $this->syllabusUpload();

        // `uploads/{id}/import` must not resolve as `uploads/{documentUpload}`.
        $this->postJson("/api/uploads/{$upload->id}/import", ['units' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subject.code');
    }

    public function test_show_returns_the_parsed_courses_for_the_confirmation_page(): void
    {
        $upload = DocumentUpload::factory()->parsed()->create([
            'type' => DocumentUpload::TYPE_SYLLABUS,
            'meta' => ['courses' => [[
                'code' => 'CSC365',
                'name' => 'Compiler Design',
                'markdown' => '# Compiler Design (CSC365)',
                'units' => [['number' => 1, 'name' => 'Compiler Structure', 'hours' => 3, 'name_guessed' => true]],
            ]]],
        ]);

        $this->getJson("/api/uploads/{$upload->id}")
            ->assertOk()
            ->assertJsonPath('data.courses.0.code', 'CSC365')
            ->assertJsonPath('data.courses.0.units.0.name', 'Compiler Structure')
            ->assertJsonPath('data.courses.0.units.0.name_guessed', true)
            ->assertJsonPath('data.imported_subject_id', null);
    }
}
