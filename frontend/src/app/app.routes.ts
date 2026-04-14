import { Routes } from '@angular/router';

export const routes: Routes = [
  {
    path: 'chats',
    loadChildren: () =>
      import('./features/chat/chat.routes').then(m => m.CHAT_ROUTES),
  },
  {
    path: 'fullcase/:type/:caseId',
    loadComponent: () =>
      import('./features/fullcase/fullcase-page.component').then(m => m.FullcasePageComponent),
  },
  { path: '', redirectTo: '/chats', pathMatch: 'full' },
  { path: '**', redirectTo: '/chats' },
];
