<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
  QFAIHint,
  QFBadge,
  QFButton,
  QFCard,
  QFInput,
  QFPageHeader,
  QFSelect,
  QFSteps,
} from '../../components/qf';
import { useBlueprintsStore, type Blueprint } from '../../stores/blueprints';
import { useCatalogStore } from '../../stores/catalog';

const route = useRoute();
const router = useRouter();
const store = useBlueprintsStore();
const catalog = useCatalogStore();

const idParam = route.params.id as string;
const isNew = idParam === 'new';

const bp = reactive<Blueprint>(store.blank());
const ready = ref(false);

// Subject dropdown is sourced from real subjects (decision: no mock data here).
const subjectOptions = computed(() =>
  catalog.subjects.map((s) => ({ value: s.code, label: `${s.code} – ${s.name}` })),
);

// Rebuild unit rules/allocations from the selected subject's REAL units,
// preserving any selections the teacher already made.
const syncUnits = async (code: string) => {
  const subj = await catalog.loadSubject(code);
  const names = (subj?.units ?? []).map((u) => u.name);
  const rules: Record<string, boolean> = {};
  const alloc: Record<string, { marks: number; count: number }[]> = {};
  for (const n of names) {
    rules[n] = bp.unitRules[n] ?? false;
    alloc[n] = bp.unitAllocations[n] ?? [];
  }
  bp.unitRules = rules;
  bp.unitAllocations = alloc;
};

onMounted(async () => {
  await catalog.fetchSubjects();
  if (!isNew) {
    const existing = await store.loadOne(Number(idParam));
    Object.assign(bp, JSON.parse(JSON.stringify(existing)));
  } else if (!bp.subject) {
    bp.subject = catalog.subjects[0]?.code ?? '';
  }
  if (bp.subject) await syncUnits(bp.subject);
  ready.value = true;
});

// React only to user-initiated subject changes (after the initial load).
watch(
  () => bp.subject,
  (code) => {
    if (ready.value && code) syncUnits(code);
  },
);

const steps = ['Basics', 'Sections', 'Unit Rules', 'Constraints', 'Review'];
const step = reactive({ current: 0 });

const totalAssigned = computed(() => bp.sections.reduce((s, sec) => s + sec.count * sec.marksEach, 0));
const deficit = computed(() => bp.totalMarks - totalAssigned.value);
const totalQuestions = computed(() => bp.sections.reduce((s, sec) => s + sec.count, 0));
const unitsRequired = computed(() => Object.values(bp.unitRules).filter(Boolean).length);

const availableMarks = computed(() => {
  const set = new Set<number>();
  for (const s of bp.sections) set.add(s.marksEach);
  return Array.from(set).sort((a, b) => a - b);
});

const unitTotal = (unit: string) =>
  (bp.unitAllocations[unit] ?? []).reduce((s, a) => s + (a.count || 0), 0);

const unitMarks = (unit: string) =>
  (bp.unitAllocations[unit] ?? []).reduce((s, a) => s + (a.count || 0) * (a.marks || 0), 0);

const totalAllocatedQs = computed(() =>
  Object.entries(bp.unitAllocations)
    .filter(([u]) => bp.unitRules[u])
    .reduce((s, [, arr]) => s + arr.reduce((x, a) => x + (a.count || 0), 0), 0),
);

// Allocations are per-unit MAXIMUMS (enforced by the generator): a unit with no
// rows is uncapped. The only structurally impossible configuration is when EVERY
// enabled unit is capped and the caps sum below the paper's question count.
const enabledUnits = computed(() => Object.keys(bp.unitRules).filter((u) => bp.unitRules[u]));

const allUnitsCapped = computed(
  () => enabledUnits.value.length > 0 && enabledUnits.value.every((u) => unitTotal(u) > 0),
);

const capShortfall = computed(() =>
  allUnitsCapped.value ? Math.max(0, totalQuestions.value - totalAllocatedQs.value) : 0,
);

const allocationColor = computed(() => {
  if (capShortfall.value > 0) return { bg: 'var(--danger-dim)', fg: 'var(--danger)' };
  return { bg: 'var(--success-dim)', fg: 'var(--success)' };
});

const addAllocation = (unit: string) => {
  const firstMark = availableMarks.value[0] ?? 5;
  bp.unitAllocations[unit].push({ marks: firstMark, count: 1 });
};

const removeAllocation = (unit: string, idx: number) => {
  bp.unitAllocations[unit].splice(idx, 1);
};

const setAllocationCount = (unit: string, idx: number, raw: number) => {
  const requested = Math.max(0, Math.floor(raw || 0));
  // A max can't usefully exceed the paper's question count.
  bp.unitAllocations[unit][idx].count = Math.min(requested, totalQuestions.value);
};

const setAllocationMarks = (unit: string, idx: number, raw: string | number) => {
  bp.unitAllocations[unit][idx].marks = +raw || 0;
};

const typeOptions = ['Short Answer', 'Long Answer', 'MCQ'];

const addSection = () => {
  bp.sections.push({
    id: Date.now(),
    name: 'New Section',
    type: 'Short Answer',
    count: 2,
    marksEach: 5,
    mandatory: false,
  });
};
const removeSection = (id: number) => {
  bp.sections = bp.sections.filter((s) => s.id !== id);
};
const toggleUnit = (u: string) => {
  bp.unitRules[u] = !bp.unitRules[u];
};

const letter = (i: number) => String.fromCharCode(65 + i);

const deficitColor = computed(() => {
  if (deficit.value === 0) return { bg: 'var(--success-dim)', fg: 'var(--success)' };
  if (deficit.value > 0) return { bg: 'var(--warn-dim)', fg: 'var(--warn)' };
  return { bg: 'var(--danger-dim)', fg: 'var(--danger)' };
});

const cancel = () => router.push('/teacher/blueprint');

const save = async () => {
  await store.save({ ...bp });
  router.push('/teacher/blueprint');
};

const back = () => {
  if (step.current === 0) cancel();
  else step.current -= 1;
};

const next = () => {
  step.current += 1;
};

const canContinue = computed(() => {
  if (step.current === 1) return deficit.value === 0;
  if (step.current === 2) return capShortfall.value === 0;
  return true;
});

const continueBlockedReason = computed(() => {
  if (step.current === 1 && deficit.value !== 0) {
    return deficit.value > 0
      ? `Allocate ${deficit.value} more mark${deficit.value === 1 ? '' : 's'} to continue`
      : `Over-allocated by ${-deficit.value} marks — reduce to continue`;
  }
  if (step.current === 2 && capShortfall.value > 0) {
    return `Your unit maximums allow only ${totalAllocatedQs.value} of ${totalQuestions.value} questions — raise some maximums or leave a unit uncapped`;
  }
  return '';
});

const exclusionRules = [
  {
    label: 'Last N Papers Exclusion',
    desc: 'Avoid questions used in the most recent N papers',
    min: 0,
    max: 10,
    unit: 'papers',
    color: 'var(--cyan)',
    key: 'lastNPapers' as const,
  },
  {
    label: 'Usage Frequency Limit',
    desc: 'Deprioritize questions used more than N times',
    min: 1,
    max: 10,
    unit: 'times',
    color: 'var(--indigo)',
    key: 'reuseThreshold' as const,
  },
];
</script>

<template>
  <div class="qf-content qf-anim-in">
    <div class="qf-anim-in">
      <QFPageHeader
        :title="isNew ? 'New Blueprint' : `Editing: ${bp.name}`"
        :breadcrumbs="[
          { label: 'Dashboard', to: '/teacher' },
          { label: 'Blueprints', to: '/teacher/blueprint' },
          { label: isNew ? 'New Blueprint' : bp.name },
        ]"
      />
      <div style="margin-bottom: 24px">
        <QFSteps :steps="steps" :current="step.current" />
      </div>

      <QFCard v-if="step.current === 0" class="qf-anim-in">
        <div class="qf-card-body">
          <div style="font-family: var(--font-head); font-weight: 600; font-size: 15px; margin-bottom: 18px">
            Paper Basics
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
            <QFInput v-model="bp.name" label="Blueprint name *" placeholder='"Midterm Paper Template"' />
            <QFSelect v-model="bp.subject" label="Subject *" :options="subjectOptions" />
            <QFInput
              :model-value="bp.totalMarks"
              label="Total marks *"
              type="number"
              hint="Paper's maximum marks"
              @update:model-value="(v) => (bp.totalMarks = +v)"
            />
            <QFInput
              :model-value="bp.duration"
              label="Duration (minutes)"
              type="number"
              @update:model-value="(v) => (bp.duration = +v)"
            />
          </div>
          <div
            style="
              display: flex;
              align-items: center;
              gap: 10px;
              padding: 12px 14px;
              background: var(--bg2);
              border-radius: var(--radius);
              border: 1px solid var(--border);
            "
          >
            <input
              id="aiAssistEdit"
              v-model="bp.aiAssist"
              type="checkbox"
              style="width: 16px; height: 16px; accent-color: var(--cyan)"
            />
            <label for="aiAssistEdit" style="cursor: pointer">
              <span style="font-weight: 500; font-size: 13.5px">Enable AI assistance</span>
              <span style="color: var(--text3); font-size: 12.5px; margin-left: 8px">
                AI fills gaps when constraints can't be fully satisfied
              </span>
            </label>
            <QFBadge variant="ai" style="margin-left: auto">✦ Recommended</QFBadge>
          </div>
        </div>
      </QFCard>

      <div v-else-if="step.current === 1" class="qf-anim-in">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px">
          <div style="font-family: var(--font-head); font-weight: 600; font-size: 15px">Paper Sections</div>
          <div style="display: flex; gap: 8px; align-items: center">
            <div
              :style="{
                fontSize: '13px',
                padding: '6px 14px',
                background: deficitColor.bg,
                color: deficitColor.fg,
                borderRadius: 'var(--radius)',
                fontFamily: 'var(--font-mono)',
                fontWeight: 600,
              }"
            >
              {{ totalAssigned }}/{{ bp.totalMarks }} marks
            </div>
            <QFButton variant="secondary" size="sm" @click="addSection">+ Add Section</QFButton>
          </div>
        </div>
        <div v-if="deficit !== 0" style="margin-bottom: 12px">
          <QFAIHint>
            {{ deficit > 0 ? `${deficit} marks unallocated.` : `Over-allocated by ${-deficit} marks.` }}
          </QFAIHint>
        </div>
        <div style="display: flex; flex-direction: column; gap: 10px">
          <QFCard v-for="(sec, si) in bp.sections" :key="sec.id">
            <div class="qf-card-body">
              <div style="display: flex; gap: 14px; align-items: flex-start">
                <div
                  style="
                    width: 28px;
                    height: 28px;
                    background: var(--cyan-dim);
                    border: 1px solid var(--cyan);
                    border-radius: 8px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    color: var(--cyan);
                    font-weight: 700;
                    font-size: 13px;
                    flex-shrink: 0;
                    margin-top: 2px;
                  "
                >{{ letter(si) }}</div>
                <div
                  class="flex-1 grid grid-cols-2 lg:grid-cols-[1fr_1fr_100px_100px] gap-3 items-end"
                >
                  <QFInput v-model="sec.name" label="Section name" />
                  <QFSelect v-model="sec.type" label="Question type" :options="typeOptions" />
                  <QFInput
                    :model-value="sec.count"
                    label="No. of Qs"
                    type="number"
                    @update:model-value="(v) => (sec.count = +v)"
                  />
                  <QFInput
                    :model-value="sec.marksEach"
                    label="Marks each"
                    type="number"
                    @update:model-value="(v) => (sec.marksEach = +v)"
                  />
                </div>
                <div
                  style="
                    display: flex;
                    flex-direction: column;
                    gap: 4px;
                    flex-shrink: 0;
                    align-items: flex-end;
                  "
                >
                  <div style="font-size: 11px; color: var(--text3)">Subtotal</div>
                  <div
                    style="
                      font-family: var(--font-head);
                      font-size: 18px;
                      font-weight: 700;
                      color: var(--cyan);
                    "
                  >{{ sec.count * sec.marksEach }}</div>
                </div>
                <button
                  v-if="!sec.mandatory"
                  style="
                    background: none;
                    border: none;
                    color: var(--text3);
                    cursor: pointer;
                    font-size: 20px;
                    padding: 4px 8px;
                    flex-shrink: 0;
                    margin-top: 14px;
                  "
                  @click="removeSection(sec.id)"
                >×</button>
              </div>
            </div>
          </QFCard>
        </div>
      </div>

      <div v-else-if="step.current === 2" class="qf-anim-in">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px">
          <div>
            <div style="font-family: var(--font-head); font-weight: 600; font-size: 15px">
              Unit Coverage & Maximums
            </div>
            <div style="color: var(--text3); font-size: 12.5px; margin-top: 2px">
              Toggle units and optionally cap how many questions may come from each
            </div>
          </div>
          <div
            :style="{
              fontSize: '13px',
              padding: '6px 14px',
              background: allocationColor.bg,
              color: allocationColor.fg,
              borderRadius: 'var(--radius)',
              fontFamily: 'var(--font-mono)',
              fontWeight: 600,
            }"
          >
            {{ allUnitsCapped ? `max ${totalAllocatedQs} / ${totalQuestions} questions` : `${totalQuestions} questions` }}
          </div>
        </div>

        <div v-if="availableMarks.length === 0" style="margin-bottom: 12px">
          <QFAIHint>
            Add at least one section in the previous step to unlock unit maximums.
          </QFAIHint>
        </div>
        <div v-else-if="capShortfall > 0" style="margin-bottom: 12px">
          <QFAIHint>
            {{ `Every unit is capped and the maximums only allow ${totalAllocatedQs} of ${totalQuestions} questions — raise some maximums or leave a unit uncapped.` }}
          </QFAIHint>
        </div>

        <div style="display: flex; flex-direction: column; gap: 10px">
          <QFCard v-for="(active, unit) in bp.unitRules" :key="unit">
            <div class="qf-card-body">
              <div
                style="display: flex; align-items: center; gap: 12px; cursor: pointer"
                @click="toggleUnit(unit)"
              >
                <div
                  :style="{
                    width: '20px',
                    height: '20px',
                    borderRadius: '4px',
                    background: active ? 'var(--cyan)' : 'var(--bg3)',
                    border: `1.5px solid ${active ? 'var(--cyan)' : 'var(--border2)'}`,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    fontSize: '12px',
                    color: 'var(--on-primary)',
                    flexShrink: 0,
                  }"
                >{{ active ? '✓' : '' }}</div>
                <span
                  :style="{
                    fontSize: '14px',
                    fontWeight: 600,
                    color: active ? 'var(--text)' : 'var(--text2)',
                  }"
                >{{ unit }}</span>
                <QFBadge v-if="active" variant="cyan" style="font-size: 11px">Required</QFBadge>
                <span
                  v-if="active"
                  style="
                    margin-left: auto;
                    font-family: var(--font-mono);
                    font-size: 12.5px;
                    color: var(--text2);
                  "
                >
                  {{ unitTotal(unit) > 0 ? `≤ ${unitTotal(unit)} Qs · ≤ ${unitMarks(unit)} marks` : 'no max' }}
                </span>
              </div>

              <div
                v-if="active"
                style="
                  margin-top: 14px;
                  padding-top: 14px;
                  border-top: 1px dashed var(--border);
                  display: flex;
                  flex-direction: column;
                  gap: 8px;
                "
              >
                <div
                  v-if="bp.unitAllocations[unit].length === 0"
                  style="color: var(--text3); font-size: 12.5px; font-style: italic"
                >
                  No maximum set — this unit is uncapped.
                </div>
                <div
                  v-for="(alloc, idx) in bp.unitAllocations[unit]"
                  :key="idx"
                  class="grid grid-cols-[1fr_1fr_auto] gap-2.5 items-end"
                >
                  <QFSelect
                    :model-value="String(alloc.marks)"
                    label="Marks each"
                    :options="availableMarks.map((m) => ({ value: String(m), label: `${m} marks` }))"
                    @update:model-value="(v) => setAllocationMarks(unit, idx, v)"
                  />
                  <QFInput
                    :model-value="alloc.count"
                    label="Max questions"
                    type="number"
                    @update:model-value="(v) => setAllocationCount(unit, idx, +v)"
                  />
                  <button
                    style="
                      background: none;
                      border: 1px solid var(--border);
                      color: var(--text3);
                      cursor: pointer;
                      font-size: 16px;
                      height: 38px;
                      width: 38px;
                      border-radius: var(--radius);
                    "
                    @click="removeAllocation(unit, idx)"
                  >×</button>
                </div>
                <div>
                  <QFButton
                    variant="secondary"
                    size="sm"
                    :disabled="availableMarks.length === 0"
                    @click="addAllocation(unit)"
                  >+ Add row</QFButton>
                </div>
              </div>
            </div>
          </QFCard>
        </div>

        <div style="margin-top: 14px">
          <QFAIHint>
            <strong style="color: var(--ai)">Tip:</strong> Maximums are optional — an uncapped
            unit can hold any number of the paper's
            {{ totalQuestions }} question{{ totalQuestions === 1 ? '' : 's' }}. Every enabled unit
            is still guaranteed at least one question. Mark values come from the sections you defined.
          </QFAIHint>
        </div>
      </div>

      <QFCard v-else-if="step.current === 3" class="qf-anim-in">
        <div class="qf-card-body">
          <div style="font-family: var(--font-head); font-weight: 600; font-size: 15px; margin-bottom: 18px">
            Exclusion & Repetition Rules
          </div>
          <div style="display: flex; flex-direction: column; gap: 14px">
            <div
              v-for="r in exclusionRules"
              :key="r.key"
              style="
                padding: 16px;
                background: var(--bg2);
                border-radius: var(--radius);
                border: 1px solid var(--border);
              "
            >
              <div style="font-weight: 600; margin-bottom: 3px">{{ r.label }}</div>
              <div style="color: var(--text3); font-size: 13px; margin-bottom: 12px">{{ r.desc }}</div>
              <div style="display: flex; align-items: center; gap: 12px">
                <input
                  type="range"
                  :min="r.min"
                  :max="r.max"
                  :value="bp.exclusionRules[r.key]"
                  :style="{ flex: 1, accentColor: r.color }"
                  @input="(e) => (bp.exclusionRules[r.key] = +(e.target as HTMLInputElement).value)"
                />
                <div
                  :style="{
                    fontFamily: 'var(--font-mono)',
                    fontSize: '16px',
                    fontWeight: 700,
                    color: r.color,
                    minWidth: '24px',
                    textAlign: 'right',
                  }"
                >{{ bp.exclusionRules[r.key] }}</div>
                <span style="color: var(--text3); font-size: 13px">{{ r.unit }}</span>
              </div>
            </div>
            <div
              v-for="[id, lbl] in [
                ['strictUnits2', 'Strict unit proportionality across required units'],
              ]"
              :key="id"
              style="
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 12px 14px;
                background: var(--bg2);
                border-radius: var(--radius);
                border: 1px solid var(--border);
              "
            >
              <input
                :id="id"
                type="checkbox"
                checked
                style="width: 16px; height: 16px; accent-color: var(--cyan)"
              />
              <label :for="id" style="cursor: pointer; font-size: 13.5px; font-weight: 500">{{ lbl }}</label>
            </div>
          </div>
        </div>
      </QFCard>

      <QFCard v-else-if="step.current === 4" glow class="qf-anim-in">
        <div class="qf-card-body">
          <div style="font-family: var(--font-head); font-weight: 700; font-size: 18px; margin-bottom: 4px">
            {{ bp.name || 'Untitled Blueprint' }}
          </div>
          <div style="color: var(--text3); font-size: 13px; margin-bottom: 18px">
            {{ bp.subject }} · {{ bp.totalMarks }} marks · {{ bp.duration }} min
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-[18px]">
            <div
              v-for="summary in [
                { label: 'Total Qs', value: totalQuestions, icon: '◈', color: 'var(--cyan)' },
                {
                  label: 'Total Marks',
                  value: totalAssigned,
                  icon: '⬡',
                  color: deficit === 0 ? 'var(--success)' : 'var(--warn)',
                },
                { label: 'Units Required', value: unitsRequired, icon: '✦', color: 'var(--indigo)' },
              ]"
              :key="summary.label"
              style="background: var(--bg2); border-radius: var(--radius); padding: 14px; text-align: center"
            >
              <div :style="{ fontSize: '18px', color: summary.color, marginBottom: '5px' }">{{ summary.icon }}</div>
              <div
                :style="{
                  fontFamily: 'var(--font-head)',
                  fontSize: '22px',
                  fontWeight: 700,
                  color: summary.color,
                }"
              >{{ summary.value }}</div>
              <div style="font-size: 12px; color: var(--text3); margin-top: 4px">{{ summary.label }}</div>
            </div>
          </div>
          <div
            v-for="(s, i) in bp.sections"
            :key="s.id"
            style="
              display: flex;
              justify-content: space-between;
              padding: 10px 14px;
              background: var(--bg2);
              border-radius: var(--radius);
              margin-bottom: 6px;
            "
          >
            <div style="display: flex; gap: 10px; align-items: center">
              <span style="color: var(--cyan); font-weight: 700; font-size: 13px">{{ letter(i) }}</span>
              <span style="font-size: 13.5px">{{ s.name }}</span>
              <QFBadge variant="neutral">{{ s.type }}</QFBadge>
            </div>
            <span style="font-family: var(--font-mono); font-size: 13px; color: var(--text2)">
              {{ s.count }} × {{ s.marksEach }} = {{ s.count * s.marksEach }}M
            </span>
          </div>
          <div v-if="deficit !== 0" style="margin-top: 12px">
            <QFAIHint>
              ⚠ {{ deficit > 0 ? `Under-allocated by ${deficit} marks.` : `Over-allocated by ${-deficit} marks.` }}
            </QFAIHint>
          </div>
        </div>
      </QFCard>

      <div style="display: flex; justify-content: space-between; margin-top: 20px; align-items: center">
        <QFButton variant="secondary" @click="back">
          {{ step.current === 0 ? '← Cancel' : '← Back' }}
        </QFButton>
        <div style="display: flex; gap: 10px; align-items: center">
          <span
            v-if="continueBlockedReason"
            style="font-size: 12.5px; color: var(--warn); font-family: var(--font-mono)"
          >{{ continueBlockedReason }}</span>
          <QFButton variant="ghost" @click="cancel">Discard</QFButton>
          <QFButton
            v-if="step.current < steps.length - 1"
            variant="primary"
            :disabled="!canContinue"
            @click="next"
          >Continue →</QFButton>
          <QFButton v-else variant="primary" @click="save">Save Blueprint</QFButton>
        </div>
      </div>
    </div>
  </div>
</template>
