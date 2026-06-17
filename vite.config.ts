import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
  plugins: [
    laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.jsx',
                'resources/js/home.tsx',
                'resources/js/navbar.tsx',
                'resources/js/auth/login.tsx',
                'resources/js/auth/register.tsx',
                'resources/js/auth/two-factor.tsx',
                'resources/js/auth/verify-email.tsx',
                'resources/js/auth/pending-approval.tsx',
                'resources/js/auth/forgot-password.tsx',
                'resources/js/auth/reset-password.tsx',
                'resources/js/auth/user-settings.tsx',
                'resources/js/admin/users.tsx',
                'resources/js/admin/audit-log.tsx',
                'resources/js/admin/interests.tsx',
                'resources/js/admin/media.tsx',
                'resources/js/admin/stories.tsx',
                'resources/js/admin/static-pages.tsx',
                'resources/js/user/media.tsx',
                'resources/js/user/characters.tsx',
                'resources/js/user/explore.tsx',
                'resources/js/user/follow-directory.tsx',
                'resources/js/user/follow-profile.tsx',
                'resources/js/user/follow-requests.tsx',
                'resources/js/media/view.tsx',
                'resources/js/stories/page.tsx',
                'resources/js/stories/reader.tsx',
            ],
      refresh: true,
    }),
    react(),
    tailwindcss(),
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'resources/js'),
      'react': path.resolve(__dirname, 'node_modules/react'),
      'react-dom': path.resolve(__dirname, 'node_modules/react-dom'),
    },
  },
  build: {
    rollupOptions: {
      external: (id) => /\.test\.[tj]sx?$/.test(id) || id.includes('/__tests__/'),
    }
  }
});
