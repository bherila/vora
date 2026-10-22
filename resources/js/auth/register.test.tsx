import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { fetchWrapper } from '@/fetchWrapper';
import { readInitialData } from '@/initialData';

import { RegisterPage } from './register';

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { post: jest.fn() } }));
jest.mock('@/initialData', () => ({ readInitialData: jest.fn() }));
jest.mock('@/components/date-picker', () => ({ DatePicker: ({ id, value, max, onChange }: { id: string; value: string; max: string; onChange: (value: string) => void }) => <input id={id} type="date" value={value} max={max} onChange={(event) => onChange(event.target.value)} /> }));

beforeEach(() => {
  jest.clearAllMocks();
  jest.mocked(readInitialData).mockReturnValue({ agePolicy: { minimum_age: 13, age_verification_required: false, birth_date_required: true, latest_birth_date: '2013-10-22' } });
  jest.mocked(fetchWrapper.post).mockResolvedValue({ success: false, message: 'Server response' });
});

it('uses the configured minimum and birthday boundary rather than hardcoded adult eligibility', async () => {
  render(<RegisterPage />);
  expect(screen.getByText(/at least 13 years old/)).toBeInTheDocument();
  expect(screen.queryByText(/ID verification will be required/)).not.toBeInTheDocument();
  expect(screen.getByLabelText('Birth date')).toHaveAttribute('max', '2013-10-22');
  fireEvent.change(screen.getByLabelText('Real name'), { target: { value: 'Family Guest' } });
  fireEvent.change(screen.getByLabelText('Display name'), { target: { value: 'Guest' } });
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'guest@example.test' } });
  fireEvent.change(screen.getByLabelText('Password', { exact: true }), { target: { value: 'password123' } });
  fireEvent.change(screen.getByLabelText('Confirm Password'), { target: { value: 'password123' } });
  const birth = screen.getByLabelText('Birth date');
  fireEvent.change(birth, { target: { value: '2013-10-23' } });
  fireEvent.submit(birth.closest('form')!);
  expect(fetchWrapper.post).not.toHaveBeenCalled();
  expect(screen.getByRole('alert')).toHaveTextContent('at least 13 years old');
  fireEvent.change(birth, { target: { value: '2013-10-22' } });
  fireEvent.submit(birth.closest('form')!);
  await waitFor(() => expect(fetchWrapper.post).toHaveBeenCalledWith('/api/auth/register', expect.objectContaining({ birth_date: '2013-10-22' })));
  expect(await screen.findByText('Server response')).toBeInTheDocument();
});
