import { Component, Input, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Citation } from '../../../../core/models/message.model';

@Component({
  selector: 'app-citation-list',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="mt-1 space-y-1.5">

      <!-- Label -->
      <div class="flex items-center gap-1.5 text-[11px] font-semibold text-gray-400 uppercase tracking-wide">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
          <polyline points="14 2 14 8 20 8"/>
        </svg>
        წყაროები ({{ citations.length }})
      </div>

      <!-- Cards -->
      <div class="flex flex-col gap-1.5">
        @for (c of citations; track c.case_id) {
          <div class="border border-gray-100 rounded-xl overflow-hidden
                      hover:border-gray-200 transition-colors">

            <!-- Header row -->
            <button
              (click)="toggle(c.case_id)"
              class="flex items-center justify-between gap-2 w-full
                     px-3.5 py-2.5 text-left bg-gray-50 hover:bg-gray-100 transition-colors"
            >
              <div class="flex items-center gap-2 min-w-0">
                <div class="w-1.5 h-1.5 rounded-full bg-accent shrink-0"></div>
                <span class="text-[16px] font-medium text-gray-700 truncate">
                  {{ c.case_num || ('Case #' + c.case_id) }}
                </span>
                @if (c.court) {
                  <span class="hidden sm:block text-[16px] text-gray-400 truncate">· {{ c.court }}</span>
                }
              </div>
              <svg
                class="w-3.5 h-3.5 text-gray-400 shrink-0 transition-transform duration-200"
                [class.rotate-180]="isOpen(c.case_id)"
                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <polyline points="6 9 12 15 18 9"/>
              </svg>
            </button>

            <!-- Expanded details -->
            @if (isOpen(c.case_id)) {
              <div class="px-3.5 py-3 bg-white border-t border-gray-100 space-y-2">

                <div class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-[16px]">
                  @if (c.case_date) {
                    <div>
                      <span class="text-gray-400">თარიღი</span>
                      <p class="text-gray-700 font-medium">{{ c.case_date }}</p>
                    </div>
                  }
                  @if (c.chamber) {
                    <div>
                      <span class="text-gray-400">პალატა</span>
                      <p class="text-gray-700 font-medium">{{ c.chamber }}</p>
                    </div>
                  }
                  @if (c.category) {
                    <div>
                      <span class="text-gray-400">კატეგორია</span>
                      <p class="text-gray-700 font-medium">{{ c.category }}</p>
                    </div>
                  }
                  @if (c.relevance_score) {
                    <div>
                      <span class="text-gray-400">რელევანტობა</span>
                      <p class="text-accent font-semibold">{{ (c.relevance_score * 100).toFixed(1) }}%</p>
                    </div>
                  }
                </div>

                @if (c.dispute_subject) {
                  <div class="text-[16px]">
                    <span class="text-gray-400">სადაო საგანი</span>
                    <p class="text-gray-700 mt-0.5">{{ c.dispute_subject }}</p>
                  </div>
                }

                @if (c.result) {
                  <div class="text-[16px]">
                    <span class="text-gray-400">შედეგი</span>
                    <p class="text-emerald-700 font-medium mt-0.5">{{ c.result }}</p>
                  </div>
                }

                @if (c.url) {
                  <a
                    [href]="c.url"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-1.5 mt-1 text-[16px] text-accent
                           hover:text-accent-hover font-medium transition-colors"
                  >
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2">
                      <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                      <polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                    </svg>
                    სრული გადაწყვეტილება →
                  </a>
                }

              </div>
            }
          </div>
        }
      </div>
    </div>
  `,
})
export class CitationListComponent {
  @Input({ required: true }) citations: Citation[] = [];

  private openId = signal<number | null>(null);

  toggle(id: number): void { this.openId.update(v => v === id ? null : id); }
  isOpen(id: number): boolean { return this.openId() === id; }
}
