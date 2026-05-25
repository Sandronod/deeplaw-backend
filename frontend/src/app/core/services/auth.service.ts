import { Injectable, signal, computed } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { tap } from 'rxjs/operators';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

export interface AuthUser {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  phone: string | null;
}

const TOKEN_KEY = 'auth_token';
const USER_KEY  = 'auth_user';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private base = environment.apiUrl;

  readonly user  = signal<AuthUser | null>(this.loadUser());
  readonly token = signal<string | null>(localStorage.getItem(TOKEN_KEY));

  readonly isAuthenticated = computed(() => !!this.token());
  readonly fullName        = computed(() => {
    const u = this.user();
    return u ? `${u.first_name} ${u.last_name}` : '';
  });

  constructor(private http: HttpClient, private router: Router) {}

  register(data: {
    first_name: string; last_name: string;
    email: string; phone: string;
    password: string; password_confirmation: string;
  }): Observable<{ user: AuthUser; token: string }> {
    return this.http.post<{ user: AuthUser; token: string }>(
      `${this.base}/auth/register`, data
    ).pipe(tap(res => this.persist(res)));
  }

  login(data: { email: string; password: string }): Observable<{ user: AuthUser; token: string }> {
    return this.http.post<{ user: AuthUser; token: string }>(
      `${this.base}/auth/login`, data
    ).pipe(tap(res => this.persist(res)));
  }

  logout(): void {
    this.http.post(`${this.base}/auth/logout`, {}).subscribe();
    this.clear();
    this.router.navigate(['/']);
  }

  private persist(res: { user: AuthUser; token: string }): void {
    localStorage.setItem(TOKEN_KEY, res.token);
    localStorage.setItem(USER_KEY, JSON.stringify(res.user));
    this.token.set(res.token);
    this.user.set(res.user);
  }

  private clear(): void {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
    this.token.set(null);
    this.user.set(null);
  }

  private loadUser(): AuthUser | null {
    try {
      const raw = localStorage.getItem(USER_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  }
}
