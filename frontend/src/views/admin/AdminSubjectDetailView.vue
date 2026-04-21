<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
  QFAIHint,
  QFBadge,
  QFButton,
  QFCard,
  QFEmptyState,
  QFInput,
  QFModal,
  QFPageHeader,
  QFSelect,
} from '../../components/qf';
import {
  useCatalogStore,
  type CatalogQuestion,
  type CatalogUnit,
  type Subject,
} from '../../stores/catalog';

const route = useRoute();
const router = useRouter();
const catalog = useCatalogStore();

const cloneSubject = (s: Subject): Subject => JSON.parse(JSON.stringify(s));

const code = computed(() => String(route.params.code ?? ''));
const subject = computed<Subject | null>(() => catalog.getSubject(code.value));

const tab = ref<'overview' | 'questions' | 'syllabus'>('overview');
const syllabusMode = ref<'view' | 'edit' | 'upload'>('view');

const syllabusDraft = ref('');
watch(
  subject,
  (s) => {
    if (s) syllabusDraft.value = s.syllabus;
  },
  { immediate: true },
);

const unitFilter = ref<'all' | number>('all');

const totalQuestions = computed(() =>
  subject.value ? subject.value.units.flatMap((u) => u.questions).length : 0,
);

const filteredQuestions = computed(() => {
  if (!subject.value) return [] as (CatalogQuestion & { unitName: string })[];
  return subject.value.units
    .filter((u) => unitFilter.value === 'all' || u.id === unitFilter.value)
    .flatMap((u) => u.questions.map((q) => ({ ...q, unitName: u.name })));
});

const unitOptions = computed(() => {
  if (!subject.value) return [{ value: 'all', label: 'All Units' }];
  return [
    { value: 'all', label: 'All Units' },
    ...subject.value.units.map((u) => ({ value: u.id, label: u.name })),
  ];
});

// Unit modal
const unitModal = ref<{ open: boolean; mode: 'add' | 'edit'; id: number | null; name: string }>({
  open: false,
  mode: 'add',
  id: null,
  name: '',
});

const openAddUnit = () => {
  unitModal.value = { open: true, mode: 'add', id: null, name: '' };
};

const openEditUnit = (u: CatalogUnit) => {
  unitModal.value = { open: true, mode: 'edit', id: u.id, name: u.name };
};

const saveUnit = () => {
  if (!subject.value || !unitModal.value.name.trim()) return;
  const updated = cloneSubject(subject.value);
  if (unitModal.value.mode === 'add') {
    const nextId = (updated.units.at(-1)?.id ?? 0) + 1;
    updated.units.push({ id: nextId, name: unitModal.value.name.trim(), questions: [] });
  } else if (unitModal.value.id !== null) {
    const idx = updated.units.findIndex((u) => u.id === unitModal.value.id);
    if (idx >= 0) updated.units[idx].name = unitModal.value.name.trim();
  }
  catalog.saveSubject(updated);
  unitModal.value.open = false;
};

const deleteUnitConfirm = ref<CatalogUnit | null>(null);

const confirmDeleteUnit = () => {
  if (!subject.value || !deleteUnitConfirm.value) return;
  const updated = cloneSubject(subject.value);
  updated.units = updated.units.filter((u) => u.id !== deleteUnitConfirm.value!.id);
  catalog.saveSubject(updated);
  deleteUnitConfirm.value = null;
};

// Question modal
type QuestionMode = 'type' | 'upload';
const questionModal = reactive({
  open: false,
  mode: 'type' as QuestionMode,
  unitId: 0,
  text: '',
  marks: 5,
  type: 'Short Answer',
  difficulty: 'Medium' as 'Easy' | 'Medium' | 'Hard',
});

const openAddQuestion = (unitId: number | null = null) => {
  questionModal.open = true;
  questionModal.mode = 'type';
  questionModal.unitId = unitId ?? subject.value?.units[0]?.id ?? 0;
  questionModal.text = '';
  questionModal.marks = 5;
  questionModal.type = 'Short Answer';
  questionModal.difficulty = 'Medium';
};

const saveQuestion = () => {
  if (!subject.value || !questionModal.unitId || !questionModal.text.trim()) return;
  const updated = cloneSubject(subject.value);
  const unit = updated.units.find((u) => u.id === questionModal.unitId);
  if (!unit) return;
  const nextId = (updated.units.flatMap((u) => u.questions).map((q) => q.id).sort((a, b) => b - a)[0] ?? 0) + 1;
  unit.questions.push({
    id: nextId,
    text: questionModal.text.trim(),
    marks: questionModal.marks,
    type: questionModal.type,
    difficulty: questionModal.difficulty,
    used: 0,
  });
  catalog.saveSubject(updated);
  questionModal.open = false;
};

const uploadDragover = ref(false);

const simulateSyllabusUpload = () => {
  syllabusMode.value = 'view';
};

const saveSyllabus = () => {
  if (!subject.value) return;
  const updated = cloneSubject(subject.value);
  updated.syllabus = syllabusDraft.value;
  catalog.saveSubject(updated);
  syllabusMode.value = 'view';
};

const deleteQuestion = (unitId: number, questionId: number) => {
  if (!subject.value) return;
  const updated = cloneSubject(subject.value);
  const unit = updated.units.find((u) => u.id === unitId);
  if (!unit) return;
  unit.questions = unit.questions.filter((q) => q.id !== questionId);
  catalog.saveSubject(updated);
};

const diffColor: Record<string, string> = {
  Easy: 'var(--success)',
  Medium: 'var(--warn)',
  Hard: 'var(--danger)',
};
</script>

<template>
  <div class="qf-content qf-anim-in">
    <template v-if="subject">
      <QFPageHeader
        :title="subject.name"
        :subtitle="`${subject.code} · ${subject.units.length} units · ${totalQuestions} questions`"
        back="Subjects"
        @back="router.push('/admin/subjects')"
      >
        <template #actions>
          <QFButton variant="secondary" @click="openAddQuestion()">+ Add Question</QFButton>
          <QFButton variant="primary" @click="openAddUnit">+ Add Unit</QFButton>
        </template>
      </QFPageHeader>

      <div style="margin-bottom: 20px">
        <div class="qf-tabs" style="display: inline-flex">
          <div
            v-for="t in (['overview', 'questions', 'syllabus'] as const)"
            :key="t"
            :class="['qf-tab', tab === t && 'active']"
            style="text-transform: capitalize"
            @click="tab = t"
          >
            {{ t }}
          </div>
        </div>
      </div>

      <!-- OVERVIEW TAB -->
      <div v-if="tab === 'overview'" style="display: grid; grid-template-columns: 1fr 320px; gap: 20px">
        <div style="display: flex; flex-direction: column; gap: 12px">
          <QFCard v-for="(u, idx) in subject.units" :key="u.id">
            <div class="qf-card-body" style="display: flex; align-items: center; gap: 14px">
              <div
                style="
                  width: 40px;
                  height: 40px;
                  border-radius: 10px;
                  background: var(--cyan-dim);
                  color: var(--cyan);
                  font-family: var(--font-mono);
                  font-weight: 700;
                  display: flex;
                  align-items: center;
                  justify-content: center;
                  flex-shrink: 0;
                "
              >{{ idx + 1 }}</div>
              <div style="flex: 1; min-width: 0">
                <div style="font-family: var(--font-head); font-weight: 600; font-size: 14px">{{ u.name }}</div>
                <div style="font-size: 12px; color: var(--text3); margin-top: 2px">
                  {{ u.questions.length }} questions
                </div>
              </div>
              <div style="display: flex; gap: 6px">
                <QFButton variant="ghost" size="sm" @click="unitFilter = u.id; tab = 'questions'">
                  View Qs
                </QFButton>
                <QFButton variant="ghost" size="sm" @click="openAddQuestion(u.id)">+ Add Q</QFButton>
                <QFButton variant="ghost" size="sm" @click="openEditUnit(u)">Edit</QFButton>
                <QFButton variant="ghost" size="sm" @click="deleteUnitConfirm = u">✕</QFButton>
              </div>
            </div>
          </QFCard>
          <QFEmptyState
            v-if="subject.units.length === 0"
            icon="⬡"
            title="No units yet"
            desc="Add the first unit to start building this subject."
          />
        </div>

        <div style="display: flex; flex-direction: column; gap: 16px">
          <QFCard>
            <div class="qf-card-body">
              <div style="font-family: var(--font-head); font-weight: 600; margin-bottom: 12px">Subject Info</div>
              <div style="display: flex; flex-direction: column; gap: 10px; font-size: 13px">
                <div style="display: flex; justify-content: space-between">
                  <span style="color: var(--text3)">Code</span>
                  <span style="font-family: var(--font-mono); color: var(--cyan)">{{ subject.code }}</span>
                </div>
                <div style="display: flex; justify-content: space-between">
                  <span style="color: var(--text3)">Units</span>
                  <span>{{ subject.units.length }}</span>
                </div>
                <div style="display: flex; justify-content: space-between">
                  <span style="color: var(--text3)">Questions</span>
                  <span>{{ totalQuestions }}</span>
                </div>
                <div style="display: flex; justify-content: space-between">
                  <span style="color: var(--text3)">Teachers</span>
                  <span>{{ subject.teachers }}</span>
                </div>
              </div>
              <p style="font-size: 12.5px; color: var(--text3); margin-top: 14px; line-height: 1.5">
                {{ subject.description || 'No description.' }}
              </p>
            </div>
          </QFCard>

          <QFCard>
            <div class="qf-card-body">
              <div
                style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px"
              >
                <span style="font-family: var(--font-head); font-weight: 600">Syllabus</span>
                <QFButton variant="ghost" size="sm" @click="tab = 'syllabus'">Open →</QFButton>
              </div>
              <p
                style="
                  font-size: 12px;
                  color: var(--text3);
                  line-height: 1.6;
                  max-height: 120px;
                  overflow: hidden;
                  white-space: pre-wrap;
                "
              >{{ subject.syllabus.slice(0, 240) }}…</p>
            </div>
          </QFCard>

          <QFAIHint>
            AI can auto-suggest unit names and summaries from your uploaded syllabus PDF.
          </QFAIHint>
        </div>
      </div>

      <!-- QUESTIONS TAB -->
      <div v-else-if="tab === 'questions'">
        <div style="display: flex; gap: 12px; margin-bottom: 16px; align-items: flex-end">
          <div style="width: 240px">
            <QFSelect v-model="unitFilter" :options="unitOptions" />
          </div>
          <div style="flex: 1" />
          <QFButton variant="secondary" @click="openAddQuestion()">+ Add Question</QFButton>
        </div>
        <QFCard>
          <table class="qf-table">
            <thead>
              <tr>
                <th style="padding-left: 20px">Question</th>
                <th>Unit</th>
                <th>Type</th>
                <th>Marks</th>
                <th>Difficulty</th>
                <th>Used</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="q in filteredQuestions" :key="q.id">
                <td style="padding-left: 20px; max-width: 340px">
                  <div
                    style="
                      font-size: 13px;
                      line-height: 1.5;
                      overflow: hidden;
                      display: -webkit-box;
                      -webkit-line-clamp: 2;
                      -webkit-box-orient: vertical;
                    "
                  >{{ q.text }}</div>
                </td>
                <td style="color: var(--text2); font-size: 12.5px">{{ q.unitName }}</td>
                <td><QFBadge variant="neutral">{{ q.type }}</QFBadge></td>
                <td style="font-family: var(--font-mono); font-size: 13px; font-weight: 600">{{ q.marks }}</td>
                <td>
                  <span
                    :style="{
                      color: q.difficulty ? diffColor[q.difficulty] : 'var(--text3)',
                      fontSize: '12.5px',
                      fontWeight: 600,
                    }"
                  >{{ q.difficulty ?? '—' }}</span>
                </td>
                <td style="font-size: 12px; color: var(--text3)">{{ q.used ?? 0 }}×</td>
                <td>
                  <QFButton
                    variant="ghost"
                    size="sm"
                    @click="deleteQuestion(subject.units.find((u) => u.name === q.unitName)!.id, q.id)"
                  >✕</QFButton>
                </td>
              </tr>
            </tbody>
          </table>
          <QFEmptyState
            v-if="filteredQuestions.length === 0"
            icon="◈"
            title="No questions"
            desc="Add questions manually or upload a past paper PDF."
          />
        </QFCard>
      </div>

      <!-- SYLLABUS TAB -->
      <div v-else>
        <div style="display: flex; gap: 8px; margin-bottom: 16px">
          <QFButton
            v-for="m in (['view', 'edit', 'upload'] as const)"
            :key="m"
            :variant="syllabusMode === m ? 'primary' : 'ghost'"
            size="sm"
            style="text-transform: capitalize"
            @click="syllabusMode = m"
          >
            {{ m }}
          </QFButton>
        </div>
        <QFCard v-if="syllabusMode === 'view'">
          <div class="qf-card-body">
            <pre
              style="
                font-family: var(--font-mono);
                font-size: 13px;
                line-height: 1.7;
                color: var(--text2);
                white-space: pre-wrap;
                margin: 0;
              "
            >{{ subject.syllabus }}</pre>
          </div>
        </QFCard>
        <QFCard v-else-if="syllabusMode === 'edit'">
          <div class="qf-card-body">
            <QFInput
              v-model="syllabusDraft"
              label="Syllabus (Markdown)"
              type="textarea"
              :rows="18"
            />
            <div style="display: flex; gap: 8px; margin-top: 12px; justify-content: flex-end">
              <QFButton variant="ghost" @click="syllabusDraft = subject.syllabus; syllabusMode = 'view'">
                Cancel
              </QFButton>
              <QFButton variant="primary" @click="saveSyllabus">Save Syllabus</QFButton>
            </div>
          </div>
        </QFCard>
        <div
          v-else
          :class="['qf-dropzone', uploadDragover && 'dragover']"
          @dragover.prevent="uploadDragover = true"
          @dragleave="uploadDragover = false"
          @drop.prevent="uploadDragover = false; simulateSyllabusUpload()"
          @click="simulateSyllabusUpload"
        >
          <div style="font-size: 36px; opacity: 0.5">⬆</div>
          <div
            style="
              font-family: var(--font-head);
              font-size: 16px;
              font-weight: 600;
              color: var(--text2);
            "
          >Upload syllabus PDF</div>
          <div style="font-size: 13px; color: var(--text3)">
            We'll auto-extract units and learning outcomes
          </div>
          <QFButton variant="secondary" size="sm">Browse files</QFButton>
        </div>
      </div>

      <!-- UNIT ADD/EDIT MODAL -->
      <QFModal
        :open="unitModal.open"
        :title="unitModal.mode === 'add' ? 'Add Unit' : 'Edit Unit'"
        :width="420"
        @close="unitModal.open = false"
      >
        <QFInput v-model="unitModal.name" label="Unit name *" placeholder="e.g. Graph Algorithms" />
        <template #footer>
          <QFButton variant="ghost" @click="unitModal.open = false">Cancel</QFButton>
          <QFButton variant="primary" @click="saveUnit">
            {{ unitModal.mode === 'add' ? 'Add Unit' : 'Save Changes' }}
          </QFButton>
        </template>
      </QFModal>

      <!-- UNIT DELETE CONFIRM -->
      <QFModal
        :open="!!deleteUnitConfirm"
        title="Delete Unit"
        :width="420"
        @close="deleteUnitConfirm = null"
      >
        <p style="font-size: 14px; color: var(--text2); line-height: 1.6">
          Delete
          <strong style="color: var(--text)">{{ deleteUnitConfirm?.name }}</strong>? All
          {{ deleteUnitConfirm?.questions.length ?? 0 }} questions in this unit will also be removed.
        </p>
        <template #footer>
          <QFButton variant="ghost" @click="deleteUnitConfirm = null">Cancel</QFButton>
          <QFButton variant="danger" @click="confirmDeleteUnit">Delete Unit</QFButton>
        </template>
      </QFModal>

      <!-- ADD QUESTION MODAL -->
      <QFModal
        :open="questionModal.open"
        title="Add Question"
        :width="540"
        @close="questionModal.open = false"
      >
        <div style="display: flex; gap: 8px; margin-bottom: 16px">
          <QFButton
            :variant="questionModal.mode === 'type' ? 'primary' : 'ghost'"
            size="sm"
            @click="questionModal.mode = 'type'"
          >Type manually</QFButton>
          <QFButton
            :variant="questionModal.mode === 'upload' ? 'primary' : 'ghost'"
            size="sm"
            @click="questionModal.mode = 'upload'"
          >Upload PDF</QFButton>
        </div>
        <div v-if="questionModal.mode === 'type'" style="display: flex; flex-direction: column; gap: 14px">
          <QFSelect
            v-model="questionModal.unitId"
            label="Unit"
            :options="subject.units.map((u) => ({ value: u.id, label: u.name }))"
          />
          <QFInput
            v-model="questionModal.text"
            label="Question text *"
            type="textarea"
            :rows="4"
            placeholder="Enter the question…"
          />
          <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px">
            <QFInput
              :model-value="questionModal.marks"
              label="Marks"
              type="number"
              @update:model-value="(v) => (questionModal.marks = +v)"
            />
            <QFSelect
              v-model="questionModal.type"
              label="Type"
              :options="['Short Answer', 'Long Answer', 'MCQ', 'Programming']"
            />
            <QFSelect
              v-model="questionModal.difficulty"
              label="Difficulty"
              :options="['Easy', 'Medium', 'Hard']"
            />
          </div>
        </div>
        <div
          v-else
          class="qf-dropzone"
          style="margin: 0"
          @click="questionModal.open = false"
        >
          <div style="font-size: 32px; opacity: 0.5">⬆</div>
          <div
            style="
              font-family: var(--font-head);
              font-size: 15px;
              font-weight: 600;
              color: var(--text2);
            "
          >Upload past paper PDF</div>
          <div style="font-size: 12.5px; color: var(--text3)">
            AI will extract and classify questions automatically
          </div>
        </div>
        <template #footer>
          <QFButton variant="ghost" @click="questionModal.open = false">Cancel</QFButton>
          <QFButton
            v-if="questionModal.mode === 'type'"
            variant="primary"
            @click="saveQuestion"
          >Add Question</QFButton>
        </template>
      </QFModal>
    </template>

    <QFEmptyState
      v-else
      icon="⬡"
      title="Subject not found"
      desc="The subject you're looking for doesn't exist."
    >
      <template #action>
        <QFButton variant="primary" @click="router.push('/admin/subjects')">Back to Subjects</QFButton>
      </template>
    </QFEmptyState>
  </div>
</template>
