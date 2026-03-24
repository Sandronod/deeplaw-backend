import { Component, OnInit, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { CommonModule } from '@angular/common';
import { ChatService } from '../../../../core/services/chat.service';
import { ApiService } from '../../../../core/services/api.service';
import { SidebarComponent } from '../../components/sidebar/sidebar.component';
import { ChatThreadComponent } from '../../components/chat-thread/chat-thread.component';
import { ChatInputComponent } from '../../components/chat-input/chat-input.component';

@Component({
  selector: 'app-chat-page',
  standalone: true,
  imports: [CommonModule, SidebarComponent, ChatThreadComponent, ChatInputComponent],
  template: `
    <div class="flex h-screen overflow-hidden bg-white dark:bg-gray-900">

      <!-- ── Mobile backdrop ─────────────────────────────────────────────── -->
      @if (sidebarOpen()) {
        <div
          class="fixed inset-0 z-40 bg-black/40 backdrop-blur-[1px] lg:hidden animate-fade-in"
          (click)="sidebarOpen.set(false)"
        ></div>
      }

      <!-- ── Sidebar (fixed on mobile, static on desktop) ────────────────── -->
      <div class="sidebar-wrapper" [class.is-open]="sidebarOpen()">
        <app-sidebar (close)="sidebarOpen.set(false)" />
      </div>

      <!-- ── Main panel ──────────────────────────────────────────────────── -->
      <main class="flex flex-col flex-1 min-w-0 overflow-hidden bg-white dark:bg-gray-900">

        <!-- Mobile top bar -->
        <div class="flex lg:hidden items-center gap-3 px-4 h-12 shrink-0
                    border-b border-gray-100 dark:border-gray-800">
          <button
            (click)="sidebarOpen.set(true)"
            class="p-1.5 -ml-1 rounded-lg text-gray-500 hover:text-gray-700 dark:hover:text-gray-300
                   hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
            aria-label="მენიუ"
          >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <line x1="3" y1="6"  x2="21" y2="6"/>
              <line x1="3" y1="12" x2="21" y2="12"/>
              <line x1="3" y1="18" x2="21" y2="18"/>
            </svg>
          </button>
          <span class="text-sm font-semibold text-gray-800 dark:text-gray-100 truncate">
            {{ chatService.activeChat()?.title || 'Legal Copilot' }}
          </span>
        </div>

        @if (chatService.activeChat()) {

          <!-- Scrollable thread -->
          <app-chat-thread class="flex flex-col flex-1 min-h-0 overflow-hidden" />

          <!-- Sticky input -->
          <app-chat-input
            [disabled]="chatService.isSending()"
            (messageSent)="onSend($event)"
          />

        } @else {

          <!-- ── Landing screen ─────────────────────────────────────────── -->
          <div class="relative flex flex-col items-center justify-center flex-1
                      gap-10 px-6 py-12 text-center select-none overflow-hidden">

            <!-- Background glow -->
            <div class="absolute inset-0 pointer-events-none" aria-hidden="true">
              <div class="absolute top-1/3 left-1/2 -translate-x-1/2 -translate-y-1/2
                          w-[480px] h-[480px] rounded-full bg-accent/5 blur-3xl"></div>
            </div>

            <!-- Brand block -->
            <div class="flex flex-col items-center gap-4 animate-fade-in relative">
              <div class="w-16 h-16 rounded-2xl bg-accent flex items-center justify-center
                          shadow-lg shadow-accent/25">
                <span class="text-white text-2xl leading-none">⚖</span>
              </div>
              <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white tracking-tight">
                  Legal Copilot
                </h1>
                <p class="mt-1.5 text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
                  ადმინისტრაციული სასამართლო გადაწყვეტილებები<br>
                  კანონმდებლობა · სამართლებრივი ანალიზი
                </p>
              </div>
            </div>

            <!-- Prompt cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 w-full max-w-xl relative animate-slide-up">
              @for (p of landingPrompts; track p.text) {
                <button
                  (click)="startChat(p.text)"
                  [disabled]="chatService.isSending()"
                  class="prompt-card group text-left px-4 py-4 rounded-2xl
                         border border-gray-200 dark:border-gray-700/80
                         bg-white dark:bg-gray-800/50
                         hover:border-accent/40 hover:bg-accent/[0.03] dark:hover:bg-accent/[0.07]
                         hover:shadow-md dark:hover:shadow-none
                         disabled:opacity-50 disabled:cursor-not-allowed disabled:pointer-events-none"
                >
                  <div class="text-xl mb-2.5">{{ p.icon }}</div>
                  <p class="text-[13px] font-semibold text-gray-800 dark:text-gray-100 leading-snug mb-1">
                    {{ p.title }}
                  </p>
                  <p class="text-[11px] text-gray-400 dark:text-gray-500 leading-snug">
                    {{ p.example }}
                  </p>
                </button>
              }
            </div>

            <!-- New chat CTA -->
            <button
              (click)="chatService.newChat()"
              class="relative flex items-center gap-2 px-5 py-2.5 bg-accent text-white text-sm
                     font-medium rounded-xl hover:bg-accent-hover active:scale-95
                     transition-all duration-150 shadow-md shadow-accent/20 animate-fade-in"
            >
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5"  y1="12" x2="19" y2="12"/>
              </svg>
              ახალი ჩატი
            </button>

          </div>

        }

      </main>
    </div>
  `,
})
export class ChatPageComponent implements OnInit {
  sidebarOpen = signal(false);

  readonly landingPrompts = [
    {
      icon: '🔍',
      title: 'გადაწყვეტილებების ძებნა',
      example: 'მიპოვე გადაწყვეტილება შრომითი დავის შესახებ',
      text: 'მიპოვე გადაწყვეტილება შრომითი დავის შესახებ',
    },
    {
      icon: '📋',
      title: 'პრეცედენტების შეჯამება',
      example: 'შემიჯამე ადმინისტრაციული სამართლის პრეცედენტები',
      text: 'შემიჯამე ადმინისტრაციული სამართლის პრეცედენტები',
    },
    {
      icon: '⚖️',
      title: 'სამართლებრივი ანალიზი',
      example: 'ამიხსენი გადასახადის გასაჩივრების პრაქტიკა',
      text: 'ამიხსენი გადასახადის გასაჩივრების პრაქტიკა',
    },
    {
      icon: '🏛️',
      title: 'საქმეების შედარება',
      example: 'შეადარე ორი გადაწყვეტილება ქონებრივ დავაზე',
      text: 'შეადარე ორი გადაწყვეტილება ქონებრივ დავაზე',
    },
  ];

  constructor(
    public chatService: ChatService,
    private route: ActivatedRoute,
    private api: ApiService,
  ) {}

  ngOnInit(): void {
    this.chatService.loadChats();

    this.route.paramMap.subscribe(params => {
      const id = params.get('id');
      if (!id) return;
      const numId = +id;
      const existing = this.chatService.chats().find(c => c.id === numId);
      if (existing) {
        this.chatService.openChat(existing);
      } else {
        this.api.getChats().subscribe(chats => {
          this.chatService.chats.set(chats);
          const found = chats.find(c => c.id === numId);
          if (found) this.chatService.openChat(found);
        });
      }
    });
  }

  startChat(text: string): void {
    this.chatService.newChatWithMessage(text);
  }

  onSend(text: string): void {
    this.chatService.sendMessage(text);
  }
}
