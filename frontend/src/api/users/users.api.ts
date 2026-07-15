import api from '../client/axios';
import type { UserRole } from '../../types/auth';

export interface ManagedUser {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  created_at: string | null;
}

export interface CreateUserPayload {
  name: string;
  email: string;
  role: UserRole;
  password: string;
}

export interface UpdateUserPayload {
  name?: string;
  email?: string;
  role?: UserRole;
  password?: string;
}

export const usersApi = {
  async list() {
    const { data } = await api.get<{ data: ManagedUser[] }>('/users');
    return data.data;
  },

  async create(payload: CreateUserPayload) {
    const { data } = await api.post<{ data: ManagedUser }>('/users', payload);
    return data.data;
  },

  async update(id: number, payload: UpdateUserPayload) {
    const { data } = await api.put<{ data: ManagedUser }>(`/users/${id}`, payload);
    return data.data;
  },

  async remove(id: number) {
    await api.delete(`/users/${id}`);
  },
};
