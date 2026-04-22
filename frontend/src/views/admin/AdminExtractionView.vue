<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import {
  QFAIHint,
  QFBadge,
  QFButton,
  QFCard,
  QFInput,
  QFPageHeader,
  QFSelect,
} from '../../components/qf';

const route = useRoute();

type Status = 'pending' | 'approved' | 'rejected';

interface ExtractedQuestion {
  id: number;
  text: string;
  units: string[];
  marks: number;
  type: string;
  status: Status;
  ai_conf: number;
  editing?: boolean;
  _snapshot?: Omit<ExtractedQuestion, '_snapshot' | 'editing'>;
}

const AVAILABLE_UNITS = [
  'Unit 1 – Sorting',
  'Unit 2 – Hashing',
  'Unit 3 – Graph Algorithms',
  'Unit 4 – Trees',
];

const QUESTION_TYPES = ['Short Answer', 'Long Answer', 'MCQ', 'Programming'];

const sourceFile = (route.query.file as string) || 'NetworkingSyllabus.pdf';
const sourceSubject = (route.query.subject as string) || 'CS302';
const sourceYear = (route.query.year as string) || '2022';

const questions = ref<ExtractedQuestion[]>([
  {
    id: 1,
    text: 'Explain the difference between BFS and DFS with time complexity analysis.',
    units: ['Unit 3 – Graph Algorithms'],
    marks: 10,
    type: 'Long Answer',
    status: 'pending',
    ai_conf: 0.97,
  },
  {
    id: 2,
    text: 'What is a hash collision? Name two resolution techniques.',
    units: ['Unit 2 – Hashing'],
    marks: 5,
    type: 'Short Answer',
    status: 'approved',
    ai_conf: 0.99,
  },
  {
    id: 3,
    text: "Implement Dijkstra's algorithm and analyse its running time using a priority queue.",
    units: ['Unit 3 – Graph Algorithms', 'Unit 1 – Sorting'],
    marks: 15,
    type: 'Programming',
    status: 'pending',
    ai_conf: 0.88,
  },
  {
    id: 4,
    text: 'Define a B-tree. What are its properties?',
    units: ['Unit 4 – Trees'],
    marks: 5,
    type: 'Short Answer',
    status: 'rejected',
    ai_conf: 0.91,
  },
  {
    id: 5,
    text: 'Compare quicksort and mergesort in terms of average and worst-case complexity.',
    units: ['Unit 1 – Sorting'],
    marks: 8,
    type: 'Long Answer',
    status: 'pending',
    ai_conf: 0.95,
  },
  {
    id: 6,
    text: 'Compare AVL trees and B-trees — when is each preferred for indexing?',
    units: ['Unit 4 – Trees', 'Unit 2 – Hashing'],
    marks: 10,
    type: 'Long Answer',
    status: 'pending',
    ai_conf: 0.84,
  },
]);

const filter = ref<'all' | Status>('all');

const filtered = computed(() =>
  filter.value === 'all'
    ? questions.value
    : questions.value.filter((q) => q.status === filter.value),
);

const pending = computed(() => questions.value.filter((q) => q.status === 'pending').length);

const questionsByUnit = computed<Record<string, ExtractedQuestion[]>>(() => {
  const map: Record<string, ExtractedQuestion[]> = {};
  for (const unit of AVAILABLE_UNITS) map[unit] = [];
  for (const q of filtered.value) {
    for (const u of q.units) {
      if (!map[u]) map[u] = [];
      map[u].push(q);
    }
  }
  return map;
});

const unlinkedQuestions = computed(() => filtered.value.filter((q) => q.units.length === 0));

const act = (id: number, status: Status) => {
  questions.value = questions.value.map((x) => (x.id === id ? { ...x, status } : x));
};

const approveAllPending = () => {
  questions.value = questions.value.map((q) => (q.status === 'pending' ? { ...q, status: 'approved' } : q));
};

const rejectAllPending = () => {
  questions.value = questions.value.map((q) => (q.status === 'pending' ? { ...q, status: 'rejected' } : q));
};

const beginEdit = (id: number) => {
  const q = questions.value.find((x) => x.id === id);
  if (!q) return;
  const { editing: _e, _snapshot: _s, ...rest } = q;
  q._snapshot = { ...rest, units: [...rest.units] };
  q.editing = true;
};

const cancelEdit = (id: number) => {
  const idx = questions.value.findIndex((x) => x.id === id);
  if (idx < 0) return;
  const q = questions.value[idx];
  if (q._snapshot) {
    questions.value[idx] = { ...q._snapshot };
  } else {
    q.editing = false;
  }
};

const saveEdit = (id: number) => {
  const q = questions.value.find((x) => x.id === id);
  if (!q) return;
  q.editing = false;
  delete q._snapshot;
};

const toggleUnit = (q: ExtractedQuestion, unit: string) => {
  if (q.units.includes(unit)) {
    q.units = q.units.filter((u) => u !== unit);
  } else {
    q.units = [...q.units, unit];
  }
};

const statusVariant = (s: Status): 'success' | 'danger' | 'warn' =>
  s === 'approved' ? 'success' : s === 'rejected' ? 'danger' : 'warn';
</script>

<template>
  <div class="qf-content qf-anim-in">
    <QFPageHeader
      title="Question Extraction Review"
      :subtitle="`${sourceFile} · ${sourceSubject} · Year ${sourceYear} · ${questions.length} questions extracted`"
      :breadcrumbs="[
        { label: 'Dashboard', to: '/admin' },
        { label: 'Past Papers', to: '/admin/upload' },
        { label: 'Question Extraction Review' },
      ]"
    >
      <template #actions>
        <QFButton variant="secondary" @click="rejectAllPending">Reject All</QFButton>
        <QFButton variant="primary" @click="approveAllPending">Approve All ({{ pending }})</QFButton>
      </template>
    </QFPageHeader>

    <QFAIHint style="margin-bottom: 20px">
      <strong style="color: var(--ai)">AI extracted {{ questions.length }} questions</strong>
      and grouped them by unit. Questions tagged to multiple units appear in each unit with a
      <span style="color: var(--ai)">🔗 linked</span> indicator — editing one updates all instances.
    </QFAIHint>

    <div style="margin-bottom: 20px">
      <div class="qf-tabs" style="display: inline-flex">
        <div
          v-for="f in (['all', 'pending', 'approved', 'rejected'] as const)"
          :key="f"
          :class="['qf-tab', filter === f && 'active']"
          style="text-transform: capitalize"
          @click="filter = f"
        >
          {{ f }}
          <template v-if="f === 'all'">({{ questions.length }})</template>
          <template v-else-if="f === 'pending'">({{ pending }})</template>
        </div>
      </div>
    </div>

    <div style="display: flex; flex-direction: column; gap: 20px">
      <section v-for="unit in AVAILABLE_UNITS" :key="unit">
        <div
          style="
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding: 0 4px;
          "
        >
          <div style="display: flex; align-items: center; gap: 10px">
            <span
              style="
                font-family: var(--font-head);
                font-weight: 600;
                font-size: 14px;
                color: var(--text);
              "
            >{{ unit }}</span>
            <QFBadge variant="neutral">{{ questionsByUnit[unit]?.length ?? 0 }} questions</QFBadge>
          </div>
        </div>

        <div
          v-if="!questionsByUnit[unit] || questionsByUnit[unit].length === 0"
          style="
            padding: 16px;
            text-align: center;
            font-size: 12.5px;
            color: var(--text3);
            background: var(--bg1);
            border: 1px dashed var(--border);
            border-radius: var(--radius-lg);
          "
        >
          No questions tagged to this unit yet.
        </div>

        <div v-else style="display: flex; flex-direction: column; gap: 10px">
          <div
            v-for="q in questionsByUnit[unit]"
            :key="`${unit}-${q.id}`"
            :style="{
              background: 'var(--bg1)',
              border: `1px solid ${q.editing ? 'var(--cyan)' : 'var(--border)'}`,
              borderRadius: 'var(--radius-lg)',
              padding: '14px 16px',
              transition: 'all 0.15s',
            }"
          >
            <!-- view mode -->
            <div v-if="!q.editing" style="display: flex; align-items: flex-start; gap: 12px">
              <div style="flex: 1; min-width: 0">
                <p style="font-size: 13.5px; line-height: 1.6; margin-bottom: 8px; color: var(--text)">
                  {{ q.text }}
                </p>
                <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center">
                  <QFBadge
                    v-for="u in q.units"
                    :key="u"
                    :variant="u === unit ? 'cyan' : 'neutral'"
                  >{{ u === unit ? u : `↗ ${u}` }}</QFBadge>
                  <QFBadge v-if="q.units.length > 1" variant="ai" dot>
                    🔗 linked · {{ q.units.length }} units
                  </QFBadge>
                  <QFBadge variant="neutral">{{ q.marks }} marks</QFBadge>
                  <QFBadge variant="neutral">{{ q.type }}</QFBadge>
                  <span
                    :style="{
                      fontFamily: 'var(--font-mono)',
                      fontSize: '11px',
                      color: q.ai_conf > 0.9 ? 'var(--success)' : 'var(--warn)',
                      background: q.ai_conf > 0.9 ? 'var(--success-dim)' : 'var(--warn-dim)',
                      padding: '2px 7px',
                      borderRadius: '10px',
                    }"
                  >AI {{ Math.round(q.ai_conf * 100) }}%</span>
                </div>
              </div>
              <div style="display: flex; flex-direction: column; gap: 6px; flex-shrink: 0">
                <QFButton variant="secondary" size="sm" @click="beginEdit(q.id)">Edit</QFButton>
                <template v-if="q.status === 'pending'">
                  <QFButton variant="primary" size="sm" @click="act(q.id, 'approved')">
                    ✓ Approve
                  </QFButton>
                  <QFButton variant="danger" size="sm" @click="act(q.id, 'rejected')">
                    ✕ Reject
                  </QFButton>
                </template>
                <QFBadge v-else :variant="statusVariant(q.status)" dot>
                  {{ q.status === 'approved' ? 'Approved' : 'Rejected' }}
                </QFBadge>
              </div>
            </div>

            <!-- edit mode -->
            <div v-else style="display: flex; flex-direction: column; gap: 14px">
              <QFInput
                :model-value="q.text"
                label="Question text"
                type="textarea"
                :rows="3"
                @update:model-value="(v) => (q.text = String(v))"
              />

              <div>
                <label
                  class="qf-label"
                  style="display: block; margin-bottom: 6px"
                >Units ({{ q.units.length }} selected)</label>
                <div style="display: flex; flex-wrap: wrap; gap: 6px">
                  <button
                    v-for="u in AVAILABLE_UNITS"
                    :key="u"
                    type="button"
                    :style="{
                      padding: '5px 10px',
                      borderRadius: '999px',
                      fontSize: '12px',
                      fontFamily: 'var(--font-ui)',
                      cursor: 'pointer',
                      border: `1px solid ${q.units.includes(u) ? 'var(--cyan)' : 'var(--border2)'}`,
                      background: q.units.includes(u) ? 'var(--cyan-dim)' : 'var(--bg2)',
                      color: q.units.includes(u) ? 'var(--cyan)' : 'var(--text2)',
                      transition: 'all 0.12s',
                    }"
                    @click="toggleUnit(q, u)"
                  >
                    {{ q.units.includes(u) ? '✓ ' : '+ ' }}{{ u }}
                  </button>
                </div>
                <div
                  v-if="q.units.length > 1"
                  style="font-size: 11.5px; color: var(--ai); margin-top: 6px"
                >
                  🔗 This question will appear in all {{ q.units.length }} selected units.
                </div>
                <div
                  v-else-if="q.units.length === 0"
                  style="font-size: 11.5px; color: var(--warn); margin-top: 6px"
                >
                  ⚠ No unit selected — question will not appear in any unit section.
                </div>
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                <QFInput
                  :model-value="q.marks"
                  label="Marks"
                  type="number"
                  @update:model-value="(v) => (q.marks = Number(v) || 0)"
                />
                <QFSelect
                  :model-value="q.type"
                  label="Type"
                  :options="QUESTION_TYPES"
                  @update:model-value="(v) => (q.type = v)"
                />
              </div>

              <div style="display: flex; gap: 8px; justify-content: flex-end">
                <QFButton variant="secondary" size="sm" @click="cancelEdit(q.id)">Cancel</QFButton>
                <QFButton variant="primary" size="sm" @click="saveEdit(q.id)">Save changes</QFButton>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section v-if="unlinkedQuestions.length > 0">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px; padding: 0 4px">
          <span
            style="
              font-family: var(--font-head);
              font-weight: 600;
              font-size: 14px;
              color: var(--warn);
            "
          >⚠ Unassigned</span>
          <QFBadge variant="warn">{{ unlinkedQuestions.length }} questions</QFBadge>
          <span style="font-size: 12px; color: var(--text3)">
            AI couldn't confidently tag these to a unit — assign one via Edit.
          </span>
        </div>
        <QFCard>
          <div class="qf-card-body" style="font-size: 12.5px; color: var(--text3)">
            {{ unlinkedQuestions.length }} question(s) have no unit assigned. Use the filter tabs
            above and click Edit on any question to add units.
          </div>
        </QFCard>
      </section>
    </div>
  </div>
</template>
