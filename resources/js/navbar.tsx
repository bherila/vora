import { createRoot } from 'react-dom/client';

import Navbar from '@/components/navbar';

const mount = document.getElementById('navbar');
if (mount) {
  const script = document.getElementById('navbar-initial-data');
  const payload = script?.textContent?.trim();

  let authenticated = false;
  let isAdmin = false;
  let requestCount = 0;

  if (payload) {
    try {
      const appData = JSON.parse(payload) as Record<string, unknown>;
      if (typeof appData === 'object' && appData !== null) {
        authenticated = appData.authenticated === true;
        isAdmin = appData.isAdmin === true;
        requestCount = typeof appData.requestCount === 'number' ? appData.requestCount : 0;
      }
    } catch {
      authenticated = false;
      isAdmin = false;
    }
  }

  createRoot(mount).render(<Navbar authenticated={authenticated} isAdmin={isAdmin} requestCount={requestCount} />);
}
