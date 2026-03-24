/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./src/**/*.{html,ts}'],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        // Sidebar — ChatGPT dark
        sidebar:         '#171717',
        'sidebar-hover': '#2a2a2a',
        'sidebar-active':'#2f2f2f',
        'sidebar-border':'#2f2f2f',
        'sidebar-text':  '#ececec',
        // Accent (legal green)
        accent:          '#10a37f',
        'accent-hover':  '#0d9268',
        'accent-light':  '#d1fae5',
        // Messages
        'user-msg':      '#f4f4f4',
        'user-msg-dark': '#2d2d2d',
        // Confidence badges
        'conf-high':    '#16a34a',
        'conf-medium':  '#d97706',
        'conf-low':     '#dc2626',
        'conf-none':    '#6b7280',
      },
      fontFamily: {
        sans: ['Inter', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'sans-serif'],
      },
      maxWidth: {
        chat: '52rem',
      },
      animation: {
        'bounce-dot':  'bounceDot 1.2s ease-in-out infinite',
        'fade-in':     'fadeIn 0.18s ease-out both',
        'slide-up':    'slideUp 0.22s ease-out both',
        'fade-slide':  'fadeSlide 0.25s ease-out both',
        'shimmer':     'shimmer 1.6s ease-in-out infinite',
      },
      keyframes: {
        bounceDot: {
          '0%, 80%, 100%': { transform: 'translateY(0)',    opacity: '0.4' },
          '40%':           { transform: 'translateY(-5px)', opacity: '1'   },
        },
        fadeIn: {
          from: { opacity: '0' },
          to:   { opacity: '1' },
        },
        slideUp: {
          from: { opacity: '0', transform: 'translateY(6px)' },
          to:   { opacity: '1', transform: 'translateY(0)'   },
        },
        fadeSlide: {
          from: { opacity: '0', transform: 'translateY(10px)' },
          to:   { opacity: '1', transform: 'translateY(0)'    },
        },
        shimmer: {
          '0%':   { backgroundPosition: '-200% center' },
          '100%': { backgroundPosition:  '200% center' },
        },
      },
      transitionTimingFunction: {
        'spring': 'cubic-bezier(0.34, 1.56, 0.64, 1)',
      },
    },
  },
  plugins: [],
};
