import { Component, ElementRef, ViewChild, effect, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ChatService } from '../../../../core/services/chat.service';
import { MessageItemComponent } from '../message-item/message-item.component';

@Component({
  selector: 'app-chat-thread',
  standalone: true,
  imports: [CommonModule, MessageItemComponent],
  template: `
    <div class="relative flex-1 overflow-hidden">
      <div #scrollContainer
           class="h-full overflow-y-auto overflow-x-hidden"
           (scroll)="onScroll()">

        <!-- ── Loading skeleton ──────────────────────────────────────────── -->
        @if (chatService.isLoading()) {
          <div class="flex flex-col max-w-3xl mx-auto px-4 pt-10 pb-6 animate-fade-in">

            <!-- Status label -->
            <div class="flex items-center gap-2 mb-8 px-1">
              <span class="flex gap-1">
                @for (d of [0,1,2]; track d) {
                  <span class="w-1.5 h-1.5 rounded-full bg-accent animate-bounce-dot"
                        [style.animation-delay]="d * 0.15 + 's'"></span>
                }
              </span>
              <span class="text-xs text-gray-400 dark:text-gray-500">
                შეტყობინებები იტვირთება...
              </span>
            </div>

            <!-- Skeleton rows -->
            @for (row of skeletonRows; track row.id) {
              <div class="flex gap-3 mb-8">
                <div class="w-8 h-8 rounded-full skeleton shrink-0 mt-0.5"></div>
                <div class="flex-1 space-y-2.5 pt-1">
                  <div class="h-3 skeleton rounded-full" [style.width]="row.w1"></div>
                  <div class="h-3 skeleton rounded-full" [style.width]="row.w2"></div>
                  <div class="h-3 skeleton rounded-full w-2/5"></div>
                </div>
              </div>
            }

          </div>
        }

        <!-- ── Empty welcome state (chat open, no messages) ─────────────── -->
        @if (!chatService.isLoading() && chatService.messages().length === 0) {
          <div class="flex flex-col items-center justify-center h-full gap-5
                      px-6 text-center animate-fade-in">

            <div class="flex flex-col items-center gap-3">
              <div class="w-12 h-12 rounded-2xl bg-accent/10 flex items-center justify-center">
                <span class="text-2xl">⚖️</span>
              </div>
              <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">
                სამართლებრივი AI ასისტენტი
              </h3>
              <p class="text-sm text-gray-500 dark:text-gray-400 max-w-xs leading-relaxed">
                ადმინისტრაციული სასამართლო გადაწყვეტილებები<br>
                კანონმდებლობა · სამართლებრივი ანალიზი
              </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-1 w-full max-w-lg">
              @for (hint of hints; track hint.text) {
                <button
                  (click)="chatService.sendMessage(hint.text)"
                  class="prompt-card group text-left px-4 py-3.5 rounded-xl
                         border border-gray-200 dark:border-gray-700/80
                         bg-white dark:bg-gray-800/50
                         hover:border-accent/40 hover:bg-accent/[0.03] dark:hover:bg-accent/[0.07]
                         hover:shadow-sm"
                >
                  <div class="text-base mb-1">{{ hint.icon }}</div>
                  <p class="text-[12px] text-gray-700 dark:text-gray-300 leading-snug">{{ hint.text }}</p>
                </button>
              }
            </div>

          </div>
        }

        <!-- ── Message list ───────────────────────────────────────────────── -->
        @if (!chatService.isLoading()) {
          <div class="pt-6 pb-2">
            @for (msg of chatService.messages(); track msg.id; let isLast = $last) {
              <app-message-item
                [message]="msg"
                [streamPhase]="isLast && msg.status === 'loading' ? chatService.streamPhase() : null"
              />
            }
          </div>
        }

        <!-- ── Global error banner ───────────────────────────────────────── -->
        @if (chatService.error()) {
          <div class="max-w-3xl mx-auto px-4 pb-4 animate-slide-up">
            <div class="flex items-center justify-between gap-3 px-4 py-3 rounded-xl text-sm
                        bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800
                        text-red-600 dark:text-red-400">
              <span>{{ chatService.error() }}</span>
              <button
                (click)="chatService.clearError()"
                class="text-red-400 hover:text-red-600 text-xl leading-none shrink-0 transition-colors"
              >×</button>
            </div>
          </div>
        }

        <div #threadEnd class="h-6"></div>
      </div>

      <!-- ── Scroll-to-bottom FAB ──────────────────────────────────────────── -->
      @if (showScrollBtn()) {
        <button
          (click)="scrollDown()"
          class="absolute bottom-4 right-4 z-10 w-9 h-9 rounded-full
                 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700
                 shadow-md hover:shadow-lg flex items-center justify-center
                 text-gray-500 dark:text-gray-400 hover:text-accent dark:hover:text-accent
                 transition-all duration-200 animate-fade-in"
          aria-label="ბოლოში გადასვლა"
        >
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="12" y1="5" x2="12" y2="19"/>
            <polyline points="5 12 12 19 19 12"/>
          </svg>
        </button>
      }
    </div>
  `,
})
export class ChatThreadComponent {
  @ViewChild('threadEnd')       private threadEnd!: ElementRef;
  @ViewChild('scrollContainer') private scrollEl!: ElementRef<HTMLElement>;

  readonly hints = [
    { icon: '🔍', text: 'მიპოვე გადაწყვეტილება შრომითი დავის შესახებ' },
    { icon: '📋', text: 'შემიჯამე ადმინისტრაციული სამართლის პრეცედენტები' },
    { icon: '⚖️', text: 'ამიხსენი გადასახადის გასაჩივრების პრაქტიკა' },
    { icon: '🏛️', text: 'შეადარე ორი გადაწყვეტილება ქონებრივ დავაზე' },
  ];

  readonly skeletonRows = [
    { id: 1, w1: '70%', w2: '55%' },
    { id: 2, w1: '50%', w2: '35%' },
    { id: 3, w1: '65%', w2: '50%' },
  ];

  readonly showScrollBtn = signal(false);

  private lastCount    = 0;
  private userScrolled = false;

  constructor(public chatService: ChatService) {
    effect(() => {
      const count = this.chatService.messages().length;
      this.chatService.streamTick(); // re-evaluate on every streaming token

      if (count !== this.lastCount) {
        this.lastCount    = count;
        this.userScrolled = false;
        this.showScrollBtn.set(false);
      }

      setTimeout(() => this.scrollToBottom());
    });
  }

  onScroll(): void {
    const el = this.scrollEl?.nativeElement;
    if (!el) return;
    const dist    = el.scrollHeight - el.scrollTop - el.clientHeight;
    this.userScrolled = dist > 80;
    this.showScrollBtn.set(dist > 300);
  }

  scrollDown(): void {
    this.userScrolled = false;
    this.showScrollBtn.set(false);
    this.threadEnd?.nativeElement?.scrollIntoView({ behavior: 'smooth', block: 'end' });
  }

  private scrollToBottom(): void {
    if (this.userScrolled) return;
    this.threadEnd?.nativeElement?.scrollIntoView({ behavior: 'smooth', block: 'end' });
  }
}
