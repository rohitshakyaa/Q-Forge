<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import {
  QFAIHint,
  QFBadge,
  QFButton,
  QFCard,
  QFEmptyState,
  QFInput,
  QFPageHeader,
  QFSelect,
} from '../../components/qf';

const router = useRouter();

type Status = 'pending' | 'approved' | 'rejected';
interface ExtractedQuestion {
  id: number;
  text: string;
  unit: string;
  marks: number;
  type: string;
  status: Status;
  ai_conf: number;
}

const questions = ref<ExtractedQuestion[]>([
  { id: 1, text: 'Explain the difference between BFS and DFS with time complexity analysis.', unit: 'Unit 3 – Graph Algorithms', marks: 10, type: 'Long Answer', status: 'pending', ai_conf: 0.97 },
  { id: 2, text: 'What is a hash collision? Name two resolution techniques.', unit: 'Unit 2 – Hashing', marks: 5, type: 'Short Answer', status: 'approved', ai_conf: 0.99 },
  { id: 3, text: "Implement Dijkstra's algorithm for finding shortest paths.", unit: 'Unit 3 – Graph Algorithms', marks: 15, type: 'Programming', status: 'pending', ai_conf: 0.88 },
  { id: 4, text: 'Define a B-tree. What are its properties?', unit: 'Unit 4 – Trees', marks: 5, type: 'Short Answer', status: 'rejected', ai_conf: 0.91 },
  { id: 5, text: 'Compare quicksort and mergesort in terms of average and worst-case complexity.', unit: 'Unit 1 – Sorting', marks: 8, type: 'Long Answer', status: 'pending', ai_conf: 0.95 },
]);

const selected = ref<ExtractedQuestion | null>(null);
const filter = ref<'all' | Status>('all');

const act = (id: number, status: Status) => {
  questions.value = questions.value.map((x) => (x.id === id ? { ...x, status } : x));
  if (selected.value?.id === id) selected.value = { ...selected.value, status };
};

const filtered = computed(() =>
  filter.value === 'all' ? questions.value : questions.value.filter((q) => q.status === filter.value),
);
const pending = computed(() => questions.value.filter((q) => q.status === 'pending').length);
</script>

<template>
  <div class="qf-content qf-anim-in">
    <QFPageHeader
      title="Question Extraction Review"
      subtitle="NetworkingSyllabus.pdf · 31 questions extracted"
      back="Upload"
      @back="router.push('/admin/upload')"
    >
      <template #actions>
        <QFButton variant="secondary">Reject All</QFButton>
        <QFButton variant="primary">Approve All ({{ pending }})</QFButton>
      </template>
    </QFPageHeader>

    <QFAIHint style="margin-bottom: 20px">
      <strong style="color: var(--ai)">AI extracted 31 questions</strong> with 93% average confidence. 3 questions need manual review — low confidence scores flagged below.
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

    <div style="display: grid; grid-template-columns: 1fr 360px; gap: 20px">
      <div style="display: flex; flex-direction: column; gap: 10px">
        <div
          v-for="q in filtered"
          :key="q.id"
          :style="{
            background: 'var(--bg1)',
            border: `1px solid ${selected?.id === q.id ? 'var(--cyan)' : 'var(--border)'}`,
            borderRadius: 'var(--radius-lg)',
            padding: '14px 16px',
            cursor: 'pointer',
            transition: 'all 0.15s',
          }"
          @click="selected = q"
        >
          <div style="display: flex; align-items: flex-start; gap: 12px">
            <div style="flex: 1">
              <p style="font-size: 13.5px; line-height: 1.6; margin-bottom: 8px; color: var(--text)">
                {{ q.text }}
              </p>
              <div style="display: flex; gap: 8px; flex-wrap: wrap">
                <QFBadge variant="neutral">{{ q.unit }}</QFBadge>
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
              <template v-if="q.status === 'pending'">
                <QFButton variant="primary" size="sm" @click.stop="act(q.id, 'approved')">
                  ✓ Approve
                </QFButton>
                <QFButton variant="danger" size="sm" @click.stop="act(q.id, 'rejected')">
                  ✕ Reject
                </QFButton>
              </template>
              <QFBadge
                v-else
                :variant="q.status === 'approved' ? 'success' : 'danger'"
                dot
              >{{ q.status === 'approved' ? 'Approved' : 'Rejected' }}</QFBadge>
            </div>
          </div>
        </div>
      </div>

      <div>
        <QFCard v-if="selected" style="position: sticky; top: 20px">
          <div class="qf-card-body">
            <div style="font-family: var(--font-head); font-weight: 600; margin-bottom: 14px">
              Edit Question
            </div>
            <div style="display: flex; flex-direction: column; gap: 14px">
              <QFInput
                :model-value="selected.text"
                label="Question text"
                type="textarea"
                :rows="4"
                @update:model-value="(v) => selected && (selected.text = v)"
              />
              <QFSelect
                v-model="selected.unit"
                label="Unit"
                :options="['Unit 1 – Sorting', 'Unit 2 – Hashing', 'Unit 3 – Graph Algorithms', 'Unit 4 – Trees']"
              />
              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px">
                <QFInput
                  :model-value="selected.marks"
                  label="Marks"
                  type="number"
                  @update:model-value="(v) => selected && (selected.marks = +v)"
                />
                <QFSelect
                  v-model="selected.type"
                  label="Type"
                  :options="['Short Answer', 'Long Answer', 'MCQ', 'Programming']"
                />
              </div>
              <div style="display: flex; gap: 8px">
                <QFButton variant="primary" block @click="act(selected.id, 'approved')">
                  ✓ Approve
                </QFButton>
                <QFButton variant="danger" block @click="act(selected.id, 'rejected')">
                  ✕ Reject
                </QFButton>
              </div>
            </div>
          </div>
        </QFCard>
        <QFCard v-else>
          <QFEmptyState
            icon="◈"
            title="Select a question"
            desc="Click any question to review and edit its details before approving."
          />
        </QFCard>
      </div>
    </div>
  </div>
</template>
