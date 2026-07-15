<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import { useAuthStore } from '../../stores/auth';
import { useThemeStore } from '../../stores/theme';
import QFButton from '../../components/qf/QFButton.vue';
import QFInput from '../../components/qf/QFInput.vue';
import QFSpinner from '../../components/qf/QFSpinner.vue';

const authStore = useAuthStore();
const theme = useThemeStore();
const router = useRouter();

const email = ref('');
const password = ref('');
const errorMessage = ref('');

const submit = async () => {
  errorMessage.value = '';
  if (!email.value || !password.value) {
    errorMessage.value = 'Please enter your email and password.';
    return;
  }

  try {
    // The client sends only email + password; the backend derives the role and
    // we route by the returned role.
    const user = await authStore.login({
      email: email.value,
      password: password.value,
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
  <div class="min-h-screen flex flex-col bg-bg text-text">
    <header class="flex items-center justify-between px-6 py-5 sm:px-8">
      <div class="inline-flex items-center gap-2.5">
        <div
          class="w-8 h-8 rounded-lg flex items-center justify-center text-base font-extrabold font-head"
          style="background: linear-gradient(135deg, var(--cyan), var(--indigo)); color: var(--on-primary)"
        >
          Q
        </div>
        <span class="font-head text-lg font-bold tracking-tight">QForge</span>
      </div>
      <button
        type="button"
        class="qf-hamburger"
        :aria-label="`Switch to ${theme.isDark ? 'light' : 'dark'} mode`"
        @click="theme.toggle()"
      >
        <span class="text-lg leading-none">{{ theme.isDark ? '☾' : '☀' }}</span>
      </button>
    </header>

    <main class="flex-1 flex items-center justify-center px-6 py-10">
      <div class="w-full max-w-[400px]">
        <div class="text-center mb-8">
          <h1 class="font-head text-[26px] sm:text-[28px] font-bold tracking-[-0.02em] mb-2">
            Sign in to QForge
          </h1>
          <p class="text-text2 text-sm">
            Smart question-paper generation for educators
          </p>
        </div>

        <div class="qf-card p-6 sm:p-7">
          <form class="flex flex-col gap-4" @submit.prevent="submit">
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
              class="bg-danger-dim border border-danger rounded-md px-3.5 py-2.5 text-danger text-[13px]"
            >
              {{ errorMessage }}
            </div>

            <QFButton
              type="submit"
              variant="primary"
              :disabled="authStore.loading"
              block
              class="!py-2.75 !text-[15px]"
            >
              <template v-if="authStore.loading" #icon>
                <QFSpinner :size="16" />
              </template>
              {{ authStore.loading ? 'Signing in…' : 'Sign in' }}
            </QFButton>
          </form>
        </div>

        <p class="text-center text-text3 text-xs mt-5">
          Accounts are provisioned by an administrator.
        </p>
      </div>
    </main>
  </div>
</template>
