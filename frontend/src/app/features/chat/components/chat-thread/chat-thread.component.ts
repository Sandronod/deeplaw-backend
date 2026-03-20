import { Component, AfterViewChecked, ElementRef, ViewChild, effect } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ChatService } from '../../../../core/services/chat.service';
import { MessageItemComponent } from '../message-item/message-item.component';

@Component({
  selector: 'app-chat-thread',
  standalone: true,
  imports: [CommonModule, MessageItemComponent],
  template: `
    <div #scrollContainer class="flex-1 overflow-y-auto overflow-x-hidden">

      <!-- Loading skeleton -->
      @if (chatService.isLoading()) {
        <div class="flex flex-col gap-6 max-w-chat mx-auto px-4 py-8">
          @for (i of [1,2,3]; track i) {
            <div class="flex gap-3 animate-pulse">
              <div class="w-7 h-7 rounded-full bg-gray-200 shrink-0 mt-1"></div>
              <div class="flex-1 space-y-2 pt-1">
                <div class="h-3.5 bg-gray-200 rounded w-3/4"></div>
                <div class="h-3.5 bg-gray-200 rounded w-1/2"></div>
              </div>
            </div>
          }
        </div>
      }

      <!-- Empty state -->
      @if (!chatService.isLoading() && chatService.messages().length === 0) {
        <div class="flex flex-col items-center justify-center h-full gap-4 px-6 text-center">
          <div class="w-12 h-12 rounded-full bg-accent/10 flex items-center justify-center">
            <span class="text-2xl">⚖️</span>
          </div>
          <div>
            <h3 class="text-lg font-semibold text-gray-800 mb-1">როგორ შემიძლია დაგეხმარო?</h3>
            <p class="text-sm text-gray-400 max-w-xs">
              სასამართლო გადაწყვეტილებები · კანონმდებლობა · იურიდიული რჩევები
            </p>
          </div>
          <div class="grid grid-cols-2 gap-2 mt-2 w-full max-w-md">
            @for (hint of hints; track hint) {
              <button
                (click)="onHint(hint)"
                class="text-left text-xs text-gray-500 border border-gray-200 rounded-xl px-3.5 py-3
                       hover:border-gray-300 hover:bg-gray-50 transition-colors leading-snug"
              >{{ hint }}</button>
            }
          </div>
        </div>
      }

      <!-- Messages -->
      <div class="py-6">
        @for (msg of chatService.messages(); track msg.id) {
          <app-message-item [message]="msg" />
        }

        <!-- Typing indicator -->
        @if (chatService.isSending()) {
          <div class="max-w-chat mx-auto px-4 py-3">
            <div class="flex gap-3">
              <div class="w-7 h-7 rounded-full bg-accent flex items-center justify-center shrink-0 mt-0.5">
                <span class="text-white text-xs font-bold">AI</span>
              </div>
              <div class="flex items-center gap-1 pt-2">
                @for (d of [0,1,2]; track d) {
                  <span
                    class="w-2 h-2 bg-gray-400 rounded-full animate-bounce-dot"
                    [style.animation-delay]="(d * 0.2) + 's'"
                  ></span>
                }
              </div>
            </div>
          </div>
        }
      </div>

      <!-- Error bar -->
      @if (chatService.error()) {
        <div class="max-w-chat mx-auto px-4 pb-4">
          <div class="flex items-center justify-between gap-3 px-4 py-3 bg-red-50
                      border border-red-200 rounded-xl text-sm text-red-600">
            <span>{{ chatService.error() }}</span>
            <button (click)="chatService.clearError()"
                    class="text-red-400 hover:text-red-600 transition-colors text-lg leading-none">×</button>
          </div>
        </div>
      }

      <div #threadEnd class="h-4"></div>
    </div>
  `,
})
export class ChatThreadComponent {
  @ViewChild('threadEnd') private threadEnd!: ElementRef;
  @ViewChild('scrollContainer') private scrollContainer!: ElementRef<HTMLElement>;

  hints = [
    'მიპოვე გადაწყვეტილება შრომითი დავის შესახებ',
    'რა უფლებები მაქვს სამუშაოდან გათავისუფლებისას?',
    'ამიხსენი სახელმწიფო კომპენსაციის წესები',
    'შეადარე ორი გადაწყვეტილება ქონებრივ დავაზე',
  ];

  private lastMessageCount = 0;
  private shouldScroll = false;

  constructor(public chatService: ChatService) {
    // მხოლოდ ახალი message-ის დამატებისას ვსქროლავთ ქვემოთ
    effect(() => {
      const count = this.chatService.messages().length;
      const sending = this.chatService.isSending();
      if (count !== this.lastMessageCount || sending) {
        this.lastMessageCount = count;
        this.shouldScroll = true;
        setTimeout(() => this.scrollToBottom());
      }
    });
  }

  private scrollToBottom(): void {
    if (!this.shouldScroll) return;
    this.threadEnd?.nativeElement?.scrollIntoView({ behavior: 'smooth', block: 'end' });
    this.shouldScroll = false;
  }

  onHint(text: string): void {
    this.chatService.sendMessage(text);
  }
}
