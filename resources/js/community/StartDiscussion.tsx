import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { fetchWrapper } from '@/fetchWrapper';

export interface CanonicalPostRef {
  id: number;
  ulid: string;
  comment_count: number;
}

interface StartDiscussionProps {
  endpoint: string;
  onStarted: (post: CanonicalPostRef) => void;
}

interface StartDiscussionResponse {
  data: {
    post: CanonicalPostRef;
  };
}

function errorMessage(error: unknown): string {
  return typeof error === 'string'
    ? error
    : error instanceof Error
      ? error.message
      : 'Could not start the discussion.';
}

export function StartDiscussion({ endpoint, onStarted }: StartDiscussionProps) {
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const trimmedBody = body.trim();

  const submit = async (): Promise<void> => {
    if (trimmedBody === '' || busy) {
      return;
    }

    setBusy(true);
    try {
      const response = await fetchWrapper.post(endpoint, { body: trimmedBody }) as StartDiscussionResponse;
      onStarted(response.data.post);
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="mx-auto w-full max-w-3xl space-y-2" aria-label="Start discussion">
      <label htmlFor="first-discussion-comment" className="text-sm font-medium">Start a discussion</label>
      <Textarea
        id="first-discussion-comment"
        value={body}
        onChange={(event) => setBody(event.target.value)}
        placeholder="Write the first comment…"
        maxLength={2000}
        disabled={busy}
      />
      <Button type="button" onClick={() => void submit()} disabled={trimmedBody === '' || busy}>
        {busy ? 'Posting…' : 'Post comment'}
      </Button>
    </section>
  );
}
