import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ConfidenceLevel } from '../../../../core/models/message.model';

@Component({
  selector: 'app-confidence-badge',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="relative inline-flex group">

      <span
        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full
               text-[10px] font-semibold tracking-wide cursor-default select-none"
        [ngClass]="badgeClass"
      >
        <span class="w-1.5 h-1.5 rounded-full shrink-0" [ngClass]="dotClass"></span>
        {{ label }}
      </span>

      @if (explanation) {
        <div class="absolute bottom-full left-0 mb-2 z-20 pointer-events-none
                    opacity-0 group-hover:opacity-100 transition-opacity duration-150">
          <!-- Tooltip box -->
          <div class="bg-gray-900 dark:bg-gray-700 text-white text-[11px] leading-snug
                      rounded-lg px-3 py-2 max-w-[220px] shadow-xl whitespace-normal">
            {{ explanation }}
          </div>
          <!-- Caret -->
          <div class="absolute top-full left-4 w-0 h-0
                      border-x-4 border-x-transparent
                      border-t-4 border-t-gray-900 dark:border-t-gray-700">
          </div>
        </div>
      }

    </div>
  `,
})
export class ConfidenceBadgeComponent {
  @Input({ required: true }) level!: ConfidenceLevel;
  @Input() explanation: string | null = null;

  get label(): string {
    return { high: 'მაღალი', medium: 'საშუალო', low: 'დაბალი', none: 'ვერ ვიპოვე' }[this.level] ?? '';
  }

  get badgeClass(): string {
    return {
      high:   'bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400',
      medium: 'bg-amber-50  dark:bg-amber-900/30   text-amber-700  dark:text-amber-400',
      low:    'bg-red-50    dark:bg-red-900/30     text-red-600    dark:text-red-400',
      none:   'bg-gray-100  dark:bg-gray-800        text-gray-500   dark:text-gray-400',
    }[this.level] ?? '';
  }

  get dotClass(): string {
    return {
      high:   'bg-emerald-500',
      medium: 'bg-amber-500',
      low:    'bg-red-500',
      none:   'bg-gray-400',
    }[this.level] ?? '';
  }
}
