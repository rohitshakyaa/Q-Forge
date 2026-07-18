import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import api from '../api/client/axios';

export interface BlueprintSection {
  id: number;
  name: string;
  type: string;
  count: number;
  marksEach: number;
  mandatory: boolean;
}

export interface UnitAllocation {
  marks: number;
  count: number;
}

export interface Blueprint {
  id: number;
  name: string;
  subject: string;
  totalMarks: number;
  duration: number;
  questions: number;
  units: number;
  sections: BlueprintSection[];
  unitRules: Record<string, boolean>;
  unitAllocations: Record<string, UnitAllocation[]>;
  exclusionRules: { lastNPapers: number; excludeExamYearsBack: number };
  aiAssist: boolean;
  lastUsed: string;
}

interface ApiBlueprint {
  id: number;
  subject_code: string;
  name: string;
  total_marks: number;
  duration: number;
  ai_assist: boolean;
  last_used_at: string | null;
  definition: {
    sections?: BlueprintSection[];
    unitRules?: Record<string, boolean>;
    unitAllocations?: Record<string, UnitAllocation[]>;
    exclusionRules?: { lastNPapers: number; excludeExamYearsBack: number };
  };
}

const formatDate = (iso: string) =>
  new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });

const derivedQuestions = (sections: BlueprintSection[]) => sections.reduce((s, x) => s + x.count, 0);
const derivedUnits = (rules: Record<string, boolean>) => Object.values(rules).filter(Boolean).length;

const mapFromApi = (b: ApiBlueprint): Blueprint => {
  const sections = b.definition.sections ?? [];
  const unitRules = b.definition.unitRules ?? {};
  return {
    id: b.id,
    name: b.name,
    subject: b.subject_code,
    totalMarks: b.total_marks,
    duration: b.duration,
    sections,
    unitRules,
    unitAllocations: b.definition.unitAllocations ?? {},
    // Normalise per key so blueprints saved with the old shape (reuseThreshold,
    // no excludeExamYearsBack) still bind cleanly to the sliders.
    exclusionRules: {
      lastNPapers: b.definition.exclusionRules?.lastNPapers ?? 2,
      excludeExamYearsBack: b.definition.exclusionRules?.excludeExamYearsBack ?? 0,
    },
    aiAssist: b.ai_assist,
    lastUsed: b.last_used_at ? formatDate(b.last_used_at) : 'Never',
    questions: derivedQuestions(sections),
    units: derivedUnits(unitRules),
  };
};

const mapToApi = (bp: Blueprint) => ({
  subject: bp.subject,
  name: bp.name,
  total_marks: bp.totalMarks,
  duration: bp.duration,
  ai_assist: bp.aiAssist,
  definition: {
    sections: bp.sections,
    unitRules: bp.unitRules,
    unitAllocations: bp.unitAllocations,
    exclusionRules: bp.exclusionRules,
  },
});

export const useBlueprintsStore = defineStore('blueprints', () => {
  const items = ref<Blueprint[]>([]);

  const list = computed(() => items.value);

  const getById = (id: number) => items.value.find((b) => b.id === id) ?? null;

  async function fetch() {
    const { data } = await api.get('/blueprints');
    items.value = (data.data as ApiBlueprint[]).map(mapFromApi);
  }

  async function loadOne(id: number) {
    const { data } = await api.get(`/blueprints/${id}`);
    return mapFromApi(data.data as ApiBlueprint);
  }

  /** Create or update depending on whether the id is a persisted server id. */
  async function save(bp: Blueprint) {
    const exists = items.value.some((b) => b.id === bp.id);
    if (exists) {
      await api.put(`/blueprints/${bp.id}`, mapToApi(bp));
    } else {
      await api.post('/blueprints', mapToApi(bp));
    }
    await fetch();
  }

  async function remove(id: number) {
    await api.delete(`/blueprints/${id}`);
    await fetch();
  }

  const blank = (): Blueprint => ({
    id: Date.now(),
    name: '',
    subject: '',
    totalMarks: 50,
    duration: 90,
    questions: 0,
    units: 0,
    sections: [
      { id: 1, name: 'Section A — Short Answer', type: 'Short Answer', count: 5, marksEach: 4, mandatory: true },
      { id: 2, name: 'Section B — Long Answer', type: 'Long Answer', count: 3, marksEach: 10, mandatory: false },
    ],
    unitRules: {},
    unitAllocations: {},
    exclusionRules: { lastNPapers: 2, excludeExamYearsBack: 0 },
    aiAssist: true,
    lastUsed: 'Never',
  });

  return { list, getById, fetch, loadOne, save, remove, blank };
});
