import { ChevronDown, Laptop, Menu, Moon, Sun, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { NotificationBell } from '@/community/NotificationBell';
import { Avatar } from '@/components/avatar';
import { fetchWrapper } from '@/fetchWrapper';

type NavbarProps = {
  brand?: NavbarBrand;
  authenticated: boolean;
  navItems: NavLink[];
  adminMenu: NavMenu | null;
  accountMenu: AccountMenu | null;
  guestMenuItems: GuestMenuItem[];
};

type ThemeMode = 'system' | 'dark' | 'light';

export interface NavbarBrand {
  label: string;
  href: string;
}

export interface NavLink {
  label: string;
  href: string;
  badge?: number;
}

export interface GuestMenuItem extends NavLink {
  variant?: 'link' | 'primary';
}

export interface NavMenuLinkItem extends NavLink {
  type: 'link';
}

export interface NavMenuActionItem {
  type: 'action';
  label: string;
  action: 'logout';
}

export type AccountMenuItem = NavMenuLinkItem | NavMenuActionItem;

export interface NavMenu {
  label: string;
  items: NavMenuLinkItem[];
}

export interface AccountMenu {
  label: string;
  avatarUrl?: string | null;
  /** When set, the avatar + name link here (the caret still opens the menu). */
  profileHref?: string;
  items: AccountMenuItem[];
}

function applyTheme(mode: ThemeMode) {
  const root = document.documentElement;
  const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  const isDark = mode === 'dark' || (mode === 'system' && prefersDark);
  root.classList.toggle('dark', isDark);
}

function safeHref(href: string): string {
  return href.startsWith('/') && !href.startsWith('//') ? href : '#';
}

function navLabel(item: NavLink): string {
  return typeof item.badge === 'number' && item.badge > 0 ? `${item.label} (${item.badge})` : item.label;
}

function dropdownLinkClassName(): string {
  return 'block px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-[#262625]';
}

export default function Navbar({
  brand = { label: '', href: '/' },
  authenticated,
  navItems,
  adminMenu,
  accountMenu,
  guestMenuItems,
}: NavbarProps) {
  const [userMenuOpen, setUserMenuOpen] = useState(false);
  const [adminMenuOpen, setAdminMenuOpen] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
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
    <nav className='relative mx-auto max-w-7xl px-4 py-3 flex items-center justify-between gap-4'>
      {/* Left: Branding + Main nav */}
      <div className='flex items-center gap-3 md:gap-6'>
        {authenticated && (
          <button
            type='button'
            className='md:hidden -ml-1 rounded-md p-1 hover:bg-gray-50 dark:hover:bg-[#1f1f1e]'
            onClick={() => setMobileOpen((v) => !v)}
            aria-expanded={mobileOpen}
            aria-label='Toggle navigation menu'
          >
            {mobileOpen ? <X className='h-5 w-5' /> : <Menu className='h-5 w-5' />}
          </button>
        )}
        <a href={safeHref(brand.href)} className='select-none'>
          <h1 className='text-lg font-semibold tracking-tight'>{brand.label}</h1>
        </a>
        <ul className='hidden md:flex items-center gap-4 text-sm'>
          {navItems.map((item) => (
            <li key={`${item.href}:${item.label}`}>
              <a className='hover:underline underline-offset-4' href={safeHref(item.href)}>
                {navLabel(item)}
              </a>
            </li>
          ))}
          {adminMenu && (
            <li className='relative' ref={adminMenuRef}>
              <button
                type='button'
                className='flex items-center gap-1 hover:underline underline-offset-4'
                onClick={() => setAdminMenuOpen((v) => !v)}
                aria-expanded={adminMenuOpen}
              >
                {adminMenu.label} <ChevronDown className='w-3 h-3' />
              </button>
              {adminMenuOpen && (
                <div className='absolute left-0 top-full mt-1 w-40 rounded-md border border-gray-200 bg-white shadow-lg dark:border-[#3E3E3A] dark:bg-[#1a1a19] z-50'>
                  {adminMenu.items.map((item) => (
                    <a
                      key={`${item.href}:${item.label}`}
                      href={safeHref(item.href)}
                      className={dropdownLinkClassName()}
                      onClick={() => setAdminMenuOpen(false)}
                    >
                      {item.label}
                    </a>
                  ))}
                </div>
              )}
            </li>
          )}
        </ul>
      </div>

      {/* Right: Auth links + Theme toggle */}
      <div className='flex items-center gap-3'>
        {authenticated && <NotificationBell />}
        {!authenticated ? (
          <div className='flex items-center gap-2 text-sm'>
            {guestMenuItems.map((item) => (
              <a
                key={`${item.href}:${item.label}`}
                href={safeHref(item.href)}
                className={item.variant === 'primary'
                  ? 'rounded-md bg-foreground px-3 py-1.5 text-background hover:opacity-90'
                  : 'hover:underline underline-offset-4'}
              >
                {item.label}
              </a>
            ))}
          </div>
        ) : (
          <div className='relative flex items-center gap-1' ref={userMenuRef}>
            {accountMenu?.profileHref ? (
              <a
                href={safeHref(accountMenu.profileHref)}
                className='flex items-center gap-2 text-sm hover:underline underline-offset-4'
              >
                <Avatar name={accountMenu?.label ?? ''} src={accountMenu?.avatarUrl} sizeClassName='h-7 w-7' />
                <span className='hidden sm:inline max-w-[10rem] truncate'>{accountMenu?.label ?? ''}</span>
              </a>
            ) : (
              <span className='flex items-center gap-2 text-sm'>
                <Avatar name={accountMenu?.label ?? ''} src={accountMenu?.avatarUrl} sizeClassName='h-7 w-7' />
                <span className='hidden sm:inline max-w-[10rem] truncate'>{accountMenu?.label ?? ''}</span>
              </span>
            )}
            <button
              type='button'
              className='rounded-md p-1 hover:bg-gray-50 dark:hover:bg-[#1f1f1e]'
              onClick={() => setUserMenuOpen((v) => !v)}
              aria-expanded={userMenuOpen}
              aria-label='Account menu'
            >
              <ChevronDown className='w-3 h-3' />
            </button>
            {userMenuOpen && (
              <div className='absolute right-0 top-full mt-1 w-44 rounded-md border border-gray-200 bg-white shadow-lg dark:border-[#3E3E3A] dark:bg-[#1a1a19] z-50'>
                {accountMenu?.items.map((item) => (
                  item.type === 'link' ? (
                    <a
                      key={`${item.href}:${item.label}`}
                      href={safeHref(item.href)}
                      className={dropdownLinkClassName()}
                      onClick={() => setUserMenuOpen(false)}
                    >
                      {item.label}
                    </a>
                  ) : (
                    <button
                      key={`${item.action}:${item.label}`}
                      type='button'
                      className='w-full text-left px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-[#262625]'
                      onClick={() => void handleLogout()}
                    >
                      {item.label}
                    </button>
                  )
                ))}
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

      {/* Mobile navigation drawer: the desktop nav list is hidden below md, so
          this is the only way authenticated users reach the app sections on a
          phone. */}
      {authenticated && mobileOpen && (
        <div className='absolute left-0 right-0 top-full z-50 border-b border-gray-200 bg-white shadow-lg md:hidden dark:border-[#3E3E3A] dark:bg-[#1a1a19]'>
          <ul className='flex flex-col py-2'>
            {navItems.map((item) => (
              <li key={`m:${item.href}:${item.label}`}>
                <a
                  className='block px-4 py-2.5 text-sm hover:bg-gray-50 dark:hover:bg-[#262625]'
                  href={safeHref(item.href)}
                  onClick={() => setMobileOpen(false)}
                >
                  {navLabel(item)}
                </a>
              </li>
            ))}
            {adminMenu && (
              <li className='mt-1 border-t border-gray-200 pt-1 dark:border-[#3E3E3A]'>
                <p className='px-4 pb-1 pt-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400'>{adminMenu.label}</p>
                {adminMenu.items.map((item) => (
                  <a
                    key={`m:${item.href}:${item.label}`}
                    className='block px-4 py-2.5 text-sm hover:bg-gray-50 dark:hover:bg-[#262625]'
                    href={safeHref(item.href)}
                    onClick={() => setMobileOpen(false)}
                  >
                    {item.label}
                  </a>
                ))}
              </li>
            )}
            {accountMenu && (
              <li className='mt-1 border-t border-gray-200 pt-1 dark:border-[#3E3E3A]'>
                <p className='px-4 pb-1 pt-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400'>{accountMenu.label}</p>
                {accountMenu.items.map((item) => (
                  item.type === 'link' ? (
                    <a
                      key={`m:${item.href}:${item.label}`}
                      className='block px-4 py-2.5 text-sm hover:bg-gray-50 dark:hover:bg-[#262625]'
                      href={safeHref(item.href)}
                      onClick={() => setMobileOpen(false)}
                    >
                      {item.label}
                    </a>
                  ) : (
                    <button
                      key={`m:${item.action}:${item.label}`}
                      type='button'
                      className='block w-full px-4 py-2.5 text-left text-sm hover:bg-gray-50 dark:hover:bg-[#262625]'
                      onClick={() => void handleLogout()}
                    >
                      {item.label}
                    </button>
                  )
                ))}
              </li>
            )}
          </ul>
        </div>
      )}
    </nav>
  );
}
