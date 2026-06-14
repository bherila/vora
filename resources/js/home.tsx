import './bootstrap';

import React from 'react';
import { createRoot } from 'react-dom/client';

import MainTitle from '@/components/MainTitle';

function Home() {
  return (
    <div className="max-w-6xl mx-auto px-4 py-8">
      <div className="mb-8">
        <MainTitle>Welcome to Your New Project</MainTitle>
        <p className="text-muted-foreground mt-2 max-w-2xl">
          Start building your application by adding components and routes.
        </p>
      </div>
    </div>
  );
}

const homeElement = document.getElementById('home');
if (homeElement) {
  createRoot(homeElement).render(<Home />);
}
