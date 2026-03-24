import { Component, Input, Output, EventEmitter, signal } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'app-message-actions',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="flex items-center gap-0.5 msg-actions">

      <!-- Copy -->
      <button
        (click)="copy()"
        title="კოპირება"
        class="flex items-center gap-1 px-2 py-1 rounded-md text-[11px]
               text-gray-400 hover:text-gray-600 dark:hover:text-gray-300
               hover:bg-gray-100 dark:hover:bg-gray-800 transition-all duration-150"
      >
        @if (copied()) {
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          <span class="text-emerald-600 dark:text-emerald-400">კოპირდა</span>
        } @else {
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2">
            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
          </svg>
        }
      </button>

      <!-- Citations toggle -->
      @if (citationCount > 0) {
        <button
          (click)="citationsToggle.emit()"
          [title]="citationsOpen ? 'წყაროების დამალვა' : 'წყაროების ჩვენება'"
          class="flex items-center gap-1 px-2 py-1 rounded-md text-[11px] transition-all duration-150"
          [ngClass]="citationsBtnClass"
        >
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
          </svg>
          {{ citationCount }} წყარო
        </button>
      }

    </div>
  `,
})
export class MessageActionsComponent {
  @Input({ required: true }) text!: string;
  @Input() citationCount = 0;
  @Input() citationsOpen = false;
  @Output() citationsToggle = new EventEmitter<void>();

  copied = signal(false);

  get citationsBtnClass(): string {
    return this.citationsOpen
      ? 'text-accent bg-accent/5'
      : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800';
  }

  copy(): void {
    navigator.clipboard.writeText(this.text).then(() => {
      this.copied.set(true);
      setTimeout(() => this.copied.set(false), 2000);
    });
  }
}
