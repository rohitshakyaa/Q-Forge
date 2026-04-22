<script setup lang="ts">
import { useRouter } from 'vue-router';
import { QFAIHint, QFBadge, QFButton, QFCard, QFPageHeader } from '../../components/qf';

const router = useRouter();

const stats = [
  { label: 'Total Questions', value: '4,821', sub: '+127 this week', color: 'var(--cyan)', icon: '◈' },
  { label: 'Documents Processed', value: '63', sub: '12 pending review', color: 'var(--indigo)', icon: '⬡' },
  { label: 'Active Teachers', value: '18', sub: '3 online now', color: 'var(--success)', icon: '◉' },
  { label: 'Papers Generated', value: '241', sub: 'this semester', color: 'var(--warn)', icon: '✦' },
];

const recent: Array<{
  name: string;
  subject: string;
  questions: number | null;
  status: 'processed' | 'processing' | 'review';
  time: string;
}> = [
  { name: 'DataStructures_2024.pdf', subject: 'CS301', questions: 47, status: 'processed', time: '2h ago' },
  { name: 'Algorithms_PastPapers.pdf', subject: 'CS302', questions: null, status: 'processing', time: '20m ago' },
  { name: 'NetworkingSyllabus.pdf', subject: 'CS401', questions: 31, status: 'review', time: '1d ago' },
  { name: 'DBMS_Finals_2023.pdf', subject: 'CS303', questions: 55, status: 'processed', time: '2d ago' },
];

const activity = [
  { action: 'Paper generated', detail: 'Advanced Math Final — 28 questions', time: '5m ago', icon: '✦', color: 'var(--cyan)' },
  { action: 'Questions approved', detail: '42 questions from DBMS Past Papers', time: '1h ago', icon: '◈', color: 'var(--success)' },
  { action: 'Blueprint created', detail: '"Short Quiz Template" by Dr. Patel', time: '3h ago', icon: '⬡', color: 'var(--indigo)' },
  { action: 'PDF uploaded', detail: 'Algorithms_PastPapers.pdf', time: '4h ago', icon: '⬆', color: 'var(--warn)' },
  { action: 'User added', detail: 'Prof. Maria Chen — Teacher role', time: '1d ago', icon: '◉', color: 'var(--text3)' },
];

const statusMap: Record<string, { v: 'success' | 'warn' | 'indigo'; l: string }> = {
  processed: { v: 'success', l: 'Processed' },
  processing: { v: 'warn', l: 'Processing…' },
  review: { v: 'indigo', l: 'Needs Review' },
};

const rowClick = (status: string) => {
  router.push(status === 'review' ? '/admin/review' : '/admin/upload');
};
</script>

<template>
  <div class="qf-content qf-anim-in">
    <QFPageHeader
      title="Admin Dashboard"
      subtitle="System overview and recent activity"
      :breadcrumbs="[{ label: 'Dashboard' }]"
    >
      <template #actions>
        <QFButton variant="secondary" @click="router.push('/admin/upload')">⬆ Upload PDF</QFButton>
        <QFButton variant="primary" @click="router.push('/admin/review')">Review Queue</QFButton>
      </template>
    </QFPageHeader>

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3.5 mb-6">
      <div
        v-for="s in stats"
        :key="s.label"
        class="qf-stat"
        :style="{ borderTop: `2px solid ${s.color}` }"
      >
        <div class="flex justify-between items-center mb-2.5">
          <div class="qf-stat-label">{{ s.label }}</div>
          <div :style="{ color: s.color }" class="text-xl">{{ s.icon }}</div>
        </div>
        <div class="qf-stat-value" :style="{ color: s.color }">{{ s.value }}</div>
        <div class="qf-stat-sub">{{ s.sub }}</div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[1fr_340px] gap-5">
      <QFCard>
        <div
          class="qf-card-header"
          style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 14px"
        >
          <span style="font-family: var(--font-head); font-weight: 600">Recent Documents</span>
          <QFButton variant="ghost" size="sm" @click="router.push('/admin/upload')">View all →</QFButton>
        </div>
        <div class="qf-table-wrap">
          <table class="qf-table">
            <thead>
              <tr>
                <th>File</th>
                <th>Subject</th>
                <th>Questions</th>
                <th>Status</th>
                <th>Uploaded</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="r in recent"
                :key="r.name"
                class="cursor-pointer"
                @click="rowClick(r.status)"
              >
                <td>
                  <div class="font-medium text-[13px]">{{ r.name }}</div>
                </td>
                <td>
                  <span class="font-mono text-xs text-text2">
                    {{ r.subject }}
                  </span>
                </td>
                <td>
                  <template v-if="r.questions !== null">{{ r.questions }}</template>
                  <span v-else class="text-text3">—</span>
                </td>
                <td><QFBadge :variant="statusMap[r.status].v">{{ statusMap[r.status].l }}</QFBadge></td>
                <td class="text-text3 text-xs">{{ r.time }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </QFCard>

      <QFCard>
        <div class="qf-card-header" style="padding-bottom: 14px">
          <span style="font-family: var(--font-head); font-weight: 600">Activity Feed</span>
        </div>
        <div class="qf-card-body" style="padding-top: 0">
          <div style="display: flex; flex-direction: column; gap: 0">
            <div
              v-for="(a, i) in activity"
              :key="i"
              :style="{
                display: 'flex',
                gap: '12px',
                padding: '12px 0',
                borderBottom: i < activity.length - 1 ? '1px solid var(--border)' : 'none',
              }"
            >
              <div
                :style="{
                  width: '28px',
                  height: '28px',
                  background: `${a.color}18`,
                  borderRadius: '8px',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  fontSize: '12px',
                  color: a.color,
                  flexShrink: 0,
                  marginTop: '1px',
                }"
              >{{ a.icon }}</div>
              <div style="flex: 1; min-width: 0">
                <div style="font-size: 13px; font-weight: 500; color: var(--text)">{{ a.action }}</div>
                <div
                  style="
                    font-size: 12px;
                    color: var(--text3);
                    margin-top: 2px;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                  "
                >{{ a.detail }}</div>
                <div style="font-size: 11px; color: var(--text3); margin-top: 3px">{{ a.time }}</div>
              </div>
            </div>
          </div>
        </div>
      </QFCard>
    </div>

    <div class="mt-5">
      <QFAIHint>
        <strong style="color: var(--ai)">AI Insight:</strong> Unit 3 (Graph Algorithms) in CS302 has only 8 questions — below the recommended minimum of 15 for adequate blueprint coverage. Consider uploading more past papers for this unit.
      </QFAIHint>
    </div>
  </div>
</template>
