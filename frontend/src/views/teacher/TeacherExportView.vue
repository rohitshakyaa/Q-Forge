<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { QFBadge, QFButton, QFCard, QFPageHeader } from '../../components/qf';
import { usePapersStore } from '../../stores/papers';

const route = useRoute();
const router = useRouter();
const store = usePapersStore();

const paperId = Number(route.params.id);
const paper = computed(() => store.getById(paperId) ?? store.list[0]);

const formats = [
  { id: 'docx', icon: '📄', label: 'Word Document', desc: '.docx — Fully editable', popular: true },
  { id: 'pdf', icon: '🖨', label: 'PDF', desc: '.pdf — Print-ready', popular: false },
  { id: 'txt', icon: '📝', label: 'Plain Text', desc: '.txt — Simple export', popular: false },
];
const selected = ref('docx');
const exported = ref(false);

const download = () => {
  if (paper.value) store.markExported(paper.value.id);
  exported.value = true;
};
</script>

<template>
  <div v-if="paper" class="qf-content qf-anim-in" style="max-width: 640px">
    <QFPageHeader
      title="Export Paper"
      :subtitle="`${paper.name} — ${paper.subject}`"
      back="Paper View"
      @back="router.push(`/teacher/paper/${paper.id}`)"
    />
    <QFCard style="margin-bottom: 20px">
      <div class="qf-card-body">
        <div style="font-family: var(--font-head); font-weight: 600; margin-bottom: 16px">
          Export Format
        </div>
        <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px">
          <div
            v-for="f in formats"
            :key="f.id"
            :style="{
              display: 'flex',
              alignItems: 'center',
              gap: '12px',
              padding: '12px 16px',
              background: selected === f.id ? 'var(--cyan-dim)' : 'var(--bg2)',
              border: `1.5px solid ${selected === f.id ? 'var(--cyan)' : 'var(--border)'}`,
              borderRadius: 'var(--radius)',
              cursor: 'pointer',
              transition: 'all 0.15s',
            }"
            @click="selected = f.id"
          >
            <span style="font-size: 20px">{{ f.icon }}</span>
            <div style="flex: 1">
              <div style="font-weight: 600; font-size: 13.5px">{{ f.label }}</div>
              <div style="font-size: 12px; color: var(--text3)">{{ f.desc }}</div>
            </div>
            <QFBadge v-if="f.popular" variant="cyan">Popular</QFBadge>
            <span v-if="selected === f.id" style="color: var(--cyan); font-size: 16px">✓</span>
          </div>
        </div>
        <QFButton
          v-if="!exported"
          variant="primary"
          block
          style="padding: 11px; font-size: 15px"
          @click="download"
        >Download .{{ selected }}</QFButton>
        <div
          v-else
          style="
            background: var(--success-dim);
            border: 1px solid var(--success);
            border-radius: var(--radius);
            padding: 12px 16px;
            display: flex;
            gap: 10px;
            align-items: center;
          "
        >
          <span style="color: var(--success); font-size: 18px">✓</span>
          <span style="color: var(--success); font-weight: 600">
            {{ paper.name.replace(/\s+/g, '') }}.{{ selected }} downloaded
          </span>
        </div>
      </div>
    </QFCard>
  </div>
</template>
