<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import { useAuthStore } from '../../stores/auth';
import type { UserRole } from '../../types/auth';
import QFButton from '../../components/qf/QFButton.vue';
import QFInput from '../../components/qf/QFInput.vue';
import QFSpinner from '../../components/qf/QFSpinner.vue';
import QFBadge from '../../components/qf/QFBadge.vue';

const authStore = useAuthStore();
const router = useRouter();

const mode = ref<'login' | 'signup'>('login');
const role = ref<UserRole>('teacher');
const name = ref('');
const email = ref('');
const password = ref('');
const errorMessage = ref('');

const stats = [
  { label: 'Questions processed today', val: '1,247', color: 'var(--cyan)', icon: '◈' },
  { label: 'Papers generated', val: '38', color: 'var(--indigo)', icon: '◉' },
  { label: 'AI suggestions accepted', val: '94%', color: 'var(--success)', icon: '✦' },
  { label: 'Active blueprints', val: '12', color: 'var(--warn)', icon: '⬡' },
];

const submitLabel = computed(() => {
  if (authStore.loading) return 'Authenticating…';
  if (mode.value === 'login') {
    return `Sign in as ${role.value === 'admin' ? 'Administrator' : 'Teacher'}`;
  }
  return 'Create account';
});

const toggleMode = () => {
  mode.value = mode.value === 'login' ? 'signup' : 'login';
  errorMessage.value = '';
};

const submit = async () => {
  errorMessage.value = '';
  if (!email.value || !password.value) {
    errorMessage.value = 'Please fill in all required fields.';
    return;
  }

  try {
    const user = await authStore.login({
      email: email.value,
      password: password.value,
      role: role.value,
    });
    await router.push(user.role === 'admin' ? '/admin' : '/teacher');
  } catch (error) {
    if (axios.isAxiosError(error)) {
      errorMessage.value = error.response?.data?.message ?? 'Unable to sign in right now.';
      return;
    }
    errorMessage.value = 'Unable to sign in right now.';
  }
};
</script>

<template>
  <div class="min-h-screen flex flex-col-reverse lg:flex-row bg-bg">
    <div class="flex-1 flex flex-col items-center justify-center px-6 py-10 sm:p-10 relative overflow-hidden">
      <div
        class="absolute inset-0 opacity-30"
        style="
          background-image: linear-gradient(var(--border) 1px, transparent 1px),
            linear-gradient(90deg, var(--border) 1px, transparent 1px);
          background-size: 40px 40px;
        "
      />
      <div
        class="absolute top-[20%] left-[10%] w-[400px] h-[400px] pointer-events-none"
        style="background: radial-gradient(circle, oklch(0.75 0.19 196 / 0.1), transparent 70%);"
      />
      <div class="relative z-[1] text-center max-w-[400px] w-full">
        <div class="mb-10">
          <div class="inline-flex items-center gap-2.5 mb-5">
            <div
              class="w-10 h-10 rounded-xl flex items-center justify-center text-xl font-extrabold text-bg font-head"
              style="background: linear-gradient(135deg, var(--cyan), var(--indigo));"
            >
              Q
            </div>
            <span class="font-head text-[22px] font-bold">
              QForge
            </span>
          </div>
          <h2 class="font-head text-[26px] sm:text-[28px] font-bold mb-3 tracking-[-0.03em]">
            {{ mode === 'login' ? 'Welcome back' : 'Create your account' }}
          </h2>
          <p class="text-text2 text-sm">
            Smart question paper generation for educators
          </p>
        </div>

        <div class="flex gap-2 mb-6 bg-bg2 p-1 rounded-md">
          <button
            v-for="r in (['teacher', 'admin'] as UserRole[])"
            :key="r"
            type="button"
            class="flex-1 py-2 border-none rounded-md cursor-pointer text-[13px] font-semibold transition-all duration-150 font-body"
            :class="role === r ? 'bg-bg1 text-text' : 'bg-transparent text-text3'"
            :style="role === r ? 'box-shadow: 0 1px 4px #0004' : ''"
            @click="role = r"
          >
            {{ r === 'teacher' ? 'Teacher' : 'Administrator' }}
          </button>
        </div>

        <form
          class="flex flex-col gap-3.5 mb-5 text-left"
          @submit.prevent="submit"
        >
          <QFInput
            v-if="mode === 'signup'"
            v-model="name"
            label="Full name"
            placeholder="Dr. Sarah Johnson"
          />
          <QFInput
            v-model="email"
            label="Email address"
            type="email"
            autocomplete="email"
            placeholder="you@institution.edu"
          />
          <QFInput
            v-model="password"
            label="Password"
            type="password"
            autocomplete="current-password"
            placeholder="••••••••"
          />

          <div
            v-if="errorMessage"
            class="bg-danger-dim border border-danger rounded-md px-3.5 py-2.5 text-danger text-[13px] text-left"
          >
            {{ errorMessage }}
          </div>

          <QFButton
            type="submit"
            variant="primary"
            :disabled="authStore.loading"
            block
            class="!py-[11px] !text-[15px]"
          >
            <template v-if="authStore.loading" #icon>
              <QFSpinner :size="16" />
            </template>
            {{ submitLabel }}
          </QFButton>
        </form>

        <div class="mt-5 text-text3 text-[13px]">
          {{ mode === 'login' ? "Don't have an account? " : 'Already have an account? ' }}
          <span
            class="text-cyan cursor-pointer font-medium"
            @click="toggleMode"
          >
            {{ mode === 'login' ? 'Sign up' : 'Sign in' }}
          </span>
        </div>
      </div>
    </div>

    <div class="w-full lg:w-[420px] bg-bg1 border-b lg:border-b-0 lg:border-l border-border flex flex-col justify-center px-6 py-8 sm:p-12 gap-5">
      <div class="mb-2">
        <div class="text-[11px] font-semibold text-text3 uppercase tracking-[0.08em] mb-4">
          System activity
        </div>
        <div
          v-for="s in stats"
          :key="s.label"
          class="flex items-center gap-3 py-3 border-b border-border"
        >
          <div
            class="w-8 h-8 rounded-lg flex items-center justify-center text-sm shrink-0"
            :style="{
              background: `color-mix(in oklab, ${s.color} 10%, transparent)`,
              color: s.color,
            }"
          >
            {{ s.icon }}
          </div>
          <div class="flex-1">
            <div class="text-xs text-text3">{{ s.label }}</div>
            <div
              class="font-head text-lg font-bold"
              :style="{ color: s.color }"
            >
              {{ s.val }}
            </div>
          </div>
        </div>
      </div>
      <div class="bg-bg2 rounded-md p-4 border border-border">
        <div class="text-[11px] text-text3 mb-2.5 uppercase tracking-[0.08em] font-semibold">
          Latest generation
        </div>
        <div class="font-head font-semibold mb-1">
          Advanced Mathematics — Final Exam
        </div>
        <div class="text-text3 text-[12.5px] mb-2.5">
          28 questions · 100 marks · 3 units covered
        </div>
        <div class="flex gap-1.5 flex-wrap">
          <QFBadge variant="success">All constraints met</QFBadge>
          <QFBadge variant="ai">✦ AI assisted</QFBadge>
        </div>
      </div>
    </div>
  </div>
</template>
