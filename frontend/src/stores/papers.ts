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
  questions: number;
  generated_at: string | null;
  sections: PaperSection[];
}

interface GenerateResponse {
  satisfiable: boolean;
  paper: ApiPaper;
  constraint_results: ConstraintResult[];
  missing_slots?: MissingSlot[];
}

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
  exports: 0,
  duration: p.duration,
  sections: p.sections,
});

export const usePapersStore = defineStore('papers', () => {
  // Papers generated in this session. A dedicated list/history endpoint arrives
  // in M3; for now the Generate screen is the only producer.
  const items = ref<Paper[]>([]);
  const current = ref<Paper | null>(null);

  const generating = ref(false);
  const satisfiable = ref<boolean | null>(null);
  const constraints = ref<ConstraintResult[]>([]);
  const missingSlots = ref<MissingSlot[]>([]);
  const error = ref<string | null>(null);

  const list = computed(() => items.value);
  const recent = computed(() => [...items.value].slice(0, 3));

  const getById = (id: number) =>
    items.value.find((p) => p.id === id) ?? current.value ?? null;

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

  const markExported = (id: number) => {
    const idx = items.value.findIndex((p) => p.id === id);
    if (idx >= 0) {
      items.value[idx] = {
        ...items.value[idx],
        status: 'exported',
        exports: items.value[idx].exports + 1,
      };
    }
  };

  return {
    list,
    recent,
    current,
    getById,
    generating,
    satisfiable,
    constraints,
    missingSlots,
    error,
    generate,
    resetGeneration,
    markExported,
  };
});
