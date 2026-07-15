<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { QFBadge, QFButton, QFCard, QFPageHeader, QFSelect } from '../../components/qf';
import { useCatalogStore } from '../../stores/catalog';

const catalog = useCatalogStore();

const search = ref('');
const subjectFilter = ref('all');
const typeFilter = ref('All Types');
const difficultyFilter = ref('All Difficulties');
const page = ref(1);

const subjectOptions = computed(() => [
  { value: 'all', label: 'All Subjects' },
  ...catalog.subjects.map((s) => ({ value: s.code, label: `${s.code} – ${s.name}` })),
]);

const load = () =>
  catalog.fetchQuestions({
    status: 'approved',
    subject: subjectFilter.value,
    type: typeFilter.value === 'All Types' ? undefined : typeFilter.value,
    difficulty: difficultyFilter.value === 'All Difficulties' ? undefined : difficultyFilter.value,
    search: search.value || undefined,
    page: page.value,
  });

onMounted(async () => {
  await catalog.fetchSubjects();
  await load();
});

// Reset to the first page and reload whenever a filter changes.
watch([subjectFilter, typeFilter, difficultyFilter, search], () => {
  page.value = 1;
  load();
});
watch(page, load);

const meta = computed(() => catalog.questionMeta);

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
      :subtitle="`${meta.total} approved questions across all subjects`"
      :breadcrumbs="[
        { label: 'Dashboard', to: '/admin' },
        { label: 'Question Bank' },
      ]"
    />

    <p class="text-text3 text-[13px] mb-5 -mt-2">
      Questions are added per subject on the
      <RouterLink to="/admin/subjects" class="text-cyan">Subjects &amp; Units</RouterLink> screen,
      or imported from the review queue.
    </p>

    <div class="flex flex-wrap gap-3 mb-5 items-end">
      <div class="qf-field flex-1 min-w-[220px] sm:flex-none sm:w-72 m-0">
        <input v-model="search" class="qf-input" placeholder="Search questions…" />
      </div>
      <div class="w-full sm:w-52">
        <QFSelect v-model="subjectFilter" :options="subjectOptions" />
      </div>
      <div class="w-full sm:w-40">
        <QFSelect
          v-model="typeFilter"
          :options="['All Types', 'Short Answer', 'Long Answer', 'MCQ']"
        />
      </div>
      <div class="w-full sm:w-40">
        <QFSelect
          v-model="difficultyFilter"
          :options="['All Difficulties', 'Easy', 'Medium', 'Hard']"
        />
      </div>
    </div>

    <QFCard>
      <div class="qf-table-wrap">
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
          </tr>
        </thead>
        <tbody>
          <tr v-for="q in catalog.questions" :key="q.id">
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
          </tr>
        </tbody>
      </table>
      </div>
    </QFCard>

    <div
      v-if="meta.total > 0"
      class="flex items-center justify-between mt-4"
      style="font-size: 13px; color: var(--text3)"
    >
      <span>
        Page {{ meta.currentPage }} of {{ meta.lastPage }} · {{ meta.total }} questions
      </span>
      <div class="flex gap-2">
        <QFButton variant="ghost" size="sm" :disabled="page <= 1" @click="page -= 1">‹ Prev</QFButton>
        <QFButton
          variant="ghost"
          size="sm"
          :disabled="page >= meta.lastPage"
          @click="page += 1"
        >Next ›</QFButton>
      </div>
    </div>
  </div>
</template>
