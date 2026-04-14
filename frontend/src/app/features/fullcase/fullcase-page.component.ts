import { Component, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { ApiService } from '../../core/services/api.service';

@Component({
  selector: 'app-fullcase-page',
  standalone: true,
  imports: [CommonModule, RouterLink],
  template: `
    <div class="min-h-screen bg-white dark:bg-gray-950 text-gray-900 dark:text-gray-100">

      <!-- ── Header ──────────────────────────────────────────────────────────── -->
      <div class="sticky top-0 z-10 bg-white dark:bg-gray-950 border-b border-gray-200 dark:border-gray-800
                  px-4 py-3 flex items-center gap-3">
        <a routerLink="/chats"
           class="flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400
                  hover:text-gray-800 dark:hover:text-gray-200 transition-colors shrink-0">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2">
            <polyline points="15 18 9 12 15 6"/>
          </svg>
          უკან
        </a>

        @if (caseData()) {
          <div class="flex items-center gap-2 min-w-0">
            <span class="text-xs px-2 py-0.5 rounded-full font-medium shrink-0"
                  [class]="caseData()!.case_type === 'civil'
                    ? 'bg-violet-100 dark:bg-violet-900/40 text-violet-700 dark:text-violet-300'
                    : 'bg-accent/10 text-accent'">
              {{ caseData()!.case_type === 'civil' ? 'სამოქალაქო' : 'ადმინისტრაციული' }}
            </span>
            @if (caseData()!.case_num) {
              <span class="text-sm font-medium truncate">{{ caseData()!.case_num }}</span>
            }
            @if (caseData()!.case_date) {
              <span class="text-xs text-gray-400 shrink-0">{{ caseData()!.case_date }}</span>
            }
          </div>
        }
      </div>

      <!-- ── Content ─────────────────────────────────────────────────────────── -->
      <div class="max-w-4xl mx-auto px-4 py-8">

        @if (isLoading()) {
          <div class="flex items-center justify-center py-24 gap-3 text-gray-400">
            <span class="flex gap-1">
              @for (d of [0, 1, 2]; track d) {
                <span class="w-2 h-2 rounded-full bg-gray-300 dark:bg-gray-600 animate-bounce-dot"
                      [style.animation-delay]="d * 0.2 + 's'"></span>
              }
            </span>
            <span class="text-sm">გადაწყვეტილება იტვირთება…</span>
          </div>
        }

        @if (error()) {
          <div class="flex flex-col items-center justify-center py-24 gap-3 text-gray-400">
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
  `,
  styles: [`
    .decision-content :deep(table) {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.85rem;
    }
    .decision-content :deep(td),
    .decision-content :deep(th) {
      padding: 4px 8px;
      border: 1px solid #e5e7eb;
    }
    .decision-content :deep(p) {
      margin-bottom: 0.75rem;
    }
  `],
})
export class FullcasePageComponent implements OnInit {
  isLoading = signal(true);
  error     = signal<string | null>(null);
  caseData  = signal<{
    case_id: number; case_type: string; case_num: string | null;
    case_date: string | null; content: string; content_type: string;
  } | null>(null);

  constructor(private route: ActivatedRoute, private api: ApiService) {}

  ngOnInit(): void {
    const type   = this.route.snapshot.paramMap.get('type')   ?? '';
    const caseId = Number(this.route.snapshot.paramMap.get('caseId'));

    this.api.getFullCase(type, caseId).subscribe({
      next: data => {
        this.caseData.set(data);
        this.isLoading.set(false);
      },
      error: () => {
        this.error.set('გადაწყვეტილება ვერ მოიძებნა.');
        this.isLoading.set(false);
      },
    });
  }
}
