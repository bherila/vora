import { Check } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';

import { FollowSuggestionButton, type SuggestedPerson } from '@/components/app-side-rail';
import { Avatar } from '@/components/avatar';
import { fetchWrapper } from '@/fetchWrapper';
import { moduleEnabled } from '@/instance';
import { safeInternalUrl } from '@/security/dom-url';

import { type OnboardingData, onboardingSteps } from './OnboardingChecklist';
import { PostComposer } from './PostComposer';
import type { CommunityPost } from './types';

const SUGGESTION_LIMIT = 4;

/**
 * Whether the feed should open as the first-run page instead of a timeline:
 * the guide is still active and the account has neither posted nor followed
 * anyone, so there is nothing of its own to show yet.
 */
export function isDayOne(onboarding: OnboardingData | null): boolean {
  return onboarding !== null && onboarding.steps.has_posted !== true && onboarding.steps.is_following !== true;
}

function StepCard({ index, total, title, why, complete, children }: {
  index: number;
  total: number;
  title: string;
  why: string;
  complete: boolean;
  children?: ReactNode;
}) {
  return (
    <section aria-label={title} className={`flex flex-col gap-3 rounded-xl border bg-card p-4 text-card-foreground ${complete ? 'opacity-70' : ''}`}>
      <p className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
        {complete && <Check className="h-3.5 w-3.5" aria-hidden="true" />}
        Step {index} of {total}{complete && <span className="sr-only"> (done)</span>}
      </p>
      <h2 className="text-lg font-semibold">{title}</h2>
      <p className="text-sm text-muted-foreground">{why}</p>
      {!complete && children}
    </section>
  );
}

interface DayOneProps {
  onboarding: OnboardingData;
  /** Leave the first-run page for the ordinary feed. */
  onContinue: () => void;
  onPosted: (post: CommunityPost) => void;
}

/**
 * The first-run feed: the setup steps are the page, each finished in place,
 * so a new account lands on things to do instead of an empty timeline.
 */
export function DayOne({ onboarding, onContinue, onPosted }: DayOneProps) {
  const [people, setPeople] = useState<SuggestedPerson[] | null>(null);
  const steps = onboardingSteps(onboarding);
  const firstName = onboarding.display_name.split(' ')[0];
  const ordered = steps.filter((step) => step.key !== 'has_interests');
  const interestsDone = onboarding.steps.has_interests === true;
  const needsFollows = steps.some((step) => step.key === 'is_following' && !step.complete);

  useEffect(() => {
    if (!needsFollows) return;

    let active = true;
    fetchWrapper.get('/api/side-rail')
      .then((response) => {
        if (active) setPeople(((response as { data?: { suggested_people?: SuggestedPerson[] } }).data?.suggested_people ?? []).slice(0, SUGGESTION_LIMIT));
      })
      .catch(() => {
        if (active) setPeople([]);
      });

    return () => {
      active = false;
    };
  }, [needsFollows]);

  const stepNumber = (key: string): number => ordered.findIndex((step) => step.key === key) + 1;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Welcome, {firstName}</h1>
          <p className="mt-1 text-muted-foreground">
            {ordered.length === 1 ? 'One quick thing' : `${ordered.length} quick things`} and your home fills up with people you care about.
          </p>
        </div>
        <button type="button" className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline" onClick={onContinue}>
          Skip for now
        </button>
      </div>

      {onboarding.steps.has_interests !== undefined && (
        <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
          {interestsDone ? (
            <>
              <Check className="h-4 w-4 text-foreground" aria-hidden="true" />
              Interests chosen.
              <a className="underline underline-offset-4 hover:text-foreground" href="/me">Edit</a>
            </>
          ) : (
            <>
              Pick a few interests so suggestions fit you.
              <a className="font-medium text-foreground underline underline-offset-4" href="/me">Choose interests</a>
            </>
          )}
        </p>
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        {ordered.map((step) => {
          if (step.key === 'has_avatar') {
            return (
              <StepCard key={step.key} index={stepNumber(step.key)} total={ordered.length} title="Add a face" why="People follow back faster when they recognize you." complete={step.complete}>
                <div className="flex items-center gap-4 rounded-lg border border-dashed p-4">
                  <Avatar name={onboarding.display_name} src={null} sizeClassName="h-14 w-14" />
                  <a href="/me" className="inline-flex h-9 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90">
                    Upload photo
                  </a>
                </div>
              </StepCard>
            );
          }

          if (step.key === 'is_following') {
            return (
              <StepCard key={step.key} index={stepNumber(step.key)} total={ordered.length} title="Follow a few people" why="Picked from your interests. Following builds your home feed." complete={step.complete}>
                {people === null ? (
                  <p className="text-sm text-muted-foreground">Finding people…</p>
                ) : (
                  <ul className="space-y-2" aria-label="Suggested people">
                    {people.map((person) => {
                      const href = safeInternalUrl(person.href);

                      return (
                        <li key={person.id} className="flex items-center gap-3">
                          <Avatar name={person.display_name} src={person.avatar_url} sizeClassName="h-9 w-9" />
                          <span className="min-w-0 flex-1">
                            {href ? <a href={href} className="block truncate text-sm font-medium hover:underline">{person.display_name}</a> : <span className="block truncate text-sm font-medium">{person.display_name}</span>}
                            {person.matching_interests_count > 0 && (
                              <span className="block text-xs text-muted-foreground">
                                {person.matching_interests_count} shared {person.matching_interests_count === 1 ? 'interest' : 'interests'}
                              </span>
                            )}
                          </span>
                          <FollowSuggestionButton person={person} />
                        </li>
                      );
                    })}
                  </ul>
                )}
                {moduleEnabled('profiles') && (
                  <a href="/users" className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline">
                    {people !== null && people.length === 0 ? 'Browse people' : 'More people'}
                  </a>
                )}
              </StepCard>
            );
          }

          return (
            <div key={step.key} className="sm:col-span-2">
              <StepCard index={stepNumber(step.key)} total={ordered.length} title="Say hello" why="A first post helps people decide to follow back." complete={step.complete}>
                <PostComposer onCreated={onPosted} />
              </StepCard>
            </div>
          );
        })}
      </div>
    </div>
  );
}
