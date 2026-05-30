<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import {
  QFButton,
  QFCard,
  QFInput,
  QFModal,
  QFPageHeader,
} from '../../components/qf';
import { useCatalogStore, type Subject } from '../../stores/catalog';

const router = useRouter();
const catalog = useCatalogStore();

const showAdd = ref(false);
const deleteConfirm = ref<Subject | null>(null);
const newSubj = reactive({ code: '', name: '', description: '' });

onMounted(() => catalog.fetchSubjects());

const addSubject = async () => {
  if (!newSubj.code || !newSubj.name) return;
  await catalog.createSubject({
    code: newSubj.code,
    name: newSubj.name,
    description: newSubj.description,
  });
  newSubj.code = '';
  newSubj.name = '';
  newSubj.description = '';
  showAdd.value = false;
};

const confirmDelete = async () => {
  if (deleteConfirm.value) {
    await catalog.removeSubject(deleteConfirm.value.code);
    deleteConfirm.value = null;
  }
};

const setHover = (e: MouseEvent, enter: boolean) => {
  (e.currentTarget as HTMLElement).style.borderColor = enter ? 'var(--cyan)' : 'var(--border)';
};

const totalQuestions = (s: Subject) => s.questionsCount ?? s.units.flatMap((u) => u.questions).length;
</script>

<template>
  <div class="qf-content qf-anim-in">
    <QFPageHeader
      title="Subjects & Units"
      subtitle="Manage academic subjects, units, questions, and syllabi"
      :breadcrumbs="[
        { label: 'Dashboard', to: '/admin' },
        { label: 'Subjects & Units' },
      ]"
    >
      <template #actions>
        <QFButton variant="primary" @click="showAdd = true">+ New Subject</QFButton>
      </template>
    </QFPageHeader>

    <div
      style="
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 16px;
      "
    >
      <QFCard v-for="s in catalog.subjects" :key="s.code">
        <div class="qf-card-body">
          <div
            style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px"
          >
            <span
              style="
                font-family: var(--font-mono);
                font-size: 12px;
                color: var(--cyan);
                background: var(--cyan-dim);
                padding: 3px 8px;
                border-radius: 6px;
              "
            >{{ s.code }}</span>
            <div style="display: flex; gap: 4px">
              <QFButton variant="ghost" size="sm" @click="deleteConfirm = s">✕</QFButton>
            </div>
          </div>
          <div style="font-family: var(--font-head); font-size: 16px; font-weight: 700; margin-bottom: 4px">
            {{ s.name }}
          </div>
          <p style="font-size: 12.5px; color: var(--text3); margin-bottom: 14px; line-height: 1.5">
            {{ s.description || 'No description.' }}
          </p>
          <div style="display: flex; gap: 12px; margin-bottom: 14px">
            <div
              v-for="stat in [
                [s.units.length, 'Units'],
                [totalQuestions(s), 'Questions'],
                [s.teachers, 'Teachers'],
              ]"
              :key="String(stat[1])"
              style="
                text-align: center;
                flex: 1;
                background: var(--bg2);
                border-radius: 6px;
                padding: 8px 0;
              "
            >
              <div
                style="
                  font-family: var(--font-head);
                  font-weight: 700;
                  font-size: 18px;
                  color: var(--text);
                "
              >{{ stat[0] }}</div>
              <div style="font-size: 10.5px; color: var(--text3); margin-top: 2px">{{ stat[1] }}</div>
            </div>
          </div>
          <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px">
            <span
              v-for="(u, i) in s.units"
              :key="u.id"
              class="qf-chip"
              style="font-size: 11px"
            >{{ u.name || `Unit ${i + 1}` }}</span>
          </div>
          <div style="border-top: 1px solid var(--border); padding-top: 12px; display: flex; gap: 8px">
            <QFButton
              variant="primary"
              size="sm"
              block
              @click="router.push(`/admin/subjects/${s.code}`)"
            >Manage →</QFButton>
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
        "
        @mouseenter="(e) => setHover(e, true)"
        @mouseleave="(e) => setHover(e, false)"
        @click="showAdd = true"
      >
        <div style="font-size: 32px; color: var(--text3)">+</div>
        <div style="color: var(--text3); font-size: 13px">Add new subject</div>
      </div>
    </div>

    <QFModal :open="showAdd" title="New Subject" :width="460" @close="showAdd = false">
      <div class="flex flex-col gap-3.5">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <QFInput v-model="newSubj.code" label="Subject code *" placeholder="e.g. CS304" />
          <QFInput v-model="newSubj.name" label="Subject name *" placeholder="e.g. Operating Systems" />
        </div>
        <QFInput
          v-model="newSubj.description"
          label="Description"
          type="textarea"
          :rows="3"
          placeholder="Brief description of the subject…"
        />
      </div>
      <template #footer>
        <QFButton variant="ghost" @click="showAdd = false">Cancel</QFButton>
        <QFButton variant="primary" @click="addSubject">Create Subject</QFButton>
      </template>
    </QFModal>

    <QFModal
      :open="!!deleteConfirm"
      title="Delete Subject"
      :width="420"
      @close="deleteConfirm = null"
    >
      <p style="font-size: 14px; color: var(--text2); line-height: 1.6">
        Delete
        <strong style="color: var(--text)">
          {{ deleteConfirm?.name }} ({{ deleteConfirm?.code }})
        </strong>? All units, questions, and the syllabus will be permanently removed.
      </p>
      <template #footer>
        <QFButton variant="ghost" @click="deleteConfirm = null">Cancel</QFButton>
        <QFButton variant="danger" @click="confirmDelete">Delete Subject</QFButton>
      </template>
    </QFModal>
  </div>
</template>
