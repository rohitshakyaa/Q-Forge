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
  <div style="min-height: 100vh; display: flex; background: var(--bg)">
    <div
      style="
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 40px;
        position: relative;
        overflow: hidden;
      "
    >
      <div
        style="
          position: absolute;
          inset: 0;
          background-image: linear-gradient(var(--border) 1px, transparent 1px),
            linear-gradient(90deg, var(--border) 1px, transparent 1px);
          background-size: 40px 40px;
          opacity: 0.3;
        "
      />
      <div
        style="
          position: absolute;
          top: 20%;
          left: 10%;
          width: 400px;
          height: 400px;
          background: radial-gradient(circle, oklch(0.75 0.19 196 / 0.1), transparent 70%);
          pointer-events: none;
        "
      />
      <div style="position: relative; z-index: 1; text-align: center; max-width: 400px; width: 100%">
        <div style="margin-bottom: 40px">
          <div style="display: inline-flex; align-items: center; gap: 10px; margin-bottom: 20px">
            <div
              style="
                width: 40px;
                height: 40px;
                background: linear-gradient(135deg, var(--cyan), var(--indigo));
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 20px;
                font-weight: 800;
                color: #070a10;
                font-family: var(--font-head);
              "
            >
              Q
            </div>
            <span style="font-family: var(--font-head); font-size: 22px; font-weight: 700">
              QForge
            </span>
          </div>
          <h2
            style="
              font-family: var(--font-head);
              font-size: 28px;
              font-weight: 700;
              margin-bottom: 12px;
              letter-spacing: -0.03em;
            "
          >
            {{ mode === 'login' ? 'Welcome back' : 'Create your account' }}
          </h2>
          <p style="color: var(--text2); font-size: 14px">
            Smart question paper generation for educators
          </p>
        </div>

        <div
          style="
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            background: var(--bg2);
            padding: 4px;
            border-radius: var(--radius);
          "
        >
          <button
            v-for="r in (['teacher', 'admin'] as UserRole[])"
            :key="r"
            type="button"
            :style="{
              flex: 1,
              padding: '8px',
              border: 'none',
              borderRadius: '6px',
              cursor: 'pointer',
              fontSize: '13px',
              fontWeight: 600,
              transition: 'all 0.15s',
              background: role === r ? 'var(--bg1)' : 'transparent',
              color: role === r ? 'var(--text)' : 'var(--text3)',
              boxShadow: role === r ? '0 1px 4px #0004' : 'none',
              fontFamily: 'var(--font-body)',
            }"
            @click="role = r"
          >
            {{ r === 'teacher' ? 'Teacher' : 'Administrator' }}
          </button>
        </div>

        <form
          style="display: flex; flex-direction: column; gap: 14px; margin-bottom: 20px; text-align: left"
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
            style="
              background: var(--danger-dim);
              border: 1px solid var(--danger);
              border-radius: var(--radius);
              padding: 10px 14px;
              color: var(--danger);
              font-size: 13px;
              text-align: left;
            "
          >
            {{ errorMessage }}
          </div>

          <QFButton
            type="submit"
            variant="primary"
            :disabled="authStore.loading"
            block
            :style="{ padding: '11px', fontSize: '15px' }"
          >
            <template v-if="authStore.loading" #icon>
              <QFSpinner :size="16" />
            </template>
            {{ submitLabel }}
          </QFButton>
        </form>

        <div style="margin-top: 20px; color: var(--text3); font-size: 13px">
          {{ mode === 'login' ? "Don't have an account? " : 'Already have an account? ' }}
          <span
            style="color: var(--cyan); cursor: pointer; font-weight: 500"
            @click="toggleMode"
          >
            {{ mode === 'login' ? 'Sign up' : 'Sign in' }}
          </span>
        </div>
      </div>
    </div>

    <div
      style="
        width: 420px;
        background: var(--bg1);
        border-left: 1px solid var(--border);
        display: flex;
        flex-direction: column;
        justify-content: center;
        padding: 48px;
        gap: 20px;
      "
    >
      <div style="margin-bottom: 8px">
        <div
          style="
            font-size: 11px;
            font-weight: 600;
            color: var(--text3);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 16px;
          "
        >
          System activity
        </div>
        <div
          v-for="s in stats"
          :key="s.label"
          style="
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
          "
        >
          <div
            :style="{
              width: '32px',
              height: '32px',
              background: `color-mix(in oklab, ${s.color} 10%, transparent)`,
              borderRadius: '8px',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              color: s.color,
              fontSize: '14px',
              flexShrink: 0,
            }"
          >
            {{ s.icon }}
          </div>
          <div style="flex: 1">
            <div style="font-size: 12px; color: var(--text3)">{{ s.label }}</div>
            <div
              :style="{
                fontFamily: 'var(--font-head)',
                fontSize: '18px',
                fontWeight: 700,
                color: s.color,
              }"
            >
              {{ s.val }}
            </div>
          </div>
        </div>
      </div>
      <div
        style="
          background: var(--bg2);
          border-radius: var(--radius);
          padding: 16px;
          border: 1px solid var(--border);
        "
      >
        <div
          style="
            font-size: 11px;
            color: var(--text3);
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 600;
          "
        >
          Latest generation
        </div>
        <div style="font-family: var(--font-head); font-weight: 600; margin-bottom: 4px">
          Advanced Mathematics — Final Exam
        </div>
        <div style="color: var(--text3); font-size: 12.5px; margin-bottom: 10px">
          28 questions · 100 marks · 3 units covered
        </div>
        <div style="display: flex; gap: 6px; flex-wrap: wrap">
          <QFBadge variant="success">All constraints met</QFBadge>
          <QFBadge variant="ai">✦ AI assisted</QFBadge>
        </div>
      </div>
    </div>
  </div>
</template>
