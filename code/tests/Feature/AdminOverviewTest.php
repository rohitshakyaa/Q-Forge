<?php

namespace Tests\Feature;

use App\Models\DocumentUpload;
use App\Models\Paper;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_teacher_cannot_reach_overview(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'teacher']));

        $this->getJson('/api/admin/overview')->assertStatus(403);
    }

    public function test_overview_returns_the_expected_shape(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/overview')
            ->assertOk()
            ->assertJsonStructure([
                'stats' => [
                    'questionsTotal', 'questionsThisWeek', 'questionsPending',
                    'documentsTotal', 'teachersTotal', 'usersTotal', 'papersGenerated',
                ],
                'recentUploads',
                'activity',
            ]);
    }

    public function test_stats_reflect_real_counts(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        Question::factory()->count(4)->create();            // approved
        Question::factory()->pending()->count(3)->create(); // pending
        DocumentUpload::factory()->count(5)->create();
        Paper::factory()->count(2)->create();               // origin=generated
        Paper::factory()->imported()->create();             // must NOT count

        // teachersTotal/usersTotal are intentionally not asserted here: the Paper
        // and Blueprint factories spawn their own owner users, so those totals are
        // not fully controlled by this test. The teacher count is covered in
        // UserApiTest; here we assert the counts this test fully owns.
        $this->getJson('/api/admin/overview')
            ->assertOk()
            ->assertJsonPath('stats.questionsTotal', 7)
            ->assertJsonPath('stats.questionsPending', 3)
            ->assertJsonPath('stats.documentsTotal', 5)
            ->assertJsonPath('stats.papersGenerated', 2); // imported paper excluded
    }

    public function test_questions_this_week_excludes_older_questions(): void
    {
        Sanctum::actingAs($this->admin());

        Question::factory()->count(2)->create(); // created now
        Question::factory()->create(['created_at' => now()->subDays(30)]);

        $this->getJson('/api/admin/overview')
            ->assertOk()
            ->assertJsonPath('stats.questionsTotal', 3)
            ->assertJsonPath('stats.questionsThisWeek', 2);
    }

    public function test_recent_uploads_are_shaped_and_capped(): void
    {
        Sanctum::actingAs($this->admin());
        DocumentUpload::factory()->count(8)->create();

        $res = $this->getJson('/api/admin/overview')->assertOk();

        $this->assertCount(6, $res->json('recentUploads'));
        $this->assertArrayHasKey('filename', $res->json('recentUploads.0'));
        $this->assertArrayHasKey('status', $res->json('recentUploads.0'));
        $this->assertArrayHasKey('createdAt', $res->json('recentUploads.0'));
    }

    public function test_activity_merges_streams_newest_first(): void
    {
        Sanctum::actingAs($this->admin());

        DocumentUpload::factory()->create(['created_at' => now()->subHour()]);
        Paper::factory()->create(['generated_at' => now()->subMinutes(5)]);

        $activity = $this->getJson('/api/admin/overview')->assertOk()->json('activity');

        $this->assertNotEmpty($activity);

        // The feed merges all three streams and each entry carries the common shape.
        $types = array_column($activity, 'type');
        $this->assertContains('upload', $types);
        $this->assertContains('paper', $types);
        foreach ($activity as $item) {
            $this->assertArrayHasKey('title', $item);
            $this->assertArrayHasKey('detail', $item);
            $this->assertArrayHasKey('at', $item);
        }

        // It is sorted most-recent-first.
        $timestamps = array_column($activity, 'at');
        $sorted = $timestamps;
        rsort($sorted);
        $this->assertSame($sorted, $timestamps);
    }
}
