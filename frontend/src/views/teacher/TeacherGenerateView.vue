<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import {
  QFAIHint,
  QFBadge,
  QFButton,
  QFCard,
  QFEmptyState,
  QFPageHeader,
  QFProgress,
  QFSpinner,
  QFSteps,
} from '../../components/qf';
import { useBlueprintsStore, type Blueprint } from '../../stores/blueprints';
import { usePapersStore } from '../../stores/papers';

const router = useRouter();
const blueprintsStore = useBlueprintsStore();
const papersStore = usePapersStore();

type Phase = 'select' | 'idle' | 'generating' | 'done';
const phase = ref<Phase>('select');
const selectedBP = ref<Blueprint | null>(null);
const bpSearch = ref('');

papersStore.resetGeneration();

const blueprints = computed(() =>
  blueprintsStore.list.filter(
    (b) =>
      !bpSearch.value ||
      b.name.toLowerCase().includes(bpSearch.value.toLowerCase()) ||
      b.subject.toLowerCase().includes(bpSearch.value.toLowerCase()),
  ),
);

const stepIndex = computed(() => {
  if (phase.value === 'select') return 0;
  if (phase.value === 'idle') return 1;
  if (phase.value === 'generating') return 2;
  return 3;
});

const startGeneration = () => {
  phase.value = 'generating';
  papersStore.startGeneration(() => {
    phase.value = 'done';
  });
};

const letter = (i: number) => String.fromCharCode(65 + i);
</script>

<template>
  <div class="qf-content qf-anim-in" style="max-width: 860px">
    <QFPageHeader
      title="Generate Question Paper"
      :subtitle="selectedBP ? `Blueprint: ${selectedBP.name} · ${selectedBP.subject} · ${selectedBP.totalMarks} marks` : 'Select a blueprint to get started'"
      back="Dashboard"
      @back="router.push('/teacher')"
    />

    <div style="margin-bottom: 24px">
      <QFSteps :steps="['Select Blueprint', 'Configure', 'Generate', 'Review']" :current="stepIndex" />
    </div>

    <div v-if="phase === 'select'" class="qf-anim-in">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px">
        <div style="font-family: var(--font-head); font-weight: 600; font-size: 15px">Your Blueprints</div>
        <QFButton variant="secondary" size="sm" @click="router.push('/teacher/blueprint/new')">
          + New Blueprint
        </QFButton>
      </div>

      <div style="display: flex; gap: 10px; margin-bottom: 16px; align-items: center">
        <div style="position: relative; flex: 1; max-width: 340px">
          <span
            style="
              position: absolute;
              left: 12px;
              top: 50%;
              transform: translateY(-50%);
              color: var(--text3);
              font-size: 14px;
              pointer-events: none;
            "
          >⌕</span>
          <input
            v-model="bpSearch"
            class="qf-input"
            placeholder="Search blueprints…"
            style="padding-left: 34px"
          />
        </div>
        <QFButton v-if="bpSearch" variant="ghost" size="sm" @click="bpSearch = ''">Clear</QFButton>
        <span style="margin-left: auto; font-size: 12.5px; color: var(--text3)">
          {{ blueprints.length }} blueprint{{ blueprints.length !== 1 ? 's' : '' }}
        </span>
      </div>

      <QFEmptyState
        v-if="blueprints.length === 0"
        icon="⬢"
        title="No blueprints found"
        :desc="`No blueprints match \u201c${bpSearch}\u201d. Try a different search or create a new blueprint.`"
      >
        <template #action>
          <div style="display: flex; gap: 8px">
            <QFButton variant="ghost" size="sm" @click="bpSearch = ''">Clear search</QFButton>
            <QFButton variant="primary" size="sm" @click="router.push('/teacher/blueprint/new')">
              + New Blueprint
            </QFButton>
          </div>
        </template>
      </QFEmptyState>

      <div
        v-else
        style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 20px"
      >
        <div
          v-for="bp in blueprints"
          :key="bp.id"
          :style="{
            background: 'var(--bg1)',
            border: `2px solid ${selectedBP?.id === bp.id ? 'var(--cyan)' : 'var(--border)'}`,
            borderRadius: 'var(--radius-lg)',
            padding: '18px 20px',
            cursor: 'pointer',
            transition: 'all 0.15s',
            boxShadow: selectedBP?.id === bp.id ? '0 0 20px var(--cyan-glow)' : 'none',
          }"
          @click="selectedBP = bp"
        >
          <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px">
            <div>
              <div style="font-family: var(--font-head); font-weight: 700; font-size: 15px; margin-bottom: 3px">
                {{ bp.name }}
              </div>
              <span
                style="
                  font-family: var(--font-mono);
                  font-size: 11.5px;
                  color: var(--cyan);
                  background: var(--cyan-dim);
                  padding: 2px 7px;
                  border-radius: 6px;
                "
              >{{ bp.subject }}</span>
            </div>
            <div
              v-if="selectedBP?.id === bp.id"
              style="
                width: 22px;
                height: 22px;
                background: var(--cyan);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #070a10;
                font-size: 12px;
                font-weight: 700;
                flex-shrink: 0;
              "
            >✓</div>
          </div>
          <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px">
            <span class="qf-chip">{{ bp.questions }} questions</span>
            <span class="qf-chip">{{ bp.totalMarks }} marks</span>
            <span class="qf-chip">{{ bp.units }} units</span>
            <span class="qf-chip">Exclude last {{ bp.exclusionRules.lastNPapers }} papers</span>
          </div>
          <div style="display: flex; flex-direction: column; gap: 3px; margin-bottom: 10px">
            <div
              v-for="(s, i) in bp.sections"
              :key="s.id"
              style="font-size: 12px; color: var(--text3); display: flex; align-items: center; gap: 6px"
            >
              <span style="color: var(--cyan); font-weight: 700; font-family: var(--font-mono)">{{ letter(i) }}</span>
              <span>{{ s.count }} {{ s.type }} ({{ s.marksEach }}M each)</span>
            </div>
          </div>
          <div
            style="
              font-size: 11.5px;
              color: var(--text3);
              border-top: 1px solid var(--border);
              padding-top: 8px;
              margin-top: 4px;
            "
          >Last used: {{ bp.lastUsed }}</div>
        </div>
      </div>
      <div style="display: flex; justify-content: space-between; align-items: center">
        <QFAIHint v-if="selectedBP">
          Blueprint <strong style="color: var(--ai)">{{ selectedBP.name }}</strong> selected —
          {{ selectedBP.questions }} questions from {{ selectedBP.subject }}, excluding last
          {{ selectedBP.exclusionRules.lastNPapers }} papers.
        </QFAIHint>
        <div v-else style="color: var(--text3); font-size: 13px">Select a blueprint above to continue</div>
        <QFButton
          variant="primary"
          :disabled="!selectedBP"
          style="margin-left: 16px; flex-shrink: 0"
          @click="phase = 'idle'"
        >Configure →</QFButton>
      </div>
    </div>

    <QFCard v-else-if="phase === 'idle'" glow class="qf-anim-in">
      <div class="qf-card-body" style="text-align: center; padding: 40px 32px">
        <div style="font-size: 48px; margin-bottom: 16px; color: var(--cyan)">✦</div>
        <div style="font-family: var(--font-head); font-size: 22px; font-weight: 700; margin-bottom: 8px">
          Ready to generate
        </div>
        <div style="font-family: var(--font-head); font-size: 15px; color: var(--cyan); margin-bottom: 10px">
          {{ selectedBP?.name }}
        </div>
        <p style="color: var(--text2); font-size: 14px; margin: 0 auto 24px; max-width: 440px; line-height: 1.7">
          The blueprint engine will select questions from the bank, apply all constraint rules, and fill any gaps with AI assistance if enabled.
        </p>
        <div
          style="display: flex; gap: 12px; justify-content: center; margin-bottom: 24px; flex-wrap: wrap"
        >
          <div
            v-for="item in [
              { value: selectedBP?.questions ?? 18, label: 'Questions' },
              { value: selectedBP?.totalMarks ?? 50, label: 'Total Marks' },
              { value: selectedBP?.units ?? 3, label: 'Units' },
              { value: selectedBP?.exclusionRules.lastNPapers ?? 2, label: 'Papers Excluded' },
            ]"
            :key="item.label"
            style="
              background: var(--bg2);
              border-radius: var(--radius);
              padding: 12px 20px;
              text-align: center;
              min-width: 90px;
            "
          >
            <div
              style="
                font-family: var(--font-head);
                font-size: 22px;
                font-weight: 700;
                color: var(--cyan);
              "
            >{{ item.value }}</div>
            <div style="font-size: 11.5px; color: var(--text3); margin-top: 2px">{{ item.label }}</div>
          </div>
        </div>
        <div style="display: flex; gap: 10px; justify-content: center">
          <QFButton variant="secondary" @click="phase = 'select'">← Change Blueprint</QFButton>
          <QFButton
            variant="primary"
            size="lg"
            style="padding: 12px 32px; font-size: 15px"
            @click="startGeneration"
          >
            <template #icon><span>✦</span></template>
            Generate Paper
          </QFButton>
        </div>
      </div>
    </QFCard>

    <div
      v-else-if="phase === 'generating'"
      class="qf-anim-in"
      style="display: flex; flex-direction: column; gap: 16px"
    >
      <QFCard>
        <div class="qf-card-body">
          <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px">
            <QFSpinner />
            <span
              class="qf-ai-working"
              style="font-family: var(--font-head); font-weight: 600; font-size: 15px; color: var(--ai)"
            >AI generating your paper…</span>
            <span
              style="
                margin-left: auto;
                font-family: var(--font-mono);
                color: var(--cyan);
                font-weight: 700;
              "
            >{{ papersStore.progress }}%</span>
          </div>
          <QFProgress :value="papersStore.progress" ai />
        </div>
      </QFCard>
      <QFCard>
        <div class="qf-card-body">
          <div
            style="
              font-family: var(--font-mono);
              font-size: 12px;
              color: var(--text2);
              display: flex;
              flex-direction: column;
              gap: 6px;
              max-height: 200px;
              overflow-y: auto;
            "
          >
            <div
              v-for="(l, i) in papersStore.logLines"
              :key="i"
              :style="{
                display: 'flex',
                gap: '10px',
                alignItems: 'center',
                color: l.includes('AI') ? 'var(--ai)' : l.includes('✓') ? 'var(--success)' : 'var(--text2)',
              }"
            >
              <span style="color: var(--text3); flex-shrink: 0">{{ String(i + 1).padStart(2, '0') }}</span>
              <span>{{ l }}</span>
            </div>
            <div
              v-if="papersStore.generating"
              style="display: flex; gap: 8px; align-items: center; color: var(--text3)"
            >
              <QFSpinner :size="12" />
              <span>Processing…</span>
            </div>
          </div>
        </div>
      </QFCard>
    </div>

    <div
      v-else
      class="qf-anim-in"
      style="display: flex; flex-direction: column; gap: 16px"
    >
      <div
        style="
          background: var(--success-dim);
          border: 1px solid var(--success);
          border-radius: var(--radius-lg);
          padding: 16px 20px;
          display: flex;
          gap: 12px;
          align-items: center;
        "
      >
        <span style="font-size: 24px">✓</span>
        <div>
          <div style="font-weight: 600; color: var(--success); margin-bottom: 2px">
            Paper generated successfully
          </div>
          <div style="font-size: 13px; color: var(--text2)">
            All 6 constraints satisfied · 1 AI-assisted question pending review
          </div>
        </div>
        <div style="margin-left: auto; display: flex; gap: 8px">
          <QFButton variant="secondary" @click="router.push(`/teacher/paper/${papersStore.list[0]?.id}`)">
            Preview Paper
          </QFButton>
          <QFButton variant="primary" @click="router.push(`/teacher/paper/${papersStore.list[0]?.id}`)">
            View &amp; Export →
          </QFButton>
        </div>
      </div>
      <QFCard>
        <div class="qf-card-header" style="padding-bottom: 14px">
          <span style="font-family: var(--font-head); font-weight: 600">Constraint Report</span>
        </div>
        <table class="qf-table">
          <thead>
            <tr>
              <th style="padding-left: 20px">Constraint</th>
              <th>Expected</th>
              <th>Result</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(c, i) in papersStore.constraints" :key="i">
              <td style="padding-left: 20px; font-weight: 500">{{ c.label }}</td>
              <td style="color: var(--text3); font-size: 13px">{{ c.expected }}</td>
              <td style="font-family: var(--font-mono); font-size: 13px">{{ c.got }}</td>
              <td>
                <QFBadge v-if="c.pass === true" variant="success">✓ Pass</QFBadge>
                <QFBadge v-else-if="c.pass === false" variant="danger">✕ Fail</QFBadge>
                <QFBadge v-else variant="ai">✦ AI Assisted</QFBadge>
              </td>
            </tr>
          </tbody>
        </table>
      </QFCard>
    </div>
  </div>
</template>
