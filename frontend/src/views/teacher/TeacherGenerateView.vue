<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
  QFBadge,
  QFButton,
  QFCard,
  QFEmptyState,
  QFPageHeader,
  QFSpinner,
  QFSteps,
} from '../../components/qf';
import { useBlueprintsStore, type Blueprint } from '../../stores/blueprints';
import { usePapersStore } from '../../stores/papers';

const route = useRoute();
const router = useRouter();
const blueprintsStore = useBlueprintsStore();
const papersStore = usePapersStore();

type Phase = 'select' | 'idle' | 'generating' | 'done';
const phase = ref<Phase>('select');
const selectedBP = ref<Blueprint | null>(null);
const bpSearch = ref('');

papersStore.resetGeneration();

onMounted(async () => {
  await blueprintsStore.fetch();
  // Deep-linked from the Blueprints list ("Generate" on a card): pre-select that
  // blueprint and skip straight to the configuration step.
  const bpId = Number(route.query.bp);
  if (bpId) {
    const match = blueprintsStore.list.find((b) => b.id === bpId);
    if (match) {
      selectedBP.value = match;
      phase.value = 'idle';
    }
  }
});

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

// Straight from a card's own button: select and jump to the Configure step in one click.
const configure = (bp: Blueprint) => {
  selectedBP.value = bp;
  phase.value = 'idle';
};

const startGeneration = async () => {
  if (!selectedBP.value) return;
  phase.value = 'generating';
  await papersStore.generate(selectedBP.value.id);
  phase.value = 'done';
  // A persisted paper stamps the blueprint's last_used_at; refresh the cards
  // so "Generate Another" doesn't show a stale "Last used: Never".
  void blueprintsStore.fetch();
};

const openPaper = () => {
  const id = papersStore.current?.id;
  if (id) router.push(`/teacher/paper/${id}`);
};

// M5 — top up the bank with AI, then regenerate. Stays on the results panel so the
// teacher watches status and lands on either a paper or a smaller shortfall.
const expandWithAi = async () => {
  if (!selectedBP.value) return;
  phase.value = 'generating';
  await papersStore.expandBank(selectedBP.value.id);
  phase.value = 'done';
  void blueprintsStore.fetch();
};

const passedCount = computed(() => papersStore.constraints.filter((c) => c.pass === true).length);
const totalConstraints = computed(() => papersStore.constraints.length);

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
      <!-- <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px">
        <div style="font-family: var(--font-head); font-weight: 600; font-size: 15px">Your Blueprints</div>
        <QFButton variant="secondary" size="sm" @click="router.push('/teacher/blueprint/new')">
          + New Blueprint
        </QFButton>
      </div> -->

      <div style="display: flex; gap: 10px; margin-bottom: 16px; align-items: center">
        <div style="position: relative; flex: 1; max-width: 340px">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            width="16"
            height="16"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            style="position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--text3); pointer-events: none"
          >
            <circle cx="11" cy="11" r="7" />
            <path d="m21 21-4.3-4.3" />
          </svg>
          <input
            v-model="bpSearch"
            class="qf-input"
            placeholder="Search blueprints…"
            style="padding-left: 36px"
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
        :desc="`No blueprints match “${bpSearch}”. Try a different search or create a new blueprint.`"
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
          style="
            background: var(--bg1);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 18px 20px;
            display: flex;
            flex-direction: column;
          "
        >
          <div style="min-width: 0; margin-bottom: 10px">
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
            >Unit Maximums</div>
            <div
              v-for="row in unitBreakdown(bp)"
              :key="row.unit"
              style="display: flex; align-items: center; gap: 6px; font-size: 11.5px"
            >
              <span style="color: var(--indigo); font-weight: 600">{{ row.unit }}</span>
              <span
                v-if="row.qs === 0"
                style="color: var(--text3); font-style: italic; font-size: 11px"
              >no max — uncapped</span>
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
                  <span v-if="ai > 0" style="color: var(--text3)"> · </span>≤{{ a.count }}×{{ a.marks }}M
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
              margin-top: auto;
              display: flex;
              align-items: center;
              justify-content: space-between;
              gap: 10px;
            "
          >
            <span>Last used: {{ bp.lastUsed }}</span>
            <QFButton size="sm" variant="primary" @click="configure(bp)">Configure →</QFButton>
          </div>
        </div>
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
          The blueprint engine will select questions from the bank and apply every constraint rule —
          unit coverage, marks, counts, and no repetition — using deterministic greedy selection with
          backtracking.
        </p>
        <div
          style="display: flex; gap: 12px; justify-content: center; margin-bottom: 24px; flex-wrap: wrap"
        >
          <div
            v-for="item in [
              { value: selectedBP?.questions ?? 0, label: 'Questions' },
              { value: selectedBP?.totalMarks ?? 0, label: 'Total Marks' },
              { value: selectedBP?.units ?? 0, label: 'Units' },
              { value: selectedBP?.exclusionRules.lastNPapers ?? 0, label: 'Papers Excluded' },
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
          >Unit Maximums</div>
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
              >no max — uncapped</span>
              <span
                v-else
                style="
                  margin-left: auto;
                  font-family: var(--font-mono);
                  color: var(--text2);
                "
              >
                <template v-for="(a, ai) in row.allocs" :key="ai">
                  <span v-if="ai > 0" style="color: var(--text3)"> · </span>≤{{ a.count }}×{{ a.marks }}M
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
    >
      <QFCard>
        <div class="qf-card-body" style="text-align: center; padding: 48px 32px">
          <div style="display: flex; justify-content: center; margin-bottom: 18px">
            <QFSpinner :size="32" />
          </div>
          <div style="font-family: var(--font-head); font-weight: 600; font-size: 16px; margin-bottom: 6px">
            Generating paper…
          </div>
          <div style="color: var(--text3); font-size: 13px">
            Running the constraint engine against the question bank.
          </div>
        </div>
      </QFCard>
    </div>

    <div
      v-else
      class="qf-anim-in"
      style="display: flex; flex-direction: column; gap: 16px"
    >
      <!-- Success banner -->
      <div
        v-if="papersStore.satisfiable"
        class="bg-success-dim border border-success rounded-[var(--radius-lg)] p-4 sm:px-5 flex flex-col sm:flex-row gap-3 sm:items-center"
      >
        <div class="flex gap-3 items-center flex-1">
          <span class="text-2xl">✓</span>
          <div>
            <div class="font-semibold text-success mb-[2px]">
              Paper generated successfully
            </div>
            <div class="text-[13px] text-text2">
              All {{ totalConstraints }} constraints satisfied · {{ papersStore.current?.questions }} questions ·
              {{ papersStore.current?.marks }} marks
            </div>
          </div>
        </div>
        <div class="flex flex-wrap gap-2 sm:ml-auto">
          <QFButton variant="secondary" @click="phase = 'select'">Generate Another</QFButton>
          <QFButton variant="primary" @click="openPaper">
            View &amp; Export →
          </QFButton>
        </div>
      </div>

      <!-- Infeasible banner + shortfall -->
      <div
        v-else
        class="bg-danger-dim border border-danger rounded-[var(--radius-lg)] p-4 sm:px-5 flex flex-col gap-3"
      >
        <div class="flex gap-3 items-center">
          <span class="text-2xl">⚠</span>
          <div class="flex-1">
            <div class="font-semibold text-danger mb-[2px]">
              Cannot satisfy this blueprint
            </div>
            <!-- Structural shortfall (coverage needs more units than questions): AI
                 can't help, so explain how to fix the blueprint instead. -->
            <div v-if="papersStore.shortfallReason" class="text-[13px] text-text2">
              {{ papersStore.shortfallReason }}
            </div>
            <div v-else class="text-[13px] text-text2">
              The question bank is too thin. {{ passedCount }}/{{ totalConstraints }} constraints met.
              Add questions to the bank for the slots below, then try again.
            </div>
          </div>
          <div class="flex gap-2">
            <QFButton
              v-if="papersStore.expandable"
              variant="primary"
              size="sm"
              :disabled="papersStore.expanding"
              @click="expandWithAi"
            >
              {{ papersStore.expanding ? 'Expanding…' : '✨ Expand bank with AI' }}
            </QFButton>
            <QFButton
              v-else
              variant="primary"
              size="sm"
              @click="router.push(`/teacher/blueprint/${selectedBP?.id}`)"
            >
              Edit blueprint →
            </QFButton>
            <QFButton variant="secondary" size="sm" @click="phase = 'select'">← Back</QFButton>
          </div>
        </div>
        <div
          v-if="papersStore.expandStatus"
          class="text-[13px] text-text2"
          style="display: flex; align-items: center; gap: 8px"
        >
          <span class="text-indigo">✨</span>{{ papersStore.expandStatus }}
        </div>
        <div
          v-if="papersStore.missingSlots.length"
          style="
            background: var(--bg2);
            border-radius: var(--radius);
            padding: 12px 14px;
            display: flex;
            flex-direction: column;
            gap: 8px;
          "
        >
          <div
            style="
              font-size: 11px;
              color: var(--text3);
              font-weight: 600;
              letter-spacing: 0.04em;
              text-transform: uppercase;
            "
          >Missing from the bank</div>
          <div
            v-for="(m, i) in papersStore.missingSlots"
            :key="i"
            style="display: flex; align-items: center; gap: 10px; font-size: 13px"
          >
            <span style="color: var(--danger); font-weight: 700; font-family: var(--font-mono)">
              {{ m.need }}×
            </span>
            <span>{{ m.marks }}-mark {{ m.type }}</span>
            <span v-if="m.unit" style="color: var(--indigo); font-weight: 600">· {{ m.unit }}</span>
            <span style="margin-left: auto; color: var(--text3); font-size: 12px">{{ m.section_label }}</span>
          </div>
        </div>
      </div>

      <!-- Constraint report (both paths) -->
      <QFCard v-if="papersStore.constraints.length">
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
                  <QFBadge v-else variant="ai">✦ Info</QFBadge>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </QFCard>
    </div>
  </div>
</template>
