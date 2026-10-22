import { render, screen } from '@testing-library/react';

import { readInitialData } from '@/initialData';

import { EventJoiningNotice, getEventJoiningUrl } from './event-joining';

jest.mock('@/initialData', () => ({ readInitialData: jest.fn() }));

const initialData = jest.mocked(readInitialData);
const joiningUrl = '/events/join/01ARZ3NDEKTSV4RRFFQ69G5FAV';

it('explains saved same-browser continuation and restricted Event membership', () => {
  initialData.mockReturnValue({ eventJoin: { continue_url: joiningUrl } });
  render(<EventJoiningNotice stage="approval" />);
  expect(screen.getByRole('alert')).toHaveTextContent('saved in this browser');
  expect(screen.getByRole('alert')).toHaveTextContent('does not grant access to a restricted Event');
  expect(getEventJoiningUrl()).toBe(joiningUrl);
});

it.each(['https://evil.example', '//evil.example', '/events/join/../logout', `${joiningUrl}?next=https://evil.example`, null])('rejects malformed joining data %s', (continue_url) => {
  initialData.mockReturnValue({ eventJoin: { continue_url } });
  const { container } = render(<EventJoiningNotice stage="sign-in" />);
  expect(container).toBeEmptyDOMElement();
  expect(getEventJoiningUrl()).toBeNull();
});

it.each(['sign-in', 'verify', 'two-factor'] as const)('explains the %s admission stage without Event metadata', (stage) => {
  initialData.mockReturnValue({ eventJoin: { continue_url: joiningUrl } });
  render(<EventJoiningNotice stage={stage} />);
  expect(screen.getByRole('alert')).toHaveTextContent(/Event/);
  if (stage === 'sign-in') expect(screen.getByRole('alert')).toHaveTextContent('separate personal signup invitation');
});
