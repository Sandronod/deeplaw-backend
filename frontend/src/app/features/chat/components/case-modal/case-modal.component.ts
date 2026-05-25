import { Component, inject, effect, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { CaseModalService } from '../../../../core/services/case-modal.service';
import { ApiService } from '../../../../core/services/api.service';

@Component({
  selector: 'app-case-modal',
  standalone: true,
  imports: [CommonModule],
  template: `
    @if (modal.isOpen()) {
      <!-- Backdrop -->
      <div
        class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-start justify-center
               p-4 sm:p-8 overflow-y-auto animate-fade-in"
        (click)="modal.close()"
      >
        <!-- Modal panel -->
        <div
          class="relative w-full max-w-4xl bg-white dark:bg-gray-900 rounded-2xl shadow-2xl
                 border border-gray-100 dark:border-gray-700 overflow-hidden
                 my-auto animate-slide-up"
          (click)="$event.stopPropagation()"
        >

          <!-- Header -->
          <div class="sticky top-0 z-10 flex items-center gap-3 px-5 py-3.5
                      bg-white dark:bg-gray-900 border-b border-gray-100 dark:border-gray-800">

            @if (caseData()) {
              <span class="text-xs px-2 py-0.5 rounded-full font-medium shrink-0"
                    [class]="caseData()!.case_type === 'civil'
                      ? 'bg-violet-100 dark:bg-violet-900/40 text-violet-700 dark:text-violet-300'
                      : 'bg-accent/10 text-accent'">
                {{ caseData()!.case_type === 'civil' ? 'სამოქალაქო' : 'ადმინისტრაციული' }}
              </span>
              @if (caseData()!.case_num) {
                <span class="text-sm font-semibold text-gray-800 dark:text-gray-100 truncate">
                  {{ caseData()!.case_num }}
                </span>
              }
              @if (caseData()!.case_date) {
                <span class="text-xs text-gray-400 shrink-0 ml-auto mr-2">
                  {{ caseData()!.case_date }}
                </span>
              }
            } @else if (isLoading()) {
              <div class="h-4 w-48 skeleton rounded-full"></div>
            } @else {
              <span class="text-sm text-gray-500">გადაწყვეტილება</span>
            }

            <!-- Close -->
            <button
              (click)="modal.close()"
              class="ml-auto shrink-0 w-8 h-8 rounded-lg flex items-center justify-center
                     text-gray-400 hover:text-gray-700 dark:hover:text-gray-200
                     hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
            >
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2.5">
                <line x1="18" y1="6" x2="6" y2="18"/>
                <line x1="6"  y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>

          <!-- Body -->
          <div class="px-5 sm:px-8 py-6 max-h-[80vh] overflow-y-auto">

            @if (isLoading()) {
              <div class="space-y-3 animate-fade-in">
                @for (w of ['90%','70%','85%','60%','75%','50%','80%']; track w) {
                  <div class="h-3 skeleton rounded-full" [style.width]="w"></div>
                }
              </div>
            }

            @if (error()) {
              <div class="flex flex-col items-center justify-center py-16 gap-3 text-gray-400">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.5">
                  <circle cx="12" cy="12" r="10"/>
                  <line x1="12" y1="8" x2="12" y2="12"/>
                  <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <p class="text-sm">{{ error() }}</p>
              </div>
            }

            @if (caseData() && !isLoading()) {
              <div class="decision-content prose prose-sm max-w-none
                          dark:prose-invert prose-headings:font-semibold
                          prose-p:leading-relaxed prose-p:text-gray-800 dark:prose-p:text-gray-200"
                   [innerHTML]="caseData()!.content">
              </div>
            }

          </div>
        </div>
      </div>
    }
  `,
  styles: [`
    .decision-content :deep(table) {
      width: 100%; border-collapse: collapse; font-size: 0.85rem;
    }
    .decision-content :deep(td),
    .decision-content :deep(th) {
      padding: 4px 8px; border: 1px solid #e5e7eb;
    }
    .decision-content :deep(p) { margin-bottom: 0.75rem; }
  `],
})
export class CaseModalComponent {
  modal = inject(CaseModalService);
  private api = inject(ApiService);

  isLoading = signal(false);
  error     = signal<string | null>(null);
  caseData  = signal<{
    case_id: number; case_type: string; case_num: string | null;
    case_date: string | null; content: string; content_type: string;
  } | null>(null);

  constructor() {
    effect(() => {
      const id   = this.modal.caseId();
      const type = this.modal.caseType();
      if (!id) { this.caseData.set(null); return; }

      this.isLoading.set(true);
      this.error.set(null);
      this.caseData.set(null);

      const req$ = type
        ? this.api.getFullCase(type, id)
        : this.api.getFullCaseById(id);

      req$.subscribe({
        next:  data => { this.caseData.set(data); this.isLoading.set(false); },
        error: ()   => { this.error.set('გადაწყვეტილება ვერ მოიძებნა.'); this.isLoading.set(false); },
      });
    });
  }
}
