import { computed } from 'vue';
import { defineStore } from 'pinia';
import { useStorage } from '@vueuse/core';

export interface CatalogQuestion {
  id: number;
  text: string;
  marks: number;
  type: string;
  difficulty?: 'Easy' | 'Medium' | 'Hard';
  used?: number;
}

export interface CatalogUnit {
  id: number;
  name: string;
  questions: CatalogQuestion[];
}

export interface Subject {
  code: string;
  name: string;
  teachers: number;
  description: string;
  syllabus: string;
  units: CatalogUnit[];
}

export interface CatalogUser {
  name: string;
  email: string;
  role: 'Teacher' | 'Admin';
  subjects: string[];
  status: 'active' | 'inactive';
  lastSeen: string;
}

const SEED_SUBJECTS: Subject[] = [
  {
    code: 'CS301',
    name: 'Data Structures',
    teachers: 3,
    description: 'Fundamental data structures including arrays, linked lists, trees, and graphs.',
    syllabus:
      '## CS301 – Data Structures\n\n**Course Overview:** This course covers fundamental data structures and their applications.\n\n### Unit 1: Arrays & Linked Lists\n- Static and dynamic arrays\n- Singly, doubly, and circular linked lists\n- Stack and Queue implementations\n\n### Unit 2: Trees\n- Binary trees and BST\n- AVL trees, Red-Black trees\n- Heap and Priority Queue\n\n### Unit 3: Graphs\n- Representation (adjacency matrix/list)\n- BFS, DFS traversal\n- Shortest path algorithms\n\n### Unit 4: Hashing\n- Hash functions\n- Collision resolution\n- Applications\n\n### Unit 5: Advanced Structures\n- Tries, Segment Trees\n- Disjoint Sets',
    units: [
      {
        id: 1,
        name: 'Arrays & Linked Lists',
        questions: [
          { id: 1, text: 'What is the time complexity of inserting at the beginning of a linked list?', marks: 4, type: 'Short Answer', difficulty: 'Easy', used: 2 },
          { id: 2, text: 'Compare arrays and linked lists in terms of memory and access time.', marks: 6, type: 'Long Answer', difficulty: 'Medium', used: 1 },
        ],
      },
      {
        id: 2,
        name: 'Trees',
        questions: [
          { id: 3, text: 'Define AVL tree and explain rotation operations.', marks: 8, type: 'Long Answer', difficulty: 'Medium', used: 3 },
          { id: 4, text: 'What is the height of a balanced BST with n nodes?', marks: 4, type: 'Short Answer', difficulty: 'Easy', used: 1 },
        ],
      },
      {
        id: 3,
        name: 'Graphs',
        questions: [{ id: 5, text: 'Explain BFS and DFS with examples.', marks: 10, type: 'Long Answer', difficulty: 'Medium', used: 2 }],
      },
      {
        id: 4,
        name: 'Hashing',
        questions: [{ id: 6, text: 'Describe open addressing for collision resolution.', marks: 5, type: 'Short Answer', difficulty: 'Easy', used: 0 }],
      },
      { id: 5, name: 'Advanced Structures', questions: [] },
    ],
  },
  {
    code: 'CS302',
    name: 'Algorithms',
    teachers: 4,
    description: 'Algorithm design, analysis, and complexity theory.',
    syllabus:
      '## CS302 – Algorithms\n\n**Course Overview:** Design and analysis of algorithms.\n\n### Unit 1: Sorting & Searching\n- QuickSort, MergeSort, HeapSort\n- Binary Search\n\n### Unit 2: Hashing\n- Hash tables and applications\n\n### Unit 3: Graph Algorithms\n- Dijkstra, Bellman-Ford\n- MST: Prim, Kruskal\n\n### Unit 4: Dynamic Programming\n- Knapsack, LCS, Matrix Chain\n\n### Unit 5: Greedy Algorithms\n- Activity Selection, Huffman Coding\n\n### Unit 6: NP-Completeness\n- P vs NP, Reductions',
    units: [
      {
        id: 1,
        name: 'Sorting & Searching',
        questions: [
          { id: 10, text: 'Derive the time complexity of MergeSort.', marks: 8, type: 'Long Answer', difficulty: 'Medium', used: 2 },
          { id: 11, text: 'What is a stable sort? Give an example.', marks: 4, type: 'Short Answer', difficulty: 'Easy', used: 1 },
        ],
      },
      {
        id: 2,
        name: 'Hashing',
        questions: [{ id: 12, text: 'What is a hash collision? Name two resolution techniques.', marks: 5, type: 'Short Answer', difficulty: 'Easy', used: 1 }],
      },
      {
        id: 3,
        name: 'Graph Algorithms',
        questions: [{ id: 13, text: "Explain Dijkstra's algorithm with a worked example.", marks: 12, type: 'Long Answer', difficulty: 'Hard', used: 3 }],
      },
      {
        id: 4,
        name: 'Dynamic Programming',
        questions: [{ id: 14, text: 'Solve the 0/1 Knapsack problem using DP.', marks: 10, type: 'Long Answer', difficulty: 'Hard', used: 2 }],
      },
      { id: 5, name: 'Greedy Algorithms', questions: [] },
      { id: 6, name: 'NP-Completeness', questions: [] },
    ],
  },
  {
    code: 'CS303',
    name: 'Database Management',
    teachers: 2,
    description: 'Relational databases, SQL, normalization, and transactions.',
    syllabus:
      '## CS303 – Database Management\n\n**Course Overview:** Principles of database systems.\n\n### Unit 1: Introduction\n- DBMS concepts and architecture\n- Data models\n\n### Unit 2: Relational Model & SQL\n- ER diagrams\n- SQL: DDL, DML, DCL\n\n### Unit 3: Normalization\n- 1NF, 2NF, 3NF, BCNF\n- Functional dependencies\n\n### Unit 4: Transactions\n- ACID properties\n- Concurrency control\n\n### Unit 5: Indexing\n- B+ trees, Hashing\n- Query optimization',
    units: [
      {
        id: 1,
        name: 'Introduction',
        questions: [{ id: 20, text: 'What is a DBMS? List its advantages over file systems.', marks: 5, type: 'Short Answer', difficulty: 'Easy', used: 2 }],
      },
      {
        id: 2,
        name: 'Relational Model & SQL',
        questions: [
          { id: 21, text: 'Write SQL to find the second highest salary from an Employee table.', marks: 6, type: 'Short Answer', difficulty: 'Medium', used: 4 },
          { id: 22, text: 'Explain ER diagrams with a university example.', marks: 10, type: 'Long Answer', difficulty: 'Medium', used: 3 },
        ],
      },
      {
        id: 3,
        name: 'Normalization',
        questions: [{ id: 23, text: 'Define BCNF and explain with an example.', marks: 8, type: 'Long Answer', difficulty: 'Medium', used: 5 }],
      },
      { id: 4, name: 'Transactions', questions: [] },
      { id: 5, name: 'Indexing', questions: [] },
    ],
  },
];

const SEED_USERS: CatalogUser[] = [
  { name: 'Dr. Sarah Johnson', email: 's.johnson@inst.edu', role: 'Teacher', subjects: ['CS301', 'CS302'], status: 'active', lastSeen: '2m ago' },
  { name: 'Prof. Alex Chen', email: 'a.chen@inst.edu', role: 'Teacher', subjects: ['CS303', 'CS401'], status: 'active', lastSeen: '1h ago' },
  { name: 'Dr. Priya Patel', email: 'p.patel@inst.edu', role: 'Teacher', subjects: ['MA201'], status: 'active', lastSeen: '3h ago' },
  { name: 'Robert Kim', email: 'r.kim@inst.edu', role: 'Admin', subjects: [], status: 'active', lastSeen: '5m ago' },
  { name: 'Maria Santos', email: 'm.santos@inst.edu', role: 'Teacher', subjects: ['CS302'], status: 'inactive', lastSeen: '2d ago' },
];

export const useCatalogStore = defineStore('catalog', () => {
  const subjects = useStorage<Subject[]>('qforge-subjects', SEED_SUBJECTS, undefined, {
    mergeDefaults: (s, d) => (s && s.length ? s : d),
  });
  const users = useStorage<CatalogUser[]>('qforge-users', SEED_USERS, undefined, {
    mergeDefaults: (s, d) => (s && s.length ? s : d),
  });

  const getSubject = (code: string) => subjects.value.find((s) => s.code === code) ?? null;

  const saveSubject = (updated: Subject) => {
    const idx = subjects.value.findIndex((s) => s.code === updated.code);
    if (idx >= 0) {
      subjects.value[idx] = updated;
    } else {
      subjects.value.push(updated);
    }
  };

  const removeSubject = (code: string) => {
    subjects.value = subjects.value.filter((s) => s.code !== code);
  };

  const questionBank = computed(() =>
    subjects.value.flatMap((s) =>
      s.units.flatMap((u) =>
        u.questions.map((q) => ({
          ...q,
          subject: s.code,
          unit: u.name,
        })),
      ),
    ),
  );

  return {
    subjects,
    users,
    questionBank,
    getSubject,
    saveSubject,
    removeSubject,
  };
});
