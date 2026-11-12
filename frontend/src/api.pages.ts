import { Injectable } from '@angular/core';

type DemoSnapshot = Record<string, Record<string, unknown>>;

@Injectable({ providedIn: 'root' })
export class Api {
  readonly readOnly = true;
  userId = 1;
  private snapshot?: Promise<DemoSnapshot>;

  async request<T>(path: string, method = 'GET', _data?: unknown): Promise<T> {
    if (method !== 'GET') throw new Error('Демонстрация доступна только для просмотра.');

    const userId = String(this.userId);
    const snapshot = await (this.snapshot ??= this.loadSnapshot().catch(error => {
      this.snapshot = undefined;
      throw error;
    }));
    const routes = Object.hasOwn(snapshot, userId) ? snapshot[userId] : undefined;
    if (!routes || !Object.hasOwn(routes, path)) {
      throw new Error('Эти данные недоступны для выбранного пользователя.');
    }
    return routes[path] as T;
  }

  private async loadSnapshot(): Promise<DemoSnapshot> {
    const response = await fetch(new URL('demo-data.json', document.baseURI));
    if (!response.ok) throw new Error('Не удалось загрузить демонстрационные данные.');

    const snapshot: unknown = await response.json();
    if (typeof snapshot !== 'object' || snapshot === null || Array.isArray(snapshot)) {
      throw new Error('Демонстрационные данные имеют неверный формат.');
    }
    return snapshot as DemoSnapshot;
  }
}
