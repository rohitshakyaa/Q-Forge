import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import api from '../api/client/axios';

export interface PaperQuestion {
  no: number;
  text: string;
  marks: number;
  unit: string | null;
  ai: boolean;
}

export interface PaperSection {
  label: string;
  note: string;
  questions: PaperQuestion[];
}

export interface Paper {
  id: number | null;
  name: string;
  subject: string;
  subjectName: string | null;
  marks: number;
  questions: number;
  date: string;
  /** Raw ISO timestamp behind `date` — kept for client-side date filtering. */
  generatedAt: string | null;
  status: 'exported' | 'saved' | 'draft';
  exports: number;
  duration: number;
  sections: PaperSection[];
}

export interface ConstraintResult {
  label: string;
  expected: string;
  got: string;
  pass: boolean | null;
}

export interface MissingSlot {
  section_label: string;
  type: string;
  marks: number;
  unit: string | null;
  // First target unit (back-compat); unit_ids carries the full target set —
  // two ids mean the AI top-up is asked for a question spanning both units.
  unit_id: number | null;
  unit_ids: number[];
  need: number;
  description: string;
}

interface ExpandResponse {
  satisfiable: boolean;
  expandable?: boolean;
  shortfall_reason?: string | null;
  jobId?: string;
  message?: string;
}

interface JobStatus {
  id: string;
  status: 'processing' | 'finished' | 'failed' | 'cancelled';
  total: number;
  pending: number;
  failed: number;
  progress: number;
}

interface ApiPaper {
  id: number | null;
  name: string;
  subject: string;
  subject_name?: string | null;
  marks: number;
  duration: number;
  status: 'draft' | 'saved' | 'exported';
  export_count?: number;
  questions: number;
  generated_at: string | null;
  sections?: PaperSection[];
}

interface GenerateResponse {
  satisfiable: boolean;
  expandable?: boolean;
  shortfall_reason?: string | null;
  paper: ApiPaper;
  constraint_results: ConstraintResult[];
  missing_slots?: MissingSlot[];
}

export interface PaperAnalytics {
  generated: number;
  questionsUsed: number;
  uniqueQuestions: number;
  reuseRate: number;
  totalExports: number;
}

export type ExportFormat = 'pdf' | 'docx';

const formatDate = (iso: string | null) =>
  iso
    ? new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
    : 'Today';

const mapPaper = (p: ApiPaper): Paper => ({
  id: p.id,
  name: p.name,
  subject: p.subject,
  subjectName: p.subject_name ?? null,
  marks: p.marks,
  questions: p.questions,
  date: formatDate(p.generated_at),
  generatedAt: p.generated_at,
  status: p.status,
  exports: p.export_count ?? 0,
  duration: p.duration,
  sections: p.sections ?? [],
});

const filenameFromDisposition = (header: unknown, fallback: string): string => {
  if (typeof header !== 'string') return fallback;
  const match = /filename="?([^"]+)"?/.exec(header);
  return match ? match[1] : fallback;
};

export const usePapersStore = defineStore('papers', () => {
  // Persisted papers. Hydrated from the history endpoint (fetchHistory) and
  // augmented by the Generate screen and fetchById.
  const items = ref<Paper[]>([]);
  const current = ref<Paper | null>(null);
  const analytics = ref<PaperAnalytics | null>(null);

  const generating = ref(false);
  const loading = ref(false);
  const satisfiable = ref<boolean | null>(null);
  const constraints = ref<ConstraintResult[]>([]);
  const missingSlots = ref<MissingSlot[]>([]);
  const error = ref<string | null>(null);

  // M5 — AI bank expansion progress for the Generate screen. `expandable` is false
  // when the shortfall is structural (coverage needs more units than there are
  // questions), where AI can't help; `shortfallReason` carries the explanation.
  const expanding = ref(false);
  const expandStatus = ref<string | null>(null);
  const expandable = ref(true);
  const shortfallReason = ref<string | null>(null);

  const list = computed(() => items.value);
  const recent = computed(() => [...items.value].slice(0, 3));

  const getById = (id: number) =>
    items.value.find((p) => p.id === id) ?? (current.value?.id === id ? current.value : null);

  /** Upsert a paper into the session cache (most-recent-first). */
  const cache = (paper: Paper) => {
    items.value = [paper, ...items.value.filter((p) => p.id !== paper.id)];
  };

  /**
   * Run the real generation engine. On a satisfiable blueprint the persisted
   * draft paper is stored and returned; on an infeasible one we keep the
   * best-effort partial plus the missing-slot shortfall for the UI to render.
   */
  async function generate(blueprintId: number): Promise<boolean> {
    generating.value = true;
    error.value = null;
    satisfiable.value = null;
    constraints.value = [];
    missingSlots.value = [];

    try {
      const { data } = await api.post<GenerateResponse>('/papers/generate', {
        blueprint_id: blueprintId,
      });

      const paper = mapPaper(data.paper);
      current.value = paper;
      constraints.value = data.constraint_results ?? [];
      missingSlots.value = data.missing_slots ?? [];
      satisfiable.value = data.satisfiable;
      expandable.value = data.expandable ?? true;
      shortfallReason.value = data.shortfall_reason ?? null;

      if (data.satisfiable && paper.id !== null) {
        items.value = [paper, ...items.value.filter((p) => p.id !== paper.id)];
      }

      return data.satisfiable;
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Generation failed';
      satisfiable.value = false;
      return false;
    } finally {
      generating.value = false;
    }
  }

  const resetGeneration = () => {
    generating.value = false;
    satisfiable.value = null;
    constraints.value = [];
    missingSlots.value = [];
    current.value = null;
    error.value = null;
    expanding.value = false;
    expandStatus.value = null;
    expandable.value = true;
    shortfallReason.value = null;
  };

  /**
   * Poll a batch's status until it finishes or fails (or we give up).
   *
   * Two independent guards, because a healthy expansion can run many minutes
   * (queue wait + slow local-LLM generation):
   *  - a hard 30-minute ceiling on the whole poll, so it can't hang forever;
   *  - a no-progress stall guard — we only give up early if `progress` hasn't
   *    advanced for STALL_LIMIT consecutive polls.
   *    Caveat that sizes STALL_LIMIT: the expansion batch holds ONE job, so
   *    `progress` stays at 0 until the whole job completes — a healthy
   *    multi-slot run (each long-answer round is minutes on CPU) looks exactly
   *    like a stuck one. The stall window must therefore exceed a realistic
   *    worst-case *whole run*, not a queue wait; the backend job's own
   *    timeout/retry (tries=2, timeout=600s) is the real stuck-job detector.
   */
  async function pollJob(jobId: string): Promise<boolean> {
    const POLL_INTERVAL_MS = 3000;
    const MAX_ATTEMPTS = (30 * 60 * 1000) / POLL_INTERVAL_MS; // 30 minutes
    const STALL_LIMIT = 400; // ~20 min with no forward progress → assume stuck

    let lastProgress = -1;
    let stalledPolls = 0;

    for (let attempt = 0; attempt < MAX_ATTEMPTS; attempt += 1) {
      const { data } = await api.get<JobStatus>(`/jobs/${jobId}`);
      if (data.status === 'finished') return true;
      if (data.status === 'failed' || data.status === 'cancelled') return false;

      if (data.progress > lastProgress) {
        lastProgress = data.progress;
        stalledPolls = 0;
      } else {
        stalledPolls += 1;
        if (stalledPolls >= STALL_LIMIT) return false;
      }

      expandStatus.value = `Generating questions… ${data.progress}%`;
      await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL_MS));
    }
    return false;
  }

  /**
   * M5: when a blueprint is infeasible, ask the backend to top up the bank with the
   * local LLM, wait for the queued job, then re-generate. The backend re-derives the
   * shortfall itself, so we only need the blueprint id (already held by the screen).
   */
  async function expandBank(blueprintId: number): Promise<boolean> {
    expanding.value = true;
    expandStatus.value = 'Requesting AI-generated questions…';
    error.value = null;

    try {
      const { data } = await api.post<ExpandResponse>(`/blueprints/${blueprintId}/expand-bank`, {});

      // Structural shortfall the backend refused to expand — AI can't add slots.
      if (data.expandable === false) {
        expandable.value = false;
        shortfallReason.value = data.shortfall_reason ?? null;
        expandStatus.value = null;
        return false;
      }

      // Already satisfiable server-side (e.g. bank changed since the last attempt).
      if (data.satisfiable || !data.jobId) {
        expandStatus.value = 'Bank is sufficient — regenerating…';
        const ok = await generate(blueprintId);
        expandStatus.value = null;
        return ok;
      }

      const finished = await pollJob(data.jobId);
      if (!finished) {
        expandStatus.value = 'AI generation did not complete — try again.';
        return false;
      }

      expandStatus.value = 'Questions added to the bank — regenerating…';
      const ok = await generate(blueprintId);
      // Clear the transient status so the fresh result (success or a new shortfall)
      // isn't overlaid by a stale "regenerating…" line.
      expandStatus.value = null;
      return ok;
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'AI expansion failed';
      expandStatus.value = null;
      return false;
    } finally {
      expanding.value = false;
    }
  }

  /** Load the authenticated teacher's paper history (list rows, no sections). */
  async function fetchHistory(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await api.get<{ data: ApiPaper[] }>('/papers');
      items.value = data.data.map(mapPaper);
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to load papers';
    } finally {
      loading.value = false;
    }
  }

  /** Authoritatively load one paper (with sections) and make it current. */
  async function fetchById(id: number): Promise<Paper | null> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await api.get<{ paper: ApiPaper }>(`/papers/${id}`);
      const paper = mapPaper(data.paper);
      current.value = paper;
      cache(paper);
      return paper;
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to load paper';
      return null;
    } finally {
      loading.value = false;
    }
  }

  async function fetchAnalytics(): Promise<void> {
    try {
      const { data } = await api.get<PaperAnalytics>('/papers/analytics');
      analytics.value = data;
    } catch {
      // Analytics are non-critical; leave as null on failure.
    }
  }

  /** Rename a paper or mark it saved; refreshes the cached copy. */
  async function update(id: number, payload: { name?: string; status?: 'saved' }): Promise<void> {
    const { data } = await api.patch<{ paper: ApiPaper }>(`/papers/${id}`, payload);
    const paper = mapPaper(data.paper);
    current.value = paper;
    cache(paper);
  }

  /**
   * Download an export. The Bearer token can't ride a plain <a href>, so we pull
   * the file as a blob through axios (which carries the interceptor's token) and
   * trigger a client-side download via an object URL.
   */
  async function exportPaper(id: number, format: ExportFormat): Promise<void> {
    const response = await api.get(`/papers/${id}/export`, {
      params: { format },
      responseType: 'blob',
    });

    const fallback = `${(current.value?.name ?? 'Paper').replace(/\s+/g, '')}.${format}`;
    const filename = filenameFromDisposition(response.headers['content-disposition'], fallback);

    const url = URL.createObjectURL(response.data as Blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);

    // Reflect the server-side status/export_count bump.
    await fetchById(id);
  }

  return {
    list,
    recent,
    current,
    analytics,
    getById,
    generating,
    loading,
    satisfiable,
    constraints,
    missingSlots,
    error,
    expanding,
    expandStatus,
    expandable,
    shortfallReason,
    generate,
    expandBank,
    resetGeneration,
    fetchHistory,
    fetchById,
    fetchAnalytics,
    update,
    exportPaper,
  };
});
