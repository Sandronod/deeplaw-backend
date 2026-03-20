import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { environment } from '../../../environments/environment';
import { Chat } from '../models/chat.model';
import { ChatMessage } from '../models/message.model';

@Injectable({ providedIn: 'root' })
export class ApiService {
  private base = environment.apiUrl;

  constructor(private http: HttpClient) {}

  // Chats
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

  // Messages
  getMessages(chatId: number): Observable<ChatMessage[]> {
    return this.http
      .get<{ data: ChatMessage[] }>(`${this.base}/chats/${chatId}/messages`)
      .pipe(map(r => r.data));
  }

  sendMessage(chatId: number, message: string): Observable<ChatMessage> {
    return this.http
      .post<{ data: ChatMessage }>(`${this.base}/chats/${chatId}/messages`, { message })
      .pipe(map(r => r.data));
  }
}
