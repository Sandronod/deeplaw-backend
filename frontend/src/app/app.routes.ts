import { Routes } from '@angular/router';

export const routes: Routes = [
  {
    path: 'chats',
    loadChildren: () =>
      import('./features/chat/chat.routes').then(m => m.CHAT_ROUTES),
  },
  { path: '', redirectTo: '/chats', pathMatch: 'full' },
  { path: '**', redirectTo: '/chats' },
];
