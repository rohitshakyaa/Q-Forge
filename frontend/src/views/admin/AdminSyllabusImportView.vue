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
} from '../../components/qf';
import { useCatalogStore } from '../../stores/catalog';
import { type Course, type Upload, useExtractionStore } from '../../stores/extraction';

const route = useRoute();
const router = useRouter();
const catalog = useCatalogStore();
const extraction = useExtractionStore();

interface UnitDraft {
  number: number;
  name: string;
  hours: string;
  content: string;
  guessed: boolean;
}

const uploadId = Number(route.params.uploadId);

// When launched from a subject's detail page, the target subject is pinned: the import
// must land in that subject (not a new one derived from the PDF), and we return there on
// success. Absent this param the screen behaves as the normal upload-page flow.
const pinnedCode = typeof route.query.subject === 'string' ? route.query.subject : undefined;

const upload = ref<Upload | null>(null);
const loading = ref(true);
const importing = ref(false);
const loadError = ref<string | null>(null);
const actionError = ref<string | null>(null);
const result = ref<string | null>(null);

const selectedIndex = ref(0);
const updateExisting = ref(false);

const subject = reactive({ code: '', name: '', description: '' });
const units = ref<UnitDraft[]>([]);

// A course with no units can't be imported (import requires >= 1 unit), so hide it —
// this drops the junk 0-unit "course" a combined syllabus + model-question PDF produces.
const courses = computed(() => (upload.value?.courses ?? []).filter((c) => c.units.length > 0));
const course = computed<Course | undefined>(() => courses.value[selectedIndex.value]);

const courseOptions = computed(() =>
  courses.value.map((c, i) => ({
    value: String(i),
    label: `${c.code ?? '—'} · ${c.name ?? 'Untitled course'} (${c.units.length} units)`,
  })),
);

/** The subject already in the bank with this code, if any. */
const existingSubject = computed(() =>
  catalog.subjects.find((s) => s.code.toLowerCase() === subject.code.trim().toLowerCase()),
);

// The subject index does not embed units — only the nested endpoint returns them —
// so they are fetched whenever the code resolves to an existing subject.
const existingUnitNames = ref<string[]>([]);

const refreshExistingUnits = async () => {
  const code = existingSubject.value?.code;
  if (!code) {
    existingUnitNames.value = [];

    return;
  }

  await extraction.fetchUnits(code);
  existingUnitNames.value = extraction.units.map((u) => u.name);
};

const normalize = (name: string) => name.toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();

const existingFingerprints = computed(() => new Set(existingUnitNames.value.map(normalize)));

const unitExists = (draft: UnitDraft) =>
  Boolean(existingSubject.value) && existingFingerprints.value.has(normalize(draft.name));

watch(() => subject.code, refreshExistingUnits);

const guessedCount = computed(() => units.value.filter((u) => u.guessed).length);
const blankCount = computed(() => units.value.filter((u) => u.name.trim() === '').length);
const willAddCount = computed(() => units.value.filter((u) => !unitExists(u)).length);

// Two units that normalize to the same name would be rejected by the backend; catch
// it here so the admin sees which rows collide rather than a bare 422.
const duplicateNames = computed(() => {
  const seen = new Map<string, number>();
  const clashes = new Set<number>();

  units.value.forEach((u, index) => {
    const key = normalize(u.name);
    if (!key) return;
    if (seen.has(key)) {
      clashes.add(index);
      clashes.add(seen.get(key) as number);
    } else {
      seen.set(key, index);
    }
  });

  return clashes;
});

const canImport = computed(
  () =>
    !importing.value &&
    subject.code.trim() !== '' &&
    subject.name.trim() !== '' &&
    units.value.length > 0 &&
    blankCount.value === 0 &&
    duplicateNames.value.size === 0,
);

/** Fills the editable form from the selected course. */
const applyCourse = () => {
  const selected = course.value;
  if (!selected) return;

  // A pinned code overrides whatever the PDF parsed, keeping the import bound to the
  // subject the user came from.
  subject.code = pinnedCode ?? selected.code ?? '';
  subject.name = selected.name ?? '';
  subject.description = selected.description ?? '';
  updateExisting.value = false;
  result.value = null;
  actionError.value = null;

  units.value = selected.units.map((u) => ({
    number: u.number,
    name: u.name ?? '',
    hours: u.hours === null ? '' : String(u.hours),
    content: u.content ?? '',
    guessed: u.nameGuessed,
  }));
};

const onCourseChange = (value: string | number) => {
  selectedIndex.value = Number(value);
  applyCourse();
};

const submit = async () => {
  actionError.value = null;
  result.value = null;
  importing.value = true;

  try {
    const outcome = await extraction.importSyllabus(uploadId, {
      subject: {
        code: subject.code.trim(),
        name: subject.name.trim(),
        description: subject.description.trim() || null,
        // Store the document exactly as parsed, not as edited: the markdown is the
        // corpus, while the fields above are the catalog entry.
        syllabus: course.value?.markdown ?? null,
      },
      units: units.value.map((u) => ({
        number: u.number,
        name: u.name.trim(),
        hours: u.hours === '' ? null : Number(u.hours),
        content: u.content.trim() || null,
      })),
      updateExisting: updateExisting.value,
    });

    result.value =
      `${outcome.created_subject ? 'Created' : 'Updated'} ${subject.code} — ` +
      `${outcome.units_created} unit(s) added, ${outcome.units_skipped} already present.`;

    // The catalog is now stale: the new subject and its units must show up here and
    // everywhere else. Refreshing also flips each row to "✓ exists" for a re-import.
    await catalog.fetchSubjects();
    await refreshExistingUnits();

    // Launched from a subject page: close the loop by returning there so the freshly
    // added units and syllabus show up in context.
    if (pinnedCode) router.push(`/admin/subjects/${pinnedCode}`);
  } catch (e: unknown) {
    const data = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
      .response?.data;
    const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
    actionError.value = first ?? data?.message ?? 'Import failed.';
  } finally {
    importing.value = false;
  }
};

onMounted(async () => {
  try {
    if (catalog.subjects.length === 0) await catalog.fetchSubjects();
    upload.value = await extraction.loadUpload(uploadId);

    if (upload.value.type !== 'syllabus') {
      loadError.value = 'This upload is a past paper, not a syllabus.';
    } else if (courses.value.length === 0) {
      loadError.value = 'No course structure was found in this PDF.';
    } else {
      applyCourse();
    }
  } catch {
    loadError.value = 'Could not load this upload.';
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <div class="qf-content qf-anim-in">
    <QFPageHeader
      title="Import Subject from Syllabus"
      :subtitle="upload ? `${upload.filename} · ${upload.pages ?? '—'} pages` : 'Loading…'"
      :breadcrumbs="[
        { label: 'Dashboard', to: '/admin' },
        { label: 'Past Papers', to: '/admin/upload' },
        { label: 'Import Subject' },
      ]"
    />

    <div v-if="loading" style="padding: 24px; color: var(--text3); font-size: 13px">
      Loading the parsed syllabus…
    </div>

    <div v-else-if="loadError" style="padding: 24px; color: var(--danger); font-size: 13px">
      {{ loadError }}
    </div>

    <template v-else>
      <QFAIHint style="margin-bottom: 20px">
        Nothing is created until you press Import. Existing units are never deleted, renamed or
        reordered — an import only <strong>adds the units that are missing</strong>, so it is safe
        to run again after a correction.
      </QFAIHint>

      <div v-if="actionError" style="margin-bottom: 14px; font-size: 13px; color: var(--danger)">
        {{ actionError }}
      </div>
      <div v-if="result" style="margin-bottom: 14px; font-size: 13px; color: var(--success)">
        {{ result }}
        <QFButton variant="ghost" size="sm" @click="router.push('/admin/subjects')">
          View subjects →
        </QFButton>
      </div>

      <QFCard v-if="courses.length > 1" style="margin-bottom: 20px">
        <div class="qf-card-body">
          <div style="font-size: 12.5px; color: var(--text3); margin-bottom: 10px">
            This PDF contains {{ courses.length }} courses. Each import creates one subject.
          </div>
          <QFSelect
            :model-value="String(selectedIndex)"
            label="Course to import"
            :options="courseOptions"
            @update:model-value="onCourseChange"
          />
        </div>
      </QFCard>

      <QFCard style="margin-bottom: 20px">
        <div class="qf-card-body">
          <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 14px">
            <span style="font-family: var(--font-head); font-weight: 600">Subject</span>
            <QFBadge v-if="existingSubject" variant="warn">EXISTS</QFBadge>
            <QFBadge v-else variant="success">NEW</QFBadge>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-[180px_1fr] gap-2.5">
            <QFInput
              v-model="subject.code"
              label="Subject code"
              placeholder="e.g. CSC365"
              :disabled="!!pinnedCode"
              :hint="pinnedCode ? 'Locked — importing into this subject' : undefined"
            />
            <QFInput v-model="subject.name" label="Subject name" />
          </div>
          <div style="margin-top: 12px">
            <QFInput
              v-model="subject.description"
              label="Description"
              type="textarea"
              :rows="2"
            />
          </div>

          <label
            v-if="existingSubject"
            style="display: flex; align-items: center; gap: 8px; margin-top: 12px; font-size: 12.5px; color: var(--text2)"
          >
            <input v-model="updateExisting" type="checkbox" />
            Also update this subject's name and description
            <span style="color: var(--text3)">(the syllabus text always refreshes)</span>
          </label>
          <div v-else style="margin-top: 10px; font-size: 11.5px; color: var(--text3)">
            The parsed syllabus will be stored on the subject as markdown.
          </div>
        </div>
      </QFCard>

      <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px; padding: 0 4px">
        <span style="font-family: var(--font-head); font-weight: 600; font-size: 14px">Units</span>
        <QFBadge variant="neutral">{{ units.length }} parsed</QFBadge>
        <QFBadge v-if="willAddCount !== units.length" variant="cyan">{{ willAddCount }} will be added</QFBadge>
        <QFBadge v-if="guessedCount > 0" variant="warn">{{ guessedCount }} name(s) guessed</QFBadge>
        <QFBadge v-if="blankCount > 0" variant="danger">{{ blankCount }} name(s) missing</QFBadge>
      </div>

      <QFCard>
        <div>
          <div
            v-for="(u, i) in units"
            :key="u.number"
            :style="{
              padding: '12px 16px',
              borderBottom: i < units.length - 1 ? '1px solid var(--border)' : 'none',
              background: duplicateNames.has(i) ? 'var(--danger-dim)' : 'transparent',
            }"
          >
            <div style="display: flex; align-items: flex-end; gap: 12px">
              <div style="width: 34px; font-family: var(--font-mono); font-size: 12px; color: var(--text3); padding-bottom: 10px">
                {{ u.number }}
              </div>
              <div style="flex: 1; min-width: 0">
                <QFInput v-model="u.name" label="Unit name" />
              </div>
              <div style="width: 92px">
                <QFInput v-model="u.hours" label="Hours" type="number" />
              </div>
              <div style="width: 130px; padding-bottom: 10px; text-align: right">
                <QFBadge v-if="unitExists(u)" variant="neutral">✓ exists</QFBadge>
                <QFBadge v-else variant="cyan">+ will add</QFBadge>
              </div>
            </div>
            <div style="display: flex; gap: 10px; margin-top: 6px; margin-left: 46px; font-size: 11.5px">
              <span v-if="u.guessed" style="color: var(--warn)">
                ~ name guessed from the first sub-topic — check it
              </span>
              <span v-if="duplicateNames.has(i)" style="color: var(--danger)">
                duplicates another unit in this list
              </span>
              <span v-if="u.name.trim() === ''" style="color: var(--danger)">
                a name is required before this unit can be created
              </span>
              <span v-if="u.content.trim()" style="color: var(--text3)">
                {{ u.content.length }} chars of syllabus content
              </span>
            </div>
            <div style="margin-top: 8px; margin-left: 46px">
              <QFInput
                v-model="u.content"
                label="Description (markdown)"
                type="textarea"
                :rows="3"
                hint="Extraction can bleed table columns into this text — review and clean it up before importing."
              />
            </div>
          </div>
        </div>
      </QFCard>

      <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px">
        <QFButton
          variant="secondary"
          @click="router.push(pinnedCode ? `/admin/subjects/${pinnedCode}` : '/admin/upload')"
        >Cancel</QFButton>
        <QFButton variant="primary" :disabled="!canImport" @click="submit">
          {{ importing ? 'Importing…' : `Import ${willAddCount} unit(s)` }}
        </QFButton>
      </div>
    </template>
  </div>
</template>
