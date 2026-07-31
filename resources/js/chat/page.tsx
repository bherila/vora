import { createRoot } from 'react-dom/client';

import { ChatPage } from '@/chat/ChatPage';

const mount = document.getElementById('chat-page');
if (mount) {
  createRoot(mount).render(<ChatPage />);
}
