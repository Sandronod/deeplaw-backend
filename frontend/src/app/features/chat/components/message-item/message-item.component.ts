import { Component, Input, signal } from "@angular/core";
import { CommonModule } from "@angular/common";
import { ChatMessage } from "../../../../core/models/message.model";
import { MarkdownService } from "../../../../core/services/markdown.service";
import { CitationListComponent } from "../citation-list/citation-list.component";
import { ConfidenceBadgeComponent } from "../confidence-badge/confidence-badge.component";
import { MessageActionsComponent } from "../message-actions/message-actions.component";

@Component({
    selector: "app-message-item",
    standalone: true,
    imports: [
        CommonModule,
        CitationListComponent,
        ConfidenceBadgeComponent,
        MessageActionsComponent,
    ],
    template: `
        <!-- ── USER message ────────────────────────────────────────────────────── -->
        @if (isUser) {
            <div
                class="max-w-3xl mx-auto px-4 py-2"
                [class.msg-enter]="message.isNew"
            >
                <div class="flex justify-end">
                    <div class="max-w-[75%] sm:max-w-[65%]">
                        <div
                            class="chat-text bg-gray-100 dark:bg-[#2d2d2d] text-gray-800 dark:text-gray-100
                        rounded-2xl rounded-br-sm px-4 py-3 leading-relaxed
                        whitespace-pre-wrap break-words shadow-sm"
                        >
                            {{ message.content }}
                        </div>
                    </div>
                </div>
            </div>
        }

        <!-- ── ASSISTANT message ───────────────────────────────────────────────── -->
        @if (isAssistant) {
            <div
                class="max-w-3xl mx-auto px-4 py-4 msg-wrapper"
                [class.msg-enter]="message.isNew"
            >
                <div class="flex gap-3 items-start">
                    <!-- Avatar -->
                    <div
                        class="w-8 h-8 rounded-full bg-accent flex items-center justify-center
                      shrink-0 mt-0.5 shadow-sm shadow-accent/20"
                    >
                        <span
                            class="text-white text-[10px] font-bold tracking-tight"
                            >AI</span
                        >
                    </div>

                    <!-- Content -->
                    <div class="flex-1 min-w-0 pt-0.5">
                        <!-- ── Loading phase label ────────────────────────────────────── -->
                        @if (isLoading) {
                            <div class="flex items-center gap-2 py-1">
                                <span class="flex gap-1">
                                    @for (d of [0, 1, 2]; track d) {
                                        <span
                                            class="w-1.5 h-1.5 rounded-full bg-gray-300 dark:bg-gray-600 animate-bounce-dot"
                                            [style.animation-delay]="
                                                d * 0.2 + 's'
                                            "
                                        ></span>
                                    }
                                </span>
                                <span
                                    class="text-xs text-gray-400 dark:text-gray-500 animate-fade-in"
                                >
                                    {{ phaseLabel }}
                                </span>
                            </div>
                        }

                        <!-- ── Content (streaming or done) ───────────────────────────── -->
                        @if (hasContent) {
                            <div
                                class="md-content chat-text text-gray-800 dark:text-gray-100 break-words"
                                [innerHTML]="renderedContent"
                            ></div>

                            @if (isStreaming) {
                                <span class="streaming-cursor"></span>
                            }
                        }

                        <!-- ── Error notice ────────────────────────────────────────────── -->
                        @if (isError) {
                            <div class="mt-2 space-y-1.5 animate-fade-in">
                                <div class="flex items-center gap-2 text-xs text-red-500 dark:text-red-400">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10" />
                                        <line x1="12" y1="8" x2="12" y2="12" />
                                        <line x1="12" y1="16" x2="12.01" y2="16" />
                                    </svg>
                                    @if (message.isPartial) {
                                        <span>პასუხი ნაწილობრივ გენერირდა — კავშირი გაწყდა.</span>
                                    } @else {
                                        <span>შეცდომა — სცადეთ თავიდან.</span>
                                    }
                                </div>
                                @if (message.canRetry) {
                                    <!-- TODO: wire (click) to a retry output/service call -->
                                    <button
                                        class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400
                                               hover:text-accent dark:hover:text-accent transition-colors"
                                        title="სცადეთ თავიდან"
                                    >
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                                             stroke="currentColor" stroke-width="2.5">
                                            <polyline points="1 4 1 10 7 10"/>
                                            <path d="M3.51 15a9 9 0 1 0 .49-4.44"/>
                                        </svg>
                                        გენერაციის გაგრძელება
                                    </button>
                                }
                            </div>
                        }

                        <!-- ── Meta row: confidence + actions (done state only) ───────── -->
                        @if (isDone && hasContent) {
                            <div
                                class="flex items-center justify-between mt-2.5 gap-2 flex-wrap"
                            >
                                <div>
                                    @if (confidence) {
                                        <app-confidence-badge
                                            [level]="confidence"
                                            [explanation]="confidenceNote"
                                        />
                                    }
                                </div>

                                <app-message-actions
                                    [text]="message.content"
                                    [citationCount]="citationCount"
                                    [citationsOpen]="citationsVisible()"
                                    (citationsToggle)="toggleCitations()"
                                />
                            </div>
                        }

                        <!-- ── Citations ───────────────────────────────────────────────── -->
                        @if (citationsVisible() && citationCount > 0) {
                            <div class="animate-slide-up">
                                <app-citation-list
                                    [domesticCitations]="message.citations"
                                    [lawCitations]="message.law_citations ?? []"
                                    [echrCitations]="message.echr_citations ?? []"
                                />
                            </div>
                        }
                    </div>
                </div>
            </div>
        }
    `,
})
export class MessageItemComponent {
    @Input({ required: true }) message!: ChatMessage;
    /** Passed from chat-thread only for the currently-loading message. */
    @Input() streamPhase: string | null = null;

    citationsVisible = signal(false);

    // Markdown memoization — avoids re-parsing on every CD tick during streaming
    private _cachedContent = "";
    private _cachedRendered = "";

    constructor(private md: MarkdownService) {}

    get isUser(): boolean {
        return this.message.role === "user";
    }
    get isAssistant(): boolean {
        return this.message.role === "assistant";
    }

    get isLoading(): boolean {
        return this.message.status === "loading";
    }
    get isStreaming(): boolean {
        return this.message.status === "streaming";
    }
    get isDone(): boolean {
        return (
            this.message.status === "done" || this.message.status === undefined
        );
    }
    get isError(): boolean {
        return this.message.status === "error";
    }

    get hasContent(): boolean {
        return this.message.content.trim().length > 0;
    }

    get phaseLabel(): string {
        switch (this.streamPhase) {
            case 'writing':        return 'ვამზადებ პასუხს…';
            case 'law_lookup':     return 'ვეძებ კანონმდებლობაში…';
            case 'case_retrieval': return 'ვეძებ გადაწყვეტილებებს…';
            case 'reranking':      return 'ვაანალიზებ შედეგებს…';
            default:               return 'ვეძებ…';
        }
    }

    get confidence() {
        return this.message.meta?.confidence ?? null;
    }
    get confidenceNote() {
        return this.message.meta?.confidence_note ?? null;
    }
    get citationCount(): number {
        return (
            (this.message.citations?.length ?? 0) +
            (this.message.law_citations?.length ?? 0) +
            (this.message.echr_citations?.length ?? 0)
        );
    }

    toggleCitations(): void {
        this.citationsVisible.update((v) => !v);
    }

    get renderedContent(): string {
        if (this.message.content !== this._cachedContent) {
            this._cachedContent = this.message.content;
            this._cachedRendered = this.md.parse(this.message.content);
        }
        return this._cachedRendered;
    }
}
