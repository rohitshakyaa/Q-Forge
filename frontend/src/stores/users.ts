import { ref } from 'vue';
import { defineStore } from 'pinia';
import {
  usersApi,
  type ManagedUser,
  type CreateUserPayload,
  type UpdateUserPayload,
} from '../api/users/users.api';

export type { ManagedUser } from '../api/users/users.api';

export const useUsersStore = defineStore('users', () => {
  const list = ref<ManagedUser[]>([]);
  const loading = ref(false);

  async function fetch() {
    loading.value = true;
    try {
      list.value = await usersApi.list();
    } finally {
      loading.value = false;
    }
  }

  async function create(payload: CreateUserPayload) {
    const user = await usersApi.create(payload);
    await fetch();
    return user;
  }

  async function update(id: number, payload: UpdateUserPayload) {
    const user = await usersApi.update(id, payload);
    await fetch();
    return user;
  }

  async function remove(id: number) {
    await usersApi.remove(id);
    await fetch();
  }

  return { list, loading, fetch, create, update, remove };
});
