import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ChatMessage } from '../../../../core/models/message.model';
import { CitationListComponent } from '../citation-list/citation-list.component';

@Component({
  selector: 'app-message-item',
  standalone: true,
  imports: [CommonModule, CitationListComponent],
  template: `
    <!-- USER message -->
    @if (isUser) {
      <div class="max-w-chat mx-auto px-4 py-2">
        <div class="flex justify-end">
          <div class="max-w-[85%] sm:max-w-[75%]">
            <div class="bg-user-msg text-gray-800 rounded-2xl rounded-br-sm
                        px-4 py-3 text-[15px] leading-relaxed break-words"
                 [innerHTML]="formatted">
            </div>
          </div>
        </div>
      </div>
    }

    <!-- ASSISTANT message -->
    @if (isAssistant) {
      <div class="max-w-chat mx-auto px-4 py-3">
        <div class="flex gap-3 items-start">

          <!-- Avatar -->
          <div class="w-7 h-7 rounded-full bg-accent flex items-center justify-center
                      shrink-0 mt-0.5 shadow-sm">
            <span class="text-white text-[10px] font-bold tracking-tight">AI</span>
          </div>

          <!-- Content -->
          <div class="flex-1 min-w-0 space-y-3">
            <div class="text-[15px] text-gray-800 leading-relaxed break-words prose-sm max-w-none"
                 [innerHTML]="formatted">
            </div>

            <!-- Citations -->
            @if (hasCitations) {
              <app-citation-list [citations]="message.citations" />
            }

            <!-- Retrieval badge -->
            @if ((message.meta?.used_case_count ?? 0) > 0) {
              <div class="flex items-center gap-1.5 text-[11px] text-gray-400">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2">
                  <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                </svg>
                {{ message.meta?.used_case_count ?? 0 }} გადაწყვეტილება ·
                {{ message.meta?.used_chunk_count ?? 0 }} ფრაგმენტი
              </div>
            }
          </div>
        </div>
      </div>
    }
  `,
})
export class MessageItemComponent {
  @Input({ required: true }) message!: ChatMessage;

  get isUser(): boolean { return this.message.role === 'user'; }
  get isAssistant(): boolean { return this.message.role === 'assistant'; }
  get hasCitations(): boolean { return (this.message.citations?.length ?? 0) > 0; }

  get formatted(): string {
    return this.message.content
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\[([^\]]+)\]\((https?:\/\/[^\)]+)\)/g,
        '<a href="$2" target="_blank" rel="noopener" ' +
        'class="text-accent underline underline-offset-2 hover:text-accent-hover">$1</a>')
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/\n\n/g, '</p><p class="mt-3">')
      .replace(/\n/g, '<br>');
  }
}
