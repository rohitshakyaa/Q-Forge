<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import {
  QFBadge,
  QFButton,
  QFCard,
  QFEmptyState,
  QFModal,
  QFPageHeader,
} from '../../components/qf';
import { useBlueprintsStore, type Blueprint } from '../../stores/blueprints';

const router = useRouter();
const store = useBlueprintsStore();

const search = ref('');
const deleteConfirm = ref<Blueprint | null>(null);

const filtered = computed(() =>
  store.list.filter(
    (b) =>
      !search.value ||
      b.name.toLowerCase().includes(search.value.toLowerCase()) ||
      b.subject.toLowerCase().includes(search.value.toLowerCase()),
  ),
);

const newBlueprint = () => router.push('/teacher/blueprint/new');
const edit = (bp: Blueprint) => router.push(`/teacher/blueprint/${bp.id}`);
const generate = () => router.push('/teacher/generate');

const confirmDelete = () => {
  if (deleteConfirm.value) {
    store.remove(deleteConfirm.value.id);
    deleteConfirm.value = null;
  }
};

const letter = (i: number) => String.fromCharCode(65 + i);

const unitBreakdown = (bp: Blueprint) =>
  Object.entries(bp.unitRules)
    .filter(([, active]) => active)
    .map(([unit]) => {
      const allocs = bp.unitAllocations?.[unit] ?? [];
      const qs = allocs.reduce((s, a) => s + (a.count || 0), 0);
      return { unit, qs, allocs };
    });

const setDashedHover = (e: MouseEvent, enter: boolean) => {
  const el = e.currentTarget as HTMLElement;
  el.style.borderColor = enter ? 'var(--cyan)' : 'var(--border)';
  el.style.color = enter ? 'var(--cyan)' : 'var(--text3)';
};
</script>

<template>
  <div class="qf-content qf-anim-in">
    <QFPageHeader
      title="Blueprint Builder"
      subtitle="Create and manage your paper structure templates"
      :breadcrumbs="[
        { label: 'Dashboard', to: '/teacher' },
        { label: 'Blueprint Builder' },
      ]"
    >
      <template #actions>
        <QFButton variant="primary" @click="newBlueprint">+ New Blueprint</QFButton>
      </template>
    </QFPageHeader>

    <div style="display: flex; gap: 12px; margin-bottom: 20px; align-items: center">
      <div style="position: relative; flex: 1; max-width: 380px">
        <span
          style="
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text3);
            font-size: 14px;
            pointer-events: none;
          "
        >⌕</span>
        <input
          v-model="search"
          class="qf-input"
          placeholder="Search blueprints by name or subject…"
          style="padding-left: 34px"
        />
      </div>
      <QFButton v-if="search" variant="ghost" size="sm" @click="search = ''">Clear</QFButton>
      <div style="margin-left: auto; color: var(--text3); font-size: 13px">
        {{ filtered.length }} blueprint{{ filtered.length !== 1 ? 's' : '' }}
      </div>
    </div>

    <QFEmptyState
      v-if="filtered.length === 0"
      icon="⬢"
      title="No blueprints found"
      :desc="search ? `No blueprints match \u201c${search}\u201d.` : 'You have no blueprints yet. Create one to get started.'"
    >
      <template #action>
        <QFButton variant="primary" @click="newBlueprint">+ Create Blueprint</QFButton>
      </template>
    </QFEmptyState>

    <div
      v-else
      style="
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 16px;
      "
    >
      <QFCard v-for="bp in filtered" :key="bp.id">
        <div class="qf-card-body">
          <div
            style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px"
          >
            <div>
              <div style="font-family: var(--font-head); font-weight: 700; font-size: 15px; margin-bottom: 5px">
                {{ bp.name }}
              </div>
              <span
                style="
                  font-family: var(--font-mono);
                  font-size: 11.5px;
                  color: var(--cyan);
                  background: var(--cyan-dim);
                  padding: 2px 8px;
                  border-radius: 6px;
                "
              >{{ bp.subject }}</span>
            </div>
            <QFBadge v-if="bp.aiAssist" variant="ai">✦ AI</QFBadge>
          </div>

          <div style="display: flex; gap: 10px; margin-bottom: 12px; flex-wrap: wrap">
            <div
              v-for="stat in [
                [bp.questions, 'Qs'],
                [bp.totalMarks, 'Marks'],
                [bp.units, 'Units'],
                [`${bp.duration}m`, 'Duration'],
              ]"
              :key="String(stat[1])"
              style="
                text-align: center;
                background: var(--bg2);
                border-radius: 6px;
                padding: 6px 10px;
                flex: 1;
                min-width: 60px;
              "
            >
              <div style="font-family: var(--font-head); font-weight: 700; font-size: 16px; color: var(--text)">
                {{ stat[0] }}
              </div>
              <div style="font-size: 10.5px; color: var(--text3); margin-top: 1px">{{ stat[1] }}</div>
            </div>
          </div>

          <div style="display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px">
            <div
              v-for="(s, i) in bp.sections"
              :key="s.id"
              style="font-size: 12.5px; color: var(--text2); display: flex; align-items: center; gap: 6px"
            >
              <span style="color: var(--cyan); font-weight: 700; font-family: var(--font-mono); font-size: 11px">
                {{ letter(i) }}
              </span>
              <span>{{ s.name }}</span>
              <span
                style="margin-left: auto; font-family: var(--font-mono); color: var(--text3); font-size: 11px"
              >{{ s.count }}×{{ s.marksEach }}M</span>
            </div>
          </div>

          <div
            v-if="unitBreakdown(bp).length"
            style="
              background: var(--bg2);
              border-radius: 8px;
              padding: 8px 10px;
              margin-bottom: 10px;
              display: flex;
              flex-direction: column;
              gap: 4px;
            "
          >
            <div
              style="
                font-size: 10.5px;
                color: var(--text3);
                font-weight: 600;
                letter-spacing: 0.04em;
                text-transform: uppercase;
              "
            >Unit Allocation</div>
            <div
              v-for="row in unitBreakdown(bp)"
              :key="row.unit"
              style="display: flex; align-items: center; gap: 6px; font-size: 11.5px"
            >
              <span style="color: var(--indigo); font-weight: 600">{{ row.unit }}</span>
              <span
                v-if="row.qs === 0"
                style="color: var(--text3); font-style: italic; font-size: 11px"
              >no allocation</span>
              <span
                v-else
                style="
                  margin-left: auto;
                  font-family: var(--font-mono);
                  color: var(--text2);
                  font-size: 11px;
                "
              >
                <template v-for="(a, ai) in row.allocs" :key="ai">
                  <span v-if="ai > 0" style="color: var(--text3)"> · </span>{{ a.count }}×{{ a.marks }}M
                </template>
              </span>
            </div>
          </div>

          <div style="font-size: 11.5px; color: var(--text3); margin-bottom: 14px">
            Last used: {{ bp.lastUsed }} · Excludes last {{ bp.exclusionRules.lastNPapers }} papers
          </div>

          <div style="display: flex; gap: 8px; border-top: 1px solid var(--border); padding-top: 12px">
            <QFButton variant="secondary" size="sm" block @click="edit(bp)">✏ Edit</QFButton>
            <QFButton variant="primary" size="sm" block @click="generate">✦ Generate</QFButton>
            <QFButton variant="danger" size="sm" @click="deleteConfirm = bp">✕</QFButton>
          </div>
        </div>
      </QFCard>

      <div
        style="
          background: var(--bg1);
          border: 2px dashed var(--border);
          border-radius: var(--radius-lg);
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: center;
          gap: 10px;
          cursor: pointer;
          padding: 32px;
          min-height: 200px;
          transition: all 0.15s;
          color: var(--text3);
        "
        @mouseenter="(e) => setDashedHover(e, true)"
        @mouseleave="(e) => setDashedHover(e, false)"
        @click="newBlueprint"
      >
        <div style="font-size: 32px">+</div>
        <div style="font-size: 13px; font-weight: 500">New Blueprint</div>
      </div>
    </div>

    <QFModal
      :open="!!deleteConfirm"
      title="Delete Blueprint"
      :width="440"
      @close="deleteConfirm = null"
    >
      <p style="font-size: 14px; color: var(--text2); line-height: 1.6">
        Are you sure you want to delete
        <strong style="color: var(--text)">{{ deleteConfirm?.name }}</strong>? This cannot be undone.
      </p>
      <template #footer>
        <QFButton variant="ghost" @click="deleteConfirm = null">Cancel</QFButton>
        <QFButton variant="danger" @click="confirmDelete">Delete Blueprint</QFButton>
      </template>
    </QFModal>
  </div>
</template>
