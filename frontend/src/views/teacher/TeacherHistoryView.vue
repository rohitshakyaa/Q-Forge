<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { QFBadge, QFButton, QFCard, QFPageHeader } from '../../components/qf';
import { usePapersStore } from '../../stores/papers';

const router = useRouter();
const store = usePapersStore();

onMounted(() => {
  store.fetchHistory();
});

// Client-side filters — the history list is fully loaded, so no refetch needed.
const search = ref('');
const dateFrom = ref('');
const dateTo = ref('');

const hasFilters = computed(() => search.value !== '' || dateFrom.value !== '' || dateTo.value !== '');

const clearFilters = () => {
  search.value = '';
  dateFrom.value = '';
  dateTo.value = '';
};

// Local calendar-day key (YYYY-MM-DD) for comparing against <input type="date"> values.
// A null generatedAt is displayed as "Today", so it filters as today too.
const dayKey = (iso: string | null) => {
  const d = iso ? new Date(iso) : new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

// Saved/Drafts view toggle. Defaults to "saved" (kept papers — saved + exported);
// "draft" shows only the auto-saved previews; "all" shows both.
type StatusView = 'saved' | 'draft' | 'all';
const statusView = ref<StatusView>('saved');
const statusViews: { value: StatusView; label: string }[] = [
  { value: 'saved', label: 'Saved' },
  { value: 'draft', label: 'Drafts' },
  { value: 'all', label: 'All' },
];

type PaperStatus = 'draft' | 'saved' | 'exported';
const statusLabel = (s: PaperStatus) => ({ draft: 'Draft', saved: 'Saved', exported: 'Exported' })[s];
const statusVariant = (s: PaperStatus): 'warn' | 'neutral' | 'success' =>
  s === 'draft' ? 'warn' : s === 'exported' ? 'success' : 'neutral';

const draftCount = computed(() => store.list.filter((p) => p.status === 'draft').length);

const matchesStatusView = (status: PaperStatus) =>
  statusView.value === 'all' ||
  (statusView.value === 'draft' ? status === 'draft' : status !== 'draft');

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase();
  return store.list.filter((p) => {
    if (!matchesStatusView(p.status)) return false;
    if (q && !`${p.name} ${p.subject} ${p.subjectName ?? ''}`.toLowerCase().includes(q)) return false;
    const day = dayKey(p.generatedAt);
    if (dateFrom.value && day < dateFrom.value) return false;
    if (dateTo.value && day > dateTo.value) return false;
    return true;
  });
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
    <div class="flex flex-wrap gap-3 mb-5 items-end">
      <div class="qf-tabs" style="display: inline-flex; align-self: center">
        <div
          v-for="v in statusViews"
          :key="v.value"
          :class="['qf-tab', statusView === v.value && 'active']"
          @click="statusView = v.value"
        >
          {{ v.label }}
          <template v-if="v.value === 'draft' && draftCount"> ({{ draftCount }})</template>
        </div>
      </div>
      <div class="qf-field flex-1 min-w-55 sm:flex-none sm:w-72 m-0">
        <input v-model="search" class="qf-input" placeholder="Search papers…" />
      </div>
      <div class="qf-field w-full sm:w-44 m-0">
        <label class="qf-label">From</label>
        <input v-model="dateFrom" type="date" class="qf-input" :max="dateTo || undefined" />
      </div>
      <div class="qf-field w-full sm:w-44 m-0">
        <label class="qf-label">To</label>
        <input v-model="dateTo" type="date" class="qf-input" :min="dateFrom || undefined" />
      </div>
      <QFButton v-if="hasFilters" variant="ghost" size="sm" @click="clearFilters">Clear</QFButton>
    </div>

    <QFCard>
        <div class="qf-table-wrap">
        <table class="qf-table">
          <thead>
            <tr>
              <th style="padding-left: 20px">Paper</th>
              <th>Subject</th>
              <th>Status</th>
              <th>Date</th>
              <th>Marks</th>
              <th>Questions</th>
              <th>Exports</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="filtered.length === 0">
              <td colspan="8" style="padding: 24px 20px; text-align: center; color: var(--text3); font-size: 13px">
                {{ hasFilters ? 'No papers match the current filters.' : 'No papers generated yet.' }}
              </td>
            </tr>
            <tr
              v-for="p in filtered"
              :key="p.id ?? p.name"
              style="cursor: pointer"
              @click="router.push(`/teacher/paper/${p.id}`)"
            >
              <td style="padding-left: 20px; font-weight: 500">{{ p.name }}</td>
              <td style="color: var(--text2); font-size: 13px">
                {{ p.subject }}<span v-if="p.subjectName" style="color: var(--text3)"> – {{ p.subjectName }}</span>
              </td>
              <td><QFBadge :variant="statusVariant(p.status)">{{ statusLabel(p.status) }}</QFBadge></td>
              <td style="color: var(--text3); font-size: 12.5px">{{ p.date }}</td>
              <td style="font-family: var(--font-mono); font-size: 13px">{{ p.marks }}</td>
              <td>{{ p.questions }}</td>
              <td><QFBadge variant="neutral">{{ p.exports }}×</QFBadge></td>
              <td>
                <QFButton
                  variant="ghost"
                  size="sm"
                  @click.stop="router.push(`/teacher/paper/${p.id}`)"
                >View</QFButton>
              </td>
            </tr>
          </tbody>
        </table>
        </div>
    </QFCard>
  </div>
</template>
