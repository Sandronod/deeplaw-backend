import { Injectable, signal, effect } from '@angular/core';

@Injectable({ providedIn: 'root' })
export class SettingsService {
  darkMode = signal<boolean>(localStorage.getItem('darkMode') === 'true');
  fontSize = signal<number>(parseInt(localStorage.getItem('fontSize') ?? '16', 10));

  constructor() {
    effect(() => {
      const dark = this.darkMode();
      localStorage.setItem('darkMode', String(dark));
      if (dark) {
        document.documentElement.classList.add('dark');
      } else {
        document.documentElement.classList.remove('dark');
      }
    });

    effect(() => {
      const size = this.fontSize();
      localStorage.setItem('fontSize', String(size));
      document.documentElement.style.setProperty('--chat-font-size', size + 'px');
    });
  }

  toggleDark(): void {
    this.darkMode.update(v => !v);
  }

  increaseFontSize(): void {
    this.fontSize.update(v => Math.min(v + 1, 22));
  }

  decreaseFontSize(): void {
    this.fontSize.update(v => Math.max(v - 1, 12));
  }
}
