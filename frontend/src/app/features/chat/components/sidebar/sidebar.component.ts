import { Component, Output, EventEmitter, signal, inject } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { ChatService } from '../../../../core/services/chat.service';
import { SettingsService } from '../../../../core/services/settings.service';
import { AuthService } from '../../../../core/services/auth.service';
import { Chat } from '../../../../core/models/chat.model';

@Component({
  selector: 'app-sidebar',
  standalone: true,
  imports: [CommonModule],
  template: `
    <aside class="flex flex-col h-full w-64 min-w-[256px] bg-sidebar border-r border-sidebar-border
                  text-sidebar-text overflow-hidden scrollbar-sidebar">

      <!-- ── Branding ─────────────────────────────────────────────────────── -->
      <div class="flex items-center gap-2.5 px-4 h-14 shrink-0 border-b border-sidebar-border">
        <div class="w-7 h-7 rounded-lg bg-accent flex items-center justify-center shrink-0 text-base shadow-sm">⚖</div>
        <span class="text-[13px] font-semibold text-white truncate tracking-tight">Legal Copilot</span>
      </div>

      <!-- ── New Chat ──────────────────────────────────────────────────────── -->
      <div class="p-3 shrink-0">
        <button
          (click)="onNewChat()"
          class="flex items-center gap-2.5 w-full px-3 py-2.5 rounded-lg text-[13px] font-medium
                 text-sidebar-text hover:bg-sidebar-hover active:bg-sidebar-active
                 transition-colors duration-150 border border-sidebar-border"
        >
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="12" y1="5" x2="12" y2="19"/>
            <line x1="5"  y1="12" x2="19" y2="12"/>
          </svg>
          ახალი ჩატი
          <span class="ml-auto text-[10px] text-gray-600 font-normal">Ctrl+N</span>
        </button>
      </div>

      <!-- ── Conversation list ─────────────────────────────────────────────── -->
      <nav class="flex-1 overflow-y-auto px-2 pb-2 space-y-0.5 scrollbar-sidebar">

        @if (chatService.chatsLoading()) {
          <div class="flex justify-center py-8">
            <svg class="animate-spin w-5 h-5 text-gray-500" viewBox="0 0 24 24" fill="none">
              <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" stroke-opacity="0.25"/>
              <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
            </svg>
          </div>
        } @else if (!chatService.hasChats()) {
          <p class="px-3 py-8 text-xs text-gray-600 text-center leading-relaxed">
            ჩატები არ არის.<br>
            <span class="text-gray-500">დაიწყეთ ახალი ჩატი.</span>
          </p>
        }

        @for (chat of chatService.chats(); track chat.id) {
          <div
            (click)="select(chat)"
            class="group relative flex items-center gap-2.5 px-3 py-2 rounded-lg cursor-pointer
                   transition-colors duration-100"
            [class.bg-sidebar-active]="isActive(chat)"
            [class.hover:bg-sidebar-hover]="!isActive(chat)"
          >

            <!-- Icon -->
            <svg class="w-3.5 h-3.5 shrink-0 text-gray-600 group-hover:text-gray-400 transition-colors"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>

            <!-- Title -->
            <span class="flex-1 text-[13px] truncate leading-snug transition-colors"
                  [class.text-white]="isActive(chat)"
                  [class.text-gray-400]="!isActive(chat)"
                  [class.group-hover:text-gray-200]="!isActive(chat)">
              {{ chat.title || 'ახალი ჩატი' }}
            </span>

            <!-- Delete (reveal on hover) -->
            <button
              (click)="deleteChat($event, chat.id)"
              class="opacity-0 group-hover:opacity-100 shrink-0 p-1 rounded
                     text-gray-600 hover:text-red-400 transition-all duration-150"
              title="წაშლა"
            >
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2">
                <polyline points="3 6 5 6 21 6"/>
                <path d="M19 6l-1 14H6L5 6M10 11v6M14 11v6M9 6V4h6v2"/>
              </svg>
            </button>

          </div>
        }
      </nav>

      <!-- ── User & Logout ──────────────────────────────────────────────────── -->
      <div class="shrink-0 border-t border-sidebar-border px-3 py-2.5
                  flex items-center justify-between gap-2">
        <div class="flex items-center gap-2 min-w-0">
          <div class="w-7 h-7 rounded-full bg-accent/20 flex items-center justify-center shrink-0">
            <span class="text-accent text-xs font-bold">
              {{ (auth.user()?.first_name?.[0] ?? '') + (auth.user()?.last_name?.[0] ?? '') }}
            </span>
          </div>
          <span class="text-xs text-gray-400 truncate">{{ auth.fullName() }}</span>
        </div>
        <button (click)="auth.logout()" title="გამოსვლა"
          class="shrink-0 p-1.5 rounded-lg text-gray-600 hover:text-red-400
                 hover:bg-sidebar-hover transition-colors">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
            <polyline points="16 17 21 12 16 7"/>
            <line x1="21" y1="12" x2="9" y2="12"/>
          </svg>
        </button>
      </div>

      <!-- ── Settings ──────────────────────────────────────────────────────── -->
      <div class="shrink-0 border-t border-sidebar-border">

        <button
          (click)="settingsOpen.set(!settingsOpen())"
          class="flex items-center justify-between w-full px-4 py-3 text-xs text-gray-500
                 hover:bg-sidebar-hover hover:text-gray-300 transition-colors duration-150"
        >
          <div class="flex items-center gap-2">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="3"/>
              <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06
                       a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09
                       A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83
                       l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09
                       A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83
                       l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09
                       a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83
                       l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09
                       a1.65 1.65 0 0 0-1.51 1z"/>
            </svg>
            პარამეტრები
          </div>
          <svg class="w-3 h-3 transition-transform duration-200"
               [class.rotate-180]="settingsOpen()"
               viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </button>

        @if (settingsOpen()) {
          <div class="px-4 pb-4 pt-1 space-y-3.5 bg-sidebar-hover animate-fade-in">

            <!-- Dark mode -->
            <div class="flex items-center justify-between">
              <span class="text-xs text-gray-400">მუქი რეჟიმი</span>
              <button
                (click)="settings.toggleDark()"
                class="relative inline-flex w-9 h-5 rounded-full transition-colors duration-200"
                [class.bg-accent]="settings.darkMode()"
                [class.bg-gray-600]="!settings.darkMode()"
              >
                <span
                  class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow
                         transition-transform duration-200"
                  [class.translate-x-4]="settings.darkMode()"
                ></span>
              </button>
            </div>

            <!-- Font size -->
            <div class="flex items-center justify-between">
              <span class="text-xs text-gray-400">შრიფტი ({{ settings.fontSize() }}px)</span>
              <div class="flex items-center gap-1">
                <button
                  (click)="settings.decreaseFontSize()"
                  class="w-6 h-6 rounded border border-gray-600 text-gray-400
                         hover:bg-gray-700 hover:text-gray-200 text-xs transition-colors"
                >A−</button>
                <button
                  (click)="settings.increaseFontSize()"
                  class="w-6 h-6 rounded border border-gray-600 text-gray-400
                         hover:bg-gray-700 hover:text-gray-200 text-xs transition-colors"
                >A+</button>
              </div>
            </div>

          </div>
        }

      </div>
    </aside>
  `,
})
export class SidebarComponent {
  @Output() close = new EventEmitter<void>();

  settingsOpen = signal(false);
  auth = inject(AuthService);

  constructor(
    public chatService: ChatService,
    public settings: SettingsService,
    private router: Router,
  ) {}

  onNewChat(): void {
    this.chatService.newChat();
    this.close.emit();
  }

  select(chat: Chat): void {
    this.chatService.openChat(chat);
    this.router.navigate(['/chats', chat.id]);
    this.close.emit();
  }

  deleteChat(e: MouseEvent, id: number): void {
    e.stopPropagation();
    if (confirm('ამ ჩატის წაშლა გნებავთ?')) this.chatService.deleteChat(id);
  }

  isActive(chat: Chat): boolean {
    return this.chatService.activeChatId() === chat.id;
  }
}
