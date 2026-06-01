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
  marks: number;
  questions: number;
  date: string;
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
  need: number;
  description: string;
}

interface ApiPaper {
  id: number | null;
  name: string;
  subject: string;
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
  marks: p.marks,
  questions: p.questions,
  date: formatDate(p.generated_at),
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
  };

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
    generate,
    resetGeneration,
    fetchHistory,
    fetchById,
    fetchAnalytics,
    update,
    exportPaper,
  };
});
