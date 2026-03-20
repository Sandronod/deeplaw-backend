import { Component, OnInit } from '@angular/core';
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
    <div class="flex h-screen overflow-hidden bg-white">
      <app-sidebar />

      <main class="relative flex flex-col flex-1 min-w-0 overflow-hidden bg-white">
        @if (chatService.activeChat()) {
          <app-chat-thread class="flex flex-col flex-1 min-h-0 overflow-hidden" />
          <app-chat-input
            [disabled]="chatService.isSending()"
            (messageSent)="onSend($event)"
          />
        } @else {
          <div class="flex flex-col items-center justify-center flex-1 gap-3 text-gray-400 select-none">
            <div class="text-5xl">⚖️</div>
            <h2 class="text-xl font-semibold text-gray-700">იურიდიული AI ასისტენტი</h2>
            <p class="text-sm text-gray-400">ახალი ჩატი დაიწყეთ ან სიდან აირჩიეთ</p>
            <button
              (click)="chatService.newChat()"
              class="mt-2 px-5 py-2 bg-accent text-white text-sm font-medium rounded-lg
                     hover:bg-accent-hover transition-colors"
            >
              + ახალი ჩატი
            </button>
          </div>
        }
      </main>
    </div>
  `,
})
export class ChatPageComponent implements OnInit {
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

  onSend(text: string): void {
    this.chatService.sendMessage(text);
  }
}
