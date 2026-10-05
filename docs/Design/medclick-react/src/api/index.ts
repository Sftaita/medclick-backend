import { httpApi } from './http';
import { mockApi } from './mock';
import type { MedClickApi } from './types';

const useMock = (import.meta.env.VITE_USE_MOCK ?? 'true') !== 'false';

export const api: MedClickApi = useMock ? mockApi : httpApi;
export type { MedClickApi };
