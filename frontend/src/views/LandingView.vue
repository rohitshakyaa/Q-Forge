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
  <div class="min-h-screen bg-bg overflow-y-auto font-body">
    <div
      class="fixed inset-0 pointer-events-none z-0 opacity-40"
      style="
        background-image: linear-gradient(var(--border) 1px, transparent 1px),
          linear-gradient(90deg, var(--border) 1px, transparent 1px);
        background-size: 48px 48px;
      "
    />
    <div
      class="fixed top-[-10%] left-[30%] w-[600px] h-[600px] pointer-events-none z-0"
      style="background: radial-gradient(circle, oklch(0.75 0.19 196 / 0.07), transparent 70%);"
    />

    <nav class="relative z-10 flex items-center justify-between px-4 sm:px-8 lg:px-12 py-4 border-b border-border">
      <div class="flex items-center gap-2.5">
        <div
          class="w-8 h-8 rounded-[10px] flex items-center justify-center text-base font-extrabold text-bg font-head"
          style="background: linear-gradient(135deg, var(--cyan), var(--indigo));"
        >
          Q
        </div>
        <span class="font-head text-lg font-bold tracking-tight">QForge</span>
        <QFBadge variant="cyan">Beta</QFBadge>
      </div>
      <div class="flex gap-2">
        <QFButton variant="ghost" @click="goToLogin">Sign in</QFButton>
        <QFButton variant="primary" @click="goToLogin">Get started →</QFButton>
      </div>
    </nav>

    <div class="relative z-10 text-center px-4 sm:px-8 lg:px-12 pt-16 sm:pt-20 lg:pt-24 pb-16 lg:pb-20">
      <div
        class="inline-flex items-center gap-2 bg-ai-dim rounded-[20px] px-3.5 py-[5px] mb-7"
        style="border: 1px solid oklch(0.72 0.18 230 / 0.3);"
      >
        <span class="text-ai text-xs">✦</span>
        <span class="text-ai text-[12.5px] font-medium">
          AI-Powered Question Paper Generation
        </span>
      </div>
      <h1 class="font-head text-4xl sm:text-5xl lg:text-[58px] font-extrabold leading-[1.08] tracking-[-0.04em] mx-auto mb-6 max-w-[820px]">
        Generate exam papers<br />
        <span
          class="bg-clip-text text-transparent"
          style="background-image: linear-gradient(135deg, var(--cyan), var(--indigo)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"
        >without the guesswork</span>
      </h1>
      <p class="text-base sm:text-lg text-text2 max-w-[520px] mx-auto mb-10 leading-[1.7]">
        Transform your syllabus and past papers into an intelligent question bank. Build blueprints. Generate perfectly structured exam papers in seconds.
      </p>
      <div class="flex gap-3 justify-center flex-wrap">
        <QFButton variant="primary" size="lg" @click="goToLogin">
          Start for free →
        </QFButton>
      </div>
    </div>

    <div class="relative z-10 flex gap-5 justify-center px-4 sm:px-8 lg:px-12 pb-16 lg:pb-20 flex-wrap">
      <div
        v-for="r in roles"
        :key="r.role"
        class="bg-bg1 border rounded-[var(--radius-lg)] px-8 py-8 w-full sm:w-[320px] max-w-[320px] cursor-pointer transition-all duration-200"
        :style="{
          borderColor: hovered === r.role ? r.color : 'var(--border)',
          boxShadow: hovered === r.role ? `0 0 30px ${r.color}20` : 'none',
        }"
        @mouseenter="hovered = r.role"
        @mouseleave="hovered = null"
        @click="goToLogin"
      >
        <div class="text-4xl mb-4">{{ r.icon }}</div>
        <div class="font-head text-xl font-bold mb-2.5 text-text">
          {{ r.role }}
        </div>
        <p class="text-text2 text-[13.5px] leading-[1.6] mb-5">
          {{ r.desc }}
        </p>
        <span :style="{ color: r.color }" class="text-[13px] font-semibold">
          {{ r.action }}
        </span>
      </div>
    </div>

    <div class="relative z-10 px-4 sm:px-8 lg:px-12 pb-16 lg:pb-20 max-w-[1100px] mx-auto">
      <div class="text-center mb-12">
        <h2 class="font-head text-2xl sm:text-[32px] font-bold tracking-[-0.03em] mb-3">
          Everything you need
        </h2>
        <p class="text-text2 text-[15px]">
          End-to-end question paper management for modern academic institutions
        </p>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <div
          v-for="f in features"
          :key="f.title"
          class="bg-bg1 border border-border rounded-[var(--radius-lg)] p-6"
        >
          <div :style="{ color: f.color }" class="text-2xl mb-3.5">{{ f.icon }}</div>
          <div class="font-head text-[15px] font-semibold mb-2">
            {{ f.title }}
          </div>
          <p class="text-text2 text-[13px] leading-[1.6]">{{ f.desc }}</p>
        </div>
      </div>
    </div>

    <div class="relative z-10 border-t border-b border-border bg-bg1 px-4 sm:px-8 lg:px-12 py-8 flex justify-center gap-8 sm:gap-16 flex-wrap">
      <div v-for="[val, lbl] in stats" :key="lbl" class="text-center">
        <div class="font-head text-2xl sm:text-[28px] font-extrabold text-cyan tracking-[-0.03em]">
          {{ val }}
        </div>
        <div class="text-text3 text-[12.5px] mt-1">{{ lbl }}</div>
      </div>
    </div>
  </div>
</template>
