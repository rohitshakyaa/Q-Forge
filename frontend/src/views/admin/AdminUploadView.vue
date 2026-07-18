<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import {
  QFAIHint,
  QFBadge,
  QFButton,
  QFCard,
  QFPageHeader,
  QFProgress,
  QFSelect,
} from '../../components/qf';
import { useCatalogStore } from '../../stores/catalog';
import { type DocumentType, type Upload, type UploadStatus, useExtractionStore } from '../../stores/extraction';

const router = useRouter();
const catalog = useCatalogStore();
const extraction = useExtractionStore();

// QFSelect emits strings; coerce at the call site rather than lying about the type.
const docType = ref<string>('past_paper');
const selectedSubject = ref<string>('');
const examYear = ref('');
const dragover = ref(false);
const submitting = ref(false);
const formError = ref<string | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);

const typeOptions = [
  { value: 'past_paper', label: 'Past paper' },
  { value: 'syllabus', label: 'Syllabus' },
];

// Exam year is optional provenance metadata (stored on each extracted question's
// attributes.exam_year). A fixed dropdown of Gregorian years keeps the value
// consistent — free text let "2023", "2023 AD", "2022/23" all mean one year,
// which breaks any later year-based filtering or exclusion. Newest first; the
// blank option means "not specified".
const currentYear = new Date().getFullYear();
const yearOptions = [
  { value: '', label: 'Year (optional)' },
  ...Array.from({ length: 30 }, (_, i) => {
    const y = String(currentYear - i);

    return { value: y, label: y };
  }),
];

const subjectOptions = computed(() =>
  catalog.subjects.map((s) => ({ value: s.id as number, label: `${s.code} — ${s.name}` })),
);

// A past paper's extracted questions need a subject to belong to; a syllabus does not.
const subjectRequired = computed(() => docType.value === 'past_paper');

const uploads = computed(() => extraction.uploads);

const statusConfig: Record<UploadStatus, { badge: 'success' | 'warn' | 'indigo' | 'danger'; label: string }> = {
  uploaded: { badge: 'warn', label: 'Queued' },
  processing: { badge: 'warn', label: 'Processing' },
  parsed: { badge: 'success', label: 'Processed' },
  failed: { badge: 'danger', label: 'Failed' },
};

const pipeline = [
  { step: 'PDF Upload', desc: 'File validated and stored on the shared volume', icon: '⬆' },
  { step: 'Queued', desc: 'ProcessDocumentUpload dispatched to Redis/Horizon', icon: '◷' },
  { step: 'Text + OCR', desc: 'Digital text, with Tesseract per scanned page', icon: '◈' },
  { step: 'Structure Parsing', desc: 'Questions, marks, and unit hints identified', icon: '⬡' },
  { step: 'Review Queue', desc: 'Admin approves candidates into the bank', icon: '◎' },
];

/** How far along the pipeline an in-flight upload has reached. */
const stepsDone = (u: Upload): number => (u.status === 'uploaded' ? 2 : u.status === 'processing' ? 3 : 5);

const openReview = (u: Upload) => {
  router.push({ path: '/admin/review', query: { upload: u.id, subject: u.subjectCode ?? undefined } });
};

// A syllabus goes to the subject/unit confirmation page instead of the question queue.
const openSyllabusImport = (u: Upload) => router.push(`/admin/syllabus/${u.id}`);

const submit = async (file: File) => {
  formError.value = null;

  if (subjectRequired.value && !selectedSubject.value) {
    formError.value = 'Choose a subject before uploading a past paper.';

    return;
  }

  submitting.value = true;
  try {
    await extraction.createUpload({
      file,
      type: docType.value as DocumentType,
      subjectId: selectedSubject.value ? Number(selectedSubject.value) : null,
      examYear: examYear.value.trim() || undefined,
    });
  } catch (e: unknown) {
    const response = (e as { response?: { data?: { message?: string } } }).response;
    formError.value = response?.data?.message ?? 'Upload failed.';
  } finally {
    submitting.value = false;
  }
};

const onPick = (e: Event) => {
  const input = e.target as HTMLInputElement;
  const file = input.files?.[0];
  if (file) submit(file);
  input.value = ''; // Allow re-picking the same file.
};

const onDrop = (e: DragEvent) => {
  e.preventDefault();
  dragover.value = false;
  const file = e.dataTransfer?.files?.[0];
  if (file) submit(file);
};

const remove = async (u: Upload) => {
  if (confirm(`Delete "${u.filename}" and its stored file?`)) await extraction.deleteUpload(u.id);
};

onMounted(async () => {
  if (catalog.subjects.length === 0) await catalog.fetchSubjects();
  selectedSubject.value = catalog.subjects[0]?.id ? String(catalog.subjects[0].id) : '';
  await extraction.fetchUploads();
});

// The store polls in-flight uploads on an interval; stop it with the screen.
onUnmounted(() => extraction.stopAllWatching());
</script>

<template>
  <div class="qf-content qf-anim-in">
    <QFPageHeader
      title="Past Papers Upload"
      subtitle="Drop past question papers or syllabus PDFs — the Python service extracts questions, marks, and unit hints for review"
      :breadcrumbs="[
        { label: 'Dashboard', to: '/admin' },
        { label: 'Past Papers Upload' },
      ]"
    />

    <div class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-6">
      <div>
        <div class="flex flex-col md:flex-row md:items-end gap-3.5 mb-4 p-4 bg-bg1 border border-border rounded-[var(--radius-lg)]">
          <div class="md:flex-1">
            <QFSelect v-model="docType" label="Document type" :options="typeOptions" />
          </div>
          <div class="md:flex-[1.4]">
            <QFSelect
              v-model="selectedSubject"
              :label="subjectRequired ? 'Tag uploads to subject' : 'Subject (optional)'"
              :options="subjectOptions"
            />
          </div>
          <div class="md:flex-1">
            <QFSelect v-model="examYear" label="Exam year" :options="yearOptions" />
          </div>
        </div>

        <div v-if="formError" class="mb-4" style="font-size: 13px; color: var(--danger)">
          {{ formError }}
        </div>

        <input ref="fileInput" type="file" accept="application/pdf" style="display: none" @change="onPick" />

        <div
          :class="['qf-dropzone', dragover && 'dragover']"
          style="margin-bottom: 24px"
          @dragover.prevent="dragover = true"
          @dragleave="dragover = false"
          @drop="onDrop"
          @click="fileInput?.click()"
        >
          <div style="font-size: 36px; opacity: 0.5">⬆</div>
          <div
            style="
              font-family: var(--font-head);
              font-size: 16px;
              font-weight: 600;
              color: var(--text2);
            "
          >{{ submitting ? 'Uploading…' : 'Drop a PDF here or click to upload' }}</div>
          <div style="font-size: 13px; color: var(--text3)">
            Syllabus documents and past question papers · PDF only · Max 50MB
          </div>
          <QFButton variant="secondary" size="sm" :disabled="submitting">Browse files</QFButton>
        </div>

        <QFCard>
          <div
            class="qf-card-header"
            style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 14px"
          >
            <span style="font-family: var(--font-head); font-weight: 600">Uploaded Documents</span>
            <QFBadge variant="neutral">{{ uploads.length }} files</QFBadge>
          </div>

          <div
            v-if="uploads.length === 0"
            style="padding: 24px; text-align: center; font-size: 13px; color: var(--text3)"
          >
            Nothing uploaded yet.
          </div>

          <div v-else>
            <div
              v-for="(u, i) in uploads"
              :key="u.id"
              :style="{
                padding: '14px 20px',
                borderBottom: i < uploads.length - 1 ? '1px solid var(--border)' : 'none',
              }"
            >
              <div
                :style="{
                  display: 'flex',
                  alignItems: 'center',
                  gap: '12px',
                  marginBottom: extraction.isTerminal(u.status) ? '0' : '10px',
                }"
              >
                <div
                  style="
                    width: 36px;
                    height: 36px;
                    background: var(--bg3);
                    border-radius: 8px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 18px;
                    flex-shrink: 0;
                  "
                >📄</div>
                <div style="flex: 1; min-width: 0">
                  <div style="font-weight: 500; font-size: 13.5px; margin-bottom: 2px">{{ u.filename }}</div>
                  <div style="font-size: 12px; color: var(--text3); display: flex; gap: 8px; flex-wrap: wrap">
                    <span>{{ u.type === 'past_paper' ? 'Past paper' : 'Syllabus' }}</span>
                    <span v-if="u.subjectCode">· {{ u.subjectCode }}</span>
                    <span v-if="u.examYear">· Year {{ u.examYear }}</span>
                    <span v-if="u.pages">· {{ u.pages }} pages</span>
                    <span v-if="u.ocrPages">· {{ u.ocrPages }} OCR'd</span>
                    <span v-if="u.type === 'past_paper' && u.questionsCreated !== null">
                      · {{ u.questionsCreated }} questions extracted
                    </span>
                    <span v-if="u.questionsSkipped">· {{ u.questionsSkipped }} duplicates skipped</span>
                    <span v-if="u.importedSubjectId">· imported as a subject</span>
                  </div>
                  <div v-if="u.error" style="font-size: 12px; color: var(--danger); margin-top: 4px">
                    {{ u.error }}
                  </div>
                </div>
                <QFBadge :variant="statusConfig[u.status].badge">{{ statusConfig[u.status].label }}</QFBadge>
                <QFButton
                  v-if="u.status === 'parsed' && u.type === 'past_paper' && u.questionsCreated"
                  variant="ai"
                  size="sm"
                  @click="openReview(u)"
                >Review →</QFButton>
                <QFButton
                  v-else-if="u.status === 'parsed' && u.type === 'syllabus'"
                  variant="ai"
                  size="sm"
                  @click="openSyllabusImport(u)"
                >{{ u.importedSubjectId ? 'Re-import →' : 'Review subject →' }}</QFButton>
                <QFButton variant="ghost" size="sm" @click="remove(u)">Delete</QFButton>
              </div>

              <div v-if="!extraction.isTerminal(u.status)" style="margin-left: 48px">
                <QFProgress :value="u.progress" ai :label="`Extracting questions… ${u.progress}%`" />
                <div style="margin-top: 8px; display: flex; gap: 16px; font-size: 11.5px; color: var(--text3)">
                  <span
                    v-for="(s, si) in pipeline"
                    :key="s.step"
                    :style="{
                      color: si < stepsDone(u) ? 'var(--success)' : 'var(--text3)',
                      display: 'flex',
                      alignItems: 'center',
                      gap: '3px',
                    }"
                  >{{ si < stepsDone(u) ? '✓' : '' }}{{ s.step }}</span>
                </div>
              </div>
            </div>
          </div>
        </QFCard>
      </div>

      <div class="flex flex-col gap-4">
        <QFCard>
          <div class="qf-card-body">
            <div class="font-head font-semibold mb-3.5">Processing Pipeline</div>
            <div v-for="(s, i) in pipeline" :key="s.step" style="display: flex; gap: 10px; margin-bottom: 14px">
              <div style="display: flex; flex-direction: column; align-items: center; gap: 0">
                <div
                  style="
                    width: 24px;
                    height: 24px;
                    border-radius: 50%;
                    background: var(--bg3);
                    border: 1.5px solid var(--border2);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 11px;
                    color: var(--text3);
                    flex-shrink: 0;
                  "
                >{{ s.icon }}</div>
                <div
                  v-if="i < pipeline.length - 1"
                  style="width: 1px; height: 20px; background: var(--border); margin: 2px 0"
                />
              </div>
              <div style="padding-bottom: 14px">
                <div style="font-size: 13px; font-weight: 500; color: var(--text2)">{{ s.step }}</div>
                <div style="font-size: 11.5px; color: var(--text3); margin-top: 2px">{{ s.desc }}</div>
              </div>
            </div>
          </div>
        </QFCard>
        <QFAIHint>
          Extraction runs asynchronously on the queue — watch it in <code>/horizon</code>. Scanned
          pages fall back to OCR, so text may need correcting in the review queue before approval.
        </QFAIHint>
      </div>
    </div>
  </div>
</template>
