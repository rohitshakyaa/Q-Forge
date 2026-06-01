<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { QFAIHint, QFBadge, QFButton, QFCard, QFPageHeader } from '../../components/qf';
import { usePapersStore } from '../../stores/papers';

const router = useRouter();
const store = usePapersStore();

onMounted(() => {
  store.fetchHistory();
  store.fetchAnalytics();
});

// Real usage aggregates from GET /papers/analytics (falls back to derived/zero).
const analytics = computed<Array<[string, string]>>(() => {
  const a = store.analytics;
  return [
    ['Papers generated', String(a?.generated ?? store.list.length)],
    ['Questions used', String(a?.questionsUsed ?? 0)],
    ['Unique questions', String(a?.uniqueQuestions ?? 0)],
    ['Avg. reuse rate', `${(a?.reuseRate ?? 0).toFixed(1)}×`],
    ['Total exports', String(a?.totalExports ?? 0)],
  ];
});
</script>

<template>
  <div class="qf-content qf-anim-in">
    <QFPageHeader
      title="Paper History"
      subtitle="All generated papers and export records"
      :breadcrumbs="[
        { label: 'Dashboard', to: '/teacher' },
        { label: 'Paper History' },
      ]"
    />
    <div class="grid grid-cols-1 lg:grid-cols-[1fr_280px] gap-5">
      <QFCard>
        <div class="qf-table-wrap">
        <table class="qf-table">
          <thead>
            <tr>
              <th style="padding-left: 20px">Paper</th>
              <th>Date</th>
              <th>Marks</th>
              <th>Questions</th>
              <th>Exports</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="p in store.list"
              :key="p.id ?? p.name"
              style="cursor: pointer"
              @click="router.push(`/teacher/paper/${p.id}`)"
            >
              <td style="padding-left: 20px; font-weight: 500">{{ p.name }}</td>
              <td style="color: var(--text3); font-size: 12.5px">{{ p.date }}</td>
              <td style="font-family: var(--font-mono); font-size: 13px">{{ p.marks }}</td>
              <td>{{ p.questions }}</td>
              <td><QFBadge variant="neutral">{{ p.exports }}×</QFBadge></td>
              <td>
                <QFButton variant="ghost" size="sm">View</QFButton>
              </td>
            </tr>
          </tbody>
        </table>
        </div>
      </QFCard>
      <div class="flex flex-col gap-3.5">
        <QFCard>
          <div class="qf-card-body">
            <div style="font-family: var(--font-head); font-weight: 600; margin-bottom: 14px">
              Usage Analytics
            </div>
            <div
              v-for="[label, value] in analytics"
              :key="label"
              style="
                display: flex;
                justify-content: space-between;
                padding: 8px 0;
                border-bottom: 1px solid var(--border);
              "
            >
              <span style="font-size: 13px; color: var(--text2)">{{ label }}</span>
              <span style="font-family: var(--font-mono); font-weight: 700; color: var(--cyan)">
                {{ value }}
              </span>
            </div>
          </div>
        </QFCard>
        <QFAIHint>
          Unit 3 questions are used 2.1× more often than other units. Consider uploading more Unit 3 papers to expand coverage.
        </QFAIHint>
      </div>
    </div>
  </div>
</template>
