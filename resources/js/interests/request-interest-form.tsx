import { type FormEvent, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { fetchWrapper } from '@/fetchWrapper';
import { buildInterestTree, flattenInterestTree, type InterestNodeBase } from '@/interests/tree';

interface RequestInterestFormProps {
  interests: InterestNodeBase[];
}

interface RequestFormState {
  name: string;
  description: string;
  parent_interest_id: string;
}

const EMPTY_FORM: RequestFormState = { name: '', description: '', parent_interest_id: '' };

function getErrorMessage(err: unknown): string {
  return typeof err === 'string' ? err : 'Request failed.';
}

/**
 * Lets a user propose a new interest for admin review. Parent options are shown
 * with the same hierarchy/indentation as the rest of the catalog.
 */
export function RequestInterestForm({ interests }: RequestInterestFormProps) {
  const [form, setForm] = useState<RequestFormState>(EMPTY_FORM);
  const [submitting, setSubmitting] = useState(false);

  const parentOptions = useMemo(() => {
    return flattenInterestTree(buildInterestTree(interests)).map((interest) => ({
      id: String(interest.id),
      label: `${'— '.repeat(interest.depth)}${interest.name}`,
    }));
  }, [interests]);

  const submit = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
    event.preventDefault();

    const name = form.name.trim();
    if (!name) {
      toast.error('Interest name is required.');
      return;
    }

    setSubmitting(true);
    try {
      await fetchWrapper.post('/api/interests/request', {
        name,
        description: form.description.trim() || null,
        parent_interest_id: form.parent_interest_id ? Number(form.parent_interest_id) : null,
      });
      setForm(EMPTY_FORM);
      toast.success('Interest request submitted for admin review.');
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <form onSubmit={(event) => void submit(event)} className="grid gap-4">
      <Input
        value={form.name}
        onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))}
        placeholder="Interest name"
        required
      />
      <Textarea
        value={form.description}
        onChange={(event) => setForm((current) => ({ ...current, description: event.target.value }))}
        placeholder="Description (optional)"
        rows={3}
      />
      <label className="grid gap-1">
        <span className="text-sm">Parent interest (optional)</span>
        <select
          value={form.parent_interest_id}
          onChange={(event) => setForm((current) => ({ ...current, parent_interest_id: event.target.value }))}
          className="w-full rounded-md border border-input bg-background px-3 py-2"
        >
          <option value="">No parent</option>
          {parentOptions.map((option) => (
            <option key={option.id} value={option.id}>
              {option.label}
            </option>
          ))}
        </select>
      </label>
      <Button type="submit" size="sm" className="w-fit" disabled={submitting}>
        {submitting ? 'Submitting…' : 'Request item'}
      </Button>
    </form>
  );
}
