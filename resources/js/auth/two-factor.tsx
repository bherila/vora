import { TwoFactorForm } from 'bwh-auth';
import { createRoot } from 'react-dom/client';

import { getAuthComponents } from './shared-components';

function TwoFactorPage({ attemptToken, appEnv }: { attemptToken: string; appEnv: string }) {
  return (
    <div className="flex min-h-screen flex-col items-center justify-center p-4">
      <div className="w-full max-w-md">
        <TwoFactorForm
          components={getAuthComponents()}
          attemptToken={attemptToken}
          appEnv={appEnv}
          onSuccess={(result) => {
            if (result.redirect) {
              window.location.href = result.redirect;
            } else {
              window.location.href = '/dashboard';
            }
          }}
        />
      </div>
    </div>
  );
}

const mountEl = document.getElementById('two-factor');
if (mountEl) {
  const attemptToken = mountEl.dataset.attemptToken ?? '';
  const appEnv = mountEl.dataset.appEnv ?? '';
  createRoot(mountEl).render(<TwoFactorPage attemptToken={attemptToken} appEnv={appEnv} />);
}
