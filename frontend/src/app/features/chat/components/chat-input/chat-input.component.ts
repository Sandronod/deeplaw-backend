import { Component, Input, Output, EventEmitter, ViewChild, ElementRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

@Component({
  selector: 'app-chat-input',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="border-t border-gray-100 bg-white px-4 pt-3 pb-4">

      <!-- Input container — ChatGPT style rounded box -->
      <div class="max-w-chat mx-auto">
        <div
          class="flex items-end gap-2 rounded-2xl border bg-white px-4 py-3 shadow-sm
                 transition-colors"
          [class.border-gray-200]="!focused"
          [class.border-gray-300]="focused"
          [class.opacity-60]="disabled"
        >
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
            class="flex-1 resize-none bg-transparent text-[15px] text-gray-800 leading-relaxed
                   outline-none placeholder-gray-400 max-h-48 overflow-y-auto
                   disabled:cursor-not-allowed"
          ></textarea>

          <!-- Send button -->
          <button
            (click)="send()"
            [disabled]="disabled || !text.trim()"
            class="shrink-0 flex items-center justify-center w-8 h-8 rounded-lg
                   transition-all duration-150 self-end"
            [class.bg-accent]="!disabled && text.trim()"
            [class.text-white]="!disabled && text.trim()"
            [class.hover:bg-accent-hover]="!disabled && text.trim()"
            [class.bg-gray-100]="disabled || !text.trim()"
            [class.text-gray-300]="disabled || !text.trim()"
            [class.cursor-not-allowed]="disabled || !text.trim()"
          >
            @if (disabled) {
              <!-- Stop icon when sending -->
              <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                <rect x="6" y="6" width="12" height="12" rx="2"/>
              </svg>
            } @else {
              <!-- Arrow up send icon -->
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <line x1="12" y1="19" x2="12" y2="5"/>
                <polyline points="5 12 12 5 19 12"/>
              </svg>
            }
          </button>
        </div>

        <p class="mt-1.5 text-center text-[10px] text-gray-300">
          Enter — გაგზავნა &nbsp;·&nbsp; Shift+Enter — ახალი სტრიქონი
        </p>
      </div>
    </div>
  `,
})
export class ChatInputComponent {
  @Input() disabled = false;
  @Output() messageSent = new EventEmitter<string>();
  @ViewChild('textarea') textarea!: ElementRef<HTMLTextAreaElement>;

  text    = '';
  focused = false;

  send(): void {
    const msg = this.text.trim();
    if (!msg || this.disabled) return;
    this.messageSent.emit(msg);
    this.text = '';
    setTimeout(() => {
      if (this.textarea?.nativeElement) {
        this.textarea.nativeElement.style.height = 'auto';
      }
    });
  }

  onKey(e: KeyboardEvent): void {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); this.send(); }
  }

  autoResize(el: HTMLTextAreaElement): void {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 192) + 'px';
  }
}
