import { ChevronDown, Laptop, Moon, Sun } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { fetchWrapper } from '@/fetchWrapper';

type NavbarProps = {
  authenticated: boolean;
  isAdmin: boolean;
  requestCount: number;
};

type ThemeMode = 'system' | 'dark' | 'light';

function applyTheme(mode: ThemeMode) {
  const root = document.documentElement;
  const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  const isDark = mode === 'dark' || (mode === 'system' && prefersDark);
  root.classList.toggle('dark', isDark);
}

export default function Navbar({ authenticated, isAdmin, requestCount }: NavbarProps) {
  const [userMenuOpen, setUserMenuOpen] = useState(false);
  const [adminMenuOpen, setAdminMenuOpen] = useState(false);
  const userMenuRef = useRef<HTMLDivElement | null>(null);
  const adminMenuRef = useRef<HTMLLIElement | null>(null);
  const [theme, setTheme] = useState<ThemeMode>(() => (localStorage.getItem('theme') as ThemeMode) || 'system');

  useEffect(() => {
    applyTheme(theme);
    localStorage.setItem('theme', theme);
  }, [theme]);

  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (userMenuRef.current && !userMenuRef.current.contains(e.target as Node)) setUserMenuOpen(false);
      if (adminMenuRef.current && !adminMenuRef.current.contains(e.target as Node)) setAdminMenuOpen(false);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, []);

  useEffect(() => {
    const mq = window.matchMedia('(prefers-color-scheme: dark)');
    const onChange = () => {
      const saved = (localStorage.getItem('theme') as ThemeMode) || 'system';
      if (saved === 'system') applyTheme('system');
    };
    mq.addEventListener('change', onChange);
    return () => mq.removeEventListener('change', onChange);
  }, []);

  const handleLogout = async () => {
    try {
      await fetchWrapper.post('/logout', {});
    } finally {
      window.location.href = '/login';
    }
  };

  return (
    <nav className='mx-auto max-w-7xl px-4 py-3 flex items-center justify-between gap-4'>
      {/* Left: Branding + Main nav */}
      <div className='flex items-center gap-6'>
        <a href='/' className='select-none'>
          <h1 className='text-lg font-semibold tracking-tight'>Application</h1>
        </a>
        <ul className='hidden md:flex items-center gap-4 text-sm'>
          <li><a className='hover:underline underline-offset-4' href='/'>Home</a></li>
          {authenticated && (
            <li><a className='hover:underline underline-offset-4' href='/dashboard'>Dashboard</a></li>
          )}
          {authenticated && (
            <li><a className='hover:underline underline-offset-4' href='/media'>Media</a></li>
          )}
          {authenticated && (
            <li><a className='hover:underline underline-offset-4' href='/characters'>Characters</a></li>
          )}
          {authenticated && (
            <li><a className='hover:underline underline-offset-4' href='/stories'>Stories</a></li>
          )}
          {authenticated && (
            <li><a className='hover:underline underline-offset-4' href='/explore'>Explore</a></li>
          )}
          {authenticated && (
            <li><a className='hover:underline underline-offset-4' href='/users'>Users</a></li>
          )}
          {authenticated && (
            <li>
              <a className='hover:underline underline-offset-4' href='/users/follow-requests'>
                Requests{requestCount > 0 ? ` (${requestCount})` : ''}
              </a>
            </li>
          )}
          {authenticated && isAdmin && (
            <li className='relative' ref={adminMenuRef}>
              <button
                type='button'
                className='flex items-center gap-1 hover:underline underline-offset-4'
                onClick={() => setAdminMenuOpen((v) => !v)}
                aria-expanded={adminMenuOpen}
              >
                Admin <ChevronDown className='w-3 h-3' />
              </button>
              {adminMenuOpen && (
                <div className='absolute left-0 top-full mt-1 w-40 rounded-md border border-gray-200 bg-white shadow-lg dark:border-[#3E3E3A] dark:bg-[#1a1a19] z-50'>
                  <a
                    href='/admin/users'
                    className='block px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-[#262625]'
                    onClick={() => setAdminMenuOpen(false)}
                >
                    Users
                  </a>
                  <a
                    href='/admin/interests'
                    className='block px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-[#262625]'
                    onClick={() => setAdminMenuOpen(false)}
                  >
                    Interests
                  </a>
                  <a
                    href='/admin/media'
                    className='block px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-[#262625]'
                    onClick={() => setAdminMenuOpen(false)}
                  >
                    Media review
                  </a>
                  <a
                    href='/admin/stories'
                    className='block px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-[#262625]'
                    onClick={() => setAdminMenuOpen(false)}
                  >
                    Story review
                  </a>
                  <a
                    href='/admin/pages'
                    className='block px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-[#262625]'
                    onClick={() => setAdminMenuOpen(false)}
                  >
                    Static pages
                  </a>
                  <a
                    href='/admin/audit-log'
                    className='block px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-[#262625]'
                    onClick={() => setAdminMenuOpen(false)}
                  >
                    Audit log
                  </a>
                </div>
              )}
            </li>
          )}
        </ul>
      </div>

      {/* Right: Auth links + Theme toggle */}
      <div className='flex items-center gap-3'>
        {!authenticated ? (
          <div className='flex items-center gap-2 text-sm'>
            <a href='/login' className='hover:underline underline-offset-4'>Log in</a>
            <a
              href='/register'
              className='rounded-md bg-foreground px-3 py-1.5 text-background hover:opacity-90'
            >
              Sign up
            </a>
          </div>
        ) : (
          <div className='relative' ref={userMenuRef}>
            <button
              type='button'
              className='flex items-center gap-1 text-sm hover:underline underline-offset-4'
              onClick={() => setUserMenuOpen((v) => !v)}
              aria-expanded={userMenuOpen}
            >
              Account <ChevronDown className='w-3 h-3' />
            </button>
            {userMenuOpen && (
              <div className='absolute right-0 top-full mt-1 w-44 rounded-md border border-gray-200 bg-white shadow-lg dark:border-[#3E3E3A] dark:bg-[#1a1a19] z-50'>
                <a
                  href='/user/settings'
                  className='block px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-[#262625]'
                  onClick={() => setUserMenuOpen(false)}
                >
                  Settings
                </a>
                <button
                  type='button'
                  className='w-full text-left px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-[#262625]'
                  onClick={() => void handleLogout()}
                >
                  Log out
                </button>
              </div>
            )}
          </div>
        )}

        {/* Tri-state theme toggle */}
        <div className='inline-flex items-center overflow-hidden rounded-md border border-gray-200 dark:border-[#3E3E3A]'>
          <button
            type='button'
            onClick={() => setTheme('system')}
            className={`px-2 py-1.5 hover:bg-gray-50 dark:hover:bg-[#1f1f1e] ${
              theme === 'system'
                ? 'bg-gray-900 text-white dark:bg-[#262625] dark:text-gray-50'
                : 'text-gray-500 dark:text-gray-400'
            }`}
            title='System'
            aria-pressed={theme === 'system'}
          >
            <Laptop className='w-4 h-4' />
          </button>
          <button
            type='button'
            onClick={() => setTheme('dark')}
            className={`px-2 py-1.5 hover:bg-gray-50 dark:hover:bg-[#1f1f1e] ${
              theme === 'dark'
                ? 'bg-gray-900 text-white dark:bg-[#262625] dark:text-gray-50'
                : 'text-gray-500 dark:text-gray-400'
            }`}
            title='Dark'
            aria-pressed={theme === 'dark'}
          >
            <Moon className='w-4 h-4' />
          </button>
          <button
            type='button'
            onClick={() => setTheme('light')}
            className={`px-2 py-1.5 hover:bg-gray-50 dark:hover:bg-[#1f1f1e] ${
              theme === 'light'
                ? 'bg-gray-900 text-white dark:bg-[#262625] dark:text-gray-50'
                : 'text-gray-500 dark:text-gray-400'
            }`}
            title='Light'
            aria-pressed={theme === 'light'}
          >
            <Sun className='w-4 h-4' />
          </button>
        </div>
      </div>
    </nav>
  );
}
