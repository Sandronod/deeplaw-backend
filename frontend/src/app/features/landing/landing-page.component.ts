import { Component, OnInit, OnDestroy, signal, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthService } from '../../core/services/auth.service';

type Modal = 'none' | 'login' | 'register';

@Component({
  selector: 'app-landing-page',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <!-- ═══════════════════════ NAVBAR ═══════════════════════ -->
    <nav class="fixed top-0 inset-x-0 z-50 flex items-center justify-between
                px-6 sm:px-10 py-4
                bg-white/80 dark:bg-gray-950/80 backdrop-blur-md
                border-b border-gray-100 dark:border-gray-800">
      <div class="flex items-center gap-2">
        <div class="w-7 h-7 rounded-lg bg-accent flex items-center justify-center">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="white" stroke-width="2.5">
            <path d="M3 6h18M3 12h18M3 18h18"/>
          </svg>
        </div>
        <span class="font-bold text-gray-900 dark:text-white text-sm tracking-tight">
          LexAI
        </span>
      </div>
      <div class="flex items-center gap-2">
        <button (click)="openModal('login')"
          class="px-4 py-1.5 text-sm font-medium text-gray-600 dark:text-gray-300
                 hover:text-gray-900 dark:hover:text-white transition-colors">
          შესვლა
        </button>
        <button (click)="openModal('register')"
          class="px-4 py-1.5 rounded-lg bg-accent text-white text-sm font-medium
                 hover:bg-accent-hover transition-colors shadow-sm">
          რეგისტრაცია
        </button>
      </div>
    </nav>

    <!-- ═══════════════════════ HERO SLIDER ═══════════════════════ -->
    <section class="relative min-h-screen overflow-hidden bg-gray-950 pt-16">

      <!-- Animated gradient background -->
      <div class="absolute inset-0 pointer-events-none">
        <div class="absolute -top-32 -left-32 w-96 h-96 rounded-full
                    bg-accent/20 blur-3xl animate-pulse"></div>
        <div class="absolute top-1/2 -right-32 w-80 h-80 rounded-full
                    bg-blue-500/10 blur-3xl animate-pulse"
             style="animation-delay:1s"></div>
        <div class="absolute -bottom-24 left-1/3 w-72 h-72 rounded-full
                    bg-violet-500/10 blur-3xl animate-pulse"
             style="animation-delay:2s"></div>
      </div>

      <!-- Slides -->
      <div class="relative z-10 flex flex-col items-center justify-center
                  min-h-screen px-6 text-center">

        @for (slide of slides; track slide.id) {
          @if (currentSlide() === slide.id) {
            <div class="slide-enter max-w-3xl mx-auto">
              <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full
                          bg-accent/10 border border-accent/20 text-accent text-xs
                          font-medium mb-6">
                <span>{{ slide.badge }}</span>
              </div>
              <h1 class="text-4xl sm:text-6xl font-extrabold text-white leading-tight mb-6
                         tracking-tight">
                {{ slide.title }}
              </h1>
              <p class="text-lg sm:text-xl text-gray-400 max-w-xl mx-auto leading-relaxed mb-10">
                {{ slide.description }}
              </p>
            </div>
          }
        }

        <!-- CTA buttons -->
        <div class="flex flex-col sm:flex-row items-center gap-3 mt-2">
          <button (click)="openModal('register')"
            class="px-8 py-3.5 rounded-xl bg-accent text-white font-semibold
                   hover:bg-accent-hover transition-all duration-200
                   shadow-lg shadow-accent/30 active:scale-95">
            უფასოდ დაიწყე →
          </button>
          <button (click)="openModal('login')"
            class="px-8 py-3.5 rounded-xl border border-gray-700 text-gray-300
                   font-semibold hover:border-gray-500 hover:text-white
                   transition-all duration-200">
            შესვლა
          </button>
        </div>

        <!-- Slide dots -->
        <div class="flex items-center gap-2 mt-16">
          @for (slide of slides; track slide.id) {
            <button (click)="goToSlide(slide.id)"
              class="rounded-full transition-all duration-300"
              [class.w-6]="currentSlide() === slide.id"
              [class.w-2]="currentSlide() !== slide.id"
              [class.h-2]="true"
              [class.bg-accent]="currentSlide() === slide.id"
              [class.bg-gray-600]="currentSlide() !== slide.id">
            </button>
          }
        </div>
      </div>

      <!-- Scroll indicator -->
      <div class="absolute bottom-8 left-1/2 -translate-x-1/2 animate-bounce">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" class="text-gray-600">
          <polyline points="6 9 12 15 18 9"/>
        </svg>
      </div>
    </section>

    <!-- ═══════════════════════ FEATURES ═══════════════════════ -->
    <section class="py-24 bg-white dark:bg-gray-950 px-6">
      <div class="max-w-5xl mx-auto">
        <div class="text-center mb-16">
          <h2 class="text-3xl sm:text-4xl font-bold text-gray-900 dark:text-white mb-4">
            რატომ LexAI?
          </h2>
          <p class="text-gray-500 dark:text-gray-400 max-w-xl mx-auto">
            ხელოვნური ინტელექტი, რომელიც ქართული სასამართლო პრაქტიკას იცნობს
          </p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-8">
          @for (f of features; track f.title) {
            <div class="p-6 rounded-2xl border border-gray-100 dark:border-gray-800
                        hover:border-accent/30 dark:hover:border-accent/30
                        transition-all duration-200 group">
              <div class="w-10 h-10 rounded-xl bg-accent/10 flex items-center
                          justify-center mb-4 group-hover:bg-accent/20 transition-colors">
                <span class="text-xl">{{ f.icon }}</span>
              </div>
              <h3 class="font-semibold text-gray-900 dark:text-white mb-2">{{ f.title }}</h3>
              <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed">{{ f.desc }}</p>
            </div>
          }
        </div>
      </div>
    </section>

    <!-- ═══════════════════════ STATS ═══════════════════════ -->
    <section class="py-16 bg-gray-50 dark:bg-gray-900/50 px-6">
      <div class="max-w-4xl mx-auto grid grid-cols-2 sm:grid-cols-4 gap-8 text-center">
        @for (s of stats; track s.label) {
          <div>
            <div class="text-3xl font-extrabold text-accent mb-1">{{ s.value }}</div>
            <div class="text-sm text-gray-500 dark:text-gray-400">{{ s.label }}</div>
          </div>
        }
      </div>
    </section>

    <!-- ═══════════════════════ CTA BAND ═══════════════════════ -->
    <section class="py-24 px-6 bg-white dark:bg-gray-950 text-center">
      <h2 class="text-3xl font-bold text-gray-900 dark:text-white mb-4">
        მზად ხარ დაიწყო?
      </h2>
      <p class="text-gray-500 dark:text-gray-400 mb-8 max-w-md mx-auto">
        რეგისტრირდი და პირველი კითხვა უფასოდ დასვი
      </p>
      <button (click)="openModal('register')"
        class="px-10 py-4 rounded-xl bg-accent text-white font-bold text-lg
               hover:bg-accent-hover transition-all shadow-xl shadow-accent/20 active:scale-95">
        დარეგისტრირდი
      </button>
    </section>

    <!-- ═══════════════════════ FOOTER ═══════════════════════ -->
    <footer class="py-8 border-t border-gray-100 dark:border-gray-800 text-center
                   text-xs text-gray-400 dark:text-gray-600">
      © {{ year }} LexAI — ქართული სამართლის AI ასისტენტი
    </footer>

    <!-- ═══════════════════════ MODALS ═══════════════════════ -->
    @if (modal() !== 'none') {
      <div class="fixed inset-0 z-50 flex items-center justify-center p-4
                  bg-black/60 backdrop-blur-sm"
           (click)="closeModal()">

        <div class="w-full max-w-md bg-white dark:bg-gray-900 rounded-2xl shadow-2xl
                    border border-gray-100 dark:border-gray-700 overflow-hidden
                    animate-slide-up"
             (click)="$event.stopPropagation()">

          <!-- Modal header -->
          <div class="px-6 pt-6 pb-4 flex items-center justify-between">
            <div>
              <h2 class="text-xl font-bold text-gray-900 dark:text-white">
                {{ modal() === 'login' ? 'შესვლა' : 'რეგისტრაცია' }}
              </h2>
              <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                {{ modal() === 'login' ? 'კეთილი იყოს თქვენი დაბრუნება' : 'გახდი LexAI-ის წევრი' }}
              </p>
            </div>
            <button (click)="closeModal()"
              class="w-8 h-8 rounded-lg flex items-center justify-center
                     text-gray-400 hover:text-gray-700 dark:hover:text-gray-200
                     hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2.5">
                <line x1="18" y1="6" x2="6" y2="18"/>
                <line x1="6" y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>

          <!-- Modal body -->
          <div class="px-6 pb-6">
            @if (modal() === 'register') {
              <form (ngSubmit)="doRegister()" class="space-y-3">
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">სახელი</label>
                    <input [(ngModel)]="reg.first_name" name="first_name" required
                      class="form-input" placeholder="გიორგი">
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">გვარი</label>
                    <input [(ngModel)]="reg.last_name" name="last_name" required
                      class="form-input" placeholder="მამარდაშვილი">
                  </div>
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">ელ. ფოსტა</label>
                  <input [(ngModel)]="reg.email" name="email" type="email" required
                    class="form-input" placeholder="name@example.com">
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">ტელეფონი</label>
                  <input [(ngModel)]="reg.phone" name="phone"
                    class="form-input" placeholder="+995 5XX XXX XXX">
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">პაროლი</label>
                  <input [(ngModel)]="reg.password" name="password" type="password" required
                    class="form-input" placeholder="მინ. 8 სიმბოლო">
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">გაიმეორე პაროლი</label>
                  <input [(ngModel)]="reg.password_confirmation" name="password_confirmation"
                    type="password" required class="form-input" placeholder="••••••••">
                </div>

                @if (formError()) {
                  <p class="text-xs text-red-500 bg-red-50 dark:bg-red-950/30 rounded-lg px-3 py-2">
                    {{ formError() }}
                  </p>
                }

                <button type="submit" [disabled]="loading()"
                  class="w-full py-3 rounded-xl bg-accent text-white font-semibold
                         hover:bg-accent-hover disabled:opacity-60 transition-all
                         shadow-sm shadow-accent/20 mt-1">
                  {{ loading() ? 'იტვირთება...' : 'დარეგისტრირდი' }}
                </button>

                <p class="text-center text-xs text-gray-400">
                  უკვე გაქვს ანგარიში?
                  <button type="button" (click)="openModal('login')"
                    class="text-accent hover:underline font-medium">შესვლა</button>
                </p>
              </form>
            }

            @if (modal() === 'login') {
              <form (ngSubmit)="doLogin()" class="space-y-3">
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">ელ. ფოსტა</label>
                  <input [(ngModel)]="login.email" name="email" type="email" required
                    class="form-input" placeholder="name@example.com">
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">პაროლი</label>
                  <input [(ngModel)]="login.password" name="password" type="password" required
                    class="form-input" placeholder="••••••••">
                </div>

                @if (formError()) {
                  <p class="text-xs text-red-500 bg-red-50 dark:bg-red-950/30 rounded-lg px-3 py-2">
                    {{ formError() }}
                  </p>
                }

                <button type="submit" [disabled]="loading()"
                  class="w-full py-3 rounded-xl bg-accent text-white font-semibold
                         hover:bg-accent-hover disabled:opacity-60 transition-all
                         shadow-sm shadow-accent/20 mt-1">
                  {{ loading() ? 'იტვირთება...' : 'შესვლა' }}
                </button>

                <p class="text-center text-xs text-gray-400">
                  ანგარიში არ გაქვს?
                  <button type="button" (click)="openModal('register')"
                    class="text-accent hover:underline font-medium">რეგისტრაცია</button>
                </p>
              </form>
            }
          </div>
        </div>
      </div>
    }
  `,
  styles: [`
    .form-input {
      width: 100%;
      padding: 0.625rem 0.875rem;
      border-radius: 0.625rem;
      border: 1px solid #e5e7eb;
      background: #f9fafb;
      font-size: 0.875rem;
      color: #111827;
      outline: none;
      transition: border-color 0.15s, box-shadow 0.15s;
    }
    .form-input:focus {
      border-color: var(--color-accent, #6366f1);
      box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-accent, #6366f1) 15%, transparent);
    }
    @media (prefers-color-scheme: dark) {
      .form-input {
        background: #1f2937;
        border-color: #374151;
        color: #f3f4f6;
      }
    }
    .slide-enter {
      animation: slideEnter 0.6s cubic-bezier(0.16, 1, 0.3, 1) both;
    }
    @keyframes slideEnter {
      from { opacity: 0; transform: translateY(24px); }
      to   { opacity: 1; transform: translateY(0); }
    }
  `],
})
export class LandingPageComponent implements OnInit, OnDestroy {
  private auth   = inject(AuthService);
  private router = inject(Router);

  modal     = signal<Modal>('none');
  loading   = signal(false);
  formError = signal<string | null>(null);
  currentSlide = signal(0);
  year = new Date().getFullYear();

  private slideTimer: any;

  reg = { first_name: '', last_name: '', email: '', phone: '', password: '', password_confirmation: '' };
  login = { email: '', password: '' };

  readonly slides = [
    {
      id: 0,
      badge: '⚡ AI-powered',
      title: 'ქართული სამართლის AI ასისტენტი',
      description: 'სასამართლო გადაწყვეტილებები, კანონმდებლობა და მაცნეს ბაზა — ერთ ადგილას.',
    },
    {
      id: 1,
      badge: '⚖️ სასამართლო პრაქტიკა',
      title: 'ათასობით გადაწყვეტილება ერთ წამში',
      description: 'უმაღლესი სასამართლოს არქივი AI-ის მეშვეობით ხელმისაწვდომია ნებისმიერ კითხვაზე.',
    },
    {
      id: 2,
      badge: '📋 მაცნე',
      title: 'კანონმდებლობა ჭკვიანი ძებნით',
      description: 'მაცნეს 100,000+ დოკუმენტი სემანტიკური ძებნით — იპოვე რელევანტური მუხლი წამებში.',
    },
  ];

  readonly features = [
    { icon: '🔍', title: 'სემანტიკური ძებნა', desc: 'Vector search + metadata — პოულობს კონტექსტს, არა მხოლოდ სიტყვებს.' },
    { icon: '⚡', title: 'სტრიმინგი', desc: 'GPT-4.1 პასუხს სტრიმინგით გაძლევს — პირველი სიტყვა 1 წამში.' },
    { icon: '📚', title: 'სამი წყარო', desc: 'სასამართლო, კანონმდებლობა, მაცნე — ყველა ერთ პასუხში.' },
    { icon: '🔗', title: 'ლინკები', desc: 'ყოველ პასუხს ახლავს წყაროების ბმულები — verify every claim.' },
    { icon: '💬', title: 'ჩატის ისტორია', desc: 'შენახული ჩატები — გააგრძელე ნებისმიერ დროს.' },
    { icon: '🌙', title: 'Dark mode', desc: 'ღამის რეჟიმი — სასიამოვნო სამუშაო ნებისმიერ გარემოში.' },
  ];

  readonly stats = [
    { value: '100K+', label: 'მაცნეს დოკუმენტი' },
    { value: '50K+',  label: 'სასამართლო საქმე' },
    { value: 'GPT-4.1', label: 'AI მოდელი' },
    { value: '<2s',   label: 'პასუხის დრო' },
  ];

  ngOnInit(): void {
    if (this.auth.isAuthenticated()) {
      this.router.navigate(['/chats']);
      return;
    }
    this.slideTimer = setInterval(() => {
      this.currentSlide.update(s => (s + 1) % this.slides.length);
    }, 4000);
  }

  ngOnDestroy(): void {
    clearInterval(this.slideTimer);
  }

  goToSlide(id: number): void {
    this.currentSlide.set(id);
    clearInterval(this.slideTimer);
    this.slideTimer = setInterval(() => {
      this.currentSlide.update(s => (s + 1) % this.slides.length);
    }, 4000);
  }

  openModal(m: Modal): void {
    this.modal.set(m);
    this.formError.set(null);
  }

  closeModal(): void {
    this.modal.set('none');
    this.formError.set(null);
  }

  doRegister(): void {
    this.loading.set(true);
    this.formError.set(null);
    this.auth.register(this.reg).subscribe({
      next: () => this.router.navigate(['/chats']),
      error: err => {
        this.loading.set(false);
        const errors = err.error?.errors as Record<string, string[]> | undefined;
        const msg = err.error?.message
          ?? (errors ? Object.values(errors)[0]?.[0] : null)
          ?? 'რეგისტრაცია ვერ მოხერხდა.';
        this.formError.set(msg as string);
      },
    });
  }

  doLogin(): void {
    this.loading.set(true);
    this.formError.set(null);
    this.auth.login(this.login).subscribe({
      next: () => this.router.navigate(['/chats']),
      error: err => {
        this.loading.set(false);
        const errors = err.error?.errors as Record<string, string[]> | undefined;
        const msg = err.error?.message
          ?? (errors ? Object.values(errors)[0]?.[0] : null)
          ?? 'შესვლა ვერ მოხერხდა.';
        this.formError.set(msg as string);
      },
    });
  }
}
