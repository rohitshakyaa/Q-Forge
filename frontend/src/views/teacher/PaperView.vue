<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
  QFAIHint,
  QFBadge,
  QFButton,
  QFModal,
  QFPageHeader,
} from '../../components/qf';
import { usePapersStore, type PaperQuestion } from '../../stores/papers';

const route = useRoute();
const router = useRouter();
const store = usePapersStore();

const paperId = Number(route.params.id);
const paper = computed(() => store.getById(paperId) ?? store.list[0]);

const editMode = ref(false);
const showReplace = ref<PaperQuestion | null>(null);

const alternatives = [
  "Explain Prim's algorithm for minimum spanning tree with a worked example.",
  'What is the significance of a spanning tree in a graph? Derive the number of possible spanning trees.',
  "Compare Prim's and Kruskal's algorithms for finding MST. When would you prefer each?",
];

const onHover = (e: MouseEvent, enter: boolean) => {
  if (!editMode.value) return;
  (e.currentTarget as HTMLElement).style.borderColor = enter ? 'var(--cyan)' : 'var(--border2)';
};

const onAltHover = (e: MouseEvent, enter: boolean) => {
  (e.currentTarget as HTMLElement).style.borderColor = enter ? 'var(--cyan)' : 'var(--border)';
};
</script>

<template>
  <div v-if="paper" class="qf-content qf-anim-in">
    <QFPageHeader
      :title="paper.name"
      :subtitle="`${paper.subject} · ${paper.marks} marks · ${paper.duration} minutes · Generated ${paper.date}`"
      :breadcrumbs="[
        { label: 'Dashboard', to: '/teacher' },
        { label: 'Generate', to: '/teacher/generate' },
        { label: paper.name },
      ]"
    >
      <template #actions>
        <QFButton variant="ghost" size="sm" @click="editMode = !editMode">
          {{ editMode ? 'Done Editing' : '✏ Edit' }}
        </QFButton>
        <QFButton variant="secondary" size="sm">Save</QFButton>
        <QFButton variant="primary" size="sm" @click="router.push(`/teacher/export/${paper.id}`)">
          Export →
        </QFButton>
      </template>
    </QFPageHeader>

    <QFAIHint v-if="editMode" style="margin-bottom: 16px">
      Edit mode active — click any question to replace it, or drag to reorder. AI can suggest alternatives below.
    </QFAIHint>

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
            :style="{
              background: 'var(--bg1)',
              border: `1px solid ${editMode ? 'var(--border2)' : 'var(--border)'}`,
              borderRadius: 'var(--radius-lg)',
              padding: '14px 18px',
              transition: 'all 0.15s',
              cursor: editMode ? 'pointer' : 'default',
              position: 'relative',
            }"
            @mouseenter="(e) => onHover(e, true)"
            @mouseleave="(e) => onHover(e, false)"
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
                <p style="font-size: 14px; line-height: 1.7; color: var(--text)">{{ q.text }}</p>
                <div style="display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap">
                  <span class="qf-chip">{{ q.unit }}</span>
                  <QFBadge v-if="q.ai" variant="ai">✦ AI Generated</QFBadge>
                </div>
              </div>
              <div
                style="
                  display: flex;
                  flex-direction: column;
                  align-items: flex-end;
                  gap: 6px;
                  flex-shrink: 0;
                "
              >
                <span
                  style="
                    font-family: var(--font-mono);
                    font-size: 13px;
                    font-weight: 700;
                    color: var(--text2);
                  "
                >[{{ q.marks }}M]</span>
                <QFButton
                  v-if="editMode"
                  variant="secondary"
                  size="sm"
                  @click="showReplace = q"
                >Replace</QFButton>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <QFModal
      :open="!!showReplace"
      title="Replace Question"
      :width="580"
      @close="showReplace = null"
    >
      <div style="margin-bottom: 14px">
        <div style="font-size: 12px; color: var(--text3); margin-bottom: 6px">Current question</div>
        <div
          style="
            background: var(--bg2);
            border-radius: var(--radius);
            padding: 10px 14px;
            font-size: 13px;
            color: var(--text2);
            line-height: 1.6;
          "
        >{{ showReplace?.text }}</div>
      </div>
      <QFAIHint style="margin-bottom: 14px">
        AI found 3 alternatives matching the same unit, marks, and type.
      </QFAIHint>
      <div style="display: flex; flex-direction: column; gap: 8px">
        <div
          v-for="(alt, i) in alternatives"
          :key="i"
          style="
            padding: 12px;
            background: var(--bg2);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            cursor: pointer;
            font-size: 13px;
            line-height: 1.6;
            color: var(--text);
          "
          @mouseenter="(e) => onAltHover(e, true)"
          @mouseleave="(e) => onAltHover(e, false)"
        >{{ alt }}</div>
      </div>
      <template #footer>
        <QFButton variant="ghost" @click="showReplace = null">Cancel</QFButton>
        <QFButton variant="primary" @click="showReplace = null">Replace Question</QFButton>
      </template>
    </QFModal>
  </div>
</template>
