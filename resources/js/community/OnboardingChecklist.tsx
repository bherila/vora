import { Check, X } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardAction, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

import { communityApi } from './api';

export interface OnboardingSteps {
  has_avatar: boolean;
  has_interests: boolean;
  is_following: boolean;
  has_posted: boolean;
}

export interface OnboardingData {
  display_name: string;
  has_personas: boolean;
  steps: OnboardingSteps;
}

interface StepDef {
  key: keyof OnboardingSteps;
  label: string;
  why: string;
  href?: string;
  cta: string;
}

const STEPS: StepDef[] = [
  {
    key: 'has_avatar',
    label: 'Add a profile photo',
    why: 'Help people recognize you when you follow them.',
    href: '/me',
    cta: 'Edit your profile photo',
  },
  {
    key: 'has_interests',
    label: 'Choose your interests',
    why: 'Share what you enjoy and discover common ground.',
    href: '/me',
    cta: 'Choose your interests',
  },
  {
    key: 'is_following',
    label: 'Follow a few people',
    why: 'Build a focused Following feed.',
    href: '/users',
    cta: 'Browse people',
  },
  {
    key: 'has_posted',
    label: 'Share your first post',
    why: 'Introduce yourself or share what is on your mind.',
    cta: 'Write your first post',
  },
];

function focusComposer(): void {
  const composer = document.getElementById('post-composer-body');
  if (!(composer instanceof HTMLElement)) return;

  composer.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
  composer.focus();
}

interface OnboardingChecklistProps {
  onboarding: OnboardingData;
}

/**
 * First-run guide shown at the top of the feed while required setup remains.
 * Dismissal is persisted on the account so the guide stays gone across devices.
 */
export function OnboardingChecklist({ onboarding }: OnboardingChecklistProps) {
  const [dismissed, setDismissed] = useState(false);
  const [dismissing, setDismissing] = useState(false);
  const [dismissError, setDismissError] = useState('');
  const { display_name: displayName, has_personas: hasPersonas, steps } = onboarding;

  if (dismissed) return null;

  const dismiss = async (): Promise<void> => {
    setDismissing(true);
    setDismissError('');

    try {
      await communityApi.dismissOnboarding();
      setDismissed(true);
    } catch {
      setDismissError('Could not dismiss the guide. Please try again.');
      setDismissing(false);
    }
  };

  const done = STEPS.filter((step) => steps[step.key]).length;

  return (
    <Card>
      <CardHeader>
        <div className="space-y-1">
          <CardTitle>
            <h2>Welcome to Vora, {displayName}!</h2>
          </CardTitle>
          <p className="text-sm text-muted-foreground">
            Share posts, media, and stories as yourself—or, if you want one, an optional persona.
          </p>
          <p className="text-sm text-muted-foreground">
            {done} of {STEPS.length} required steps done.
          </p>
        </div>
        <CardAction>
          <Button
            type="button"
            size="icon-sm"
            variant="ghost"
            onClick={() => void dismiss()}
            aria-label="Dismiss checklist"
            disabled={dismissing}
          >
            <X className="h-4 w-4" />
          </Button>
        </CardAction>
      </CardHeader>
      <CardContent className="space-y-4">
        <ul className="space-y-3" aria-label="Getting started steps">
          {STEPS.map((step) => {
            const complete = steps[step.key];

            return (
              <li key={step.key} className="flex items-start justify-between gap-3">
                <span className="flex min-w-0 items-start gap-2 text-sm">
                  <span
                    aria-hidden="true"
                    className={`mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full border ${
                      complete ? 'border-primary bg-primary text-primary-foreground' : 'border-muted-foreground/40'
                    }`}
                  >
                    {complete && <Check className="h-3 w-3" />}
                  </span>
                  <span>
                    <span className={complete ? 'text-muted-foreground line-through' : 'font-medium'}>
                      {step.label}
                    </span>
                    <span className="sr-only">{complete ? 'Completed' : 'Not completed'}</span>
                    <span className="block text-muted-foreground">{step.why}</span>
                  </span>
                </span>
                {!complete && step.href && (
                  <a className="shrink-0 text-sm font-medium underline underline-offset-4" href={step.href}>
                    {step.cta}
                  </a>
                )}
                {!complete && !step.href && (
                  <button
                    type="button"
                    className="shrink-0 text-sm font-medium underline underline-offset-4"
                    onClick={focusComposer}
                  >
                    {step.cta}
                  </button>
                )}
              </li>
            );
          })}
        </ul>

        {!hasPersonas && (
          <div className="space-y-2 rounded-md border border-dashed p-3" data-testid="persona-invitation">
            <div className="flex items-center gap-2">
              <span className="text-sm font-medium">Curious about personas?</span>
              <Badge variant="outline">Optional</Badge>
            </div>
            <p className="text-sm text-muted-foreground">
              A persona is a separate identity for fiction, art, or role-play. Most people never need one.
            </p>
            <a className="text-sm font-medium underline underline-offset-4" href="/personas/new">
              Create a persona
            </a>
          </div>
        )}

        {dismissError && <p className="text-sm text-destructive" role="alert">{dismissError}</p>}
      </CardContent>
    </Card>
  );
}
