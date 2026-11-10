import { createRoot } from 'react-dom/client';

import { AdminPlacesPage } from './AdminPlacesPage';

const mountEl = document.getElementById('admin-places');
if (mountEl) createRoot(mountEl).render(<AdminPlacesPage />);
