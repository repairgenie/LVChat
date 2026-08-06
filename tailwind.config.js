/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./views/**/*.php', './src/controllers/**/*.php', './src/services/commands/**/*.php', './public/assets/js/**/*.js'],
  theme: {
    extend: {
      colors: {
        blurple: 'rgb(var(--c-blurple) / <alpha-value>)',
        'blurple-dark': 'rgb(var(--c-blurple-dark) / <alpha-value>)',
        sidebar: 'rgb(var(--c-sidebar) / <alpha-value>)',
        'sidebar-hover': 'rgb(var(--c-sidebar-hover) / <alpha-value>)',
        discord: {
          100: 'rgb(var(--c-d-100) / <alpha-value>)',
          200: 'rgb(var(--c-d-200) / <alpha-value>)',
          300: 'rgb(var(--c-d-300) / <alpha-value>)',
          400: 'rgb(var(--c-d-400) / <alpha-value>)',
          500: 'rgb(var(--c-d-500) / <alpha-value>)',
          600: 'rgb(var(--c-d-600) / <alpha-value>)',
          700: 'rgb(var(--c-d-700) / <alpha-value>)',
          750: 'rgb(var(--c-d-750) / <alpha-value>)',
          800: 'rgb(var(--c-d-800) / <alpha-value>)',
          850: 'rgb(var(--c-d-850) / <alpha-value>)',
          900: 'rgb(var(--c-d-900) / <alpha-value>)',
          950: 'rgb(var(--c-d-950) / <alpha-value>)',
        },
      },
      fontFamily: {
        sans: ['var(--font-sans)'],
      },
    },
  },
  plugins: [],
};