import { Component, Input, Output, EventEmitter, ViewChild, ElementRef, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ChatService } from '../../../../core/services/chat.service';

@Component({
  selector: 'app-chat-input',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="bg-white dark:bg-gray-900 px-3 sm:px-4 pb-4 sm:pb-5 pt-2 sm:pt-3
                border-t border-gray-100 dark:border-gray-800">

      <div class="max-w-3xl mx-auto">

        <!-- Source selector -->
        <div class="flex flex-wrap gap-1 mb-2">
          <!-- All toggle -->
          <button
            (click)="toggleAll()"
            class="px-2.5 py-1 rounded-full text-[11px] font-medium transition-all duration-150 border"
            [class.border-transparent]="!allSelected()"
            [class.text-gray-500]="!allSelected()"
            [class.bg-gray-100]="!allSelected()"
            [class.dark:bg-gray-800]="!allSelected()"
            [class.border-accent]="allSelected()"
            [class.text-accent]="allSelected()"
            [class.bg-accent-50]="allSelected()"
            [class.dark:bg-gray-700]="allSelected()"
            [class.font-semibold]="allSelected()"
          >ყველა</button>

          @for (opt of sourceOptions; track opt.value) {
            <button
              (click)="toggleSource(opt.value)"
              class="px-2.5 py-1 rounded-full text-[11px] font-medium transition-all duration-150 border"
              [class.border-transparent]="!isSelected(opt.value)"
              [class.text-gray-500]="!isSelected(opt.value)"
              [class.dark:text-gray-400]="!isSelected(opt.value)"
              [class.bg-gray-100]="!isSelected(opt.value)"
              [class.dark:bg-gray-800]="!isSelected(opt.value)"
              [class.border-accent]="isSelected(opt.value)"
              [class.text-accent]="isSelected(opt.value)"
              [class.bg-accent-50]="isSelected(opt.value)"
              [class.dark:bg-gray-700]="isSelected(opt.value)"
              [class.font-semibold]="isSelected(opt.value)"
            >{{ opt.label }}</button>
          }
        </div>

        <!-- Input box -->
        <div
          class="flex items-end gap-2 rounded-2xl border bg-white dark:bg-gray-800
                 px-3 sm:px-4 py-2.5 sm:py-3
                 shadow-sm transition-all duration-150"
          [class.border-gray-200]="!focused"
          [class.dark:border-gray-700]="!focused"
          [class.border-accent]="focused"
          [class.dark:border-accent]="focused"
          [class.opacity-60]="disabled"
          [class.pointer-events-none]="disabled"
        >

          <!-- Textarea -->
          <textarea
            #textarea
            [(ngModel)]="text"
            (focus)="focused = true"
            (blur)="focused = false"
            (keydown)="onKey($event)"
            (input)="autoResize(textarea)"
            [disabled]="disabled"
            placeholder="დასვით იურიდიული შეკითხვა..."
            rows="1"
            class="chat-text flex-1 resize-none bg-transparent
                   text-gray-800 dark:text-gray-100 leading-relaxed
                   outline-none placeholder-gray-400 dark:placeholder-gray-500
                   max-h-48 overflow-y-auto disabled:cursor-not-allowed"
          ></textarea>

          <!-- Send button -->
          <button
            (click)="send()"
            [disabled]="disabled || !text.trim()"
            class="shrink-0 flex items-center justify-center w-8 h-8 rounded-lg self-end
                   transition-all duration-150 active:scale-95"
            [class.bg-accent]="!disabled && !!text.trim()"
            [class.text-white]="!disabled && !!text.trim()"
            [class.hover:bg-accent-hover]="!disabled && !!text.trim()"
            [class.shadow-sm]="!disabled && !!text.trim()"
            [class.bg-gray-100]="disabled || !text.trim()"
            [class.dark:bg-gray-700]="disabled || !text.trim()"
            [class.text-gray-400]="disabled || !text.trim()"
            [class.dark:text-gray-500]="disabled || !text.trim()"
            [class.cursor-not-allowed]="disabled || !text.trim()"
          >
            @if (disabled) {
              <svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor">
                <rect x="6" y="6" width="12" height="12" rx="2"/>
              </svg>
            } @else {
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <line x1="12" y1="19" x2="12" y2="5"/>
                <polyline points="5 12 12 5 19 12"/>
              </svg>
            }
          </button>

        </div>

        <p class="mt-2 text-center text-[10px] text-gray-400 dark:text-gray-600 select-none
                  hidden sm:block">
          Enter — გაგზავნა &nbsp;·&nbsp; Shift+Enter — ახალი სტრიქონი
        </p>

      </div>
    </div>
  `,
})
export class ChatInputComponent {
  @Input()  disabled = false;
  @Output() messageSent = new EventEmitter<string>();

  @ViewChild('textarea') private textarea!: ElementRef<HTMLTextAreaElement>;

  readonly chatService = inject(ChatService);

  text    = '';
  focused = false;

  readonly allSources = ['court', 'matsne', 'eu', 'german', 'const_court'];

  readonly sourceOptions: { value: string; label: string }[] = [
    { value: 'court',       label: '⚖️ საქმეები' },
    { value: 'matsne',      label: '📋 მაცნე' },
    { value: 'eu',          label: '🇪🇺 EU' },
    { value: 'german',      label: '🇩🇪 გერმანია' },
    { value: 'const_court', label: '🏛️ საკონსტ.' },
  ];

  isSelected(value: string): boolean {
    return this.chatService.sources().includes(value);
  }

  allSelected(): boolean {
    return this.allSources.every(s => this.chatService.sources().includes(s));
  }

  toggleSource(value: string): void {
    const current = this.chatService.sources();
    if (current.includes(value)) {
      // keep at least one selected
      const next = current.filter(s => s !== value);
      if (next.length > 0) this.chatService.sources.set(next);
    } else {
      this.chatService.sources.set([...current, value]);
    }
  }

  toggleAll(): void {
    if (this.allSelected()) {
      // deselect all → keep only court as fallback
      this.chatService.sources.set(['court']);
    } else {
      this.chatService.sources.set([...this.allSources]);
    }
  }

  send(): void {
    const msg = this.text.trim();
    if (!msg || this.disabled) return;

    this.messageSent.emit(msg);
    this.text = '';

    setTimeout(() => {
      const el = this.textarea?.nativeElement;
      if (el) el.style.height = 'auto';
    });
  }

  onKey(e: KeyboardEvent): void {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      this.send();
    }
  }

  autoResize(el: HTMLTextAreaElement): void {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 192) + 'px';
  }
}
