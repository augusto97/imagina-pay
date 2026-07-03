/** @type {import('tailwindcss').Config} */
export default {
  prefix: 'impay-',
  important: '#impay-root',
  content: ['./src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        accent: {
          DEFAULT: '#4F46E5',
          hover: '#4338CA',
          soft: '#EEF2FF',
        },
        ink: '#18181B',
        muted: '#71717A',
        line: '#E4E4E7',
        canvas: '#FAFAFA',
        ok: '#059669',
        warn: '#D97706',
        bad: '#DC2626',
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'sans-serif'],
      },
      boxShadow: {
        card: '0 1px 2px rgb(0 0 0 / 0.04)',
      },
      borderRadius: {
        card: '12px',
        control: '10px',
      },
    },
  },
  plugins: [],
};
