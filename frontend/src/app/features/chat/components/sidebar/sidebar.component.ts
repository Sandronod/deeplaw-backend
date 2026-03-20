import { Component } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { ChatService } from '../../../../core/services/chat.service';
import { Chat } from '../../../../core/models/chat.model';

@Component({
  selector: 'app-sidebar',
  standalone: true,
  imports: [CommonModule],
  template: `
    <aside class="flex flex-col h-full w-64 min-w-[256px] bg-gray-50 border-r border-gray-200 overflow-hidden">

      <!-- New Chat Button -->
      <div class="p-3 border-b border-gray-200">
        <button
          (click)="chatService.newChat()"
          class="flex items-center gap-3 w-full px-3 py-2.5 rounded-lg text-sm font-medium
                 text-gray-700 hover:bg-gray-200 transition-colors group"
        >
          <span class="flex items-center justify-center w-6 h-6 rounded-md border border-gray-300
                       group-hover:border-gray-400 transition-colors text-gray-500 group-hover:text-gray-700">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
          </span>
          ახალი ჩატი
        </button>
      </div>

      <!-- Chat List -->
      <nav class="flex-1 overflow-y-auto py-2 px-2">

        @if (!chatService.hasChats()) {
          <p class="px-3 py-4 text-xs text-gray-400 text-center">ჩატები არ არის</p>
        }

        @for (chat of chatService.chats(); track chat.id) {
          <div
            (click)="select(chat)"
            class="group relative flex items-center gap-2 px-3 py-2.5 rounded-lg cursor-pointer
                   transition-colors mb-0.5"
            [class.bg-gray-200]="isActive(chat)"
            [class.hover:bg-gray-100]="!isActive(chat)"
          >
            <!-- Chat icon -->
            <svg class="w-4 h-4 shrink-0 text-gray-400" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.8">
              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>

            <span class="flex-1 text-[13px] text-gray-600 truncate leading-snug"
                  [class.text-gray-900]="isActive(chat)"
                  [class.font-medium]="isActive(chat)">
              {{ chat.title || 'ახალი ჩატი' }}
            </span>

            <!-- Delete btn — appears on hover -->
            <button
              (click)="deleteChat($event, chat.id)"
              class="opacity-0 group-hover:opacity-100 shrink-0 p-1 rounded text-gray-400
                     hover:text-red-500 hover:bg-red-50 transition-all"
            >
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2">
                <polyline points="3 6 5 6 21 6"/>
                <path d="M19 6l-1 14H6L5 6M10 11v6M14 11v6M9 6V4h6v2"/>
              </svg>
            </button>
          </div>
        }
      </nav>

      <!-- Footer -->
      <div class="p-3 border-t border-gray-200">
        <div class="flex items-center gap-2 px-2 py-2 rounded-lg text-xs text-gray-500">
          <span class="w-6 h-6 rounded-full bg-accent flex items-center justify-center text-white font-bold text-xs">⚖</span>
          <span>იურიდიული AI</span>
        </div>
      </div>
    </aside>
  `,
})
export class SidebarComponent {
  constructor(public chatService: ChatService, private router: Router) {}

  select(chat: Chat): void {
    this.chatService.openChat(chat);
    this.router.navigate(['/chats', chat.id]);
  }

  deleteChat(e: MouseEvent, id: number): void {
    e.stopPropagation();
    if (confirm('ამ ჩატის წაშლა გნებავთ?')) this.chatService.deleteChat(id);
  }

  isActive(chat: Chat): boolean {
    return this.chatService.activeChatId() === chat.id;
  }
}
