<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentUpload;
use App\Models\Paper;
use App\Models\Question;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminOverviewController extends Controller
{
    /**
     * System-wide dashboard aggregates for the admin overview screen.
     *
     * All figures are real counts derived from the domain tables; the activity
     * feed is synthesized from the created_at/generated_at timestamps of the
     * three streams an admin can see (uploads, generated papers, users) — there
     * is no separate audit-log table.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'stats' => [
                'questionsTotal' => Question::count(),
                'questionsThisWeek' => Question::where('created_at', '>=', now()->subDays(7))->count(),
                'questionsPending' => Question::where('status', 'pending')->count(),
                'documentsTotal' => DocumentUpload::count(),
                'teachersTotal' => User::where('role', 'teacher')->count(),
                'usersTotal' => User::count(),
                'papersGenerated' => Paper::where('origin', 'generated')->count(),
            ],
            'recentUploads' => $this->recentUploads(),
            'activity' => $this->activity(),
        ]);
    }

    /** The six most recent document uploads, shaped for the Recent Documents table. */
    private function recentUploads(): array
    {
        return DocumentUpload::with('subject')
            ->latest('id')
            ->take(6)
            ->get()
            ->map(fn (DocumentUpload $u) => [
                'id' => $u->id,
                'filename' => $u->original_filename,
                'subjectCode' => $u->subject?->code,
                'questionsCreated' => $u->meta['questions_created'] ?? null,
                'status' => $u->status,
                'createdAt' => $u->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * A merged, most-recent-first activity feed built from three real streams.
     * Each stream contributes its latest few events; they are combined and the
     * top eight by timestamp are returned.
     */
    private function activity(): array
    {
        $uploads = DocumentUpload::latest('created_at')->take(8)->get()
            ->map(fn (DocumentUpload $u) => [
                'type' => 'upload',
                'title' => 'PDF uploaded',
                'detail' => $u->original_filename,
                'at' => $u->created_at,
            ]);

        $papers = Paper::where('origin', 'generated')
            ->whereNotNull('generated_at')
            ->latest('generated_at')
            ->take(8)
            ->get()
            ->map(fn (Paper $p) => [
                'type' => 'paper',
                'title' => 'Paper generated',
                'detail' => $p->name,
                'at' => $p->generated_at,
            ]);

        $users = User::latest('created_at')->take(8)->get()
            ->map(fn (User $u) => [
                'type' => 'user',
                'title' => 'User added',
                'detail' => $u->name.' — '.$u->role,
                'at' => $u->created_at,
            ]);

        return $uploads
            ->concat($papers)
            ->concat($users)
            ->filter(fn (array $e) => $e['at'] !== null)
            ->sortByDesc('at')
            ->take(8)
            ->map(fn (array $e) => [
                'type' => $e['type'],
                'title' => $e['title'],
                'detail' => $e['detail'],
                'at' => $e['at']->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
