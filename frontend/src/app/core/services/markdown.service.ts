import { Injectable } from '@angular/core';

/**
 * Lightweight Markdown → HTML parser for legal AI assistant responses.
 * Handles: headers, bold/italic, code blocks, lists, blockquotes, links, HR.
 * No external dependencies. Classes use `md-*` prefix for SCSS targeting.
 */
@Injectable({ providedIn: 'root' })
export class MarkdownService {

  parse(raw: string): string {
    if (!raw) return '';

    // 1. Escape HTML (before any processing)
    let s = raw
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');

    // 2. Fenced code blocks (``` ... ```)
    s = s.replace(/```(\w*)\n?([\s\S]*?)```/g, (_, lang, code) => {
      const cls = lang ? ` class="language-${lang}"` : '';
      return `<pre class="md-pre"><code${cls}>${code.trim()}</code></pre>`;
    });

    // 3. Inline code
    s = s.replace(/`([^`\n]+)`/g, '<code class="md-code">$1</code>');

    // 4. Headers (h1–h4)
    s = s.replace(/^#### (.+)$/gm, '<h4 class="md-h4">$1</h4>');
    s = s.replace(/^### (.+)$/gm,  '<h3 class="md-h3">$1</h3>');
    s = s.replace(/^## (.+)$/gm,   '<h2 class="md-h2">$1</h2>');
    s = s.replace(/^# (.+)$/gm,    '<h1 class="md-h1">$1</h1>');

    // 5. Horizontal rule
    s = s.replace(/^---+$/gm, '<hr class="md-hr">');

    // 6. Bold + italic (order: *** before ** before *)
    s = s.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
    s = s.replace(/\*\*(.+?)\*\*/g,      '<strong>$1</strong>');
    s = s.replace(/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/g, '<em>$1</em>');

    // 7. Blockquotes
    s = s.replace(/^&gt;\s?(.+)$/gm, '<blockquote class="md-blockquote">$1</blockquote>');

    // 8. Links — handle after HTML escape (URLs won't have &lt; etc.)
    s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/g,
      '<a href="$2" target="_blank" rel="noopener noreferrer" class="md-link">$1 ↗</a>'
    );

    // 9. Unordered lists — group consecutive bullet lines
    s = s.replace(/((?:^[-*•] .+$\n?)+)/gm, (block) => {
      const items = block.trim().split('\n')
        .map(line => `<li>${line.replace(/^[-*•] /, '')}</li>`)
        .join('');
      return `<ul class="md-ul">${items}</ul>\n`;
    });

    // 10. Ordered lists — group consecutive numbered lines
    s = s.replace(/((?:^\d+\. .+$\n?)+)/gm, (block) => {
      const items = block.trim().split('\n')
        .map(line => `<li>${line.replace(/^\d+\. /, '')}</li>`)
        .join('');
      return `<ol class="md-ol">${items}</ol>\n`;
    });

    // 11. Paragraphs — split on double newlines, wrap non-block content
    const blockTags = /^<(h[1-6]|pre|ul|ol|blockquote|hr)/;
    const parts = s.split(/\n\n+/);
    s = parts
      .map(para => {
        para = para.trim();
        if (!para) return '';
        if (blockTags.test(para)) return para;
        // Single newlines within paragraph → <br>
        return `<p class="md-p">${para.replace(/\n/g, '<br>')}</p>`;
      })
      .filter(Boolean)
      .join('\n');

    return s;
  }
}
