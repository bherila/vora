import { createRoot } from 'react-dom/client';

import type { AccountMenu, GuestMenuItem, NavbarBrand, NavLink, NavMenu } from '@/components/navbar';
import Navbar from '@/components/navbar';
import { readInitialData } from '@/initialData';

interface NavbarInitialData {
  navbar?: {
    brand?: NavbarBrand;
    authenticated?: boolean;
    requestCount?: number;
    navItems?: NavLink[];
    adminMenu?: NavMenu | null;
    accountMenu?: AccountMenu | null;
    guestMenuItems?: GuestMenuItem[];
  };
}

const mount = document.getElementById('navbar');
if (mount) {
  const { navbar } = readInitialData<NavbarInitialData>();
  createRoot(mount).render(
    <Navbar
      brand={navbar?.brand ?? { label: '', href: '/' }}
      authenticated={navbar?.authenticated === true}
      navItems={navbar?.navItems ?? []}
      adminMenu={navbar?.adminMenu ?? null}
      accountMenu={navbar?.accountMenu ?? null}
      guestMenuItems={navbar?.guestMenuItems ?? []}
    />,
  );
}
