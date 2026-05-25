import { Injectable, signal } from '@angular/core';

@Injectable({ providedIn: 'root' })
export class CaseModalService {
  readonly caseId   = signal<number | null>(null);
  readonly caseType = signal<string | null>(null);
  readonly isOpen   = signal(false);

  open(caseId: number, caseType?: string | null): void {
    this.caseId.set(caseId);
    this.caseType.set(caseType ?? null);
    this.isOpen.set(true);
  }

  close(): void {
    this.isOpen.set(false);
    this.caseId.set(null);
    this.caseType.set(null);
  }
}
