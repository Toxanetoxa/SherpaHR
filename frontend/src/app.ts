import { afterNextRender, Component, computed, ElementRef, inject, Injector, signal, viewChild } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Api } from './api';
import { AttentionItem, AttentionSignal, Comment, Context, Project, Task, TaskDetail, TaskStatus } from './models';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './app.html',
})
export class AppComponent {
  api = inject(Api);
  context = signal<Context | null>(null);
  projects = signal<Project[]>([]);
  project = signal<Project | null>(null);
  tasks = signal<Task[]>([]);
  attentionOpen = signal(false);
  attention = signal<AttentionItem[]>([]);
  attentionLoading = signal(false);
  attentionError = signal('');
  attentionFilter = signal<AttentionSignal | 'all'>('all');
  ledProjects = computed(() => this.projects().filter(p => !p.archived && p.owner_id === this.context()?.user.id));
  filteredAttention = computed(() => {
    const filter = this.attentionFilter();
    return this.attention().filter(item => filter === 'all' || item.signals.includes(filter));
  });
  detail = signal<TaskDetail | null>(null);
  detailLoading = signal(false);
  detailProject = computed(() => {
    const task = this.detail();
    return task ? this.projects().find(p => p.id === task.project_id) : this.project();
  });
  loading = signal(false);
  saving = signal(false);
  error = signal('');
  notice = signal('');
  status = '';
  search = '';
  comment = '';
  creating = signal(false);
  draft = this.emptyDraft();
  private generation = 0;
  private userGeneration = 0;
  private attentionGeneration = 0;
  private detailGeneration = 0;
  private detailTrigger: HTMLElement | null = null;
  private readonly injector = inject(Injector);
  private readonly drawerTitle = viewChild<ElementRef<HTMLElement>>('drawerTitle');
  private readonly discussionTitle = viewChild<ElementRef<HTMLElement>>('discussionTitle');
  private readonly attentionTitle = viewChild<ElementRef<HTMLElement>>('attentionTitle');
  readonly statuses: { value: TaskStatus; label: string }[] = [
    { value: 'open', label: 'Открыта' }, { value: 'in_progress', label: 'В работе' },
    { value: 'blocked', label: 'Заблокирована' }, { value: 'done', label: 'Готово' },
  ];
  readonly attentionLabels: Record<AttentionSignal, string> = {
    blocked: 'Заблокирована',
    overdue: 'Срок истёк',
    needs_help: 'Возможное препятствие',
    due_soon: 'Срок в ближайшие 24 ч',
  };
  readonly attentionFilters: { value: AttentionSignal | 'all'; label: string }[] = [
    { value: 'all', label: 'Все' },
    { value: 'blocked', label: 'Блокировки' },
    { value: 'overdue', label: 'Просрочены' },
    { value: 'needs_help', label: 'Обсуждения' },
    { value: 'due_soon', label: 'Срок близко' },
  ];

  constructor() { void this.load(); }

  async load(): Promise<void> {
    const generation = ++this.generation;
    this.loading.set(true);
    this.error.set('');
    this.closeDetail();
    try {
      const [context, projects] = await Promise.all([
        this.api.request<Context>('/context'), this.api.request<Project[]>('/projects'),
      ]);
      if (generation !== this.generation) return;
      this.context.set(context);
      this.projects.set(projects);
      const project = projects.find(p => p.id === this.project()?.id);
      if (project) {
        await this.selectProject(project);
      } else if (this.ledProjects().length) {
        this.openAttention();
      } else if (projects[0]) {
        await this.selectProject(projects[0]);
      }
    } catch (error) {
      if (generation === this.generation) this.fail(error);
    } finally {
      if (generation === this.generation) this.loading.set(false);
    }
  }

  switchUser(value: string): void {
    ++this.userGeneration;
    ++this.attentionGeneration;
    this.api.userId = Number(value);
    this.project.set(null);
    this.projects.set([]);
    this.tasks.set([]);
    this.context.set(null);
    this.notice.set('');
    this.attentionOpen.set(false);
    this.attention.set([]);
    this.attentionLoading.set(false);
    this.attentionError.set('');
    this.attentionFilter.set('all');
    void this.load();
  }

  openAttention(): void {
    ++this.generation;
    this.loading.set(false);
    this.error.set('');
    this.notice.set('');
    this.project.set(null);
    this.tasks.set([]);
    this.closeDetail();
    this.attentionOpen.set(true);
    void this.refreshAttention();
  }

  async refreshAttention(): Promise<void> {
    const generation = ++this.attentionGeneration;
    this.attentionLoading.set(true);
    this.attentionError.set('');
    try {
      const items = await this.api.request<AttentionItem[]>('/attention');
      if (generation === this.attentionGeneration) this.attention.set(items);
    } catch (error) {
      if (generation === this.attentionGeneration) {
        this.attentionError.set(error instanceof Error ? error.message : 'Не удалось загрузить сводку');
      }
    } finally {
      if (generation === this.attentionGeneration) this.attentionLoading.set(false);
    }
  }

  attentionCount(filter: AttentionSignal | 'all'): number {
    return filter === 'all' ? this.attention().length
      : this.attention().filter(item => item.signals.includes(filter)).length;
  }

  async selectProject(project: Project): Promise<void> {
    const generation = ++this.generation;
    this.loading.set(true);
    this.error.set('');
    this.notice.set('');
    this.attentionOpen.set(false);
    this.project.set(project);
    this.tasks.set([]);
    this.status = '';
    this.search = '';
    this.closeDetail();
    try {
      const tasks = await this.api.request<Task[]>(`/projects/${project.id}/tasks`);
      if (generation === this.generation) this.tasks.set(tasks);
    } catch (error) {
      if (generation === this.generation) this.fail(error);
    } finally {
      if (generation === this.generation) this.loading.set(false);
    }
  }

  filteredTasks(): Task[] {
    return this.tasks().filter(task => (!this.status || task.status === this.status)
      && task.title.toLocaleLowerCase('ru').includes(this.search.toLocaleLowerCase('ru')));
  }

  async openTask(task: Task, trigger: EventTarget | null, discuss = false): Promise<void> {
    const generation = ++this.detailGeneration;
    this.detailTrigger = trigger instanceof HTMLElement ? trigger : null;
    this.error.set('');
    this.creating.set(false);
    this.detail.set(null);
    this.detailLoading.set(true);
    try {
      const detail = await this.api.request<TaskDetail>(`/tasks/${task.id}`);
      if (generation !== this.detailGeneration) return;
      this.detail.set(detail);
      this.comment = '';
      this.draft = {
        title: detail.title, description: detail.description, status: detail.status,
        assignee_id: detail.assignee_id,
        due_at: detail.due_at ? detail.due_at.slice(0, 16) : '',
      };
      this.focusDrawer(generation, discuss);
    } catch (error) {
      if (generation === this.detailGeneration) this.fail(error);
    } finally {
      if (generation === this.detailGeneration) this.detailLoading.set(false);
    }
  }

  closeDetail(restoreFocus = false): void {
    ++this.detailGeneration;
    const trigger = this.detailTrigger;
    this.detailTrigger = null;
    this.detail.set(null);
    this.detailLoading.set(false);
    this.creating.set(false);
    this.comment = '';
    if (restoreFocus) {
      afterNextRender(() => {
        const target = trigger?.isConnected ? trigger : this.attentionTitle()?.nativeElement;
        target?.focus();
      }, { injector: this.injector });
    }
  }

  createTask(trigger: EventTarget | null): void {
    this.closeDetail();
    this.detailTrigger = trigger instanceof HTMLElement ? trigger : null;
    this.draft = this.emptyDraft();
    this.creating.set(true);
    this.focusDrawer(this.detailGeneration);
  }

  async saveTask(): Promise<void> {
    const project = this.detailProject();
    if (!project || project.archived || this.saving()) return;
    const user = this.userGeneration;
    const generation = this.detailGeneration;
    const existing = this.detail();
    this.saving.set(true);
    this.error.set('');
    try {
      const data = {
        ...this.draft,
        due_at: this.draft.due_at ? `${this.draft.due_at}:00Z` : null,
        ...(existing ? { version: existing.version } : {}),
      };
      const task = await this.api.request<Task>(existing ? `/tasks/${existing.id}` : `/projects/${project.id}/tasks`, existing ? 'PATCH' : 'POST', data);
      if (user !== this.userGeneration) return;
      if (!existing) this.projects.update(list => list.map(p => p.id === project.id ? { ...p, task_count: p.task_count + 1 } : p));
      if (this.project()?.id === project.id) {
        this.tasks.update(list => existing ? list.map(t => t.id === task.id ? task : t) : [...list, task]);
      }
      if (generation === this.detailGeneration) {
        // Saving fields must not erase a comment the user has not sent yet.
        this.detail.set({ ...task, comments: existing?.comments ?? [] });
        this.creating.set(false);
        this.draft = {
          title: task.title, description: task.description, status: task.status,
          assignee_id: task.assignee_id, due_at: task.due_at?.slice(0, 16) ?? '',
        };
        this.notice.set('Задача сохранена');
      }
      if (this.attentionOpen()) void this.refreshAttention();
    } catch (error) { if (generation === this.detailGeneration) this.fail(error); }
    finally { this.saving.set(false); }
  }

  async addComment(): Promise<void> {
    const task = this.detail();
    const project = this.detailProject();
    if (!task || !project || project.archived || this.saving()) return;
    const user = this.userGeneration;
    const generation = this.detailGeneration;
    this.saving.set(true);
    this.error.set('');
    try {
      const comment = await this.api.request<Comment>(`/tasks/${task.id}/comments`, 'POST', { body: this.comment });
      if (user !== this.userGeneration) return;
      if (generation === this.detailGeneration) {
        // Refresh only the discussion: fields may contain unsaved user edits.
        this.detail.update(current => current ? { ...current, comments: [...current.comments, comment] } : current);
        this.comment = '';
        this.notice.set('Комментарий добавлен');
      }
      if (this.attentionOpen()) void this.refreshAttention();
    } catch (error) { if (generation === this.detailGeneration) this.fail(error); }
    finally { this.saving.set(false); }
  }

  statusLabel(status: string): string { return this.statuses.find(s => s.value === status)?.label ?? status; }
  userName(id: number | null): string { return this.context()?.users.find(u => u.id === id)?.name ?? 'Не назначен'; }
  private focusDrawer(generation: number, discuss = false): void {
    afterNextRender(() => {
      if (generation !== this.detailGeneration) return;
      const target = discuss ? this.discussionTitle() : this.drawerTitle();
      target?.nativeElement.focus();
    }, { injector: this.injector });
  }
  private fail(error: unknown): void { this.error.set(error instanceof Error ? error.message : 'Не удалось выполнить запрос'); }
  private emptyDraft(): { title: string; description: string; status: TaskStatus; assignee_id: number | null; due_at: string } {
    return { title: '', description: '', status: 'open', assignee_id: null, due_at: '' };
  }
}
