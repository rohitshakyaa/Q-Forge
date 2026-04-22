import { computed } from 'vue';
import { defineStore } from 'pinia';
import { useStorage } from '@vueuse/core';

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
  exclusionRules: { lastNPapers: number; reuseThreshold: number };
  aiAssist: boolean;
  lastUsed: string;
}

const SEED: Blueprint[] = [
  {
    id: 1,
    name: 'Standard Midterm',
    subject: 'CS302',
    totalMarks: 50,
    duration: 90,
    questions: 20,
    units: 3,
    sections: [
      { id: 1, name: 'Section A — Short Answer', type: 'Short Answer', count: 5, marksEach: 4, mandatory: true },
      { id: 2, name: 'Section B — Long Answer', type: 'Long Answer', count: 3, marksEach: 10, mandatory: false },
    ],
    unitRules: { 'Unit 1': true, 'Unit 2': true, 'Unit 3': true, 'Unit 4': false, 'Unit 5': false },
    unitAllocations: {
      'Unit 1': [{ marks: 4, count: 2 }, { marks: 10, count: 1 }],
      'Unit 2': [{ marks: 4, count: 2 }, { marks: 10, count: 1 }],
      'Unit 3': [{ marks: 4, count: 1 }, { marks: 10, count: 1 }],
    },
    exclusionRules: { lastNPapers: 2, reuseThreshold: 3 },
    aiAssist: true,
    lastUsed: 'Apr 10, 2024',
  },
  {
    id: 2,
    name: 'Quick Quiz',
    subject: 'CS301',
    totalMarks: 20,
    duration: 30,
    questions: 10,
    units: 2,
    sections: [
      { id: 1, name: 'Section A — MCQ', type: 'MCQ', count: 5, marksEach: 1, mandatory: true },
      { id: 2, name: 'Section B — Short Answer', type: 'Short Answer', count: 5, marksEach: 3, mandatory: false },
    ],
    unitRules: { 'Unit 1': true, 'Unit 2': true, 'Unit 3': false, 'Unit 4': false, 'Unit 5': false },
    unitAllocations: {
      'Unit 1': [{ marks: 1, count: 3 }, { marks: 3, count: 2 }],
      'Unit 2': [{ marks: 1, count: 2 }, { marks: 3, count: 3 }],
    },
    exclusionRules: { lastNPapers: 1, reuseThreshold: 2 },
    aiAssist: true,
    lastUsed: 'Apr 15, 2024',
  },
  {
    id: 3,
    name: 'Comprehensive Final',
    subject: 'CS303',
    totalMarks: 100,
    duration: 180,
    questions: 35,
    units: 6,
    sections: [
      { id: 1, name: 'Section A — MCQ', type: 'MCQ', count: 10, marksEach: 2, mandatory: true },
      { id: 2, name: 'Section B — Short Answer', type: 'Short Answer', count: 8, marksEach: 5, mandatory: true },
      { id: 3, name: 'Section C — Long Answer', type: 'Long Answer', count: 4, marksEach: 10, mandatory: false },
    ],
    unitRules: {
      'Unit 1': true,
      'Unit 2': true,
      'Unit 3': true,
      'Unit 4': true,
      'Unit 5': true,
      'Unit 6': true,
    },
    unitAllocations: {
      'Unit 1': [{ marks: 2, count: 2 }, { marks: 5, count: 1 }],
      'Unit 2': [{ marks: 2, count: 2 }, { marks: 5, count: 2 }],
      'Unit 3': [{ marks: 2, count: 2 }, { marks: 5, count: 1 }, { marks: 10, count: 1 }],
      'Unit 4': [{ marks: 2, count: 1 }, { marks: 5, count: 2 }, { marks: 10, count: 1 }],
      'Unit 5': [{ marks: 2, count: 2 }, { marks: 5, count: 1 }, { marks: 10, count: 1 }],
      'Unit 6': [{ marks: 2, count: 1 }, { marks: 5, count: 1 }, { marks: 10, count: 1 }],
    },
    exclusionRules: { lastNPapers: 3, reuseThreshold: 4 },
    aiAssist: true,
    lastUsed: 'Mar 20, 2024',
  },
  {
    id: 4,
    name: 'Algorithms Midterm',
    subject: 'CS302',
    totalMarks: 50,
    duration: 90,
    questions: 18,
    units: 3,
    sections: [
      { id: 1, name: 'Section A — Short Answer', type: 'Short Answer', count: 5, marksEach: 4, mandatory: true },
      { id: 2, name: 'Section B — Long Answer', type: 'Long Answer', count: 3, marksEach: 10, mandatory: false },
    ],
    unitRules: { 'Unit 1': true, 'Unit 2': true, 'Unit 3': true, 'Unit 4': false, 'Unit 5': false },
    unitAllocations: {
      'Unit 1': [{ marks: 4, count: 2 }, { marks: 10, count: 1 }],
      'Unit 2': [{ marks: 4, count: 2 }, { marks: 10, count: 1 }],
      'Unit 3': [{ marks: 4, count: 1 }, { marks: 10, count: 1 }],
    },
    exclusionRules: { lastNPapers: 2, reuseThreshold: 3 },
    aiAssist: false,
    lastUsed: 'Apr 18, 2024',
  },
];

export const useBlueprintsStore = defineStore('blueprints', () => {
  const items = useStorage<Blueprint[]>('qforge-blueprints', SEED, undefined, {
    mergeDefaults: (s, d) => (s && s.length ? s : d),
  });

  const list = computed(() => items.value);

  const getById = (id: number) => items.value.find((b) => b.id === id) ?? null;

  const save = (bp: Blueprint) => {
    const idx = items.value.findIndex((b) => b.id === bp.id);
    const normalized: Blueprint = {
      ...bp,
      questions: bp.sections.reduce((s, x) => s + x.count, 0),
      units: Object.values(bp.unitRules).filter(Boolean).length,
    };
    if (idx >= 0) {
      items.value[idx] = normalized;
    } else {
      items.value.push(normalized);
    }
    return normalized;
  };

  const remove = (id: number) => {
    items.value = items.value.filter((b) => b.id !== id);
  };

  const blank = (): Blueprint => ({
    id: Date.now(),
    name: '',
    subject: 'CS302',
    totalMarks: 50,
    duration: 90,
    questions: 0,
    units: 0,
    sections: [
      { id: 1, name: 'Section A — Short Answer', type: 'Short Answer', count: 5, marksEach: 4, mandatory: true },
      { id: 2, name: 'Section B — Long Answer', type: 'Long Answer', count: 3, marksEach: 10, mandatory: false },
    ],
    unitRules: { 'Unit 1': true, 'Unit 2': true, 'Unit 3': true, 'Unit 4': false, 'Unit 5': false },
    unitAllocations: { 'Unit 1': [], 'Unit 2': [], 'Unit 3': [] },
    exclusionRules: { lastNPapers: 2, reuseThreshold: 3 },
    aiAssist: true,
    lastUsed: 'Never',
  });

  return { list, getById, save, remove, blank };
});
