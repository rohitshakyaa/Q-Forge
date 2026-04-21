import api from '../client/axios';
import type { LoginPayload, LoginResponse } from '../../types/auth';

export const authApi = {
  async login(payload: LoginPayload) {
    const { data } = await api.post<LoginResponse>('/auth/login', payload);
    return data;
  },

  async me() {
    const { data } = await api.get<{ user: LoginResponse['user'] }>('/auth/me');
    return data;
  },

  async logout() {
    const { data } = await api.post<{ message: string }>('/auth/logout');
    return data;
  },
};
