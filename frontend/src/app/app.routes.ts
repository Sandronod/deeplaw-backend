import { Routes } from '@angular/router';
import { authGuard } from './core/guards/auth.guard';

export const routes: Routes = [
  {
    path: '',
    loadComponent: () =>
      import('./features/landing/landing-page.component').then(m => m.LandingPageComponent),
  },
  {
    path: 'chats',
    canActivate: [authGuard],
    loadChildren: () =>
      import('./features/chat/chat.routes').then(m => m.CHAT_ROUTES),
  },
  {
    path: 'fullcase/:type/:caseId',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./features/fullcase/fullcase-page.component').then(m => m.FullcasePageComponent),
  },
  {
    path: 'fullcase/:caseId',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./features/fullcase/fullcase-page.component').then(m => m.FullcasePageComponent),
  },
  { path: '**', redirectTo: '/' },
];
