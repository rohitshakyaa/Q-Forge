<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { QFBadge, QFButton, QFPageHeader, QFQuestionText } from '../../components/qf';
import { usePapersStore } from '../../stores/papers';

const route = useRoute();
const router = useRouter();
const store = usePapersStore();

// Preview mode = the just-generated result held in store.current; the id route is
// loaded authoritatively from the server. Every generated paper is auto-saved as a
// draft, so the Save/Discard vs Export choice follows the paper's STATUS, not the
// route — a draft reopened from History must offer Save/Discard, not Export.
const isPreview = computed(() => route.name === 'teacher-paper-preview');
const paperId = computed(() => Number(route.params.id));
const paper = computed(() => (isPreview.value ? store.current : store.getById(paperId.value)));
const isDraft = computed(() => paper.value?.status === 'draft');

onMounted(() => {
  // A preview already lives in the store; only saved papers are fetched by id
  // (so direct links, refreshes, and post-export status changes work).
  if (!isPreview.value) store.fetchById(paperId.value);
});

// Save = promote the draft to a kept paper. Works both from the preview and from a
// draft reopened in History (the by-id promote needs no generation seed).
const savePaper = async () => {
  const id = paper.value?.id ?? null;
  const savedId = id !== null ? await store.saveDraft(id) : await store.savePaper();
  if (savedId !== null) router.replace(`/teacher/paper/${savedId}`);
};

// Discard = throw the draft away, then leave the page.
const discardPaper = async () => {
  const id = paper.value?.id ?? null;
  if (id !== null) await store.discardPaper(id);
  router.push(isPreview.value ? '/teacher/generate' : '/teacher/history');
};
</script>

<template>
  <div v-if="paper" class="qf-content qf-anim-in">
    <QFPageHeader
      :title="paper.name"
      :subtitle="isDraft
        ? `${paper.subject} · ${paper.marks} marks · ${paper.duration} minutes · Draft — not saved yet`
        : `${paper.subject} · ${paper.marks} marks · ${paper.duration} minutes · Generated ${paper.date}`"
      :breadcrumbs="[
        { label: 'Dashboard', to: '/teacher' },
        { label: 'Generate', to: '/teacher/generate' },
        { label: isDraft ? 'Draft' : paper.name },
      ]"
    >
      <template #actions>
        <template v-if="isDraft">
          <QFButton variant="secondary" size="sm" :disabled="store.saving" @click="discardPaper">
            ← Discard
          </QFButton>
          <QFButton variant="primary" size="sm" :disabled="store.saving" @click="savePaper">
            {{ store.saving ? 'Saving…' : 'Save Paper' }}
          </QFButton>
        </template>
        <QFButton
          v-else
          variant="primary"
          size="sm"
          @click="router.push(`/teacher/export/${paper.id}`)"
        >
          Export →
        </QFButton>
      </template>
    </QFPageHeader>

    <div style="max-width: 740px; margin: 0 auto">
      <div
        style="
          background: var(--bg1);
          border: 1px solid var(--border);
          border-radius: var(--radius-lg);
          padding: 28px 32px;
          margin-bottom: 20px;
          text-align: center;
        "
      >
        <div
          style="
            font-family: var(--font-head);
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.1em;
            color: var(--text3);
            text-transform: uppercase;
            margin-bottom: 8px;
          "
        >Institute of Technology</div>
        <div style="font-family: var(--font-head); font-size: 20px; font-weight: 800; margin-bottom: 4px">
          {{ paper.name }} ({{ paper.subject }})
        </div>
        <div style="font-family: var(--font-head); font-size: 15px; font-weight: 500; margin-bottom: 12px">
          Examination — {{ paper.date }}
        </div>
        <div style="display: flex; justify-content: center; gap: 32px; font-size: 13px; color: var(--text2)">
          <span>Duration: {{ paper.duration }} minutes</span>
          <span>Maximum Marks: {{ paper.marks }}</span>
        </div>
        <div
          style="
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid var(--border);
            font-size: 12.5px;
            color: var(--text3);
            text-align: left;
          "
        >
          <strong>Instructions:</strong> Answer all questions in Section A. Attempt any 3 from Section B. Write clearly. Show all workings.
        </div>
      </div>

      <div v-for="(sec, si) in paper.sections" :key="si" style="margin-bottom: 24px">
        <div
          style="
            background: var(--bg2);
            border-radius: var(--radius);
            padding: 10px 16px;
            margin-bottom: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
          "
        >
          <span style="font-family: var(--font-head); font-weight: 700; font-size: 14px">
            {{ sec.label }}
          </span>
          <span style="font-size: 12.5px; color: var(--text3); font-style: italic">{{ sec.note }}</span>
        </div>
        <div style="display: flex; flex-direction: column; gap: 10px">
          <div
            v-for="q in sec.questions"
            :key="q.no"
            style="
              background: var(--bg1);
              border: 1px solid var(--border);
              border-radius: var(--radius-lg);
              padding: 14px 18px;
              position: relative;
            "
          >
            <div style="display: flex; gap: 14px; align-items: flex-start">
              <span
                style="
                  font-family: var(--font-mono);
                  font-size: 13px;
                  font-weight: 700;
                  color: var(--cyan);
                  flex-shrink: 0;
                  margin-top: 2px;
                "
              >{{ q.no }}.</span>
              <div style="flex: 1">
                <QFQuestionText :text="q.text" style="font-size: 14px; line-height: 1.7; color: var(--text)" />
                <div style="display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap">
                  <span v-if="q.unit" class="qf-chip">{{ q.unit }}</span>
                  <QFBadge v-if="q.ai" variant="ai">✦ AI Generated</QFBadge>
                </div>
              </div>
              <span
                style="
                  font-family: var(--font-mono);
                  font-size: 13px;
                  font-weight: 700;
                  color: var(--text2);
                  flex-shrink: 0;
                "
              >[{{ q.marks }}M]</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div v-else-if="isPreview" class="qf-content qf-anim-in" style="text-align: center; padding: 64px 0">
    <div style="font-size: 15px; color: var(--text2); margin-bottom: 6px">This preview is no longer available.</div>
    <div style="font-size: 13px; color: var(--text3); margin-bottom: 18px">
      Previews aren't saved until you click “Save Paper”. Generate one to continue.
    </div>
    <QFButton variant="primary" size="sm" @click="router.push('/teacher/generate')">Generate a paper →</QFButton>
  </div>
</template>
