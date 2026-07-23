<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { QFBadge, QFButton, QFCard, QFPageHeader } from '../../components/qf';
import { usePapersStore, type ExportFormat } from '../../stores/papers';

const route = useRoute();
const store = usePapersStore();

const paperId = Number(route.params.id);
const paper = computed(() => store.getById(paperId));

// Only the formats Laravel can render (txt is intentionally not offered).
const formats: Array<{ id: ExportFormat; icon: string; label: string; desc: string; popular: boolean }> = [
  { id: 'docx', icon: '📄', label: 'Word Document', desc: '.docx — Fully editable', popular: true },
  { id: 'pdf', icon: '🖨', label: 'PDF', desc: '.pdf — Print-ready', popular: false },
];
const selected = ref<ExportFormat>('docx');
const exported = ref(false);
const downloading = ref(false);
const errorMsg = ref<string | null>(null);

onMounted(() => store.fetchById(paperId));

const download = async () => {
  if (paper.value?.id == null) return;
  downloading.value = true;
  errorMsg.value = null;
  try {
    await store.exportPaper(paper.value.id, selected.value);
    exported.value = true;
  } catch {
    errorMsg.value = 'Export failed. Please try again.';
  } finally {
    downloading.value = false;
  }
};
</script>

<template>
  <div v-if="paper" class="qf-content qf-anim-in">
    <QFPageHeader
      title="Export Paper"
      :subtitle="`${paper.name} — ${paper.subject}`"
      :breadcrumbs="[
        { label: 'Dashboard', to: '/teacher' },
        { label: 'Generate', to: '/teacher/generate' },
        { label: paper.name, to: `/teacher/paper/${paper.id}` },
        { label: 'Export' },
      ]"
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
            @click="selected = f.id; exported = false"
          >
            <span style="font-size: 20px">{{ f.icon }}</span>
            <div style="flex: 1">
              <div style="font-weight: 600; font-size: 13.5px">{{ f.label }}</div>
              <div style="font-size: 12px; color: var(--text3)">{{ f.desc }}</div>
            </div>
            <!-- <QFBadge v-if="f.popular" variant="cyan">Popular</QFBadge> -->
            <span v-if="selected === f.id" style="color: var(--cyan); font-size: 16px">✓</span>
          </div>
        </div>
        <QFButton
          variant="primary"
          block
          :disabled="downloading"
          style="padding: 11px; font-size: 15px"
          @click="download"
        >{{ downloading ? 'Preparing…' : `Download .${selected}` }}</QFButton>
        <div
          v-if="exported"
          style="
            margin-top: 12px;
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
        <div
          v-if="errorMsg"
          style="margin-top: 12px; color: var(--danger, #e44); font-size: 13px; font-weight: 600"
        >{{ errorMsg }}</div>
      </div>
    </QFCard>
  </div>
</template>
