import { Component, Input, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { Citation, EchrCitation, LawCitation } from '../../../../core/models/message.model';

@Component({
  selector: 'app-citation-list',
  standalone: true,
  imports: [CommonModule, RouterLink],
  template: `
    <div class="mt-1 space-y-3">

      <!-- ── Court decisions ───────────────────────────────────────────────── -->
      @if (domesticCitations.length > 0) {
        <div class="space-y-1.5">
          <div class="flex items-center gap-1.5 text-[11px] font-semibold text-gray-400 uppercase tracking-wide">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
              <polyline points="14 2 14 8 20 8"/>
            </svg>
            სასამართლო პრაქტიკა ({{ domesticCitations.length }})
          </div>

          <div class="flex flex-col gap-1.5">
            @for (c of domesticCitations; track c.case_id) {
              <div class="border border-gray-100 dark:border-gray-700 rounded-xl overflow-hidden
                          hover:border-gray-200 dark:hover:border-gray-600 transition-colors">

                <button
                  (click)="toggleCase(c.case_id)"
                  class="flex items-center justify-between gap-2 w-full
                         px-3.5 py-2.5 text-left bg-gray-50 dark:bg-gray-800
                         hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                >
                  <div class="flex items-center gap-2 min-w-0">
                    <div class="w-1.5 h-1.5 rounded-full bg-accent shrink-0"></div>
                    <span class="text-xs font-medium text-gray-700 dark:text-gray-200 truncate">
                      {{ c.case_num || ('Case #' + c.case_id) }}
                    </span>
                    @if (c.court) {
                      <span class="hidden sm:block text-xs text-gray-400 truncate">· {{ c.court }}</span>
                    }
                  </div>
                  <svg
                    class="w-3.5 h-3.5 text-gray-400 shrink-0 transition-transform duration-200"
                    [class.rotate-180]="isCaseOpen(c.case_id)"
                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                  </svg>
                </button>

                @if (isCaseOpen(c.case_id)) {
                  <div class="px-3.5 py-3 bg-white dark:bg-gray-900 border-t border-gray-100 dark:border-gray-700 space-y-2">
                    <div class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs">
                      @if (c.case_date) {
                        <div>
                          <span class="text-gray-400 dark:text-gray-500">თარიღი</span>
                          <p class="text-gray-700 dark:text-gray-200 font-medium">{{ c.case_date }}</p>
                        </div>
                      }
                      @if (c.chamber) {
                        <div>
                          <span class="text-gray-400 dark:text-gray-500">პალატა</span>
                          <p class="text-gray-700 dark:text-gray-200 font-medium">{{ c.chamber }}</p>
                        </div>
                      }
                      @if (c.category) {
                        <div>
                          <span class="text-gray-400 dark:text-gray-500">კატეგორია</span>
                          <p class="text-gray-700 dark:text-gray-200 font-medium">{{ c.category }}</p>
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
                      <div class="text-xs">
                        <span class="text-gray-400 dark:text-gray-500">სადაო საგანი</span>
                        <p class="text-gray-700 dark:text-gray-200 mt-0.5">{{ c.dispute_subject }}</p>
                      </div>
                    }
                    @if (c.result) {
                      <div class="text-xs">
                        <span class="text-gray-400">შედეგი</span>
                        <p class="text-emerald-700 font-medium mt-0.5">{{ c.result }}</p>
                      </div>
                    }
                    @if (c.case_type && c.case_id) {
                      <a [routerLink]="['/fullcase', c.case_type, c.case_id]" target="_blank"
                         class="inline-flex items-center gap-1.5 mt-1 text-xs text-accent
                                hover:text-accent-hover font-medium transition-colors">
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
      }

      <!-- ── Law articles ───────────────────────────────────────────────────── -->
      @if (lawCitations.length > 0) {
        <div class="space-y-1.5">
          <div class="flex items-center gap-1.5 text-[11px] font-semibold text-gray-400 uppercase tracking-wide">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5">
              <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
            </svg>
            კანონმდებლობა ({{ lawCitations.length }})
          </div>

          <div class="flex flex-col gap-1.5">
            @for (l of lawCitations; track l.article_id) {
              <div class="border border-blue-100 dark:border-blue-900/40 rounded-xl overflow-hidden
                          hover:border-blue-200 dark:hover:border-blue-800 transition-colors">

                <button
                  (click)="toggleLaw(l.article_id)"
                  class="flex items-center justify-between gap-2 w-full
                         px-3.5 py-2.5 text-left
                         bg-blue-50/60 dark:bg-blue-950/30
                         hover:bg-blue-50 dark:hover:bg-blue-950/50 transition-colors"
                >
                  <div class="flex items-center gap-2 min-w-0">
                    <div class="w-1.5 h-1.5 rounded-full bg-blue-500 shrink-0"></div>
                    <span class="text-xs font-medium text-gray-700 dark:text-gray-200 truncate">
                      {{ l.article_num || l.title }}
                    </span>
                    @if (l.article_title) {
                      <span class="hidden sm:block text-xs text-gray-400 truncate">— {{ l.article_title }}</span>
                    }
                  </div>
                  <svg
                    class="w-3.5 h-3.5 text-gray-400 shrink-0 transition-transform duration-200"
                    [class.rotate-180]="isLawOpen(l.article_id)"
                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                  </svg>
                </button>

                @if (isLawOpen(l.article_id)) {
                  <div class="px-3.5 py-3 bg-white dark:bg-gray-900 border-t border-blue-100 dark:border-blue-900/40 space-y-2">
                    <div class="text-xs">
                      <span class="text-gray-400 dark:text-gray-500">კანონი</span>
                      <p class="text-gray-700 dark:text-gray-200 font-medium mt-0.5">{{ l.title }}</p>
                    </div>
                    @if (l.excerpt) {
                      <div class="text-xs">
                        <span class="text-gray-400 dark:text-gray-500">ტექსტი</span>
                        <p class="text-gray-600 dark:text-gray-300 mt-0.5 leading-relaxed line-clamp-6">{{ l.excerpt }}</p>
                      </div>
                    }
                    <div class="flex items-center justify-between">
                      <span class="text-[10px] text-blue-500">{{ (l.similarity * 100).toFixed(1) }}% შესაბამისობა</span>
                      @if (l.url) {
                        <a [href]="l.url" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1.5 text-xs text-blue-600 dark:text-blue-400
                                  hover:text-blue-700 font-medium transition-colors">
                          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                               stroke="currentColor" stroke-width="2">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                            <polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                          </svg>
                          მაწნე →
                        </a>
                      }
                    </div>
                  </div>
                }
              </div>
            }
          </div>
        </div>
      }

      <!-- ── ECHR cases ──────────────────────────────────────────────────────── -->
      @if (echrCitations.length > 0) {
        <div class="space-y-1.5">
          <div class="flex items-center gap-1.5 text-[11px] font-semibold text-gray-400 uppercase tracking-wide">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5">
              <circle cx="12" cy="12" r="10"/>
              <line x1="2" y1="12" x2="22" y2="12"/>
              <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
            </svg>
            ECHR ({{ echrCitations.length }})
          </div>

          <div class="flex flex-col gap-1.5">
            @for (e of echrCitations; track e.application_no) {
              <div class="border border-violet-100 dark:border-violet-900/40 rounded-xl overflow-hidden
                          hover:border-violet-200 dark:hover:border-violet-800 transition-colors">

                <button
                  (click)="toggleEchr(e.application_no)"
                  class="flex items-center justify-between gap-2 w-full
                         px-3.5 py-2.5 text-left
                         bg-violet-50/60 dark:bg-violet-950/30
                         hover:bg-violet-50 dark:hover:bg-violet-950/50 transition-colors"
                >
                  <div class="flex items-center gap-2 min-w-0">
                    <div class="w-1.5 h-1.5 rounded-full bg-violet-500 shrink-0"></div>
                    <span class="text-xs font-medium text-gray-700 dark:text-gray-200 truncate">
                      {{ e.case_name || e.application_no }}
                    </span>
                    @if (e.application_no) {
                      <span class="hidden sm:block text-xs text-gray-400 shrink-0">· {{ e.application_no }}</span>
                    }
                  </div>
                  <svg
                    class="w-3.5 h-3.5 text-gray-400 shrink-0 transition-transform duration-200"
                    [class.rotate-180]="isEchrOpen(e.application_no)"
                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                  </svg>
                </button>

                @if (isEchrOpen(e.application_no)) {
                  <div class="px-3.5 py-3 bg-white dark:bg-gray-900 border-t border-violet-100 dark:border-violet-900/40 space-y-2">
                    @if (e.judgment_date) {
                      <div class="text-xs">
                        <span class="text-gray-400 dark:text-gray-500">თარიღი</span>
                        <p class="text-gray-700 dark:text-gray-200 font-medium">{{ e.judgment_date }}</p>
                      </div>
                    }
                    @if (e.articles_violated.length > 0) {
                      <div class="text-xs">
                        <span class="text-gray-400 dark:text-gray-500">მუხლები</span>
                        <div class="flex flex-wrap gap-1 mt-1">
                          @for (art of e.articles_violated; track art) {
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px]
                                         font-medium bg-violet-100 dark:bg-violet-900/40
                                         text-violet-700 dark:text-violet-300">
                              Art. {{ art }}
                            </span>
                          }
                        </div>
                      </div>
                    }
                    @if (e.excerpt) {
                      <div class="text-xs">
                        <span class="text-gray-400 dark:text-gray-500">ამონარიდი</span>
                        <p class="text-gray-600 dark:text-gray-300 mt-0.5 leading-relaxed line-clamp-6">{{ e.excerpt }}</p>
                      </div>
                    }
                    @if (e.url) {
                      <a [href]="e.url" target="_blank" rel="noopener"
                         class="inline-flex items-center gap-1.5 mt-1 text-xs text-violet-600 dark:text-violet-400
                                hover:text-violet-700 font-medium transition-colors">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2">
                          <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                          <polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                        </svg>
                        HUDOC →
                      </a>
                    }
                  </div>
                }
              </div>
            }
          </div>
        </div>
      }

    </div>
  `,
})
export class CitationListComponent {
  @Input({ required: true }) domesticCitations: Citation[] = [];
  @Input() lawCitations: LawCitation[] = [];
  @Input() echrCitations: EchrCitation[] = [];

  private openCaseId  = signal<number | null>(null);
  private openLawId   = signal<number | null>(null);
  private openEchrKey = signal<string | null>(null);

  toggleCase(id: number): void { this.openCaseId.update(v => v === id ? null : id); }
  isCaseOpen(id: number): boolean { return this.openCaseId() === id; }

  toggleLaw(id: number): void { this.openLawId.update(v => v === id ? null : id); }
  isLawOpen(id: number): boolean { return this.openLawId() === id; }

  toggleEchr(key: string): void { this.openEchrKey.update(v => v === key ? null : key); }
  isEchrOpen(key: string): boolean { return this.openEchrKey() === key; }
}
