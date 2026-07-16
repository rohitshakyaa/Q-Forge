<script setup lang="ts">
import { computed, onUnmounted, reactive, ref, watch } from 'vue';
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
  QFProgress,
  QFSelect,
} from '../../components/qf';
import {
  useCatalogStore,
  type CatalogQuestion,
  type CatalogUnit,
  type Subject,
} from '../../stores/catalog';
import { useExtractionStore } from '../../stores/extraction';

const route = useRoute();
const router = useRouter();
const catalog = useCatalogStore();
const extraction = useExtractionStore();

const code = computed(() => String(route.params.code ?? ''));
const subject = computed<Subject | null>(() =>
  catalog.current && catalog.current.code === code.value ? catalog.current : null,
);

watch(
  code,
  (c) => {
    if (c) catalog.loadSubject(c);
  },
  { immediate: true },
);

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

// Each question is listed once, under its primary unit; `extraUnits` powers the
// "+ Unit" chips for multi-unit questions.
type ListedQuestion = CatalogQuestion & {
  unitName: string;
  primaryUnitId: number;
  extraUnits: { id: number; name: string }[];
};

const allQuestions = computed<ListedQuestion[]>(() => {
  if (!subject.value) return [];
  return subject.value.units.flatMap((u) =>
    u.questions.map((q) => ({
      ...q,
      unitName: u.name,
      primaryUnitId: u.id,
      extraUnits: q.units.filter((tag) => tag.id !== u.id),
    })),
  );
});

// The unit filter matches ANY tagged unit (mirrors the generator's rule), so a
// multi-unit question surfaces when filtering by its secondary unit too.
const filteredQuestions = computed(() =>
  allQuestions.value.filter(
    (q) =>
      unitFilter.value === 'all' ||
      q.primaryUnitId === unitFilter.value ||
      q.unitIds.includes(unitFilter.value as number),
  ),
);

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

const saveUnit = async () => {
  if (!subject.value || !unitModal.value.name.trim()) return;
  const name = unitModal.value.name.trim();
  if (unitModal.value.mode === 'add') {
    await catalog.addUnit(code.value, name);
  } else if (unitModal.value.id !== null) {
    await catalog.updateUnit(code.value, unitModal.value.id, name);
  }
  unitModal.value.open = false;
};

const deleteUnitConfirm = ref<CatalogUnit | null>(null);

const confirmDeleteUnit = async () => {
  if (!subject.value || !deleteUnitConfirm.value) return;
  await catalog.deleteUnit(code.value, deleteUnitConfirm.value.id);
  deleteUnitConfirm.value = null;
};

// Question modal
type QuestionMode = 'type' | 'upload';
const questionModal = reactive({
  open: false,
  mode: 'type' as QuestionMode,
  unitId: 0,
  additionalUnitIds: [] as number[],
  text: '',
  marks: 5,
  type: 'Short Answer',
});

const openAddQuestion = (unitId: number | null = null) => {
  questionModal.open = true;
  questionModal.mode = 'type';
  questionModal.unitId = unitId ?? subject.value?.units[0]?.id ?? 0;
  questionModal.additionalUnitIds = [];
  questionModal.text = '';
  questionModal.marks = 5;
  questionModal.type = 'Short Answer';
};

// A question can also cover units beyond its primary one.
const otherUnitOptions = computed(
  () => subject.value?.units.filter((u) => u.id !== questionModal.unitId) ?? [],
);

const toggleAdditionalUnit = (unitId: number) => {
  const list = questionModal.additionalUnitIds;
  const idx = list.indexOf(unitId);
  if (idx === -1) list.push(unitId);
  else list.splice(idx, 1);
};

const saveQuestion = async () => {
  if (!subject.value?.id || !questionModal.unitId || !questionModal.text.trim()) return;
  await catalog.createQuestion(code.value, {
    subjectId: subject.value.id,
    unitId: questionModal.unitId,
    additionalUnitIds: questionModal.additionalUnitIds,
    text: questionModal.text.trim(),
    marks: questionModal.marks,
    type: questionModal.type,
  });
  questionModal.open = false;
};

const uploadDragover = ref(false);
const syllabusFileInput = ref<HTMLInputElement | null>(null);
const syllabusUploadId = ref<number | null>(null);
const syllabusError = ref<string | null>(null);

// The store owns polling; we just track which upload is ours and read its live status.
const syllabusUpload = computed(() =>
  syllabusUploadId.value === null
    ? null
    : extraction.uploads.find((u) => u.id === syllabusUploadId.value) ?? null,
);

const uploadSyllabus = async (file: File) => {
  if (!subject.value?.id) return;
  syllabusError.value = null;
  try {
    const upload = await extraction.createUpload({
      file,
      type: 'syllabus',
      subjectId: subject.value.id,
    });
    syllabusUploadId.value = upload.id;
  } catch (e: unknown) {
    const response = (e as { response?: { data?: { message?: string } } }).response;
    syllabusError.value = response?.data?.message ?? 'Upload failed.';
  }
};

const onSyllabusPick = (e: Event) => {
  const input = e.target as HTMLInputElement;
  const file = input.files?.[0];
  if (file) uploadSyllabus(file);
  input.value = ''; // Allow re-picking the same file.
};

const onSyllabusDrop = (e: DragEvent) => {
  uploadDragover.value = false;
  const file = e.dataTransfer?.files?.[0];
  if (file) uploadSyllabus(file);
};

// Once the queued extraction finishes, hand off to the pinned import-review screen for
// this subject; surface failures inline and let the user retry.
watch(
  () => syllabusUpload.value?.status,
  (status) => {
    if (status === 'parsed') {
      const id = syllabusUploadId.value;
      syllabusUploadId.value = null;
      router.push(`/admin/syllabus/${id}?subject=${code.value}`);
    } else if (status === 'failed') {
      syllabusError.value = syllabusUpload.value?.error ?? 'Extraction failed.';
      syllabusUploadId.value = null;
    }
  },
);

onUnmounted(() => {
  if (syllabusUploadId.value !== null) extraction.stopWatching(syllabusUploadId.value);
});

const saveSyllabus = async () => {
  if (!subject.value) return;
  await catalog.updateSubject(code.value, { syllabus: syllabusDraft.value });
  syllabusMode.value = 'view';
};

const deleteQuestion = async (_unitId: number, questionId: number) => {
  if (!subject.value) return;
  await catalog.deleteQuestion(code.value, questionId);
};
</script>

<template>
  <div class="qf-content qf-anim-in">
    <template v-if="subject">
      <QFPageHeader
        :title="subject.name"
        :subtitle="`${subject.code} · ${subject.units.length} units · ${totalQuestions} questions`"
        :breadcrumbs="[
          { label: 'Dashboard', to: '/admin' },
          { label: 'Subjects', to: '/admin/subjects' },
          { label: subject.name },
        ]"
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
      <div v-if="tab === 'overview'" class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-5">
        <div class="flex flex-col gap-3">
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

        <div class="flex flex-col gap-4">
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
        <div class="flex flex-wrap gap-3 mb-4 items-end">
          <div class="w-full sm:w-60">
            <QFSelect v-model="unitFilter" :options="unitOptions" />
          </div>
          <div class="hidden sm:block flex-1" />
          <QFButton variant="secondary" @click="openAddQuestion()">+ Add Question</QFButton>
        </div>
        <QFCard>
          <div class="qf-table-wrap">
          <table class="qf-table">
            <thead>
              <tr>
                <th style="padding-left: 20px">Question</th>
                <th>Unit</th>
                <th>Type</th>
                <th>Marks</th>
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
                <td style="color: var(--text2); font-size: 12.5px">
                  {{ q.unitName }}
                  <QFBadge
                    v-for="extra in q.extraUnits"
                    :key="extra.id"
                    variant="neutral"
                    style="margin-left: 4px"
                  >+ {{ extra.name }}</QFBadge>
                </td>
                <td><QFBadge variant="neutral">{{ q.type }}</QFBadge></td>
                <td style="font-family: var(--font-mono); font-size: 13px; font-weight: 600">{{ q.marks }}</td>
                <td style="font-size: 12px; color: var(--text3)">{{ q.used ?? 0 }}×</td>
                <td>
                  <QFButton
                    variant="ghost"
                    size="sm"
                    @click="deleteQuestion(q.primaryUnitId, q.id)"
                  >✕</QFButton>
                </td>
              </tr>
            </tbody>
          </table>
          </div>
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
        <div v-else>
          <input
            ref="syllabusFileInput"
            type="file"
            accept="application/pdf"
            style="display: none"
            @change="onSyllabusPick"
          />

          <div v-if="syllabusError" style="margin-bottom: 12px; font-size: 13px; color: var(--danger)">
            {{ syllabusError }}
          </div>

          <QFCard v-if="syllabusUpload && !extraction.isTerminal(syllabusUpload.status)">
            <div class="qf-card-body">
              <div style="font-weight: 500; font-size: 13.5px; margin-bottom: 10px">
                {{ syllabusUpload.filename }}
              </div>
              <QFProgress
                :value="syllabusUpload.progress"
                ai
                :label="`Extracting units… ${syllabusUpload.progress}%`"
              />
              <div style="margin-top: 8px; font-size: 12px; color: var(--text3)">
                You'll be taken to the review screen automatically once extraction finishes.
              </div>
            </div>
          </QFCard>

          <div
            v-else
            :class="['qf-dropzone', uploadDragover && 'dragover']"
            @dragover.prevent="uploadDragover = true"
            @dragleave="uploadDragover = false"
            @drop.prevent="onSyllabusDrop"
            @click="syllabusFileInput?.click()"
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
              We'll auto-extract units and learning outcomes · PDF only
            </div>
            <QFButton variant="secondary" size="sm">Browse files</QFButton>
          </div>
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
          <div v-if="otherUnitOptions.length > 0">
            <div style="font-size: 12.5px; color: var(--text3); margin-bottom: 6px">
              Also covers (optional)
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: 10px">
              <label
                v-for="u in otherUnitOptions"
                :key="u.id"
                style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer"
              >
                <input
                  type="checkbox"
                  :checked="questionModal.additionalUnitIds.includes(u.id)"
                  @change="toggleAdditionalUnit(u.id)"
                />
                {{ u.name }}
              </label>
            </div>
          </div>
          <QFInput
            v-model="questionModal.text"
            label="Question text *"
            type="textarea"
            :rows="4"
            placeholder="Enter the question…"
          />
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
            <QFInput
              :model-value="questionModal.marks"
              label="Marks"
              type="number"
              @update:model-value="(v) => (questionModal.marks = +v)"
            />
            <QFSelect
              v-model="questionModal.type"
              label="Type"
              :options="['Short Answer', 'Long Answer', 'MCQ']"
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
