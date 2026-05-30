<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
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

onMounted(() => blueprintsStore.fetch());

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

const unitBreakdown = (bp: Blueprint) =>
  Object.entries(bp.unitRules)
    .filter(([, active]) => active)
    .map(([unit]) => {
      const allocs = bp.unitAllocations?.[unit] ?? [];
      const qs = allocs.reduce((s, a) => s + (a.count || 0), 0);
      const marks = allocs.reduce((s, a) => s + (a.count || 0) * (a.marks || 0), 0);
      return { unit, qs, marks, allocs };
    });
</script>

<template>
  <div class="qf-content qf-anim-in">
    <QFPageHeader
      title="Generate Question Paper"
      :subtitle="selectedBP ? `Blueprint: ${selectedBP.name} · ${selectedBP.subject} · ${selectedBP.totalMarks} marks` : 'Select a blueprint to get started'"
      :breadcrumbs="[
        { label: 'Dashboard', to: '/teacher' },
        { label: 'Generate Paper' },
      ]"
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
        class="grid grid-cols-1 md:grid-cols-2 gap-3.5 mb-5"
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
            v-if="unitBreakdown(bp).length"
            style="
              background: var(--bg2);
              border-radius: 8px;
              padding: 8px 10px;
              margin-bottom: 10px;
              display: flex;
              flex-direction: column;
              gap: 4px;
            "
          >
            <div
              style="
                font-size: 10.5px;
                color: var(--text3);
                font-weight: 600;
                letter-spacing: 0.04em;
                text-transform: uppercase;
              "
            >Unit Allocation</div>
            <div
              v-for="row in unitBreakdown(bp)"
              :key="row.unit"
              style="display: flex; align-items: center; gap: 6px; font-size: 11.5px"
            >
              <span style="color: var(--indigo); font-weight: 600">{{ row.unit }}</span>
              <span
                v-if="row.qs === 0"
                style="color: var(--text3); font-style: italic; font-size: 11px"
              >no allocation</span>
              <span
                v-else
                style="
                  margin-left: auto;
                  font-family: var(--font-mono);
                  color: var(--text2);
                  font-size: 11px;
                "
              >
                <template v-for="(a, ai) in row.allocs" :key="ai">
                  <span v-if="ai > 0" style="color: var(--text3)"> · </span>{{ a.count }}×{{ a.marks }}M
                </template>
              </span>
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
        <div
          v-if="selectedBP && unitBreakdown(selectedBP).length"
          style="
            background: var(--bg2);
            border-radius: var(--radius);
            padding: 14px 16px;
            margin: 0 auto 24px;
            max-width: 520px;
            text-align: left;
          "
        >
          <div
            style="
              font-size: 11px;
              color: var(--text3);
              font-weight: 600;
              letter-spacing: 0.04em;
              text-transform: uppercase;
              margin-bottom: 8px;
            "
          >Unit Allocation</div>
          <div style="display: flex; flex-direction: column; gap: 5px">
            <div
              v-for="row in unitBreakdown(selectedBP)"
              :key="row.unit"
              style="display: flex; align-items: center; gap: 10px; font-size: 12.5px"
            >
              <span style="color: var(--indigo); font-weight: 600; min-width: 70px">{{ row.unit }}</span>
              <span
                v-if="row.qs === 0"
                style="color: var(--text3); font-style: italic"
              >no allocation</span>
              <span
                v-else
                style="
                  margin-left: auto;
                  font-family: var(--font-mono);
                  color: var(--text2);
                "
              >
                <template v-for="(a, ai) in row.allocs" :key="ai">
                  <span v-if="ai > 0" style="color: var(--text3)"> · </span>{{ a.count }}×{{ a.marks }}M
                </template>
                <span style="color: var(--text3); margin-left: 8px">= {{ row.qs }}Q / {{ row.marks }}M</span>
              </span>
            </div>
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
      <div class="bg-success-dim border border-success rounded-[var(--radius-lg)] p-4 sm:px-5 flex flex-col sm:flex-row gap-3 sm:items-center">
        <div class="flex gap-3 items-center flex-1">
          <span class="text-2xl">✓</span>
          <div>
            <div class="font-semibold text-success mb-[2px]">
              Paper generated successfully
            </div>
            <div class="text-[13px] text-text2">
              All 6 constraints satisfied · 1 AI-assisted question pending review
            </div>
          </div>
        </div>
        <div class="flex flex-wrap gap-2 sm:ml-auto">
          <QFButton variant="secondary" @click="router.push(`/teacher/paper/${papersStore.list[0]?.id}`)">
            Preview Paper
          </QFButton>
          <QFButton variant="primary" @click="router.push(`/teacher/paper/${papersStore.list[0]?.id}`)">
            View &amp; Export →
          </QFButton>
        </div>
      </div>
      <QFCard>
        <div class="qf-card-header pb-3.5">
          <span class="font-head font-semibold">Constraint Report</span>
        </div>
        <div class="qf-table-wrap">
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
        </div>
      </QFCard>
    </div>
  </div>
</template>
