import { Routes } from '@angular/router';
import { ChatPageComponent } from './pages/chat-page/chat-page.component';

export const CHAT_ROUTES: Routes = [
  { path: '', component: ChatPageComponent },
  { path: ':id', component: ChatPageComponent },
];
