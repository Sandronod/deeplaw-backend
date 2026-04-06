import { Injectable, signal, computed } from '@angular/core';
import { Router } from '@angular/router';
import { finalize } from 'rxjs';
import { ApiService } from './api.service';
import { Chat } from '../models/chat.model';
import {
  ChatMessage,
  SseDoneData,
  SseErrorData,
  SseStatusData,
  SseTokenData,
  StreamPhase,
} from '../models/message.model';

@Injectable({ providedIn: 'root' })
export class ChatService {
  // ── State signals ─────────────────────────────────────────────────────────
  readonly chats        = signal<Chat[]>([]);
  readonly activeChat   = signal<Chat | null>(null);
  readonly messages     = signal<ChatMessage[]>([]);
  readonly isLoading    = signal(false);
  readonly isSending    = signal(false);
  readonly streamPhase  = signal<StreamPhase | null>(null);
  readonly error        = signal<string | null>(null);
  /** Increments on every token — lets chat-thread re-evaluate scroll position. */
  readonly streamTick   = signal(0);

  readonly hasChats     = computed(() => this.chats().length > 0);
  readonly activeChatId = computed(() => this.activeChat()?.id ?? null);

  constructor(private api: ApiService, private router: Router) {}

  // ── Chat management ───────────────────────────────────────────────────────

  loadChats(): void {
    this.api.getChats().subscribe({
      next: chats => this.chats.set(chats),
      error: ()   => this.error.set('ჩატების ჩატვირთვა ვერ მოხერხდა.'),
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
      next: msgs => this.messages.set(msgs.map(m => ({ ...m, status: 'done' as const }))),
      error: ()  => this.error.set('შეტყობინებების ჩატვირთვა ვერ მოხერხდა.'),
    });
  }

  newChat(): void {
    this.api.createChat().subscribe({
      next: chat => {
        this.chats.update(list => [chat, ...list]);
        this.activeChat.set(chat);
        this.messages.set([]);
        this.error.set(null);
        this.router.navigate(['/chats', chat.id]);
      },
      error: () => this.error.set('ახალი ჩატის შექმნა ვერ მოხერხდა.'),
    });
  }

  /** Create a new chat and immediately send the first message. */
  newChatWithMessage(text: string): void {
    this.api.createChat().subscribe({
      next: chat => {
        this.chats.update(list => [chat, ...list]);
        this.activeChat.set(chat);
        this.messages.set([]);
        this.error.set(null);
        this.router.navigate(['/chats', chat.id]);
        this.sendMessage(text);
      },
      error: () => this.error.set('ახალი ჩატის შექმნა ვერ მოხერხდა.'),
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
      error: () => this.error.set('ჩატის წაშლა ვერ მოხერხდა.'),
    });
  }

  clearError(): void {
    this.error.set(null);
  }

  // ── Send with SSE streaming ───────────────────────────────────────────────

  sendMessage(text: string): void {
    const chat = this.activeChat();
    if (!chat || !text.trim() || this.isSending()) return;

    // 1. Optimistic user message
    const userMsg: ChatMessage = {
      id:         Date.now(),
      chat_id:    chat.id,
      role:       'user',
      content:    text.trim(),
      citations:  [],
      status:     'done',
      isNew:      true,
      created_at: new Date().toISOString(),
    };
    this.messages.update(msgs => [...msgs, userMsg]);

    // 2. Empty assistant bubble in loading state
    const tempId = Date.now() + 1;
    const assistantPlaceholder: ChatMessage = {
      id:         tempId,
      chat_id:    chat.id,
      role:       'assistant',
      content:    '',
      citations:  [],
      status:     'loading',
      isNew:      true,
      created_at: new Date().toISOString(),
    };
    this.messages.update(msgs => [...msgs, assistantPlaceholder]);

    this.isSending.set(true);
    this.streamPhase.set('searching');
    this.error.set(null);

    // 3. Subscribe to SSE stream
    this.api.streamMessage(chat.id, text.trim()).subscribe({
      next: sseEvent => {
        switch (sseEvent.event) {

          case 'status': {
            const d = sseEvent.data as SseStatusData;
            this.streamPhase.set(d.phase);
            break;
          }

          case 'token': {
            const d = sseEvent.data as SseTokenData;
            this.messages.update(msgs => msgs.map(m =>
              m.id === tempId
                ? { ...m, content: m.content + d.token, status: 'streaming' as const }
                : m
            ));
            this.streamTick.update(n => n + 1);
            break;
          }

          case 'done': {
            const d = sseEvent.data as SseDoneData;
            this.messages.update(msgs => msgs.map(m =>
              m.id === tempId
                ? {
                    ...m,
                    id:            d.message_id,
                    citations:     d.citations,
                    law_citations: d.law_citations  ?? [],
                    echr_citations:d.echr_citations ?? [],
                    meta:          d.meta,
                    status:    'done' as const,
                  }
                : m
            ));
            this.isSending.set(false);
            this.streamPhase.set(null);
            // Refresh sidebar titles after first message
            this.api.getChats().subscribe(chats => this.chats.set(chats));
            break;
          }

          case 'error': {
            const hasPartialContent = this.messages().find(m => m.id === tempId)?.content.length;
            this.messages.update(msgs => msgs.map(m =>
              m.id === tempId
                ? { ...m, status: 'error' as const, isPartial: !!hasPartialContent, canRetry: !!hasPartialContent }
                : m
            ));
            this.isSending.set(false);
            this.streamPhase.set(null);
            break;
          }
        }
      },

      error: () => {
        const hasPartial = !!this.messages().find(m => m.id === tempId)?.content.length;
        this.messages.update(msgs => msgs.map(m =>
          m.id === tempId
            ? { ...m, status: 'error' as const, isPartial: hasPartial, canRetry: hasPartial }
            : m
        ));
        this.isSending.set(false);
        this.streamPhase.set(null);
        if (!hasPartial) {
          this.error.set('კავშირი გაწყდა. სცადეთ თავიდან.');
        }
      },
    });
  }
}
