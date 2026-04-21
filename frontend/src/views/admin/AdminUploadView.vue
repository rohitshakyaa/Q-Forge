<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import {
  QFAIHint,
  QFBadge,
  QFButton,
  QFCard,
  QFPageHeader,
  QFProgress,
} from '../../components/qf';

const router = useRouter();

interface UploadFile {
  name: string;
  size: string;
  status: 'done' | 'processing' | 'review';
  progress: number;
  questions: number | null;
}

const dragover = ref(false);
const files = ref<UploadFile[]>([
  { name: 'DBMS_Finals_2023.pdf', size: '2.4 MB', status: 'done', progress: 100, questions: 55 },
  { name: 'Algorithms_PastPapers.pdf', size: '3.8 MB', status: 'processing', progress: 62, questions: null },
  { name: 'NetworkingSyllabus.pdf', size: '1.1 MB', status: 'review', progress: 100, questions: 31 },
]);

const simulateUpload = () => {
  const newFile: UploadFile = {
    name: 'NewPastPaper_2024.pdf',
    size: '2.1 MB',
    status: 'processing',
    progress: 0,
    questions: null,
  };
  files.value = [newFile, ...files.value];
  let p = 0;
  const iv = setInterval(() => {
    p += Math.random() * 18;
    if (p >= 100) {
      p = 100;
      clearInterval(iv);
      files.value = files.value.map((f, i) =>
        i === 0 ? { ...f, progress: 100, status: 'review', questions: 38 } : f,
      );
    } else {
      files.value = files.value.map((f, i) =>
        i === 0 ? { ...f, progress: Math.round(p) } : f,
      );
    }
  }, 300);
};

const onDrop = (e: DragEvent) => {
  e.preventDefault();
  dragover.value = false;
  simulateUpload();
};

const statusConfig: Record<string, { badge: 'success' | 'warn' | 'indigo'; label: string }> = {
  done: { badge: 'success', label: 'Processed' },
  processing: { badge: 'warn', label: 'Processing' },
  review: { badge: 'indigo', label: 'Needs Review' },
};

const pipeline = [
  { step: 'PDF Upload', desc: 'Files validated and queued', icon: '⬆', done: true },
  { step: 'OCR Extraction', desc: 'Text extracted from scanned pages', icon: '◈', done: true },
  { step: 'Structure Parsing', desc: 'Questions, marks, and units identified', icon: '⬡', done: false },
  { step: 'Classification', desc: 'Q-type, difficulty, unit tagged', icon: '✦', done: false },
  { step: 'Review Queue', desc: 'Admin approves extracted questions', icon: '◎', done: false },
];
</script>

<template>
  <div class="qf-content qf-anim-in">
    <QFPageHeader
      title="PDF Upload & Processing"
      subtitle="Upload syllabus documents and past question papers for AI extraction"
      back="Dashboard"
      @back="router.push('/admin')"
    />

    <div style="display: grid; grid-template-columns: 1fr 320px; gap: 24px">
      <div>
        <div
          :class="['qf-dropzone', dragover && 'dragover']"
          style="margin-bottom: 24px"
          @dragover.prevent="dragover = true"
          @dragleave="dragover = false"
          @drop="onDrop"
          @click="simulateUpload"
        >
          <div style="font-size: 36px; opacity: 0.5">⬆</div>
          <div
            style="
              font-family: var(--font-head);
              font-size: 16px;
              font-weight: 600;
              color: var(--text2);
            "
          >Drop PDFs here or click to upload</div>
          <div style="font-size: 13px; color: var(--text3)">
            Supports syllabus documents and past question papers · PDF only · Max 50MB
          </div>
          <QFButton variant="secondary" size="sm">Browse files</QFButton>
        </div>

        <QFCard>
          <div
            class="qf-card-header"
            style="
              display: flex;
              justify-content: space-between;
              align-items: center;
              padding-bottom: 14px;
            "
          >
            <span style="font-family: var(--font-head); font-weight: 600">Uploaded Documents</span>
            <QFBadge variant="neutral">{{ files.length }} files</QFBadge>
          </div>
          <div>
            <div
              v-for="(f, i) in files"
              :key="f.name + i"
              :style="{
                padding: '14px 20px',
                borderBottom: i < files.length - 1 ? '1px solid var(--border)' : 'none',
              }"
            >
              <div
                :style="{
                  display: 'flex',
                  alignItems: 'center',
                  gap: '12px',
                  marginBottom: f.status === 'processing' ? '10px' : '0',
                }"
              >
                <div
                  style="
                    width: 36px;
                    height: 36px;
                    background: var(--bg3);
                    border-radius: 8px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 18px;
                    flex-shrink: 0;
                  "
                >📄</div>
                <div style="flex: 1; min-width: 0">
                  <div style="font-weight: 500; font-size: 13.5px; margin-bottom: 2px">{{ f.name }}</div>
                  <div style="font-size: 12px; color: var(--text3); display: flex; gap: 8px">
                    <span>{{ f.size }}</span>
                    <span v-if="f.questions">· {{ f.questions }} questions extracted</span>
                  </div>
                </div>
                <QFBadge :variant="statusConfig[f.status].badge">
                  {{ statusConfig[f.status].label }}
                </QFBadge>
                <QFButton
                  v-if="f.status === 'review'"
                  variant="ai"
                  size="sm"
                  @click="router.push('/admin/review')"
                >Review →</QFButton>
              </div>
              <div v-if="f.status === 'processing'" style="margin-left: 48px">
                <QFProgress :value="f.progress" ai :label="`Extracting questions… ${f.progress}%`" />
                <div style="margin-top: 8px; display: flex; gap: 16px; font-size: 11.5px; color: var(--text3)">
                  <span
                    v-for="[step, done] in [
                      ['OCR', f.progress > 15],
                      ['Parse Structure', f.progress > 35],
                      ['Classify Questions', f.progress > 60],
                      ['Link Units', f.progress > 80],
                    ]"
                    :key="String(step)"
                    :style="{
                      color: done ? 'var(--success)' : 'var(--text3)',
                      display: 'flex',
                      alignItems: 'center',
                      gap: '3px',
                    }"
                  >{{ done ? '✓' : '' }}{{ step }}</span>
                </div>
              </div>
            </div>
          </div>
        </QFCard>
      </div>

      <div style="display: flex; flex-direction: column; gap: 16px">
        <QFCard>
          <div class="qf-card-body">
            <div style="font-family: var(--font-head); font-weight: 600; margin-bottom: 14px">
              Processing Pipeline
            </div>
            <div
              v-for="(s, i) in pipeline"
              :key="s.step"
              style="display: flex; gap: 10px; margin-bottom: 14px"
            >
              <div style="display: flex; flex-direction: column; align-items: center; gap: 0">
                <div
                  :style="{
                    width: '24px',
                    height: '24px',
                    borderRadius: '50%',
                    background: s.done ? 'var(--success-dim)' : 'var(--bg3)',
                    border: `1.5px solid ${s.done ? 'var(--success)' : 'var(--border2)'}`,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    fontSize: '11px',
                    color: s.done ? 'var(--success)' : 'var(--text3)',
                    flexShrink: 0,
                  }"
                >{{ s.done ? '✓' : s.icon }}</div>
                <div
                  v-if="i < pipeline.length - 1"
                  style="width: 1px; height: 20px; background: var(--border); margin: 2px 0"
                />
              </div>
              <div style="padding-bottom: 14px">
                <div
                  :style="{
                    fontSize: '13px',
                    fontWeight: 500,
                    color: s.done ? 'var(--text)' : 'var(--text2)',
                  }"
                >{{ s.step }}</div>
                <div style="font-size: 11.5px; color: var(--text3); margin-top: 2px">{{ s.desc }}</div>
              </div>
            </div>
          </div>
        </QFCard>
        <QFAIHint>
          AI extracts questions, marks, and unit references automatically. Review helps correct misclassifications before adding to the bank.
        </QFAIHint>
      </div>
    </div>
  </div>
</template>
