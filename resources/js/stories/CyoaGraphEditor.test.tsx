import { act, fireEvent, render, screen } from '@testing-library/react';

import { storiesApi } from './api';
import { CyoaGraphEditor, graphWarnings } from './CyoaGraphEditor';
import type { StoryEditor } from './types';

jest.mock('./api', () => ({ storiesApi: { saveGraph: jest.fn() } }));
jest.mock('./CyoaGraphDiagram', () => ({ CyoaGraphDiagram: () => null }));

function story(): StoryEditor {
  return {
    id: 3, ulid: 'story', title: 'Adventure', type: 'cyoa', status: 'draft', audience: 'everyone', discoverable: true,
    body: null, owner: null, interests: [], involves: [], authors: [], review: { status: 'pending', label: 'Pending', note: null },
    node_count: 1, published_at: null, created_at: null, updated_at: null, can_manage_authors: false, involvable_options: [],
    nodes: [{ id: 11, key: 'start', title: 'Start', body: 'Passage', is_start: true, position_x: 24, position_y: 24 }],
    choices: [{ from_node_id: 11, to_node_id: null, label: 'End', position: 0 }],
  };
}

function pendingSave() {
  let resolve!: (updated: StoryEditor) => void;
  let reject!: (reason: string) => void;
  const promise = new Promise<StoryEditor>((res, rej) => { resolve = res; reject = rej; });
  jest.mocked(storiesApi.saveGraph).mockReturnValueOnce(promise);
  return { resolve, reject };
}

describe('CyoaGraphEditor in-flight edits', () => {
  beforeEach(() => jest.clearAllMocks());

  it('retains newer passage and choice edits, reports unsaved state and resubmits them', async () => {
    const pending = pendingSave();
    const onSaved = jest.fn();
    render(<CyoaGraphEditor story={story()} onSaved={onSaved} />);
    fireEvent.click(screen.getByRole('button', { name: 'Save graph' }));
    fireEvent.change(screen.getByPlaceholderText('Passage title'), { target: { value: 'New title' } });
    fireEvent.change(screen.getByPlaceholderText('Passage text (markdown)…'), { target: { value: 'New passage' } });
    fireEvent.change(screen.getByPlaceholderText('Choice label'), { target: { value: 'New ending' } });
    await act(async () => pending.resolve(story()));
    expect(screen.getByPlaceholderText('Passage title')).toHaveValue('New title');
    expect(screen.getByPlaceholderText('Passage text (markdown)…')).toHaveValue('New passage');
    expect(screen.getByPlaceholderText('Choice label')).toHaveValue('New ending');
    expect(screen.getByText('Unsaved graph changes')).toBeInTheDocument();
    expect(screen.queryByText('Saved')).not.toBeInTheDocument();
    expect(onSaved).toHaveBeenCalledWith(story());
    const next = pendingSave();
    fireEvent.click(screen.getByRole('button', { name: 'Save graph' }));
    expect(storiesApi.saveGraph).toHaveBeenLastCalledWith(3, [expect.objectContaining({ title: 'New title', body: 'New passage' })], [{ from: 'start', to: null, label: 'New ending', position: 0 }]);
    const canonical = story();
    canonical.nodes[0] = { ...canonical.nodes[0]!, title: 'New title', body: 'New passage' };
    canonical.choices[0] = { ...canonical.choices[0]!, label: 'New ending' };
    await act(async () => next.resolve(canonical));
    expect(screen.getByText('Saved')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Add passage' }));
    expect(screen.getByText('Unsaved graph changes')).toBeInTheDocument();
    expect(screen.queryByText('Saved')).not.toBeInTheDocument();
  });

  it('does not resurrect passages removed while saving', async () => {
    const pending = pendingSave();
    render(<CyoaGraphEditor story={story()} onSaved={jest.fn()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Save graph' }));
    fireEvent.click(screen.getByRole('button', { name: 'Delete passage' }));
    await act(async () => pending.resolve(story()));
    expect(screen.getByText('No passages yet.')).toBeInTheDocument();
    expect(screen.queryByPlaceholderText('Passage title')).not.toBeInTheDocument();
    expect(screen.getByText('Unsaved graph changes')).toBeInTheDocument();
  });

  it('adopts the canonical graph and selection when no graph edits followed submission', async () => {
    const pending = pendingSave();
    render(<CyoaGraphEditor story={story()} onSaved={jest.fn()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Save graph' }));
    const canonical = story();
    canonical.nodes = [{ ...canonical.nodes[0]!, id: 22, key: 'promoted', title: 'Canonical start' }];
    canonical.choices = [];
    await act(async () => pending.resolve(canonical));
    expect(screen.getByPlaceholderText('Passage title')).toHaveValue('Canonical start');
    expect(screen.queryByPlaceholderText('Choice label')).not.toBeInTheDocument();
    expect(screen.getByText('Saved')).toBeInTheDocument();
  });

  it('retains the graph and reports a failed save', async () => {
    const pending = pendingSave();
    render(<CyoaGraphEditor story={story()} onSaved={jest.fn()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Save graph' }));
    fireEvent.change(screen.getByPlaceholderText('Choice label'), { target: { value: 'Keep this choice' } });
    await act(async () => pending.reject('Synthetic graph failure'));
    expect(screen.getByPlaceholderText('Choice label')).toHaveValue('Keep this choice');
    expect(screen.getByText('Synthetic graph failure')).toBeInTheDocument();
    expect(screen.getByText('Unsaved graph changes')).toBeInTheDocument();
  });
});

describe('graphWarnings', () => {
  it('warns about missing starts and unreachable passages', () => {
    const warnings = graphWarnings(
      [
        { key: 'a', title: 'A', body: '', is_start: false, position_x: 24, position_y: 24 },
        { key: 'b', title: 'B', body: '', is_start: false, position_x: 244, position_y: 24 },
      ],
      [],
    );

    expect(warnings).toContain('No start passage is selected.');
    expect(warnings.some((warning) => warning.includes('Unreachable passages: A, B.'))).toBe(true);
  });

  it('warns when a reachable loop cannot reach an ending', () => {
    const warnings = graphWarnings(
      [
        { key: 'start', title: 'Start', body: '', is_start: true, position_x: 24, position_y: 24 },
        { key: 'loop', title: 'Loop', body: '', is_start: false, position_x: 244, position_y: 24 },
      ],
      [
        { fromKey: 'start', toKey: 'loop', label: 'Go' },
        { fromKey: 'loop', toKey: 'start', label: 'Again' },
      ],
    );

    expect(warnings.some((warning) => warning.includes('No ending can be reached from: Start, Loop.'))).toBe(true);
  });

  it('does not warn for a reachable path to an ending', () => {
    expect(
      graphWarnings(
        [{ key: 'start', title: 'Start', body: '', is_start: true, position_x: 24, position_y: 24 }],
        [{ fromKey: 'start', toKey: null, label: 'End' }],
      ),
    ).toEqual([]);
  });

  it('does not warn when an ending is only reachable through a back-edge to an ancestor', () => {
    // start -> mid (mid loops back to start) and start -> end. `mid` reaches an
    // ending via mid -> start -> end, so it must not be flagged regardless of
    // the order nodes are evaluated.
    const warnings = graphWarnings(
      [
        { key: 'start', title: 'Start', body: '', is_start: true, position_x: 24, position_y: 24 },
        { key: 'mid', title: 'Mid', body: '', is_start: false, position_x: 244, position_y: 24 },
        { key: 'end', title: 'End', body: '', is_start: false, position_x: 464, position_y: 24 },
      ],
      [
        { fromKey: 'start', toKey: 'mid', label: 'Go' },
        { fromKey: 'start', toKey: 'end', label: 'Finish' },
        { fromKey: 'mid', toKey: 'start', label: 'Back' },
        { fromKey: 'end', toKey: null, label: 'The end' },
      ],
    );

    expect(warnings.some((warning) => warning.includes('No ending can be reached'))).toBe(false);
  });
});
