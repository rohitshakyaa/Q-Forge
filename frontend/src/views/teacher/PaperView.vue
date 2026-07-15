<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { QFBadge, QFButton, QFPageHeader } from '../../components/qf';
import { usePapersStore } from '../../stores/papers';

const route = useRoute();
const router = useRouter();
const store = usePapersStore();

const paperId = Number(route.params.id);
const paper = computed(() => store.getById(paperId));
const saving = ref(false);

// Always load authoritatively on mount so direct links, refreshes, and History
// navigation work (and we pick up status/export_count changes after export).
onMounted(() => store.fetchById(paperId));

const save = async () => {
  if (paper.value?.id == null) return;
  saving.value = true;
  try {
    await store.update(paper.value.id, { status: 'saved' });
  } finally {
    saving.value = false;
  }
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
        <QFButton variant="secondary" size="sm" :disabled="saving || paper.status === 'saved'" @click="save">
          {{ paper.status === 'saved' ? 'Saved' : saving ? 'Saving…' : 'Save' }}
        </QFButton>
        <QFButton variant="primary" size="sm" @click="router.push(`/teacher/export/${paper.id}`)">
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
                <p style="font-size: 14px; line-height: 1.7; color: var(--text)">{{ q.text }}</p>
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
</template>
