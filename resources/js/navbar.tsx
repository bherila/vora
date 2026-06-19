import { createRoot } from 'react-dom/client';

import Navbar from '@/components/navbar';
import { readInitialData } from '@/initialData';

interface NavbarInitialData {
  navbar?: {
    authenticated?: boolean;
    isAdmin?: boolean;
    requestCount?: number;
  };
}

const mount = document.getElementById('navbar');
if (mount) {
  const { navbar } = readInitialData<NavbarInitialData>();
  createRoot(mount).render(
    <Navbar
      authenticated={navbar?.authenticated === true}
      isAdmin={navbar?.isAdmin === true}
      requestCount={typeof navbar?.requestCount === 'number' ? navbar.requestCount : 0}
    />,
  );
}
