import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import api from '../api/client/axios';

export interface CatalogQuestion {
  id: number;
  text: string;
  marks: number;
  type: string;
  used?: number;
  source?: string;
  createdAt?: string;
  /** Every unit the question is tagged with (primary included). */
  unitIds: number[];
  units: { id: number; name: string }[];
}

export interface CatalogUnit {
  id: number;
  name: string;
  questions: CatalogQuestion[];
}

export interface Subject {
  id?: number;
  code: string;
  name: string;
  teachers: number;
  description: string;
  syllabus: string;
  units: CatalogUnit[];
  // Counts from the list endpoint (the index does not embed questions).
  unitsCount?: number;
  questionsCount?: number;
}

export interface BankQuestion extends CatalogQuestion {
  subject: string; // subject code
  subjectName: string; // subject name
  unit: string; // unit name
  /** Source exam year, when the question was extracted from a past paper. */
  examYear?: string | null;
}

export interface QuestionFilters {
  subject?: string; // code
  unit?: number; // id
  type?: string; // display label
  status?: string;
  /** 'extracted' | 'ai' | 'manual' */
  source?: string;
  search?: string;
  /** 'used' → most-used first; omitted → newest first. */
  sort?: string;
  page?: number;
  perPage?: number;
}

export interface PageMeta {
  currentPage: number;
  lastPage: number;
  total: number;
  perPage: number;
}

// ── Enum mapping: backend stays canonical lowercase; the UI uses display labels.
const TYPE_TO_API: Record<string, string> = {
  'Short Answer': 'short',
  'Long Answer': 'long',
  MCQ: 'mcq',
};
const TYPE_FROM_API: Record<string, string> = {
  short: 'Short Answer',
  long: 'Long Answer',
  mcq: 'MCQ',
};
const toApiType = (t?: string) => (t ? (TYPE_TO_API[t] ?? t.toLowerCase()) : undefined);
const fromApiType = (t: string) => TYPE_FROM_API[t] ?? t;

interface ApiQuestion {
  id: number;
  text: string;
  marks: number;
  type: string;
  used_count: number;
  source?: string;
  created_at?: string;
  subject_code?: string;
  subject_name?: string;
  unit_name?: string;
  unit_ids?: number[];
  units?: { id: number; name: string }[];
  attributes?: { exam_year?: string | null } | null;
}
interface ApiUnit {
  id: number;
  name: string;
  questions?: ApiQuestion[];
}
interface ApiSubject {
  id: number;
  code: string;
  name: string;
  description: string | null;
  syllabus: string | null;
  teachers: number;
  units_count?: number;
  questions_count?: number;
  units?: ApiUnit[];
}

const mapQuestion = (q: ApiQuestion): CatalogQuestion => ({
  id: q.id,
  text: q.text,
  marks: q.marks,
  type: fromApiType(q.type),
  used: q.used_count,
  source: q.source,
  createdAt: q.created_at,
  unitIds: q.unit_ids ?? [],
  units: q.units ?? [],
});

const mapUnit = (u: ApiUnit): CatalogUnit => ({
  id: u.id,
  name: u.name,
  questions: (u.questions ?? []).map(mapQuestion),
});

const mapSubject = (s: ApiSubject): Subject => ({
  id: s.id,
  code: s.code,
  name: s.name,
  description: s.description ?? '',
  syllabus: s.syllabus ?? '',
  teachers: s.teachers ?? 0,
  unitsCount: s.units_count,
  questionsCount: s.questions_count,
  units: (s.units ?? []).map(mapUnit),
});

export const useCatalogStore = defineStore('catalog', () => {
  const subjects = ref<Subject[]>([]);
  const current = ref<Subject | null>(null);
  const questions = ref<BankQuestion[]>([]);
  const questionMeta = ref<PageMeta>({ currentPage: 1, lastPage: 1, total: 0, perPage: 20 });

  const getSubject = (code: string) =>
    current.value?.code === code ? current.value : (subjects.value.find((s) => s.code === code) ?? null);

  const questionBank = computed(() => questions.value);

  async function fetchSubjects() {
    const { data } = await api.get('/subjects');
    subjects.value = (data.data as ApiSubject[]).map(mapSubject);
  }

  async function loadSubject(code: string) {
    const { data } = await api.get(`/subjects/${code}`);
    current.value = mapSubject(data.data as ApiSubject);
    return current.value;
  }

  async function createSubject(payload: { code: string; name: string; description?: string }) {
    await api.post('/subjects', payload);
    await fetchSubjects();
  }

  async function updateSubject(code: string, payload: Partial<{ name: string; description: string; syllabus: string }>) {
    await api.put(`/subjects/${code}`, payload);
    if (current.value?.code === code) await loadSubject(code);
  }

  async function removeSubject(code: string) {
    await api.delete(`/subjects/${code}`);
    await fetchSubjects();
  }

  async function addUnit(code: string, name: string) {
    await api.post(`/subjects/${code}/units`, { name });
    await loadSubject(code);
  }

  async function updateUnit(code: string, unitId: number, name: string) {
    await api.put(`/units/${unitId}`, { name });
    await loadSubject(code);
  }

  async function deleteUnit(code: string, unitId: number) {
    await api.delete(`/units/${unitId}`);
    await loadSubject(code);
  }

  async function createQuestion(
    code: string,
    payload: {
      subjectId: number;
      unitId: number;
      text: string;
      marks: number;
      type: string;
      /** Extra units the question also covers (primary excluded). */
      additionalUnitIds?: number[];
    },
  ) {
    const extras = (payload.additionalUnitIds ?? []).filter((id) => id !== payload.unitId);
    await api.post('/questions', {
      subject_id: payload.subjectId,
      unit_id: payload.unitId,
      // unit_ids is the FULL set and must contain the primary.
      ...(extras.length ? { unit_ids: [payload.unitId, ...extras] } : {}),
      text: payload.text,
      marks: payload.marks,
      type: toApiType(payload.type),
    });
    await loadSubject(code);
  }

  async function deleteQuestion(code: string, questionId: number) {
    await api.delete(`/questions/${questionId}`);
    await loadSubject(code);
  }

  async function fetchQuestions(filters: QuestionFilters = {}) {
    const params: Record<string, string | number> = {
      status: filters.status ?? 'approved',
      page: filters.page ?? 1,
      per_page: filters.perPage ?? 20,
    };
    if (filters.subject && filters.subject !== 'all') params.subject = filters.subject;
    if (filters.unit) params.unit = filters.unit;
    if (filters.source) params.source = filters.source;
    if (filters.sort) params.sort = filters.sort;
    const apiType = toApiType(filters.type);
    if (apiType) params.type = apiType;
    if (filters.search) params.search = filters.search;

    const { data } = await api.get('/questions', { params });
    questions.value = (data.data as ApiQuestion[]).map((q) => ({
      ...mapQuestion(q),
      subject: q.subject_code ?? '',
      subjectName: q.subject_name ?? '',
      unit: q.unit_name ?? '',
      examYear: q.attributes?.exam_year ?? null,
    }));
    questionMeta.value = {
      currentPage: data.meta.current_page,
      lastPage: data.meta.last_page,
      total: data.meta.total,
      perPage: data.meta.per_page,
    };
  }

  return {
    subjects,
    current,
    questions,
    questionMeta,
    questionBank,
    getSubject,
    fetchSubjects,
    loadSubject,
    createSubject,
    updateSubject,
    removeSubject,
    addUnit,
    updateUnit,
    deleteUnit,
    createQuestion,
    deleteQuestion,
    fetchQuestions,
  };
});
