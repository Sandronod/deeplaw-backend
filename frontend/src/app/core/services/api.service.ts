import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { environment } from '../../../environments/environment';
import { Chat } from '../models/chat.model';
import { ChatMessage, SseEvent, SseStatusData, SseTokenData, SseDoneData, SseErrorData } from '../models/message.model';

@Injectable({ providedIn: 'root' })
export class ApiService {
  private base = environment.apiUrl;

  constructor(private http: HttpClient) {}

  // ── Chats ──────────────────────────────────────────────────────────────────

  getChats(): Observable<Chat[]> {
    return this.http.get<{ data: Chat[] }>(`${this.base}/chats`).pipe(map(r => r.data));
  }

  createChat(title?: string): Observable<Chat> {
    return this.http.post<{ data: Chat }>(`${this.base}/chats`, { title }).pipe(map(r => r.data));
  }

  deleteChat(chatId: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/chats/${chatId}`);
  }

  updateChatTitle(chatId: number, title: string): Observable<Chat> {
    return this.http
      .patch<{ data: Chat }>(`${this.base}/chats/${chatId}/title`, { title })
      .pipe(map(r => r.data));
  }

  // ── Messages ───────────────────────────────────────────────────────────────

  getMessages(chatId: number): Observable<ChatMessage[]> {
    return this.http
      .get<{ data: ChatMessage[] }>(`${this.base}/chats/${chatId}/messages`)
      .pipe(map(r => r.data));
  }

  getFullCase(type: string, caseId: number): Observable<{
    case_id: number; case_type: string; case_num: string | null;
    case_date: string | null; content: string; content_type: 'html' | 'text';
  }> {
    return this.http.get<any>(`${this.base}/cases/${type}/${caseId}`);
  }

  /** Non-streaming fallback — returns full assistant message at once. */
  sendMessage(chatId: number, message: string): Observable<ChatMessage> {
    return this.http
      .post<{ data: ChatMessage }>(`${this.base}/chats/${chatId}/messages`, { message })
      .pipe(map(r => r.data));
  }

  /**
   * Streams the assistant response via SSE (POST + ReadableStream).
   * Emits SseEvent objects: status → token* → done | error.
   * The Observable completes after 'done' or 'error'.
   */
  streamMessage(chatId: number, message: string): Observable<SseEvent> {
    return new Observable<SseEvent>(observer => {
      const controller = new AbortController();

      fetch(`${this.base}/chats/${chatId}/messages/stream`, {
        method:  'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept':        'text/event-stream',
        },
        body:   JSON.stringify({ message }),
        signal: controller.signal,
      })
        .then(response => {
          if (!response.ok || !response.body) {
            observer.error(new Error(`Stream HTTP error ${response.status}`));
            return;
          }

          const reader  = response.body.getReader();
          const decoder = new TextDecoder();
          let buffer    = '';
          let eventName = '';

          const pump = (): Promise<void> =>
            reader.read().then(({ done, value }) => {
              if (done) {
                observer.complete();
                return;
              }

              buffer += decoder.decode(value, { stream: true });

              // Process all complete lines
              const lines = buffer.split('\n');
              buffer = lines.pop() ?? ''; // keep the incomplete trailing chunk

              for (const raw of lines) {
                const line = raw.trim();

                if (line.startsWith('event: ')) {
                  eventName = line.slice(7).trim();
                  continue;
                }

                if (line.startsWith('data: ') && eventName) {
                  try {
                    const data = JSON.parse(line.slice(6));
                    observer.next({ event: eventName as SseEvent['event'], data });

                    // Complete the observable after terminal events
                    if (eventName === 'done' || eventName === 'error') {
                      observer.complete();
                      return;
                    }
                  } catch {
                    // Malformed JSON — skip
                  }
                  eventName = '';
                }
              }

              return pump();
            });

          pump().catch(err => {
            if (err?.name !== 'AbortError') {
              observer.error(err);
            }
          });
        })
        .catch(err => {
          if (err?.name !== 'AbortError') {
            observer.error(err);
          }
        });

      // Teardown: abort the fetch if the observable is unsubscribed
      return () => controller.abort();
    });
  }
}
