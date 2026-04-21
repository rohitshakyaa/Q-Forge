<script setup lang="ts">
import { computed, ref } from 'vue';
import { QFBadge, QFButton, QFCard, QFPageHeader, QFSelect } from '../../components/qf';
import { useCatalogStore } from '../../stores/catalog';

const catalog = useCatalogStore();

const search = ref('');
const subjectFilter = ref('all');
const typeFilter = ref('All Types');
const difficultyFilter = ref('All Difficulties');

const subjectOptions = computed(() => [
  { value: 'all', label: 'All Subjects' },
  ...catalog.subjects.map((s) => ({ value: s.code, label: `${s.code} – ${s.name}` })),
]);

const filtered = computed(() =>
  catalog.questionBank.filter((q) => {
    if (subjectFilter.value !== 'all' && q.subject !== subjectFilter.value) return false;
    if (typeFilter.value !== 'All Types' && q.type !== typeFilter.value) return false;
    if (difficultyFilter.value !== 'All Difficulties' && q.difficulty !== difficultyFilter.value) return false;
    if (search.value && !q.text.toLowerCase().includes(search.value.toLowerCase())) return false;
    return true;
  }),
);

const diffColor: Record<string, string> = {
  Easy: 'var(--success)',
  Medium: 'var(--warn)',
  Hard: 'var(--danger)',
};
</script>

<template>
  <div class="qf-content qf-anim-in">
    <QFPageHeader
      title="Question Bank"
      :subtitle="`${catalog.questionBank.length} approved questions across all subjects`"
    >
      <template #actions>
        <QFButton variant="ai">
          <template #icon><span>✦</span></template>
          AI Suggest
        </QFButton>
        <QFButton variant="primary">+ Add Question</QFButton>
      </template>
    </QFPageHeader>

    <div style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; align-items: flex-end">
      <div class="qf-field" style="width: 280px; margin: 0">
        <input v-model="search" class="qf-input" placeholder="Search questions…" />
      </div>
      <div style="width: 200px">
        <QFSelect v-model="subjectFilter" :options="subjectOptions" />
      </div>
      <div style="width: 160px">
        <QFSelect
          v-model="typeFilter"
          :options="['All Types', 'Short Answer', 'Long Answer', 'MCQ', 'Programming']"
        />
      </div>
      <div style="width: 160px">
        <QFSelect
          v-model="difficultyFilter"
          :options="['All Difficulties', 'Easy', 'Medium', 'Hard']"
        />
      </div>
    </div>

    <QFCard>
      <table class="qf-table">
        <thead>
          <tr>
            <th style="padding-left: 20px">Question</th>
            <th>Subject</th>
            <th>Unit</th>
            <th>Type</th>
            <th>Marks</th>
            <th>Difficulty</th>
            <th>Used</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="q in filtered" :key="q.id">
            <td style="padding-left: 20px; max-width: 320px">
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
            <td>
              <span style="font-family: var(--font-mono); font-size: 12px; color: var(--cyan)">
                {{ q.subject }}
              </span>
            </td>
            <td style="color: var(--text2); font-size: 12.5px">{{ q.unit }}</td>
            <td><QFBadge variant="neutral">{{ q.type }}</QFBadge></td>
            <td style="font-family: var(--font-mono); font-size: 13px; font-weight: 600">
              {{ q.marks }}
            </td>
            <td>
              <span
                :style="{
                  color: q.difficulty ? diffColor[q.difficulty] : 'var(--text3)',
                  fontSize: '12.5px',
                  fontWeight: 600,
                }"
              >{{ q.difficulty ?? '—' }}</span>
            </td>
            <td>
              <div style="display: flex; align-items: center; gap: 4px">
                <div
                  style="
                    width: 32px;
                    height: 4px;
                    background: var(--bg3);
                    border-radius: 2px;
                    overflow: hidden;
                  "
                >
                  <div
                    :style="{
                      width: `${Math.min(((q.used ?? 0) / 6) * 100, 100)}%`,
                      height: '100%',
                      background: (q.used ?? 0) > 4 ? 'var(--warn)' : 'var(--cyan)',
                    }"
                  />
                </div>
                <span style="font-size: 12px; color: var(--text3)">{{ q.used ?? 0 }}×</span>
              </div>
            </td>
            <td>
              <QFButton variant="ghost" size="sm">Edit</QFButton>
            </td>
          </tr>
        </tbody>
      </table>
    </QFCard>
  </div>
</template>
