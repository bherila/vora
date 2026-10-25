import { Check, X } from 'lucide-react';
import { useId, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { moduleEnabled } from '@/instance';

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
  /** Steps for modules this instance has turned off are omitted by the server. */
  steps: Partial<OnboardingSteps>;
}

interface StepDef {
  key: keyof OnboardingSteps;
  label: string;
  why: string;
  href?: string;
  cta: string;
  /** Compact label for the strip's single next-step action. */
  next: string;
}

const STEPS: StepDef[] = [
  {
    key: 'has_avatar',
    label: 'Add a profile photo',
    why: 'Help people recognize you when you follow them.',
    href: '/me',
    cta: 'Edit your profile photo',
    next: 'Add photo',
  },
  {
    key: 'has_interests',
    label: 'Choose your interests',
    why: 'Share what you enjoy and discover common ground.',
    href: '/me',
    cta: 'Choose your interests',
    next: 'Pick interests',
  },
  {
    key: 'is_following',
    label: 'Follow a few people',
    why: 'Build a focused Following feed.',
    href: '/users',
    cta: 'Browse people',
    next: 'Find people',
  },
  {
    key: 'has_posted',
    label: 'Share your first post',
    why: 'Introduce yourself or share what is on your mind.',
    cta: 'Write your first post',
    next: 'Write a post',
  },
];

export function focusComposer(): void {
  const composer = document.getElementById('post-composer-body');
  if (!(composer instanceof HTMLElement)) return;

  composer.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
  composer.focus();
}

/** The steps this instance offers, in order, with their completion state. */
export function onboardingSteps(onboarding: OnboardingData): Array<StepDef & { complete: boolean }> {
  return STEPS
    .filter((step) => onboarding.steps[step.key] !== undefined)
    .map((step) => ({ ...step, complete: onboarding.steps[step.key] === true }));
}

interface StepActionProps {
  step: StepDef;
  className: string;
  children: string;
}

function StepAction({ step, className, children }: StepActionProps) {
  return step.href
    ? <a className={className} href={step.href}>{children}</a>
    : <button type="button" className={className} onClick={focusComposer}>{children}</button>;
}

interface OnboardingChecklistProps {
  onboarding: OnboardingData;
}

/**
 * First-run guide shown at the top of the feed while required setup remains.
 * It rests as one strip — progress and the single next step — and expands to
 * the full list on request. Dismissal is persisted on the account so the guide
 * stays gone across devices.
 */
export function OnboardingChecklist({ onboarding }: OnboardingChecklistProps) {
  const [dismissed, setDismissed] = useState(false);
  const [dismissing, setDismissing] = useState(false);
  const [dismissError, setDismissError] = useState('');
  const [expanded, setExpanded] = useState(false);
  const listId = useId();
  const { display_name: displayName, has_personas: hasPersonas } = onboarding;

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

  const steps = onboardingSteps(onboarding);
  const done = steps.filter((step) => step.complete).length;
  const nextStep = steps.find((step) => !step.complete) ?? null;
  // Finishing the last step in this session completes the guide, as the server
  // will report on the next load.
  if (nextStep === null) return null;

  return (
    <section aria-labelledby={`${listId}-heading`} className="rounded-xl border bg-card p-3 text-card-foreground sm:px-4">
      <h2 id={`${listId}-heading`} className="sr-only">Welcome to Vora, {displayName}!</h2>
      <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
        <div className="flex gap-1" aria-hidden="true">
          {steps.map((step) => (
            <span key={step.key} className={`h-1 w-6 rounded-full ${step.complete ? 'bg-primary' : 'bg-muted'}`} />
          ))}
        </div>
        <p className="min-w-48 flex-1 text-sm text-muted-foreground">
          <span className="font-semibold text-foreground">{done} of {steps.length} done.</span>
          {' '}Next: {nextStep.label.charAt(0).toLowerCase() + nextStep.label.slice(1)}.
        </p>
        <div className="flex items-center gap-1">
          <StepAction step={nextStep} className="inline-flex h-8 items-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground hover:bg-primary/90">
            {nextStep.next}
          </StepAction>
          <Button
            type="button"
            size="sm"
            variant="ghost"
            aria-expanded={expanded}
            aria-controls={listId}
            onClick={() => setExpanded((value) => !value)}
          >
            {expanded ? 'Hide steps' : 'See all'}
          </Button>
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
        </div>
      </div>

      {expanded && (
        <div id={listId} className="mt-4 space-y-4 border-t pt-4">
          <ul className="space-y-3" aria-label="Getting started steps">
            {steps.map((step) => (
              <li key={step.key} className="flex items-start justify-between gap-3">
                <span className="flex min-w-0 items-start gap-2 text-sm">
                  <span
                    aria-hidden="true"
                    className={`mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full border ${
                      step.complete ? 'border-primary bg-primary text-primary-foreground' : 'border-muted-foreground/40'
                    }`}
                  >
                    {step.complete && <Check className="h-3 w-3" />}
                  </span>
                  <span>
                    <span className={step.complete ? 'text-muted-foreground line-through' : 'font-medium'}>
                      {step.label}
                    </span>
                    <span className="sr-only">{step.complete ? 'Completed' : 'Not completed'}</span>
                    <span className="block text-muted-foreground">{step.why}</span>
                  </span>
                </span>
                {!step.complete && (
                  <StepAction step={step} className="shrink-0 text-sm font-medium underline underline-offset-4">
                    {step.cta}
                  </StepAction>
                )}
              </li>
            ))}
          </ul>

          {!hasPersonas && moduleEnabled('personas') && (
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
        </div>
      )}

      {dismissError && <p className="mt-2 text-sm text-destructive" role="alert">{dismissError}</p>}
    </section>
  );
}
