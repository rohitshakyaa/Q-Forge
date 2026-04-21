import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { useStorage } from '@vueuse/core';

export interface PaperQuestion {
  no: number;
  text: string;
  marks: number;
  unit: string;
  ai: boolean;
}

export interface PaperSection {
  label: string;
  note: string;
  questions: PaperQuestion[];
}

export interface Paper {
  id: number;
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

const DEFAULT_SECTIONS: PaperSection[] = [
  {
    label: 'Section A — Short Answer',
    note: 'Answer all questions. Each question carries 4 marks.',
    questions: [
      { no: 1, text: 'What is the time complexity of binary search? Justify your answer.', marks: 4, unit: 'Unit 1', ai: false },
      { no: 2, text: 'Define a stable sorting algorithm. Give one example.', marks: 4, unit: 'Unit 1', ai: false },
      { no: 3, text: 'What is a hash collision? State two resolution strategies.', marks: 4, unit: 'Unit 2', ai: false },
      { no: 4, text: 'Explain the concept of amortized time complexity with an example.', marks: 4, unit: 'Unit 2', ai: false },
      { no: 5, text: "What is a minimum spanning tree? State Kruskal's algorithm steps.", marks: 4, unit: 'Unit 3', ai: true },
    ],
  },
  {
    label: 'Section B — Long Answer',
    note: 'Attempt any 3 questions. Each question carries 10 marks.',
    questions: [
      { no: 6, text: 'Explain BFS and DFS algorithms. Compare their time and space complexities with suitable examples.', marks: 10, unit: 'Unit 3', ai: false },
      { no: 7, text: 'Describe the merge sort algorithm. Derive its time complexity using the recurrence relation.', marks: 10, unit: 'Unit 1', ai: false },
      { no: 8, text: 'What is dynamic programming? Solve the 0/1 Knapsack problem using DP with a worked example.', marks: 10, unit: 'Unit 4', ai: false },
    ],
  },
];

const SEED_PAPERS: Paper[] = [
  {
    id: 101,
    name: 'Algorithms Midterm 2024',
    subject: 'CS302',
    marks: 50,
    questions: 18,
    date: 'Apr 18, 2024',
    status: 'exported',
    exports: 2,
    duration: 90,
    sections: DEFAULT_SECTIONS,
  },
  {
    id: 102,
    name: 'Data Structures Quiz 3',
    subject: 'CS301',
    marks: 20,
    questions: 10,
    date: 'Apr 15, 2024',
    status: 'saved',
    exports: 1,
    duration: 30,
    sections: DEFAULT_SECTIONS,
  },
  {
    id: 103,
    name: 'DBMS Final Exam',
    subject: 'CS303',
    marks: 100,
    questions: 32,
    date: 'Apr 10, 2024',
    status: 'draft',
    exports: 3,
    duration: 180,
    sections: DEFAULT_SECTIONS,
  },
  {
    id: 104,
    name: 'Algorithms Quiz 2',
    subject: 'CS302',
    marks: 20,
    questions: 10,
    date: 'Mar 28, 2024',
    status: 'exported',
    exports: 1,
    duration: 30,
    sections: DEFAULT_SECTIONS,
  },
  {
    id: 105,
    name: 'Data Structures Midterm',
    subject: 'CS301',
    marks: 50,
    questions: 18,
    date: 'Mar 15, 2024',
    status: 'exported',
    exports: 2,
    duration: 90,
    sections: DEFAULT_SECTIONS,
  },
];

export const GENERATION_LOGS = [
  'Loading blueprint…',
  'Querying question bank…',
  'Applying exclusion rules (last 2 papers)…',
  'Selecting Section A questions — Unit coverage check…',
  'Found 5 Short Answer questions matching constraints.',
  'Selecting Section B questions — difficulty balance…',
  'AI assisting: Unit 3 has limited eligible questions.',
  'AI suggestion: 1 generated question for Unit 3 (pending review).',
  'All constraints satisfied ✓',
  'Paper assembled successfully — 18 questions, 50 marks.',
];

export const CONSTRAINT_RESULTS: ConstraintResult[] = [
  { label: 'Total marks', expected: '50', got: '50', pass: true },
  { label: 'Unit coverage', expected: '3 units', got: '3 units', pass: true },
  { label: 'Section A (Short)', expected: '5 questions', got: '5 questions', pass: true },
  { label: 'Section B (Long)', expected: '3 questions', got: '3 questions', pass: true },
  { label: 'No repeated questions', expected: 'Last 2 papers excluded', got: '0 repeats', pass: true },
  { label: 'Difficulty balance', expected: 'Easy/Med/Hard', got: '2/3/3', pass: true },
  { label: 'AI assistance used', expected: 'Optional', got: '1 question', pass: null },
];

export const usePapersStore = defineStore('papers', () => {
  const items = useStorage<Paper[]>('qforge-papers', SEED_PAPERS, undefined, {
    mergeDefaults: (s, d) => (s && s.length ? s : d),
  });

  const progress = ref(0);
  const logLines = ref<string[]>([]);
  const constraints = ref<ConstraintResult[]>([]);
  const generating = ref(false);

  const list = computed(() => items.value);
  const recent = computed(() => [...items.value].slice(0, 3));

  const getById = (id: number) => items.value.find((p) => p.id === id) ?? null;

  const startGeneration = (onDone: (paperId: number) => void) => {
    progress.value = 0;
    logLines.value = [];
    constraints.value = [];
    generating.value = true;

    let p = 0;
    let li = 0;
    const iv = setInterval(() => {
      p += Math.random() * 12;
      if (p >= 100) {
        p = 100;
        clearInterval(iv);
        progress.value = 100;
        constraints.value = CONSTRAINT_RESULTS;
        generating.value = false;
        const newPaper: Paper = {
          id: Date.now(),
          name: 'Algorithms Midterm 2024',
          subject: 'CS302',
          marks: 50,
          questions: 18,
          date: 'Today',
          status: 'draft',
          exports: 0,
          duration: 90,
          sections: DEFAULT_SECTIONS,
        };
        items.value = [newPaper, ...items.value];
        onDone(newPaper.id);
        return;
      }
      progress.value = Math.round(p);
      const logIdx = Math.floor(p / 10);
      if (logIdx > li && li < GENERATION_LOGS.length) {
        logLines.value = [...logLines.value, GENERATION_LOGS[li]];
        li++;
      }
    }, 250);
  };

  const resetGeneration = () => {
    progress.value = 0;
    logLines.value = [];
    constraints.value = [];
    generating.value = false;
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
    getById,
    progress,
    logLines,
    constraints,
    generating,
    startGeneration,
    resetGeneration,
    markExported,
  };
});
