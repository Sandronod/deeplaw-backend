import { Component, Input, signal, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Citation, ConstCourtCitation, EchrCitation, EuCitation, GermanCitation, LawCitation, MatsneCitation } from '../../../../core/models/message.model';
import { CaseModalService } from '../../../../core/services/case-modal.service';

@Component({
  selector: 'app-citation-list',
  standalone: true,
  imports: [CommonModule],
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
                    @if (c.case_id) {
                      <button (click)="caseModal.open(c.case_id, c.case_type)"
                         class="inline-flex items-center gap-1.5 mt-1 text-xs text-accent
                                hover:text-accent-hover font-medium transition-colors">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2">
                          <rect x="3" y="3" width="18" height="18" rx="2"/>
                          <path d="M9 3v18M15 9h3M15 12h3M15 15h3"/>
                        </svg>
                        სრული გადაწყვეტილება →
                      </button>
                    }
                  </div>
                }
              </div>
            }
          </div>
        </div>
      }

      <!-- ── Matsne documents ──────────────────────────────────────────────────── -->
      @if (matsneCitations.length > 0) {
        <div class="space-y-1.5">
          <div class="flex items-center gap-1.5 text-[11px] font-semibold text-gray-400 uppercase tracking-wide">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5">
              <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
              <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
            </svg>
            მაცნე ({{ matsneCitations.length }})
          </div>

          <div class="flex flex-col gap-1.5">
            @for (m of matsneCitations; track m.matsne_id) {
              <div class="border border-emerald-100 dark:border-emerald-900/40 rounded-xl overflow-hidden
                          hover:border-emerald-200 dark:hover:border-emerald-800 transition-colors">

                <button
                  (click)="toggleMatsne(m.matsne_id)"
                  class="flex items-center justify-between gap-2 w-full
                         px-3.5 py-2.5 text-left
                         bg-emerald-50/60 dark:bg-emerald-950/30
                         hover:bg-emerald-50 dark:hover:bg-emerald-950/50 transition-colors"
                >
                  <div class="flex items-center gap-2 min-w-0">
                    <div class="w-1.5 h-1.5 rounded-full shrink-0"
                         [class.bg-emerald-500]="m.is_active"
                         [class.bg-gray-400]="!m.is_active"></div>
                    <span class="text-xs font-medium text-gray-700 dark:text-gray-200 truncate">
                      {{ m.title }}
                    </span>
                    @if (m.doc_type) {
                      <span class="hidden sm:block text-xs text-gray-400 shrink-0 truncate">· {{ m.doc_type }}</span>
                    }
                  </div>
                  <svg
                    class="w-3.5 h-3.5 text-gray-400 shrink-0 transition-transform duration-200"
                    [class.rotate-180]="isMatsneOpen(m.matsne_id)"
                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                  </svg>
                </button>

                @if (isMatsneOpen(m.matsne_id)) {
                  <div class="px-3.5 py-3 bg-white dark:bg-gray-900 border-t border-emerald-100 dark:border-emerald-900/40 space-y-2">
                    <div class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs">
                      @if (m.issuer) {
                        <div>
                          <span class="text-gray-400 dark:text-gray-500">გამომცემელი</span>
                          <p class="text-gray-700 dark:text-gray-200 font-medium">{{ m.issuer }}</p>
                        </div>
                      }
                      <div>
                        <span class="text-gray-400 dark:text-gray-500">სტატუსი</span>
                        <p class="font-medium" [class.text-emerald-600]="m.is_active" [class.text-gray-400]="!m.is_active">
                          {{ m.is_active ? 'მოქმედი' : 'ძველი ვერსია' }}
                        </p>
                      </div>
                      @if (m.effective_from_year) {
                        <div>
                          <span class="text-gray-400 dark:text-gray-500">წელი</span>
                          <p class="text-gray-700 dark:text-gray-200 font-medium">
                            {{ m.effective_from_year }}{{ m.effective_to_year ? ('–' + m.effective_to_year) : '' }}
                          </p>
                        </div>
                      }
                    </div>
                    @if (m.excerpt) {
                      <div class="text-xs">
                        <span class="text-gray-400 dark:text-gray-500">ამონარიდი</span>
                        <p class="text-gray-600 dark:text-gray-300 mt-0.5 leading-relaxed line-clamp-6">{{ m.excerpt }}</p>
                      </div>
                    }
                    <div class="flex items-center justify-between">
                      <span class="text-[10px] text-emerald-600">{{ (m.similarity * 100).toFixed(1) }}% შესაბამისობა</span>
                      @if (m.url) {
                        <a [href]="m.url" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1.5 text-xs text-emerald-600 dark:text-emerald-400
                                  hover:text-emerald-700 font-medium transition-colors">
                          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                               stroke="currentColor" stroke-width="2">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                            <polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                          </svg>
                          მაცნე →
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

      <!-- ── EU documents ──────────────────────────────────────────────────────── -->
      @if (euCitations.length > 0) {
        <div class="space-y-1.5">
          <div class="flex items-center gap-1.5 text-[11px] font-semibold text-gray-400 uppercase tracking-wide">
            <span class="text-[13px] leading-none">🇪🇺</span>
            EU ({{ euCitations.length }})
          </div>

          <div class="flex flex-col gap-1.5">
            @for (e of euCitations; track e.cellar_id) {
              <div class="border border-blue-100 dark:border-blue-900/40 rounded-xl overflow-hidden
                          hover:border-blue-200 dark:hover:border-blue-800 transition-colors">

                <button
                  (click)="toggleEu(e.cellar_id)"
                  class="flex items-center justify-between gap-2 w-full
                         px-3.5 py-2.5 text-left
                         bg-blue-50/40 dark:bg-blue-950/20
                         hover:bg-blue-50 dark:hover:bg-blue-950/40 transition-colors"
                >
                  <div class="flex items-center gap-2 min-w-0">
                    <div class="w-1.5 h-1.5 rounded-full bg-blue-600 shrink-0"></div>
                    <span class="text-[10px] px-1.5 py-0.5 rounded font-semibold shrink-0"
                          [class]="e.source === 'case_law'
                            ? 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300'
                            : 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300'">
                      {{ e.source === 'case_law' ? 'CJEU' : e.doc_type | uppercase }}
                    </span>
                    <span class="text-xs font-medium text-gray-700 dark:text-gray-200 truncate">
                      {{ e.title || e.case_num || e.cellar_id }}
                    </span>
                  </div>
                  <svg
                    class="w-3.5 h-3.5 text-gray-400 shrink-0 transition-transform duration-200"
                    [class.rotate-180]="isEuOpen(e.cellar_id)"
                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                  </svg>
                </button>

                @if (isEuOpen(e.cellar_id)) {
                  <div class="px-3.5 py-3 bg-white dark:bg-gray-900 border-t border-blue-100 dark:border-blue-900/40 space-y-2">
                    <div class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs">
                      @if (e.court) {
                        <div>
                          <span class="text-gray-400 dark:text-gray-500">სასამართლო</span>
                          <p class="text-gray-700 dark:text-gray-200 font-medium">{{ e.court }}</p>
                        </div>
                      }
                      @if (e.doc_date) {
                        <div>
                          <span class="text-gray-400 dark:text-gray-500">თარიღი</span>
                          <p class="text-gray-700 dark:text-gray-200 font-medium">{{ e.doc_date }}</p>
                        </div>
                      }
                      @if (e.case_num) {
                        <div class="col-span-2">
                          <span class="text-gray-400 dark:text-gray-500">საქმე</span>
                          <p class="text-gray-700 dark:text-gray-200 font-medium">{{ e.case_num }}</p>
                        </div>
                      }
                    </div>
                    @if (e.excerpt) {
                      <div class="text-xs">
                        <span class="text-gray-400 dark:text-gray-500">ამონარიდი</span>
                        <p class="text-gray-600 dark:text-gray-300 mt-0.5 leading-relaxed line-clamp-6">{{ e.excerpt }}</p>
                      </div>
                    }
                    <div class="flex items-center justify-between">
                      <span class="text-[10px] text-blue-600">{{ (e.similarity * 100).toFixed(1) }}% შესაბამისობა</span>
                      @if (e.url) {
                        <a [href]="e.url" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1.5 text-xs text-blue-600 dark:text-blue-400
                                  hover:text-blue-700 font-medium transition-colors">
                          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                               stroke="currentColor" stroke-width="2">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                            <polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                          </svg>
                          EUR-Lex →
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

      <!-- ── German cases ──────────────────────────────────────────────────────── -->
      @if (germanCitations.length > 0) {
        <div class="space-y-1.5">
          <div class="flex items-center gap-1.5 text-[11px] font-semibold text-gray-400 uppercase tracking-wide">
            <span class="text-[13px] leading-none">🇩🇪</span>
            გერმანული სასამართლო ({{ germanCitations.length }})
          </div>

          <div class="flex flex-col gap-1.5">
            @for (g of germanCitations; track g.case_id) {
              <div class="border border-yellow-100 dark:border-yellow-900/40 rounded-xl overflow-hidden
                          hover:border-yellow-200 dark:hover:border-yellow-800 transition-colors">

                <button
                  (click)="toggleGerman(g.case_id)"
                  class="flex items-center justify-between gap-2 w-full
                         px-3.5 py-2.5 text-left
                         bg-yellow-50/40 dark:bg-yellow-950/20
                         hover:bg-yellow-50 dark:hover:bg-yellow-950/40 transition-colors"
                >
                  <div class="flex items-center gap-2 min-w-0">
                    <div class="w-1.5 h-1.5 rounded-full bg-yellow-500 shrink-0"></div>
                    <span class="text-xs font-medium text-gray-700 dark:text-gray-200 truncate">
                      {{ g.court_name || 'German Court' }}
                    </span>
                    @if (g.date_year) {
                      <span class="hidden sm:block text-xs text-gray-400 shrink-0">· {{ g.date_year }}</span>
                    }
                  </div>
                  <svg
                    class="w-3.5 h-3.5 text-gray-400 shrink-0 transition-transform duration-200"
                    [class.rotate-180]="isGermanOpen(g.case_id)"
                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                  </svg>
                </button>

                @if (isGermanOpen(g.case_id)) {
                  <div class="px-3.5 py-3 bg-white dark:bg-gray-900 border-t border-yellow-100 dark:border-yellow-900/40 space-y-2">
                    <div class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs">
                      @if (g.level_of_appeal) {
                        <div>
                          <span class="text-gray-400 dark:text-gray-500">ინსტანცია</span>
                          <p class="text-gray-700 dark:text-gray-200 font-medium">{{ g.level_of_appeal }}</p>
                        </div>
                      }
                      @if (g.date_year) {
                        <div>
                          <span class="text-gray-400 dark:text-gray-500">წელი</span>
                          <p class="text-gray-700 dark:text-gray-200 font-medium">{{ g.date_year }}</p>
                        </div>
                      }
                    </div>
                    @if (g.excerpt) {
                      <div class="text-xs">
                        <span class="text-gray-400 dark:text-gray-500">ამონარიდი (ქართულად)</span>
                        <p class="text-gray-600 dark:text-gray-300 mt-0.5 leading-relaxed line-clamp-6">{{ g.excerpt }}</p>
                      </div>
                    }
                    <span class="text-[10px] text-yellow-600">{{ (g.similarity * 100).toFixed(1) }}% შესაბამისობა</span>
                  </div>
                }
              </div>
            }
          </div>
        </div>
      }

      <!-- ── Constitutional Court ────────────────────────────────────────────── -->
      @if (constCourtCitations.length > 0) {
        <div class="space-y-1.5">
          <div class="flex items-center gap-1.5 text-[11px] font-semibold text-gray-400 uppercase tracking-wide">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5">
              <path d="M3 22h18M3 10h18M12 2L2 10h20L12 2zM6 10v12M18 10v12M10 10v12M14 10v12"/>
            </svg>
            საკონსტიტუციო სასამართლო ({{ constCourtCitations.length }})
          </div>

          <div class="flex flex-col gap-1.5">
            @for (cc of constCourtCitations; track cc.legal_id) {
              <div class="border border-orange-100 dark:border-orange-900/40 rounded-xl overflow-hidden
                          hover:border-orange-200 dark:hover:border-orange-800 transition-colors">

                <button
                  (click)="toggleConstCourt(cc.legal_id)"
                  class="flex items-center justify-between gap-2 w-full
                         px-3.5 py-2.5 text-left
                         bg-orange-50/40 dark:bg-orange-950/20
                         hover:bg-orange-50 dark:hover:bg-orange-950/40 transition-colors"
                >
                  <div class="flex items-center gap-2 min-w-0">
                    <div class="w-1.5 h-1.5 rounded-full bg-orange-500 shrink-0"></div>
                    <span class="text-xs font-medium text-gray-700 dark:text-gray-200 truncate">
                      {{ cc.case_number || cc.case_name || ('Decision #' + cc.legal_id) }}
                    </span>
                    @if (cc.decision_type) {
                      <span class="hidden sm:block text-xs text-gray-400 shrink-0 truncate">· {{ cc.decision_type }}</span>
                    }
                  </div>
                  <svg
                    class="w-3.5 h-3.5 text-gray-400 shrink-0 transition-transform duration-200"
                    [class.rotate-180]="isConstCourtOpen(cc.legal_id)"
                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                  </svg>
                </button>

                @if (isConstCourtOpen(cc.legal_id)) {
                  <div class="px-3.5 py-3 bg-white dark:bg-gray-900 border-t border-orange-100 dark:border-orange-900/40 space-y-2">
                    <div class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs">
                      @if (cc.decision_date) {
                        <div>
                          <span class="text-gray-400 dark:text-gray-500">თარიღი</span>
                          <p class="text-gray-700 dark:text-gray-200 font-medium">{{ cc.decision_date }}</p>
                        </div>
                      }
                      @if (cc.college) {
                        <div>
                          <span class="text-gray-400 dark:text-gray-500">კოლეგია</span>
                          <p class="text-gray-700 dark:text-gray-200 font-medium">{{ cc.college }}</p>
                        </div>
                      }
                      @if (cc.respondent) {
                        <div class="col-span-2">
                          <span class="text-gray-400 dark:text-gray-500">მოპასუხე</span>
                          <p class="text-gray-700 dark:text-gray-200 font-medium">{{ cc.respondent }}</p>
                        </div>
                      }
                    </div>
                    @if (cc.case_name) {
                      <div class="text-xs">
                        <span class="text-gray-400 dark:text-gray-500">საქმე</span>
                        <p class="text-gray-700 dark:text-gray-200 mt-0.5">{{ cc.case_name }}</p>
                      </div>
                    }
                    @if (cc.result) {
                      <div class="text-xs">
                        <span class="text-gray-400 dark:text-gray-500">შედეგი</span>
                        <p class="text-emerald-700 font-medium mt-0.5">{{ cc.result }}</p>
                      </div>
                    }
                    @if (cc.excerpt) {
                      <div class="text-xs">
                        <span class="text-gray-400 dark:text-gray-500">ამონარიდი</span>
                        <p class="text-gray-600 dark:text-gray-300 mt-0.5 leading-relaxed line-clamp-6">{{ cc.excerpt }}</p>
                      </div>
                    }
                    <div class="flex items-center justify-between">
                      <span class="text-[10px] text-orange-600">{{ (cc.similarity * 100).toFixed(1) }}% შესაბამისობა</span>
                      @if (cc.url) {
                        <a [href]="cc.url" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1.5 text-xs text-orange-600 dark:text-orange-400
                                  hover:text-orange-700 font-medium transition-colors">
                          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                               stroke="currentColor" stroke-width="2">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                            <polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                          </svg>
                          constcourt.ge →
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
  @Input() matsneCitations: MatsneCitation[] = [];
  @Input() euCitations: EuCitation[] = [];
  @Input() germanCitations: GermanCitation[] = [];
  @Input() constCourtCitations: ConstCourtCitation[] = [];

  caseModal = inject(CaseModalService);

  private openCaseId    = signal<number | null>(null);
  private openLawId     = signal<number | null>(null);
  private openEchrKey   = signal<string | null>(null);
  private openMatsneId  = signal<number | null>(null);
  private openEuId      = signal<string | null>(null);
  private openGermanId     = signal<number | null>(null);
  private openConstCourtId = signal<number | null>(null);

  toggleCase(id: number): void { this.openCaseId.update(v => v === id ? null : id); }
  isCaseOpen(id: number): boolean { return this.openCaseId() === id; }

  toggleLaw(id: number): void { this.openLawId.update(v => v === id ? null : id); }
  isLawOpen(id: number): boolean { return this.openLawId() === id; }

  toggleEchr(key: string): void { this.openEchrKey.update(v => v === key ? null : key); }
  isEchrOpen(key: string): boolean { return this.openEchrKey() === key; }

  toggleMatsne(id: number): void { this.openMatsneId.update(v => v === id ? null : id); }
  isMatsneOpen(id: number): boolean { return this.openMatsneId() === id; }

  toggleEu(id: string): void { this.openEuId.update(v => v === id ? null : id); }
  isEuOpen(id: string): boolean { return this.openEuId() === id; }

  toggleGerman(id: number): void { this.openGermanId.update(v => v === id ? null : id); }
  isGermanOpen(id: number): boolean { return this.openGermanId() === id; }

  toggleConstCourt(id: number): void { this.openConstCourtId.update(v => v === id ? null : id); }
  isConstCourtOpen(id: number): boolean { return this.openConstCourtId() === id; }
}
