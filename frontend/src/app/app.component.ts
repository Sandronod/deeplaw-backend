import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { SettingsService } from './core/services/settings.service';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet],
  template: `<router-outlet />`,
  styles: [`
    :host { display: block; height: 100vh; }
  `],
})
export class AppComponent {
  constructor(settings: SettingsService) {}
}
