/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./views/**/*.php', './src/controllers/**/*.php', './src/services/commands/**/*.php', './public/assets/js/**/*.js'],
  theme: {
    extend: {
      colors: {
        blurple: '#5865f2',
        'blurple-dark': '#4752c4',
        discord: {
          100: '#f2f3f5',
          200: '#dbdee1',
          300: '#b5bac1',
          400: '#80848e',
          500: '#4e5058',
          600: '#3f4147',
          700: '#383a40',
          750: '#313338',
          800: '#2b2d31',
          850: '#292b2f',
          900: '#1e1f22',
          950: '#111214',
        },
      },
      fontFamily: {
        sans: ['"gg sans"', '"Helvetica Neue"', 'Helvetica', 'Arial', 'sans-serif'],
      },
    },
  },
  plugins: [],
};