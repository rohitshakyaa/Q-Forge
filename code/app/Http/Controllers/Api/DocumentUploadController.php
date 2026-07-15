<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentUploadRequest;
use App\Http\Requests\SyllabusImportRequest;
use App\Http\Resources\DocumentUploadResource;
use App\Jobs\ProcessDocumentUpload;
use App\Models\DocumentUpload;
use App\Services\Extraction\SyllabusImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DocumentUploadController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $uploads = DocumentUpload::query()
            ->with('subject')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 20))
            ->withQueryString();

        return DocumentUploadResource::collection($uploads);
    }

    /**
     * Store the PDF on the shared volume and queue its extraction.
     *
     * Responds 202: the work happens in ProcessDocumentUpload, and the client
     * polls `show()` for the outcome.
     */
    public function store(DocumentUploadRequest $request): JsonResponse
    {
        $file = $request->file('file');

        // The `shared` disk is bind-mounted into the Python container, which reads
        // the file back by path — see config/filesystems.php.
        $storedPath = $file->store('uploads/'.now()->format('Y-m'), 'shared');

        $upload = DocumentUpload::create([
            'uploader_id' => $request->user()->id,
            'subject_id' => $request->input('subject_id'),
            'type' => $request->input('type'),
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'status' => DocumentUpload::STATUS_UPLOADED,
            'meta' => array_filter(['exam_year' => $request->input('exam_year')]),
        ]);

        ProcessDocumentUpload::dispatch($upload->id);

        return (new DocumentUploadResource($upload->load('subject')))
            ->response()
            ->setStatusCode(202);
    }

    /** Polled by the upload screen until `status` is terminal. */
    public function show(DocumentUpload $documentUpload): DocumentUploadResource
    {
        return new DocumentUploadResource($documentUpload->load('subject'));
    }

    /**
     * Create a subject and its units from an admin-confirmed syllabus proposal.
     *
     * Idempotent: the importer only ever adds missing units, so re-running after a
     * correction is safe and a double submit is a no-op.
     */
    public function import(
        SyllabusImportRequest $request,
        DocumentUpload $documentUpload,
        SyllabusImporter $importer,
    ): JsonResponse {
        if ($documentUpload->type !== DocumentUpload::TYPE_SYLLABUS) {
            throw ValidationException::withMessages([
                'upload' => 'Only a syllabus upload can create a subject.',
            ]);
        }

        if ($documentUpload->status !== DocumentUpload::STATUS_PARSED) {
            throw ValidationException::withMessages([
                'upload' => 'This upload has not finished extracting.',
            ]);
        }

        $result = $importer->import($documentUpload, $request->validated());

        return response()->json($result, $result['created_subject'] ? 201 : 200);
    }

    public function destroy(DocumentUpload $documentUpload): \Illuminate\Http\Response
    {
        Storage::disk('shared')->delete($documentUpload->stored_path);
        $documentUpload->delete();

        return response()->noContent();
    }
}
