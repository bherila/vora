import { Check, X } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

export interface OnboardingSteps {
  has_avatar: boolean;
  has_interests: boolean;
  is_following: boolean;
  has_posted: boolean;
}

interface StepDef {
  key: keyof OnboardingSteps;
  label: string;
  href?: string;
  cta?: string;
}

const STEPS: StepDef[] = [
  { key: 'has_avatar', label: 'Add a profile photo', href: '/user/settings', cta: 'Open settings' },
  { key: 'has_interests', label: 'Choose your interests', href: '/user/settings', cta: 'Open settings' },
  { key: 'is_following', label: 'Follow a few people', href: '/users', cta: 'Browse users' },
  { key: 'has_posted', label: 'Share your first post' },
];

const DISMISS_KEY = 'onboarding-dismissed';

interface OnboardingChecklistProps {
  steps: OnboardingSteps;
}

/**
 * First-run checklist shown at the top of the feed while any step is incomplete.
 * The server only sends `steps` when work remains (it sends null once done), and
 * the user can also dismiss it permanently — a guide, never a nag.
 */
export function OnboardingChecklist({ steps }: OnboardingChecklistProps) {
  const [dismissed, setDismissed] = useState(() => localStorage.getItem(DISMISS_KEY) === '1');
  if (dismissed) return null;

  const dismiss = () => {
    localStorage.setItem(DISMISS_KEY, '1');
    setDismissed(true);
  };

  const done = STEPS.filter((step) => steps[step.key]).length;

  return (
    <Card>
      <CardHeader className="flex-row items-start justify-between gap-3 space-y-0">
        <div>
          <CardTitle>Get started</CardTitle>
          <p className="text-sm text-muted-foreground">{done} of {STEPS.length} done — finish setting up your profile.</p>
        </div>
        <Button type="button" size="sm" variant="ghost" onClick={dismiss} aria-label="Dismiss checklist">
          <X className="h-4 w-4" />
        </Button>
      </CardHeader>
      <CardContent>
        <ul className="space-y-2">
          {STEPS.map((step) => {
            const complete = steps[step.key];
            return (
              <li key={step.key} className="flex items-center justify-between gap-3">
                <span className="flex items-center gap-2 text-sm">
                  <span
                    aria-hidden="true"
                    className={`inline-flex h-5 w-5 items-center justify-center rounded-full border ${
                      complete ? 'border-primary bg-primary text-primary-foreground' : 'border-muted-foreground/40'
                    }`}
                  >
                    {complete && <Check className="h-3 w-3" />}
                  </span>
                  <span className={complete ? 'text-muted-foreground line-through' : ''}>{step.label}</span>
                </span>
                {!complete && step.href && (
                  <a className="text-sm font-medium underline underline-offset-4" href={step.href}>{step.cta}</a>
                )}
              </li>
            );
          })}
        </ul>
      </CardContent>
    </Card>
  );
}
