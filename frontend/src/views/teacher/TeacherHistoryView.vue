<script setup lang="ts">
import { useRouter } from 'vue-router';
import { QFAIHint, QFBadge, QFButton, QFCard, QFPageHeader } from '../../components/qf';
import { usePapersStore } from '../../stores/papers';

const router = useRouter();
const store = usePapersStore();

const analytics: Array<[string, string]> = [
  ['Papers generated', String(store.list.length)],
  ['Questions used', '88'],
  ['Unique questions', '72'],
  ['Avg. reuse rate', '1.2×'],
];
</script>

<template>
  <div class="qf-content qf-anim-in">
    <QFPageHeader title="Paper History" subtitle="All generated papers and export records" />
    <div style="display: grid; grid-template-columns: 1fr 280px; gap: 20px">
      <QFCard>
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
              :key="p.id"
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
      </QFCard>
      <div style="display: flex; flex-direction: column; gap: 14px">
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
