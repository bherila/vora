import { graphWarnings } from './CyoaGraphEditor';

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
