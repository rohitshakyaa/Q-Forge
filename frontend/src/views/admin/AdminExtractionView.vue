<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import {
  QFAIHint,
  QFBadge,
  QFButton,
  QFInput,
  QFModal,
  QFPageHeader,
  QFQuestionText,
  QFSelect,
} from '../../components/qf';
import { useCatalogStore } from '../../stores/catalog';
import {
  type Candidate,
  QUESTION_TYPES,
  type ReviewStatus,
  useExtractionStore,
} from '../../stores/extraction';

const route = useRoute();
const catalog = useCatalogStore();
const extraction = useExtractionStore();

type Filter = 'all' | ReviewStatus;

const uploadId = route.query.upload ? Number(route.query.upload) : undefined;
const selectedSubject = ref<string>((route.query.subject as string) ?? '');
const filter = ref<Filter>('pending');
const actionError = ref<string | null>(null);
const skippedNotice = ref<string | null>(null);

/** Per-candidate edit buffers, keyed by id. Absent means "not editing". */
const drafts = reactive<
  Record<number, { text: string; marks: string; type: string; unitId: string; additionalUnitIds: number[] }>
>({});

const subjectOptions = computed(() => catalog.subjects.map((s) => ({ value: s.code, label: s.code })));
const unitOptions = computed(() => [
  { value: '', label: '— Unassigned —' },
  ...extraction.units.map((u) => ({ value: String(u.id), label: u.name })),
]);

const candidates = computed(() => extraction.candidates);
const visible = computed(() =>
  filter.value === 'all' ? candidates.value : candidates.value.filter((c) => c.status === filter.value),
);
const pending = computed(() => candidates.value.filter((c) => c.status === 'pending'));

/** Candidates grouped under the unit they are tagged to, in syllabus order. */
const byUnit = computed(() =>
  extraction.units.map((unit) => ({
    unit,
    items: visible.value.filter((c) => c.unitId === unit.id),
  })),
);

// The parser leaves these untagged; they cannot be approved until someone assigns a unit.
const unassigned = computed(() => visible.value.filter((c) => c.unitId === null));

const countsLabel = computed(() => {
  const source = uploadId ? `Upload #${uploadId}` : selectedSubject.value || 'All subjects';

  return `${source} · ${candidates.value.length} extracted · ${pending.value.length} pending`;
});

const load = async () => {
  await extraction.fetchCandidates({
    subject: selectedSubject.value || undefined,
    upload: uploadId,
    status: filter.value,
  });

  // With no subject filter the queue can span subjects; unit assignment then needs
  // whichever subject the candidates actually came from.
  const code = selectedSubject.value || candidates.value[0]?.subjectCode;
  if (code) await extraction.fetchUnits(code);
};

const isEditing = (id: number) => id in drafts;

const beginEdit = (c: Candidate) => {
  drafts[c.id] = {
    text: c.text,
    marks: c.marks === null ? '' : String(c.marks),
    type: c.type,
    // M6 Phase 3: a candidate the backend could not auto-assign (suggestions all
    // below threshold, or none) still opens with the top suggestion pre-selected
    // — one click to confirm, still the human's call to change.
    unitId:
      c.unitId !== null
        ? String(c.unitId)
        : c.suggestedUnits.length > 0
          ? String(c.suggestedUnits[0].unitId)
          : '',
    additionalUnitIds: c.unitIds.filter((u) => u !== c.unitId),
  };
};

/** M6 Phase 3: resolve a suggested unit id to its name (suggestions may cite a unit outside the current filter — hide those). */
const unitName = (unitId: number) => extraction.units.find((u) => u.id === unitId)?.name ?? null;

/** Apply a suggestion chip: open (or update) the edit form with that unit selected. */
const applySuggestion = (c: Candidate, unitId: number) => {
  if (!isEditing(c.id)) beginEdit(c);
  drafts[c.id].unitId = String(unitId);
};

/** Units offered as "also covers": everything except the draft's primary. */
const otherUnits = (id: number) =>
  extraction.units.filter((u) => String(u.id) !== drafts[id].unitId);

const toggleAdditionalUnit = (id: number, unitId: number) => {
  const list = drafts[id].additionalUnitIds;
  const idx = list.indexOf(unitId);
  if (idx === -1) list.push(unitId);
  else list.splice(idx, 1);
};

const cancelEdit = (id: number) => {
  delete drafts[id];
};

const draftPatch = (id: number) => {
  const draft = drafts[id];

  return {
    text: draft.text,
    type: draft.type,
    marks: draft.marks === '' ? null : Number(draft.marks),
    unitId: draft.unitId === '' ? null : Number(draft.unitId),
    additionalUnitIds: draft.additionalUnitIds,
  };
};

/** Surfaces the backend's validation message — e.g. "a unit is required". */
const withErrorHandling = async (fn: () => Promise<void>) => {
  actionError.value = null;
  try {
    await fn();
  } catch (e: unknown) {
    const data = (e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })
      .response?.data;
    const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
    actionError.value = first ?? data?.message ?? 'Something went wrong.';
  }
};

const saveEdit = (id: number) =>
  withErrorHandling(async () => {
    await extraction.save(id, draftPatch(id));
    cancelEdit(id);
  });

const approve = (c: Candidate) =>
  withErrorHandling(async () => {
    // An open editor's corrections are applied as part of the approval.
    await extraction.approve(c.id, isEditing(c.id) ? draftPatch(c.id) : {});
    cancelEdit(c.id);
  });

const reject = (c: Candidate) =>
  withErrorHandling(async () => {
    await extraction.reject(c.id);
    cancelEdit(c.id);
  });

const approveAllPending = () =>
  withErrorHandling(async () => {
    skippedNotice.value = null;
    const skipped = await extraction.bulkApprove(pending.value.map((c) => c.id));
    if (skipped.length > 0) {
      const dupes = skipped.filter((s) => s.reason === 'duplicate').length;
      const incomplete = skipped.length - dupes;
      const parts: string[] = [];
      if (incomplete > 0) parts.push(`${incomplete} still need a unit or marks`);
      if (dupes > 0) parts.push(`${dupes} are duplicates that need an explicit decision`);
      skippedNotice.value = `${skipped.length} question(s) were skipped — ${parts.join('; ')}.`;
    }
  });

const rejectAllPending = () =>
  withErrorHandling(() => extraction.bulkReject(pending.value.map((c) => c.id)));

const statusVariant = (s: ReviewStatus): 'success' | 'danger' | 'warn' =>
  s === 'approved' ? 'success' : s === 'rejected' ? 'danger' : 'warn';

// Exact-text duplicate flag (independent of the semantic `≈ similar` flag). The
// pool is approved+pending only, so an approved match is the strongest signal.
const dupLabel = (c: Candidate): string => {
  const approved = c.duplicates.filter((d) => d.status === 'approved').length;
  const suffix = approved > 0 ? ` (${approved} approved)` : '';
  return `duplicate of ${c.duplicates.length} existing${suffix}`;
};
const dupTitle = (c: Candidate): string =>
  c.duplicates.map((d) => `Q#${d.questionId} (${d.status})`).join(', ');

// A flagged lookalike (exact duplicate or semantic ≈) points at a bank question
// that isn't in this queue, so we fetch its full text on demand and show it in a
// modal — the reviewer sees exactly what the candidate collides with.
type Lookalike = { id: number; status: ReviewStatus; score?: number; question: Candidate | null };
const lookalike = reactive<{ open: boolean; title: string; loading: boolean; error: string | null; items: Lookalike[] }>({
  open: false,
  title: '',
  loading: false,
  error: null,
  items: [],
});

async function openLookalikes(title: string, refs: Array<{ id: number; status: ReviewStatus; score?: number }>) {
  lookalike.open = true;
  lookalike.title = title;
  lookalike.loading = true;
  lookalike.error = null;
  lookalike.items = refs.map((r) => ({ id: r.id, status: r.status, score: r.score, question: null }));
  try {
    const fetched = await Promise.all(refs.map((r) => extraction.fetchQuestion(r.id)));
    lookalike.items = refs.map((r, i) => ({ id: r.id, status: r.status, score: r.score, question: fetched[i] }));
  } catch {
    lookalike.error = 'Could not load the question(s) — they may have been deleted.';
  } finally {
    lookalike.loading = false;
  }
}

const showDuplicates = (c: Candidate) =>
  openLookalikes('Duplicate of', c.duplicates.map((d) => ({ id: d.questionId, status: d.status })));

const showSimilar = (c: Candidate) => {
  if (!c.similar) return;
  // The semantic flag doesn't carry the match's status; the modal reads it from
  // the fetched question, so a neutral placeholder is fine here.
  openLookalikes('Most similar to', [{ id: c.similar.questionId, status: 'approved', score: c.similar.score }]);
};

onMounted(async () => {
  if (catalog.subjects.length === 0) await catalog.fetchSubjects();
  await load();
});

watch([filter, selectedSubject], load);
</script>

<template>
  <div class="qf-content qf-anim-in">
    <QFPageHeader
      title="Question Extraction Review"
      :subtitle="countsLabel"
      :breadcrumbs="[
        { label: 'Dashboard', to: '/admin' },
        { label: 'Past Papers', to: '/admin/upload' },
        { label: 'Question Extraction Review' },
      ]"
    >
      <template #actions>
        <QFButton variant="secondary" :disabled="pending.length === 0" @click="rejectAllPending">
          Reject All
        </QFButton>
        <QFButton variant="primary" :disabled="pending.length === 0" @click="approveAllPending">
          Approve All ({{ pending.length }})
        </QFButton>
      </template>
    </QFPageHeader>

    <QFAIHint style="margin-bottom: 20px">
      Extracted questions land here as <strong>pending</strong> — none of them reach the generator
      until approved. A question with no unit or no marks cannot be approved: the parser could not
      read them off the paper, so assign them below.
      <br />
      <span style="color: var(--text3)">
        ⚠ Extraction may be incomplete on scanned or photocopied papers. Those pages are read with
        OCR (marked with the <strong>OCR</strong> badge), and OCR has limits: pen marks or smudges
        over a question number can make that question vanish or merge into its neighbor, faint
        print can garble words, and marks like <em>[2+8]</em> may be misread and left empty. Papers
        that print no per-question marks at all are left blank on purpose — better missing than
        wrong. Always skim the original PDF once and fix anything off via Edit before approving.
      </span>
    </QFAIHint>

    <div v-if="actionError" style="margin-bottom: 14px; font-size: 13px; color: var(--danger)">
      {{ actionError }}
    </div>
    <div v-if="skippedNotice" style="margin-bottom: 14px; font-size: 13px; color: var(--warn)">
      {{ skippedNotice }}
    </div>

    <div style="display: flex; gap: 16px; align-items: flex-end; margin-bottom: 20px; flex-wrap: wrap">
      <div class="qf-tabs" style="display: inline-flex">
        <div
          v-for="f in (['all', 'pending', 'approved', 'rejected'] as const)"
          :key="f"
          :class="['qf-tab', filter === f && 'active']"
          style="text-transform: capitalize"
          @click="filter = f"
        >
          {{ f }}
          <template v-if="f === 'pending'">({{ pending.length }})</template>
        </div>
      </div>
      <div v-if="!uploadId" style="min-width: 200px">
        <QFSelect
          v-model="selectedSubject"
          label="Subject"
          :options="[{ value: '', label: 'All subjects' }, ...subjectOptions]"
        />
      </div>
    </div>

    <div v-if="extraction.loading" style="padding: 24px; color: var(--text3); font-size: 13px">
      Loading candidates…
    </div>

    <div v-else-if="candidates.length === 0" style="padding: 24px; color: var(--text3); font-size: 13px">
      No extracted questions here yet. Upload a past paper to populate the queue.
    </div>

    <div v-else style="display: flex; flex-direction: column; gap: 20px">
      <section v-for="group in byUnit" :key="group.unit.id">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px; padding: 0 4px">
          <span
            style="
              font-family: var(--font-head);
              font-weight: 600;
              font-size: 14px;
              color: var(--text);
            "
          >{{ group.unit.name }}</span>
          <QFBadge variant="neutral">{{ group.items.length }} questions</QFBadge>
        </div>

        <div
          v-if="group.items.length === 0"
          style="
            padding: 16px;
            text-align: center;
            font-size: 12.5px;
            color: var(--text3);
            background: var(--bg1);
            border: 1px dashed var(--border);
            border-radius: var(--radius-lg);
          "
        >
          No questions tagged to this unit yet.
        </div>

        <div v-else style="display: flex; flex-direction: column; gap: 10px">
          <div
            v-for="c in group.items"
            :key="c.id"
            :style="{
              background: 'var(--bg1)',
              border: `1px solid ${isEditing(c.id) ? 'var(--cyan)' : 'var(--border)'}`,
              borderRadius: 'var(--radius-lg)',
              padding: '14px 16px',
            }"
          >
            <!-- view mode -->
            <div v-if="!isEditing(c.id)" style="display: flex; align-items: flex-start; gap: 12px">
              <div style="flex: 1; min-width: 0">
                <QFQuestionText
                  :text="c.text"
                  style="font-size: 13.5px; line-height: 1.6; margin-bottom: 8px; color: var(--text)"
                />
                <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center">
                  <QFBadge variant="cyan">{{ group.unit.name }}</QFBadge>
                  <!-- M6 Phase 3b: the unit came from RAG, not the paper — flag it until a human confirms -->
                  <QFBadge v-if="c.autoAssigned && c.status === 'pending'" variant="ai" dot>
                    auto-assigned · {{ Math.round(c.autoAssigned.score * 100) }}%
                  </QFBadge>
                  <QFBadge v-if="c.marks !== null" variant="neutral">{{ c.marks }} marks</QFBadge>
                  <QFBadge v-else variant="warn">marks not detected</QFBadge>
                  <QFBadge variant="neutral">{{ c.type }}</QFBadge>
                  <QFBadge v-if="c.ocr" variant="ai" dot>OCR</QFBadge>
                  <!-- M6: nearest-lookalike flag — informational, never blocks approval. Click to read it. -->
                  <QFBadge
                    v-if="c.similar"
                    variant="warn"
                    dot
                    style="cursor: pointer"
                    title="Click to view the similar question"
                    @click="showSimilar(c)"
                  >
                    ≈ Q#{{ c.similar.questionId }} ({{ Math.round(c.similar.score * 100) }}% similar)
                  </QFBadge>
                  <!-- Exact-text duplicate of existing bank/queue questions — bulk approve skips these; decide per-item. Click to read them. -->
                  <QFBadge
                    v-if="c.duplicates.length > 0"
                    variant="danger"
                    dot
                    style="cursor: pointer"
                    :title="`${dupTitle(c)} — click to view`"
                    @click="showDuplicates(c)"
                  >
                    {{ dupLabel(c) }}
                  </QFBadge>
                  <span v-if="c.page" style="font-size: 11px; color: var(--text3)">p.{{ c.page }}</span>
                  <span v-if="c.unitHint" style="font-size: 11px; color: var(--text3)">
                    hint: {{ c.unitHint }}
                  </span>
                </div>
              </div>
              <div style="display: flex; flex-direction: column; gap: 6px; flex-shrink: 0">
                <QFButton variant="secondary" size="sm" @click="beginEdit(c)">Edit</QFButton>
                <template v-if="c.status === 'pending'">
                  <QFButton variant="primary" size="sm" @click="approve(c)">✓ Approve</QFButton>
                  <QFButton variant="danger" size="sm" @click="reject(c)">✕ Reject</QFButton>
                </template>
                <QFBadge v-else :variant="statusVariant(c.status)" dot>
                  {{ c.status === 'approved' ? 'Approved' : 'Rejected' }}
                </QFBadge>
              </div>
            </div>

            <!-- edit mode -->
            <div v-else style="display: flex; flex-direction: column; gap: 14px">
              <QFInput
                :model-value="drafts[c.id].text"
                label="Question text"
                type="textarea"
                :rows="3"
                @update:model-value="(v) => (drafts[c.id].text = String(v))"
              />
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                <QFSelect v-model="drafts[c.id].unitId" label="Unit" :options="unitOptions" />
                <QFInput
                  :model-value="drafts[c.id].marks"
                  label="Marks"
                  type="number"
                  @update:model-value="(v) => (drafts[c.id].marks = String(v))"
                />
                <QFSelect v-model="drafts[c.id].type" label="Type" :options="QUESTION_TYPES" />
              </div>
              <div v-if="drafts[c.id].unitId !== '' && otherUnits(c.id).length > 0">
                <div style="font-size: 12px; color: var(--text3); margin-bottom: 6px">
                  Also covers (optional)
                </div>
                <div style="display: flex; flex-wrap: wrap; gap: 10px">
                  <label
                    v-for="u in otherUnits(c.id)"
                    :key="u.id"
                    style="display: flex; align-items: center; gap: 6px; font-size: 12.5px; cursor: pointer"
                  >
                    <input
                      type="checkbox"
                      :checked="drafts[c.id].additionalUnitIds.includes(u.id)"
                      @change="toggleAdditionalUnit(c.id, u.id)"
                    />
                    {{ u.name }}
                  </label>
                </div>
              </div>
              <div style="display: flex; gap: 8px; justify-content: flex-end">
                <QFButton variant="secondary" size="sm" @click="cancelEdit(c.id)">Cancel</QFButton>
                <QFButton variant="secondary" size="sm" @click="saveEdit(c.id)">Save changes</QFButton>
                <QFButton v-if="c.status === 'pending'" variant="primary" size="sm" @click="approve(c)">
                  Save &amp; Approve
                </QFButton>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section v-if="unassigned.length > 0">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px; padding: 0 4px">
          <span
            style="
              font-family: var(--font-head);
              font-weight: 600;
              font-size: 14px;
              color: var(--warn);
            "
          >⚠ Unassigned</span>
          <QFBadge variant="warn">{{ unassigned.length }} questions</QFBadge>
          <span style="font-size: 12px; color: var(--text3)">
            The parser found no unit heading for these — assign one via Edit before approving.
          </span>
        </div>

        <div style="display: flex; flex-direction: column; gap: 10px">
          <div
            v-for="c in unassigned"
            :key="c.id"
            :style="{
              background: 'var(--bg1)',
              border: `1px solid ${isEditing(c.id) ? 'var(--cyan)' : 'var(--warn)'}`,
              borderRadius: 'var(--radius-lg)',
              padding: '14px 16px',
            }"
          >
            <div v-if="!isEditing(c.id)" style="display: flex; align-items: flex-start; gap: 12px">
              <div style="flex: 1; min-width: 0">
                <QFQuestionText
                  :text="c.text"
                  style="font-size: 13.5px; line-height: 1.6; margin-bottom: 8px; color: var(--text)"
                />
                <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center">
                  <QFBadge variant="warn">no unit</QFBadge>
                  <QFBadge v-if="c.marks !== null" variant="neutral">{{ c.marks }} marks</QFBadge>
                  <QFBadge v-else variant="warn">marks not detected</QFBadge>
                  <QFBadge variant="neutral">{{ c.type }}</QFBadge>
                  <QFBadge v-if="c.ocr" variant="ai" dot>OCR</QFBadge>
                  <!-- M6: nearest-lookalike flag — informational, never blocks approval. Click to read it. -->
                  <QFBadge
                    v-if="c.similar"
                    variant="warn"
                    dot
                    style="cursor: pointer"
                    title="Click to view the similar question"
                    @click="showSimilar(c)"
                  >
                    ≈ Q#{{ c.similar.questionId }} ({{ Math.round(c.similar.score * 100) }}% similar)
                  </QFBadge>
                  <!-- Exact-text duplicate of existing bank/queue questions — bulk approve skips these; decide per-item. Click to read them. -->
                  <QFBadge
                    v-if="c.duplicates.length > 0"
                    variant="danger"
                    dot
                    style="cursor: pointer"
                    :title="`${dupTitle(c)} — click to view`"
                    @click="showDuplicates(c)"
                  >
                    {{ dupLabel(c) }}
                  </QFBadge>
                  <!-- M6 Phase 3: suggestions that fell below the auto-assign bar — click to apply, human confirms on approve -->
                  <template v-for="s in c.suggestedUnits" :key="s.unitId">
                    <button
                      v-if="unitName(s.unitId)"
                      :style="{
                        fontSize: '11px',
                        padding: '2px 8px',
                        borderRadius: '999px',
                        border: '1px dashed var(--cyan)',
                        color: 'var(--cyan)',
                        background: 'transparent',
                        cursor: 'pointer',
                      }"
                      :title="`Suggested from course material (similarity ${Math.round(s.score * 100)}%) — click to apply`"
                      @click="applySuggestion(c, s.unitId)"
                    >
                      💡 {{ unitName(s.unitId) }} · {{ Math.round(s.score * 100) }}%
                    </button>
                  </template>
                  <span v-if="c.unitHint" style="font-size: 11px; color: var(--text3)">
                    hint: {{ c.unitHint }}
                  </span>
                </div>
              </div>
              <div style="display: flex; flex-direction: column; gap: 6px; flex-shrink: 0">
                <QFButton variant="primary" size="sm" @click="beginEdit(c)">Assign unit</QFButton>
                <QFButton v-if="c.status === 'pending'" variant="danger" size="sm" @click="reject(c)">
                  ✕ Reject
                </QFButton>
              </div>
            </div>

            <div v-else style="display: flex; flex-direction: column; gap: 14px">
              <QFInput
                :model-value="drafts[c.id].text"
                label="Question text"
                type="textarea"
                :rows="3"
                @update:model-value="(v) => (drafts[c.id].text = String(v))"
              />
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                <QFSelect v-model="drafts[c.id].unitId" label="Unit" :options="unitOptions" />
                <QFInput
                  :model-value="drafts[c.id].marks"
                  label="Marks"
                  type="number"
                  @update:model-value="(v) => (drafts[c.id].marks = String(v))"
                />
                <QFSelect v-model="drafts[c.id].type" label="Type" :options="QUESTION_TYPES" />
              </div>
              <div v-if="drafts[c.id].unitId !== '' && otherUnits(c.id).length > 0">
                <div style="font-size: 12px; color: var(--text3); margin-bottom: 6px">
                  Also covers (optional)
                </div>
                <div style="display: flex; flex-wrap: wrap; gap: 10px">
                  <label
                    v-for="u in otherUnits(c.id)"
                    :key="u.id"
                    style="display: flex; align-items: center; gap: 6px; font-size: 12.5px; cursor: pointer"
                  >
                    <input
                      type="checkbox"
                      :checked="drafts[c.id].additionalUnitIds.includes(u.id)"
                      @change="toggleAdditionalUnit(c.id, u.id)"
                    />
                    {{ u.name }}
                  </label>
                </div>
              </div>
              <div style="display: flex; gap: 8px; justify-content: flex-end">
                <QFButton variant="secondary" size="sm" @click="cancelEdit(c.id)">Cancel</QFButton>
                <QFButton variant="secondary" size="sm" @click="saveEdit(c.id)">Save changes</QFButton>
                <QFButton v-if="c.status === 'pending'" variant="primary" size="sm" @click="approve(c)">
                  Save &amp; Approve
                </QFButton>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>

    <!-- Full text of a flagged lookalike (exact duplicate or ≈ similar), fetched on click. -->
    <QFModal :open="lookalike.open" :title="lookalike.title" :width="640" @close="lookalike.open = false">
      <div v-if="lookalike.loading" style="padding: 8px 0; color: var(--text3)">Loading…</div>
      <div v-else-if="lookalike.error" style="color: var(--danger)">{{ lookalike.error }}</div>
      <div v-else style="display: flex; flex-direction: column; gap: 14px">
        <div
          v-for="item in lookalike.items"
          :key="item.id"
          style="border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 12px 14px"
        >
          <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 8px">
            <QFBadge variant="neutral">Q#{{ item.id }}</QFBadge>
            <QFBadge v-if="item.question" :variant="statusVariant(item.question.status)" dot>
              {{ item.question.status }}
            </QFBadge>
            <QFBadge v-if="item.score != null" variant="warn" dot>
              {{ Math.round(item.score * 100) }}% similar
            </QFBadge>
            <span v-if="item.question?.unitName" style="font-size: 11px; color: var(--text3)">
              {{ item.question.unitName }}
            </span>
            <span v-if="item.question?.marks != null" style="font-size: 11px; color: var(--text3)">
              · {{ item.question.marks }} marks
            </span>
          </div>
          <QFQuestionText
            v-if="item.question"
            :text="item.question.text"
            style="font-size: 13.5px; line-height: 1.6; color: var(--text)"
          />
          <div v-else style="color: var(--text3); font-size: 12px">Not found.</div>
        </div>
      </div>
    </QFModal>
  </div>
</template>
