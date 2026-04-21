<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import QFButton from '../components/qf/QFButton.vue';
import QFBadge from '../components/qf/QFBadge.vue';

const router = useRouter();
const hovered = ref<string | null>(null);

const features = [
  {
    icon: '⬡',
    color: 'var(--cyan)',
    title: 'PDF Intelligence',
    desc: 'Upload syllabus and past papers. AI extracts, classifies, and structures every question automatically.',
  },
  {
    icon: '◈',
    color: 'var(--indigo)',
    title: 'Blueprint Engine',
    desc: 'Define exact rules — marks distribution, unit coverage, question types — with a visual builder.',
  },
  {
    icon: '✦',
    color: 'var(--ai)',
    title: 'Hybrid Generation',
    desc: 'Rule-based constraints + AI fill-in. Every paper satisfies your requirements without repetition.',
  },
  {
    icon: '◎',
    color: 'var(--success)',
    title: 'Usage Tracking',
    desc: 'Question history prevents reuse. Full audit trail of every paper generated across all teachers.',
  },
];

const roles = [
  {
    role: 'Administrator',
    icon: '🛡',
    color: 'var(--cyan)',
    desc: 'Manage question banks, users, and AI processing. Full system control.',
    action: 'Admin portal →',
  },
  {
    role: 'Teacher',
    icon: '✏',
    color: 'var(--indigo)',
    desc: 'Build blueprints, generate papers, track usage, export results.',
    action: 'Teacher portal →',
  },
];

const stats: Array<[string, string]> = [
  ['10,000+', 'Questions extracted'],
  ['98%', 'Blueprint satisfaction'],
  ['< 3s', 'Generation time'],
  ['100%', 'Constraint accuracy'],
];

const goToLogin = () => router.push('/login');
</script>

<template>
  <div style="min-height: 100vh; background: var(--bg); overflow-y: auto; font-family: var(--font-body)">
    <div
      style="
        position: fixed;
        inset: 0;
        pointer-events: none;
        z-index: 0;
        background-image: linear-gradient(var(--border) 1px, transparent 1px),
          linear-gradient(90deg, var(--border) 1px, transparent 1px);
        background-size: 48px 48px;
        opacity: 0.4;
      "
    />
    <div
      style="
        position: fixed;
        top: -10%;
        left: 30%;
        width: 600px;
        height: 600px;
        background: radial-gradient(circle, oklch(0.75 0.19 196 / 0.07), transparent 70%);
        pointer-events: none;
        z-index: 0;
      "
    />

    <nav
      style="
        position: relative;
        z-index: 10;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 16px 48px;
        border-bottom: 1px solid var(--border);
      "
    >
      <div style="display: flex; align-items: center; gap: 10px">
        <div
          style="
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, var(--cyan), var(--indigo));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 800;
            color: #070a10;
            font-family: var(--font-head);
          "
        >
          Q
        </div>
        <span
          style="
            font-family: var(--font-head);
            font-size: 18px;
            font-weight: 700;
            letter-spacing: -0.03em;
          "
        >
          QForge
        </span>
        <QFBadge variant="cyan">Beta</QFBadge>
      </div>
      <div style="display: flex; gap: 8px">
        <QFButton variant="ghost" @click="goToLogin">Sign in</QFButton>
        <QFButton variant="primary" @click="goToLogin">Get started →</QFButton>
      </div>
    </nav>

    <div style="position: relative; z-index: 1; text-align: center; padding: 100px 48px 80px">
      <div
        style="
          display: inline-flex;
          align-items: center;
          gap: 8px;
          background: var(--ai-dim);
          border: 1px solid oklch(0.72 0.18 230 / 0.3);
          border-radius: 20px;
          padding: 5px 14px;
          margin-bottom: 28px;
        "
      >
        <span style="color: var(--ai); font-size: 12px">✦</span>
        <span style="color: var(--ai); font-size: 12.5px; font-weight: 500">
          AI-Powered Question Paper Generation
        </span>
      </div>
      <h1
        style="
          font-family: var(--font-head);
          font-size: 58px;
          font-weight: 800;
          line-height: 1.08;
          letter-spacing: -0.04em;
          margin: 0 auto 24px;
          max-width: 820px;
        "
      >
        Generate exam papers<br />
        <span
          style="
            background: linear-gradient(135deg, var(--cyan), var(--indigo));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
          "
        >without the guesswork</span>
      </h1>
      <p
        style="
          font-size: 18px;
          color: var(--text2);
          max-width: 520px;
          margin: 0 auto 40px;
          line-height: 1.7;
        "
      >
        Transform your syllabus and past papers into an intelligent question bank. Build blueprints. Generate perfectly structured exam papers in seconds.
      </p>
      <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap">
        <QFButton
          variant="primary"
          size="lg"
          @click="goToLogin"
        >
          Start for free →
        </QFButton>
        <QFButton variant="secondary" size="lg" @click="goToLogin">View demo</QFButton>
      </div>
    </div>

    <div
      style="
        position: relative;
        z-index: 1;
        display: flex;
        gap: 20px;
        justify-content: center;
        padding: 0 48px 80px;
        flex-wrap: wrap;
      "
    >
      <div
        v-for="r in roles"
        :key="r.role"
        :style="{
          background: 'var(--bg1)',
          border: `1px solid ${hovered === r.role ? r.color : 'var(--border)'}`,
          borderRadius: 'var(--radius-lg)',
          padding: '32px 36px',
          maxWidth: '320px',
          cursor: 'pointer',
          transition: 'all 0.2s',
          boxShadow: hovered === r.role ? `0 0 30px ${r.color}20` : 'none',
        }"
        @mouseenter="hovered = r.role"
        @mouseleave="hovered = null"
        @click="goToLogin"
      >
        <div style="font-size: 36px; margin-bottom: 16px">{{ r.icon }}</div>
        <div
          style="
            font-family: var(--font-head);
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 10px;
            color: var(--text);
          "
        >
          {{ r.role }}
        </div>
        <p style="color: var(--text2); font-size: 13.5px; line-height: 1.6; margin-bottom: 20px">
          {{ r.desc }}
        </p>
        <span :style="{ color: r.color, fontSize: '13px', fontWeight: 600 }">
          {{ r.action }}
        </span>
      </div>
    </div>

    <div
      style="position: relative; z-index: 1; padding: 0 48px 80px; max-width: 1100px; margin: 0 auto"
    >
      <div style="text-align: center; margin-bottom: 48px">
        <h2
          style="
            font-family: var(--font-head);
            font-size: 32px;
            font-weight: 700;
            letter-spacing: -0.03em;
            margin-bottom: 12px;
          "
        >
          Everything you need
        </h2>
        <p style="color: var(--text2); font-size: 15px">
          End-to-end question paper management for modern academic institutions
        </p>
      </div>
      <div
        style="
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
          gap: 20px;
        "
      >
        <div
          v-for="f in features"
          :key="f.title"
          style="
            background: var(--bg1);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 24px;
          "
        >
          <div :style="{ fontSize: '24px', marginBottom: '14px', color: f.color }">{{ f.icon }}</div>
          <div
            style="
              font-family: var(--font-head);
              font-size: 15px;
              font-weight: 600;
              margin-bottom: 8px;
            "
          >
            {{ f.title }}
          </div>
          <p style="color: var(--text2); font-size: 13px; line-height: 1.6">{{ f.desc }}</p>
        </div>
      </div>
    </div>

    <div
      style="
        position: relative;
        z-index: 1;
        border-top: 1px solid var(--border);
        border-bottom: 1px solid var(--border);
        background: var(--bg1);
        padding: 32px 48px;
        display: flex;
        justify-content: center;
        gap: 64px;
        flex-wrap: wrap;
      "
    >
      <div v-for="[val, lbl] in stats" :key="lbl" style="text-align: center">
        <div
          style="
            font-family: var(--font-head);
            font-size: 28px;
            font-weight: 800;
            color: var(--cyan);
            letter-spacing: -0.03em;
          "
        >
          {{ val }}
        </div>
        <div style="color: var(--text3); font-size: 12.5px; margin-top: 4px">{{ lbl }}</div>
      </div>
    </div>
  </div>
</template>
