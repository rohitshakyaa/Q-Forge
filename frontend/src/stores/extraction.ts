import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import api from '../api/client/axios';

// ── Enum mapping: the backend stays canonical lowercase; the UI uses display labels.
const TYPE_FROM_API: Record<string, string> = {
  short: 'Short Answer',
  long: 'Long Answer',
  mcq: 'MCQ',
};
const TYPE_TO_API: Record<string, string> = {
  'Short Answer': 'short',
  'Long Answer': 'long',
  MCQ: 'mcq',
};

export const QUESTION_TYPES = Object.keys(TYPE_TO_API);

export const toApiType = (t: string) => TYPE_TO_API[t] ?? t.toLowerCase();
export const fromApiType = (t: string) => TYPE_FROM_API[t] ?? t;

export type UploadStatus = 'uploaded' | 'processing' | 'parsed' | 'failed';
export type DocumentType = 'past_paper' | 'syllabus';
export type ReviewStatus = 'pending' | 'approved' | 'rejected';

/** A unit as the syllabus parser found it, before an admin confirms it. */
export interface CourseUnit {
  number: number;
  name: string | null;
  hours: number | null;
  content: string | null;
  /** The syllabus printed no name; this one was inferred from the first sub-topic. */
  nameGuessed: boolean;
}

/** A course block parsed out of a syllabus PDF. `code`/`name` are null when the PDF has no header. */
export interface Course {
  code: string | null;
  name: string | null;
  description: string | null;
  markdown: string;
  units: CourseUnit[];
}

export interface Upload {
  id: number;
  status: UploadStatus;
  progress: number;
  type: DocumentType;
  subjectId: number | null;
  subjectCode: string | null;
  filename: string;
  error: string | null;
  examYear: string | null;
  pages: number | null;
  ocrPages: number | null;
  questionsCreated: number | null;
  questionsSkipped: number | null;
  questionsUnlinked: number | null;
  /** Syllabus uploads only, and only from `GET /uploads/{id}`. */
  courses: Course[];
  importedSubjectId: number | null;
  createdAt: string;
}

export interface ImportResult {
  subject_id: number;
  created_subject: boolean;
  units_created: number;
  units_skipped: number;
}

export interface Candidate {
  id: number;
  text: string;
  marks: number | null;
  type: string; // display label
  status: ReviewStatus;
  subjectId: number;
  subjectCode: string;
  unitId: number | null;
  unitName: string | null;
  source: string;
  unitHint: string | null;
  page: number | null;
  ocr: boolean;
  uploadId: number | null;
}

export interface UnitOption {
  id: number;
  name: string;
}

/** A candidate the backend refused to approve, and why. */
export interface SkippedCandidate {
  id: number;
  reason: string;
}

interface ApiCourseUnit {
  number: number;
  name: string | null;
  hours: number | null;
  content: string | null;
  name_guessed: boolean;
}

interface ApiCourse {
  code: string | null;
  name: string | null;
  description: string | null;
  markdown: string;
  units: ApiCourseUnit[];
}

interface ApiUpload {
  id: number;
  status: UploadStatus;
  progress: number;
  type: DocumentType;
  subject_id: number | null;
  subject_code?: string | null;
  original_filename: string;
  error: string | null;
  exam_year: string | null;
  pages: number | null;
  ocr_pages: number | null;
  questions_created: number | null;
  questions_skipped: number | null;
  questions_unlinked: number | null;
  courses?: ApiCourse[];
  imported_subject_id: number | null;
  created_at: string;
}

interface ApiCandidate {
  id: number;
  text: string;
  marks: number | null;
  type: string;
  status: ReviewStatus;
  subject_id: number;
  subject_code?: string;
  unit_id: number | null;
  unit_name?: string;
  source: string;
  attributes: Record<string, unknown> | null;
}

const mapCourse = (c: ApiCourse): Course => ({
  code: c.code,
  name: c.name,
  description: c.description,
  markdown: c.markdown,
  units: (c.units ?? []).map((u) => ({
    number: u.number,
    name: u.name,
    hours: u.hours,
    content: u.content,
    nameGuessed: u.name_guessed,
  })),
});

const mapUpload = (u: ApiUpload): Upload => ({
  id: u.id,
  status: u.status,
  progress: u.progress,
  type: u.type,
  subjectId: u.subject_id,
  subjectCode: u.subject_code ?? null,
  filename: u.original_filename,
  error: u.error,
  examYear: u.exam_year,
  pages: u.pages,
  ocrPages: u.ocr_pages,
  questionsCreated: u.questions_created,
  questionsSkipped: u.questions_skipped,
  questionsUnlinked: u.questions_unlinked,
  // Only `GET /uploads/{id}` carries the proposal; the index omits it.
  courses: (u.courses ?? []).map(mapCourse),
  importedSubjectId: u.imported_subject_id,
  createdAt: u.created_at,
});

const mapCandidate = (q: ApiCandidate): Candidate => {
  const attrs = q.attributes ?? {};

  return {
    id: q.id,
    text: q.text,
    marks: q.marks,
    type: fromApiType(q.type),
    status: q.status,
    subjectId: q.subject_id,
    subjectCode: q.subject_code ?? '',
    unitId: q.unit_id,
    unitName: q.unit_name ?? null,
    source: q.source,
    unitHint: (attrs.unit_hint as string) ?? null,
    page: (attrs.page as number) ?? null,
    ocr: Boolean(attrs.ocr),
    uploadId: (attrs.upload_id as number) ?? null,
  };
};

const POLL_INTERVAL_MS = 1500;
const TERMINAL: UploadStatus[] = ['parsed', 'failed'];

export const useExtractionStore = defineStore('extraction', () => {
  const uploads = ref<Upload[]>([]);
  const candidates = ref<Candidate[]>([]);
  const units = ref<UnitOption[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);

  const pollers = new Map<number, ReturnType<typeof setInterval>>();

  const pendingCount = computed(() => candidates.value.filter((c) => c.status === 'pending').length);

  const isTerminal = (status: UploadStatus) => TERMINAL.includes(status);

  // ── Uploads ───────────────────────────────────────────────────────────────
  async function fetchUploads() {
    loading.value = true;
    try {
      const { data } = await api.get('/uploads', { params: { per_page: 25 } });
      uploads.value = (data.data as ApiUpload[]).map(mapUpload);
      // Resume polling anything the server is still working on.
      uploads.value.filter((u) => !isTerminal(u.status)).forEach((u) => watchUpload(u.id));
    } finally {
      loading.value = false;
    }
  }

  /** Uploads the PDF and starts polling; resolves as soon as the job is queued. */
  async function createUpload(payload: {
    file: File;
    type: DocumentType;
    subjectId?: number | null;
    examYear?: string;
  }): Promise<Upload> {
    const form = new FormData();
    form.append('file', payload.file);
    form.append('type', payload.type);
    if (payload.subjectId) form.append('subject_id', String(payload.subjectId));
    if (payload.examYear) form.append('exam_year', payload.examYear);

    const { data } = await api.post('/uploads', form);
    const upload = mapUpload(data.data as ApiUpload);

    uploads.value = [upload, ...uploads.value];
    watchUpload(upload.id);

    return upload;
  }

  async function refreshUpload(id: number): Promise<Upload | null> {
    const { data } = await api.get(`/uploads/${id}`);
    const fresh = mapUpload(data.data as ApiUpload);
    uploads.value = uploads.value.map((u) => (u.id === id ? fresh : u));

    return fresh;
  }

  /** Polls `GET /uploads/{id}` until the extraction job reaches a terminal state. */
  function watchUpload(id: number) {
    if (pollers.has(id)) return;

    const handle = setInterval(async () => {
      try {
        const fresh = await refreshUpload(id);
        if (!fresh || isTerminal(fresh.status)) stopWatching(id);
      } catch {
        stopWatching(id); // The upload was deleted, or we lost the session.
      }
    }, POLL_INTERVAL_MS);

    pollers.set(id, handle);
  }

  function stopWatching(id: number) {
    const handle = pollers.get(id);
    if (handle) clearInterval(handle);
    pollers.delete(id);
  }

  /** Views must call this on unmount, or the polls outlive the screen. */
  function stopAllWatching() {
    pollers.forEach((handle) => clearInterval(handle));
    pollers.clear();
  }

  async function deleteUpload(id: number) {
    stopWatching(id);
    await api.delete(`/uploads/${id}`);
    uploads.value = uploads.value.filter((u) => u.id !== id);
  }

  // ── Syllabus import ───────────────────────────────────────────────────────
  /** Fetches one upload, including the parsed courses the index omits. */
  async function loadUpload(id: number): Promise<Upload> {
    const { data } = await api.get(`/uploads/${id}`);

    return mapUpload(data.data as ApiUpload);
  }

  /**
   * Creates the subject and its units from a confirmed proposal. Additive: the
   * backend only ever adds missing units, so this is safe to re-run.
   */
  async function importSyllabus(
    uploadId: number,
    payload: {
      subject: { code: string; name: string; description?: string | null; syllabus?: string | null };
      units: { number: number; name: string; hours: number | null; content: string | null }[];
      updateExisting?: boolean;
    },
  ): Promise<ImportResult> {
    const { data } = await api.post(`/uploads/${uploadId}/import`, {
      subject: payload.subject,
      units: payload.units,
      update_existing: payload.updateExisting ?? false,
    });

    return data as ImportResult;
  }

  // ── Review queue ──────────────────────────────────────────────────────────
  async function fetchCandidates(filters: {
    subject?: string;
    upload?: number;
    status?: ReviewStatus | 'all';
  } = {}) {
    loading.value = true;
    error.value = null;
    try {
      const params: Record<string, string | number> = { per_page: 100 };
      if (filters.subject && filters.subject !== 'all') params.subject = filters.subject;
      if (filters.upload) params.upload = filters.upload;
      if (filters.status && filters.status !== 'all') params.status = filters.status;

      const { data } = await api.get('/questions', { params });
      candidates.value = (data.data as ApiCandidate[]).map(mapCandidate);
    } finally {
      loading.value = false;
    }
  }

  async function fetchUnits(subjectCode: string) {
    if (!subjectCode) {
      units.value = [];

      return;
    }
    const { data } = await api.get(`/subjects/${subjectCode}/units`);
    units.value = (data.data as UnitOption[]).map((u) => ({ id: u.id, name: u.name }));
  }

  function replace(candidate: Candidate) {
    candidates.value = candidates.value.map((c) => (c.id === candidate.id ? candidate : c));
  }

  /**
   * Approve one candidate, optionally correcting it first.
   * The backend rejects a candidate with no unit or no marks — surface that.
   */
  async function approve(
    id: number,
    patch: { unitId?: number | null; marks?: number | null; type?: string; text?: string } = {},
  ) {
    const body: Record<string, unknown> = {};
    if (patch.unitId != null) body.unit_id = patch.unitId;
    if (patch.marks != null) body.marks = patch.marks;
    if (patch.type) body.type = toApiType(patch.type);
    if (patch.text) body.text = patch.text;

    const { data } = await api.post(`/questions/${id}/approve`, body);
    replace(mapCandidate(data.data as ApiCandidate));
  }

  async function reject(id: number) {
    const { data } = await api.post(`/questions/${id}/reject`);
    replace(mapCandidate(data.data as ApiCandidate));
  }

  /**
   * Saves an edit without changing the candidate's review status.
   * Unset unit/marks are omitted rather than sent as null: the questions endpoint
   * validates them as integers whenever they are present, and a candidate is
   * allowed to stay unlinked until someone approves it.
   */
  async function save(id: number, patch: { unitId?: number | null; marks?: number | null; type?: string; text?: string }) {
    const candidate = candidates.value.find((c) => c.id === id);
    if (!candidate) return;

    const body: Record<string, unknown> = { subject_id: candidate.subjectId };
    const unitId = patch.unitId ?? candidate.unitId;
    const marks = patch.marks ?? candidate.marks;
    if (unitId != null) body.unit_id = unitId;
    if (marks != null) body.marks = marks;
    body.type = toApiType(patch.type ?? candidate.type);
    body.text = patch.text ?? candidate.text;

    const { data } = await api.put(`/questions/${id}`, body);
    replace(mapCandidate(data.data as ApiCandidate));
  }

  /**
   * Approve every complete candidate in one call. Returns the ids the backend
   * refused, so the screen can say which still need a unit or marks.
   */
  async function bulkApprove(ids: number[]): Promise<SkippedCandidate[]> {
    if (ids.length === 0) return [];

    const { data } = await api.post('/questions/bulk-approve', { ids });
    const approved = new Set<number>(data.approved as number[]);
    candidates.value = candidates.value.map((c) =>
      approved.has(c.id) ? { ...c, status: 'approved' as const } : c,
    );

    return data.skipped as SkippedCandidate[];
  }

  async function bulkReject(ids: number[]) {
    if (ids.length === 0) return;

    const { data } = await api.post('/questions/bulk-reject', { ids });
    const rejected = new Set<number>(data.rejected as number[]);
    candidates.value = candidates.value.map((c) =>
      rejected.has(c.id) ? { ...c, status: 'rejected' as const } : c,
    );
  }

  return {
    uploads,
    candidates,
    units,
    loading,
    error,
    pendingCount,
    isTerminal,
    fetchUploads,
    createUpload,
    refreshUpload,
    watchUpload,
    stopWatching,
    stopAllWatching,
    deleteUpload,
    loadUpload,
    importSyllabus,
    fetchCandidates,
    fetchUnits,
    approve,
    reject,
    save,
    bulkApprove,
    bulkReject,
  };
});
