<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { QFBadge, QFButton, QFPageHeader } from '../../components/qf';
import { useBlueprintsStore } from '../../stores/blueprints';
import { usePapersStore } from '../../stores/papers';
import { useCatalogStore } from '../../stores/catalog';
import { useAuthStore } from '../../stores/auth';

const router = useRouter();
const blueprintsStore = useBlueprintsStore();
const papersStore = usePapersStore();
const catalogStore = useCatalogStore();
const authStore = useAuthStore();

const greeting = computed(() => {
  const h = new Date().getHours();
  const partOfDay = h < 12 ? 'morning' : h < 18 ? 'afternoon' : 'evening';
  const name = authStore.user?.name ?? 'there';
  return `Good ${partOfDay}, ${name} — ready to generate today's paper?`;
});

const quickActions = [
  { icon: '⬡', label: 'Blueprints', desc: 'Browse and manage paper templates', color: 'var(--cyan)', to: '/teacher/blueprint' },
  { icon: '✦', label: 'Generate Paper', desc: 'From an existing blueprint', color: 'var(--indigo)', to: '/teacher/generate' },
  { icon: '◉', label: 'Paper History', desc: 'View and re-export past papers', color: 'var(--violet)', to: '/teacher/history' },
];

const statusMap: Record<string, { variant: 'success' | 'indigo' | 'neutral'; label: string }> = {
  exported: { variant: 'success', label: 'Exported' },
  saved: { variant: 'indigo', label: 'Saved' },
  draft: { variant: 'neutral', label: 'Draft' },
};

const recentPapers = computed(() => papersStore.list.slice(0, 4));

const stats = computed(() => {
  const papers = papersStore.list;
  const totalExports = papers.reduce((s, p) => s + p.exports, 0);
  const totalMarks = papers.reduce((s, p) => s + p.marks, 0);
  // Question-bank size comes from the subjects index (teacher-accessible); the
  // per-question catalog endpoint is admin-only, so questionBank is never
  // hydrated for a teacher.
  const questions = catalogStore.subjects.reduce((s, sub) => s + (sub.questionsCount ?? 0), 0);
  return {
    papers: papers.length,
    blueprints: blueprintsStore.list.length,
    questions,
    subjects: catalogStore.subjects.length,
    exports: totalExports,
    marks: totalMarks,
  };
});

const questionsBySubject = computed(() => {
  const arr = catalogStore.subjects
    .map((s) => ({ subject: s.code, count: s.questionsCount ?? 0 }))
    .filter((r) => r.count > 0)
    .sort((a, b) => b.count - a.count);
  const max = Math.max(1, ...arr.map((r) => r.count));
  return arr.map((r) => ({ ...r, pct: Math.round((r.count / max) * 100) }));
});

const subjectBreakdown = computed(() => {
  const map = new Map<string, { subject: string; count: number; marks: number }>();
  for (const p of papersStore.list) {
    const cur = map.get(p.subject) ?? { subject: p.subject, count: 0, marks: 0 };
    cur.count += 1;
    cur.marks += p.marks;
    map.set(p.subject, cur);
  }
  const arr = [...map.values()].sort((a, b) => b.count - a.count);
  const max = Math.max(1, ...arr.map((r) => r.count));
  return arr.map((r) => ({ ...r, pct: Math.round((r.count / max) * 100) }));
});

const statusBreakdown = computed(() => {
  const counts: Record<'exported' | 'saved' | 'draft', number> = { exported: 0, saved: 0, draft: 0 };
  for (const p of papersStore.list) counts[p.status] += 1;
  const total = Math.max(1, papersStore.list.length);
  return [
    { key: 'exported', label: 'Exported', count: counts.exported, pct: Math.round((counts.exported / total) * 100), color: 'var(--success)' },
    { key: 'saved', label: 'Saved', count: counts.saved, pct: Math.round((counts.saved / total) * 100), color: 'var(--indigo)' },
    { key: 'draft', label: 'Drafts', count: counts.draft, pct: Math.round((counts.draft / total) * 100), color: 'var(--text3)' },
  ];
});

const setHover = (e: MouseEvent, color: string, enter: boolean) => {
  const el = e.currentTarget as HTMLElement;
  el.style.borderColor = enter ? color : 'var(--border)';
  el.style.boxShadow = enter ? `0 0 20px ${color}15` : 'none';
};

const setListHover = (e: MouseEvent, enter: boolean) => {
  const el = e.currentTarget as HTMLElement;
  el.style.borderColor = enter ? 'var(--border2)' : 'var(--border)';
};

// Hydrate everything the dashboard reads so a direct visit is correct — the
// stores are otherwise populated only by the pages that own them.
onMounted(() => {
  papersStore.fetchHistory();
  if (blueprintsStore.list.length === 0) blueprintsStore.fetch();
  if (catalogStore.subjects.length === 0) catalogStore.fetchSubjects();
});
</script>

<template>
  <div class="qf-content qf-anim-in">
    <QFPageHeader
      title="Teacher Dashboard"
      :subtitle="greeting"
      :breadcrumbs="[{ label: 'Dashboard' }]"
    />

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3.5 mb-6">
      <div
        v-for="q in quickActions"
        :key="q.label"
        :style="{
          background: 'var(--bg1)',
          border: '1px solid var(--border)',
          borderRadius: 'var(--radius-lg)',
          padding: '18px 20px',
          cursor: 'pointer',
          transition: 'all 0.15s',
          display: 'flex',
          alignItems: 'center',
          gap: '14px',
        }"
        @mouseenter="(e) => setHover(e, q.color, true)"
        @mouseleave="(e) => setHover(e, q.color, false)"
        @click="router.push(q.to)"
      >
        <div
          :style="{
            width: '44px',
            height: '44px',
            background: `${q.color}18`,
            borderRadius: '10px',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            fontSize: '20px',
            color: q.color,
            flexShrink: 0,
          }"
        >
          {{ q.icon }}
        </div>
        <div>
          <div style="font-family: var(--font-head); font-weight: 600; font-size: 14px; margin-bottom: 3px">
            {{ q.label }}
          </div>
          <div style="font-size: 12px; color: var(--text3)">{{ q.desc }}</div>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5 mb-6">
      <div class="qf-stat">
        <div class="qf-stat-label">Papers Created</div>
        <div class="qf-stat-value">{{ stats.papers }}</div>
        <div class="qf-stat-sub">{{ stats.marks }} marks total</div>
      </div>
      <div class="qf-stat">
        <div class="qf-stat-label">Blueprints</div>
        <div class="qf-stat-value">{{ stats.blueprints }}</div>
        <div class="qf-stat-sub">Templates ready to use</div>
      </div>
      <div class="qf-stat">
        <div class="qf-stat-label">Question Bank</div>
        <div class="qf-stat-value">{{ stats.questions }}</div>
        <div class="qf-stat-sub">Across {{ stats.subjects }} subjects</div>
      </div>
      <div class="qf-stat">
        <div class="qf-stat-label">Exports</div>
        <div class="qf-stat-value">{{ stats.exports }}</div>
        <div class="qf-stat-sub">All-time downloads</div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-5">
      <div>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px">
          <div style="font-family: var(--font-head); font-weight: 600; font-size: 15px">Recent Papers</div>
          <QFButton variant="ghost" size="sm" @click="router.push('/teacher/history')">View all →</QFButton>
        </div>
        <div style="display: flex; flex-direction: column; gap: 10px">
          <div
            v-for="p in recentPapers"
            :key="p.id ?? p.name"
            style="
              background: var(--bg1);
              border: 1px solid var(--border);
              border-radius: var(--radius-lg);
              padding: 14px 18px;
              cursor: pointer;
              transition: border-color 0.15s;
            "
            @mouseenter="(e) => setListHover(e, true)"
            @mouseleave="(e) => setListHover(e, false)"
            @click="router.push(`/teacher/paper/${p.id}`)"
          >
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px">
              <div style="min-width: 0">
                <div style="font-weight: 600; font-size: 14px; margin-bottom: 4px">{{ p.name }}</div>
                <div style="display: flex; gap: 8px; font-size: 12.5px; color: var(--text3); flex-wrap: wrap">
                  <span style="color: var(--cyan); font-family: var(--font-mono)">{{ p.subject }}</span>
                  <span>·</span><span>{{ p.questions }} questions</span>
                  <span>·</span><span>{{ p.marks }} marks</span>
                  <span>·</span><span>{{ p.date }}</span>
                </div>
              </div>
              <div style="display: flex; gap: 8px; align-items: center; flex-shrink: 0">
                <QFBadge :variant="statusMap[p.status].variant">{{ statusMap[p.status].label }}</QFBadge>
                <QFButton
                  variant="ghost"
                  size="sm"
                  @click.stop="router.push(`/teacher/paper/${p.id}`)"
                >
                  View →
                </QFButton>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div style="display: flex; flex-direction: column; gap: 16px">
        <div
          style="
            background: var(--bg1);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 16px 18px;
          "
        >
          <div style="font-family: var(--font-head); font-weight: 600; font-size: 14px; margin-bottom: 14px">
            Papers by Subject
          </div>
          <div v-if="subjectBreakdown.length === 0" style="font-size: 12.5px; color: var(--text3)">
            No papers yet.
          </div>
          <div v-else style="display: flex; flex-direction: column; gap: 12px">
            <div v-for="row in subjectBreakdown" :key="row.subject">
              <div style="display: flex; justify-content: space-between; font-size: 12.5px; margin-bottom: 5px">
                <span style="color: var(--cyan); font-family: var(--font-mono)">{{ row.subject }}</span>
                <span style="color: var(--text2)">{{ row.count }} paper{{ row.count === 1 ? '' : 's' }}</span>
              </div>
              <div style="height: 6px; background: var(--bg3); border-radius: 3px; overflow: hidden">
                <div
                  :style="{
                    width: `${row.pct}%`,
                    height: '100%',
                    background: 'linear-gradient(90deg, var(--indigo), var(--cyan))',
                    borderRadius: '3px',
                  }"
                />
              </div>
            </div>
          </div>
        </div>

        <div
          style="
            background: var(--bg1);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 16px 18px;
          "
        >
          <div style="font-family: var(--font-head); font-weight: 600; font-size: 14px; margin-bottom: 14px">
            Paper Status
          </div>
          <div style="display: flex; flex-direction: column; gap: 10px">
            <div
              v-for="s in statusBreakdown"
              :key="s.key"
              style="display: flex; align-items: center; gap: 10px"
            >
              <span class="qf-dot" :style="{ background: s.color }" />
              <span style="font-size: 13px; flex: 1">{{ s.label }}</span>
              <span style="font-size: 13px; font-weight: 600; color: var(--text)">{{ s.count }}</span>
              <span style="font-size: 12px; color: var(--text3); min-width: 34px; text-align: right">{{ s.pct }}%</span>
            </div>
          </div>
        </div>

        <div
          style="
            background: var(--bg1);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 16px 18px;
          "
        >
          <div style="font-family: var(--font-head); font-weight: 600; font-size: 14px; margin-bottom: 14px">
            Questions by Subject
          </div>
          <div v-if="questionsBySubject.length === 0" style="font-size: 12.5px; color: var(--text3)">
            No questions yet.
          </div>
          <div v-else style="display: flex; flex-direction: column; gap: 12px">
            <div v-for="row in questionsBySubject" :key="row.subject">
              <div style="display: flex; justify-content: space-between; font-size: 12.5px; margin-bottom: 5px">
                <span style="color: var(--cyan); font-family: var(--font-mono)">{{ row.subject }}</span>
                <span style="color: var(--text2)">{{ row.count }} question{{ row.count === 1 ? '' : 's' }}</span>
              </div>
              <div style="height: 6px; background: var(--bg3); border-radius: 3px; overflow: hidden">
                <div
                  :style="{
                    width: `${row.pct}%`,
                    height: '100%',
                    background: 'linear-gradient(90deg, var(--indigo), var(--cyan))',
                    borderRadius: '3px',
                  }"
                />
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
