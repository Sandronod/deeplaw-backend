/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./src/**/*.{html,ts}'],
  theme: {
    extend: {
      colors: {
        sidebar: '#171717',
        'sidebar-hover': '#2a2a2a',
        'sidebar-active': '#3e3e3e',
        'sidebar-border': '#2f2f2f',
        accent: '#10a37f',
        'accent-hover': '#0d9268',
        'user-msg': '#f4f4f4',
      },
      fontFamily: {
        sans: ['Inter', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'sans-serif'],
      },
      maxWidth: {
        chat: '48rem',
      },
      animation: {
        'bounce-dot': 'bounceDot 1.2s ease-in-out infinite',
      },
      keyframes: {
        bounceDot: {
          '0%, 80%, 100%': { transform: 'translateY(0)', opacity: '0.4' },
          '40%': { transform: 'translateY(-6px)', opacity: '1' },
        },
      },
    },
  },
  plugins: [],
};
