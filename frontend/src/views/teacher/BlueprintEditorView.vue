<script setup lang="ts">
import { computed, reactive } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
  QFAIHint,
  QFBadge,
  QFButton,
  QFCard,
  QFInput,
  QFSelect,
  QFSteps,
} from '../../components/qf';
import { useBlueprintsStore, type Blueprint } from '../../stores/blueprints';

const route = useRoute();
const router = useRouter();
const store = useBlueprintsStore();

const idParam = route.params.id as string;
const existing = idParam !== 'new' ? store.getById(Number(idParam)) : null;

const bp = reactive<Blueprint>(
  existing ? (JSON.parse(JSON.stringify(existing)) as Blueprint) : store.blank(),
);
const isNew = !existing;

const steps = ['Basics', 'Sections', 'Unit Rules', 'Constraints', 'Review'];
const step = reactive({ current: 0 });

const totalAssigned = computed(() => bp.sections.reduce((s, sec) => s + sec.count * sec.marksEach, 0));
const deficit = computed(() => bp.totalMarks - totalAssigned.value);
const totalQuestions = computed(() => bp.sections.reduce((s, sec) => s + sec.count, 0));
const unitsRequired = computed(() => Object.values(bp.unitRules).filter(Boolean).length);

const subjectOptions = [
  { value: 'CS301', label: 'CS301 – Data Structures' },
  { value: 'CS302', label: 'CS302 – Algorithms' },
  { value: 'CS303', label: 'CS303 – DBMS' },
  { value: 'MA201', label: 'MA201 – Discrete Math' },
];
const typeOptions = ['Short Answer', 'Long Answer', 'MCQ', 'Programming', 'Case Study'];

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

const save = () => {
  store.save({
    ...bp,
    lastUsed: 'Today',
  });
  router.push('/teacher/blueprint');
};

const back = () => {
  if (step.current === 0) cancel();
  else step.current -= 1;
};

const next = () => {
  step.current += 1;
};

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
  <div class="qf-content qf-anim-in" style="max-width: 900px">
    <div class="qf-anim-in">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px">
        <div style="font-family: var(--font-head); font-weight: 700; font-size: 18px">
          {{ isNew ? 'New Blueprint' : `Editing: ${bp.name}` }}
        </div>
        <QFButton variant="ghost" size="sm" @click="cancel">← Back to list</QFButton>
      </div>
      <div style="margin-bottom: 24px">
        <QFSteps :steps="steps" :current="step.current" />
      </div>

      <QFCard v-if="step.current === 0" class="qf-anim-in">
        <div class="qf-card-body">
          <div style="font-family: var(--font-head); font-weight: 600; font-size: 15px; margin-bottom: 18px">
            Paper Basics
          </div>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px">
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
                  style="
                    flex: 1;
                    display: grid;
                    grid-template-columns: 1fr 1fr 100px 100px;
                    gap: 12px;
                    align-items: end;
                  "
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

      <QFCard v-else-if="step.current === 2" class="qf-anim-in">
        <div class="qf-card-body">
          <div style="font-family: var(--font-head); font-weight: 600; font-size: 15px; margin-bottom: 4px">
            Unit Coverage Rules
          </div>
          <div style="color: var(--text3); font-size: 13px; margin-bottom: 18px">
            Select which units must be covered in the generated paper
          </div>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px">
            <div
              v-for="(active, unit) in bp.unitRules"
              :key="unit"
              :style="{
                display: 'flex',
                alignItems: 'center',
                gap: '12px',
                padding: '12px 16px',
                background: active ? 'var(--cyan-dim)' : 'var(--bg2)',
                border: `1.5px solid ${active ? 'var(--cyan)' : 'var(--border)'}`,
                borderRadius: 'var(--radius)',
                cursor: 'pointer',
                transition: 'all 0.15s',
              }"
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
                  color: '#070a10',
                  flexShrink: 0,
                }"
              >{{ active ? '✓' : '' }}</div>
              <span
                :style="{
                  fontSize: '13.5px',
                  fontWeight: 500,
                  color: active ? 'var(--text)' : 'var(--text2)',
                }"
              >{{ unit }}</span>
              <QFBadge v-if="active" variant="cyan" style="margin-left: auto; font-size: 11px">Required</QFBadge>
            </div>
          </div>
          <QFAIHint>
            <strong style="color: var(--ai)">Tip:</strong> Including all units is recommended for final exams. For quizzes, select 2–3 focused units.
          </QFAIHint>
        </div>
      </QFCard>

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
                ['balanceDiff2', 'Balance difficulty distribution (Easy / Medium / Hard)'],
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
          <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 18px">
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

      <div style="display: flex; justify-content: space-between; margin-top: 20px">
        <QFButton variant="secondary" @click="back">
          {{ step.current === 0 ? '← Cancel' : '← Back' }}
        </QFButton>
        <div style="display: flex; gap: 8px">
          <QFButton variant="ghost" @click="cancel">Discard</QFButton>
          <QFButton
            v-if="step.current < steps.length - 1"
            variant="primary"
            @click="next"
          >Continue →</QFButton>
          <QFButton v-else variant="primary" @click="save">Save Blueprint</QFButton>
        </div>
      </div>
    </div>
  </div>
</template>
