<script setup lang="ts">
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { QFBadge, QFButton, QFPageHeader } from '../../components/qf';
import { useBlueprintsStore } from '../../stores/blueprints';
import { usePapersStore } from '../../stores/papers';

const router = useRouter();
const blueprintsStore = useBlueprintsStore();
const papersStore = usePapersStore();

const quickActions = [
  { icon: '⬡', label: 'New Blueprint', desc: 'Define paper structure and rules', color: 'var(--cyan)', to: '/teacher/blueprint' },
  { icon: '✦', label: 'Generate Paper', desc: 'From an existing blueprint', color: 'var(--indigo)', to: '/teacher/generate' },
  { icon: '◉', label: 'Paper History', desc: 'View and re-export past papers', color: 'var(--violet)', to: '/teacher/history' },
];

const statusMap: Record<string, { variant: 'success' | 'indigo' | 'neutral'; label: string }> = {
  exported: { variant: 'success', label: 'Exported' },
  saved: { variant: 'indigo', label: 'Saved' },
  draft: { variant: 'neutral', label: 'Draft' },
};

const recentPapers = computed(() => papersStore.list.slice(0, 3));
const blueprints = computed(() => blueprintsStore.list.slice(0, 3));

const setHover = (e: MouseEvent, color: string, enter: boolean) => {
  const el = e.currentTarget as HTMLElement;
  el.style.borderColor = enter ? color : 'var(--border)';
  el.style.boxShadow = enter ? `0 0 20px ${color}15` : 'none';
};

const setListHover = (e: MouseEvent, enter: boolean) => {
  const el = e.currentTarget as HTMLElement;
  el.style.borderColor = enter ? 'var(--border2)' : 'var(--border)';
};

const setDashedHover = (e: MouseEvent, enter: boolean) => {
  const el = e.currentTarget as HTMLElement;
  el.style.borderColor = enter ? 'var(--cyan)' : 'var(--border)';
  el.style.color = enter ? 'var(--cyan)' : 'var(--text3)';
};
</script>

<template>
  <div class="qf-content qf-anim-in">
    <QFPageHeader title="Teacher Dashboard" subtitle="Good morning, Dr. Johnson — ready to generate today's paper?" />

    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 24px">
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

    <div style="display: grid; grid-template-columns: 1fr 300px; gap: 20px">
      <div>
        <div style="font-family: var(--font-head); font-weight: 600; font-size: 15px; margin-bottom: 14px">
          Recent Papers
        </div>
        <div style="display: flex; flex-direction: column; gap: 10px">
          <div
            v-for="p in recentPapers"
            :key="p.id"
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
            <div style="display: flex; justify-content: space-between; align-items: flex-start">
              <div>
                <div style="font-weight: 600; font-size: 14px; margin-bottom: 4px">{{ p.name }}</div>
                <div style="display: flex; gap: 8px; font-size: 12.5px; color: var(--text3)">
                  <span style="color: var(--cyan); font-family: var(--font-mono)">{{ p.subject }}</span>
                  <span>·</span><span>{{ p.questions }} questions</span>
                  <span>·</span><span>{{ p.marks }} marks</span>
                  <span>·</span><span>{{ p.date }}</span>
                </div>
              </div>
              <div style="display: flex; gap: 8px; align-items: center">
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

      <div>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px">
          <div style="font-family: var(--font-head); font-weight: 600; font-size: 15px">My Blueprints</div>
          <QFButton variant="ghost" size="sm" @click="router.push('/teacher/blueprint')">+ New</QFButton>
        </div>
        <div style="display: flex; flex-direction: column; gap: 8px">
          <div
            v-for="b in blueprints"
            :key="b.id"
            style="
              background: var(--bg1);
              border: 1px solid var(--border);
              border-radius: var(--radius-lg);
              padding: 12px 16px;
              cursor: pointer;
            "
            @click="router.push('/teacher/generate')"
          >
            <div style="font-weight: 600; font-size: 13.5px; margin-bottom: 6px">{{ b.name }}</div>
            <div style="display: flex; gap: 6px; flex-wrap: wrap">
              <span class="qf-chip">{{ b.questions }}Q</span>
              <span class="qf-chip">{{ b.totalMarks }} marks</span>
              <span class="qf-chip">{{ b.units }} units</span>
            </div>
          </div>
          <div
            style="
              border: 2px dashed var(--border);
              border-radius: var(--radius-lg);
              padding: 14px;
              display: flex;
              align-items: center;
              justify-content: center;
              gap: 8px;
              cursor: pointer;
              color: var(--text3);
              font-size: 13px;
              transition: all 0.15s;
            "
            @mouseenter="(e) => setDashedHover(e, true)"
            @mouseleave="(e) => setDashedHover(e, false)"
            @click="router.push('/teacher/blueprint')"
          >
            + Create Blueprint
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
