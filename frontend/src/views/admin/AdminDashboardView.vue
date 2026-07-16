<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { QFBadge, QFButton, QFCard, QFEmptyState, QFPageHeader, QFSpinner } from '../../components/qf';
import { useAdminOverviewStore } from '../../stores/adminOverview';
import type { ActivityType } from '../../stores/adminOverview';
import type { UploadStatus } from '../../stores/extraction';

const router = useRouter();
const overview = useAdminOverviewStore();

const stats = computed(() => {
  const s = overview.stats;
  return [
    { label: 'Total Questions', value: s.questionsTotal, sub: `+${s.questionsThisWeek} this week`, color: 'var(--cyan)', icon: '◈' },
    { label: 'Documents Processed', value: s.documentsTotal, sub: `${s.questionsPending} pending review`, color: 'var(--indigo)', icon: '⬡' },
    { label: 'Active Teachers', value: s.teachersTotal, sub: `${s.usersTotal} users total`, color: 'var(--success)', icon: '◉' },
    { label: 'Papers Generated', value: s.papersGenerated, sub: 'all-time', color: 'var(--warn)', icon: '✦' },
  ];
});

const statusMap: Record<UploadStatus, { v: 'success' | 'warn' | 'indigo' | 'danger'; l: string }> = {
  parsed: { v: 'success', l: 'Processed' },
  processing: { v: 'warn', l: 'Processing…' },
  uploaded: { v: 'indigo', l: 'Queued' },
  failed: { v: 'danger', l: 'Failed' },
};

const activityStyle: Record<ActivityType, { icon: string; color: string }> = {
  upload: { icon: '⬆', color: 'var(--warn)' },
  paper: { icon: '✦', color: 'var(--cyan)' },
  user: { icon: '◉', color: 'var(--success)' },
};

// Compact "time ago" for ISO timestamps (e.g. "2h ago", "3d ago").
const timeAgo = (iso: string): string => {
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return '';
  const s = Math.max(0, Math.round((Date.now() - then) / 1000));
  if (s < 60) return 'just now';
  const m = Math.round(s / 60);
  if (m < 60) return `${m}m ago`;
  const h = Math.round(m / 60);
  if (h < 24) return `${h}h ago`;
  const d = Math.round(h / 24);
  return `${d}d ago`;
};

const rowClick = () => router.push('/admin/upload');

onMounted(() => overview.fetch());
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
        <div class="qf-stat-value" :style="{ color: s.color }">{{ s.value.toLocaleString() }}</div>
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

        <div v-if="overview.loading && overview.recentUploads.length === 0" class="flex justify-center py-8">
          <QFSpinner />
        </div>
        <QFEmptyState
          v-else-if="overview.recentUploads.length === 0"
          icon="📄"
          title="No documents yet"
          desc="Uploaded PDFs will appear here once processed."
        />
        <div v-else class="qf-table-wrap">
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
                v-for="r in overview.recentUploads"
                :key="r.id"
                class="cursor-pointer"
                @click="rowClick()"
              >
                <td>
                  <div class="font-medium text-[13px]">{{ r.filename }}</div>
                </td>
                <td>
                  <span class="font-mono text-xs text-text2">{{ r.subjectCode ?? '—' }}</span>
                </td>
                <td>
                  <template v-if="r.questionsCreated !== null">{{ r.questionsCreated }}</template>
                  <span v-else class="text-text3">—</span>
                </td>
                <td><QFBadge :variant="statusMap[r.status].v">{{ statusMap[r.status].l }}</QFBadge></td>
                <td class="text-text3 text-xs">{{ timeAgo(r.createdAt) }}</td>
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
          <div v-if="overview.loading && overview.activity.length === 0" class="flex justify-center py-8">
            <QFSpinner />
          </div>
          <QFEmptyState
            v-else-if="overview.activity.length === 0"
            icon="✦"
            title="No activity yet"
            desc="Recent uploads, papers, and users will show up here."
          />
          <div v-else style="display: flex; flex-direction: column; gap: 0">
            <div
              v-for="(a, i) in overview.activity"
              :key="i"
              :style="{
                display: 'flex',
                gap: '12px',
                padding: '12px 0',
                borderBottom: i < overview.activity.length - 1 ? '1px solid var(--border)' : 'none',
              }"
            >
              <div
                :style="{
                  width: '28px',
                  height: '28px',
                  background: `${activityStyle[a.type].color}18`,
                  borderRadius: '8px',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  fontSize: '12px',
                  color: activityStyle[a.type].color,
                  flexShrink: 0,
                  marginTop: '1px',
                }"
              >{{ activityStyle[a.type].icon }}</div>
              <div style="flex: 1; min-width: 0">
                <div style="font-size: 13px; font-weight: 500; color: var(--text)">{{ a.title }}</div>
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
                <div style="font-size: 11px; color: var(--text3); margin-top: 3px">{{ timeAgo(a.at) }}</div>
              </div>
            </div>
          </div>
        </div>
      </QFCard>
    </div>
  </div>
</template>
