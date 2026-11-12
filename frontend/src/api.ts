import { Injectable } from '@angular/core';

@Injectable({ providedIn: 'root' })
export class Api {
  readonly readOnly = false;
  userId = 1;

  async request<T>(path: string, method = 'GET', data?: unknown): Promise<T> {
    const response = await fetch(`/api${path}`, {
      method,
      headers: { 'Content-Type': 'application/json', 'X-Demo-User': String(this.userId) },
      body: data === undefined ? undefined : JSON.stringify(data),
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error ?? `Ошибка ${response.status}`);
    return result.data as T;
  }
}
