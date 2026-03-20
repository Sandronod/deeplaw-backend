import { Injectable, signal, computed } from '@angular/core';
import { Router } from '@angular/router';
import { Observable, tap, catchError, EMPTY, finalize } from 'rxjs';
import { ApiService } from './api.service';
import { Chat } from '../models/chat.model';
import { ChatMessage } from '../models/message.model';

@Injectable({ providedIn: 'root' })
export class ChatService {
  // --- State signals ---
  readonly chats         = signal<Chat[]>([]);
  readonly activeChat    = signal<Chat | null>(null);
  readonly messages      = signal<ChatMessage[]>([]);
  readonly isLoading     = signal(false);   // loading messages list
  readonly isSending     = signal(false);   // sending a message
  readonly error         = signal<string | null>(null);

  readonly hasChats      = computed(() => this.chats().length > 0);
  readonly activeChatId  = computed(() => this.activeChat()?.id ?? null);

  constructor(private api: ApiService, private router: Router) {}

  loadChats(): void {
    this.api.getChats().subscribe({
      next: (chats) => this.chats.set(chats),
      error: () => this.error.set('Failed to load chats.'),
    });
  }

  openChat(chat: Chat): void {
    this.activeChat.set(chat);
    this.messages.set([]);
    this.error.set(null);
    this.isLoading.set(true);

    this.api.getMessages(chat.id).pipe(
      finalize(() => this.isLoading.set(false))
    ).subscribe({
      next: (msgs) => this.messages.set(msgs),
      error: () => this.error.set('Failed to load messages.'),
    });
  }

  newChat(): void {
    this.api.createChat().subscribe({
      next: (chat) => {
        this.chats.update(list => [chat, ...list]);
        this.activeChat.set(chat);
        this.messages.set([]);
        this.error.set(null);
        this.router.navigate(['/chats', chat.id]);
      },
      error: () => this.error.set('Failed to create chat.'),
    });
  }

  sendMessage(text: string): void {
    const chat = this.activeChat();
    if (!chat || !text.trim() || this.isSending()) return;

    // Optimistic: add user message immediately
    const optimisticUser: ChatMessage = {
      id: Date.now(),
      chat_id: chat.id,
      role: 'user',
      content: text.trim(),
      citations: [],
      meta: { retrieval_mode: null, used_case_count: 0, used_chunk_count: 0 },
      created_at: new Date().toISOString(),
    };

    this.messages.update(msgs => [...msgs, optimisticUser]);
    this.isSending.set(true);
    this.error.set(null);

    this.api.sendMessage(chat.id, text.trim()).pipe(
      finalize(() => this.isSending.set(false))
    ).subscribe({
      next: (assistantMsg) => {
        this.messages.update(msgs => [...msgs, assistantMsg]);
        // Update chat title in sidebar if it was set server-side
        this.api.getChats().subscribe(chats => this.chats.set(chats));
      },
      error: () => {
        // Remove optimistic message on failure
        this.messages.update(msgs => msgs.filter(m => m.id !== optimisticUser.id));
        this.error.set('Failed to send message. Please try again.');
      },
    });
  }

  deleteChat(chatId: number): void {
    this.api.deleteChat(chatId).subscribe({
      next: () => {
        this.chats.update(list => list.filter(c => c.id !== chatId));
        if (this.activeChat()?.id === chatId) {
          this.activeChat.set(null);
          this.messages.set([]);
          this.router.navigate(['/chats']);
        }
      },
      error: () => this.error.set('Failed to delete chat.'),
    });
  }

  clearError(): void {
    this.error.set(null);
  }
}
