import { Check, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

import { storiesApi } from './api';
import { CyoaGraphDiagram } from './CyoaGraphDiagram';
import type { StoryEditor } from './types';

interface EditorNode {
  key: string;
  title: string;
  body: string;
  is_start: boolean;
  position_x: number;
  position_y: number;
}

interface EditorChoice {
  fromKey: string;
  toKey: string | null;
  label: string;
}

interface CyoaGraphEditorProps {
  story: StoryEditor;
  onSaved: (updated: StoryEditor) => void;
}

function randomKey(): string {
  return `n-${Math.random().toString(36).slice(2, 10)}`;
}

function defaultPosition(index: number): { x: number; y: number } {
  return {
    x: 24 + (index % 4) * 220,
    y: 24 + Math.floor(index / 4) * 150,
  };
}

export function graphWarnings(nodes: EditorNode[], choices: EditorChoice[]): string[] {
  const warnings: string[] = [];
  const start = nodes.find((node) => node.is_start) ?? null;
  const nodeKeys = new Set(nodes.map((node) => node.key));
  const adjacency = new Map<string, string[]>();
  nodes.forEach((node) => adjacency.set(node.key, []));
  choices.forEach((choice) => {
    if (nodeKeys.has(choice.fromKey) && choice.toKey !== null && nodeKeys.has(choice.toKey)) {
      adjacency.get(choice.fromKey)?.push(choice.toKey);
    }
  });

  if (nodes.length > 0 && start === null) {
    warnings.push('No start passage is selected.');
  }

  const reachable = new Set<string>();
  if (start !== null) {
    const queue = [start.key];
    reachable.add(start.key);
    while (queue.length > 0) {
      const current = queue.shift();
      if (!current) continue;
      for (const next of adjacency.get(current) ?? []) {
        if (!reachable.has(next)) {
          reachable.add(next);
          queue.push(next);
        }
      }
    }
  }

  const unreachableTitles = nodes
    .filter((node) => !reachable.has(node.key))
    .map((node) => node.title || 'Untitled passage');
  if (unreachableTitles.length > 0) {
    warnings.push(`Unreachable passages: ${unreachableTitles.join(', ')}.`);
  }

  // A node reaches an ending if it has a terminal choice (or no choices) or an
  // outgoing edge to another node that does. Solved by reverse reachability from
  // the ending nodes so the result is independent of node/edge order and correct
  // for cyclic graphs (a back-edge to an ancestor cannot poison the result).
  const reverse = new Map<string, string[]>();
  nodes.forEach((node) => reverse.set(node.key, []));
  const canReachEnding = new Set<string>();
  const queue: string[] = [];
  nodes.forEach((node) => {
    const outgoing = choices.filter((choice) => choice.fromKey === node.key);
    if (outgoing.length === 0 || outgoing.some((choice) => choice.toKey === null)) {
      if (!canReachEnding.has(node.key)) {
        canReachEnding.add(node.key);
        queue.push(node.key);
      }
    }
    outgoing.forEach((choice) => {
      if (choice.toKey !== null && nodeKeys.has(choice.toKey)) {
        reverse.get(choice.toKey)?.push(node.key);
      }
    });
  });
  while (queue.length > 0) {
    const current = queue.shift();
    if (!current) continue;
    for (const prev of reverse.get(current) ?? []) {
      if (!canReachEnding.has(prev)) {
        canReachEnding.add(prev);
        queue.push(prev);
      }
    }
  }

  const loopTitles = nodes
    .filter((node) => reachable.has(node.key) && !canReachEnding.has(node.key))
    .map((node) => node.title || 'Untitled passage');
  if (loopTitles.length > 0) {
    warnings.push(`No ending can be reached from: ${loopTitles.join(', ')}.`);
  }

  return warnings;
}

/**
 * Passage + choices editor for choose-your-own-adventure stories. Authors edit
 * each passage's markdown and wire up the choices that lead to other passages;
 * a live diagram shows the resulting branch graph.
 */
export function CyoaGraphEditor({ story, onSaved }: CyoaGraphEditorProps) {
  const fromStory = (s: StoryEditor): { nodes: EditorNode[]; choices: EditorChoice[] } => {
    const idToKey = new Map<number, string>();
    s.nodes.forEach((n) => {
      if (typeof n.id === 'number') idToKey.set(n.id, n.key);
    });
    return {
      nodes: s.nodes.map((n, index) => {
        const fallback = defaultPosition(index);
        return {
          key: n.key,
          title: n.title ?? '',
          body: n.body ?? '',
          is_start: n.is_start,
          position_x: Number.isFinite(n.position_x) ? n.position_x : fallback.x,
          position_y: Number.isFinite(n.position_y) ? n.position_y : fallback.y,
        };
      }),
      choices: s.choices
        .map((c) => ({
          fromKey: idToKey.get(c.from_node_id ?? -1) ?? '',
          toKey: c.to_node_id != null ? (idToKey.get(c.to_node_id) ?? null) : null,
          label: c.label,
        }))
        .filter((c) => c.fromKey !== ''),
    };
  };

  const [nodes, setNodes] = useState<EditorNode[]>(() => fromStory(story).nodes);
  const [choices, setChoices] = useState<EditorChoice[]>(() => fromStory(story).choices);
  const [selectedKey, setSelectedKey] = useState<string | null>(() => nodes.find((n) => n.is_start)?.key ?? nodes[0]?.key ?? null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [saved, setSaved] = useState(false);

  const selected = nodes.find((n) => n.key === selectedKey) ?? null;
  const selectedChoices = choices.filter((c) => c.fromKey === selectedKey);
  const warnings = graphWarnings(nodes, choices);

  const addNode = (): void => {
    const key = randomKey();
    setNodes((prev) => {
      const position = defaultPosition(prev.length);
      return [...prev, { key, title: '', body: '', is_start: prev.length === 0, position_x: position.x, position_y: position.y }];
    });
    setSelectedKey(key);
  };

  const updateNode = (key: string, patch: Partial<EditorNode>): void => {
    setNodes((prev) => prev.map((n) => (n.key === key ? { ...n, ...patch } : n)));
  };

  const setStart = (key: string): void => {
    setNodes((prev) => prev.map((n) => ({ ...n, is_start: n.key === key })));
  };

  const removeNode = (key: string): void => {
    setNodes((prev) => prev.filter((n) => n.key !== key));
    setChoices((prev) => prev.filter((c) => c.fromKey !== key).map((c) => (c.toKey === key ? { ...c, toKey: null } : c)));
    if (selectedKey === key) setSelectedKey(null);
  };

  const addChoice = (fromKey: string): void => {
    setChoices((prev) => [...prev, { fromKey, toKey: null, label: 'Continue' }]);
  };

  const updateChoice = (index: number, patch: Partial<EditorChoice>): void => {
    setChoices((prev) => prev.map((c, i) => (i === index ? { ...c, ...patch } : c)));
  };

  const removeChoice = (index: number): void => {
    setChoices((prev) => prev.filter((_, i) => i !== index));
  };

  const save = async (): Promise<void> => {
    setSaving(true);
    setError('');
    setSaved(false);
    try {
      const nodePayload = nodes.map((n, i) => ({
        key: n.key,
        title: n.title || null,
        body: n.body || null,
        is_start: n.is_start,
        position_x: n.position_x,
        position_y: n.position_y,
      }));
      const choicePayload = choices
        .filter((c) => nodes.some((n) => n.key === c.fromKey))
        .map((c, i) => ({ from: c.fromKey, to: c.toKey, label: c.label || 'Continue', position: i }));
      const updated = await storiesApi.saveGraph(story.id, nodePayload, choicePayload);
      // Adopt the server-normalized graph (e.g. a promoted start passage, dropped
      // dangling edges) so the editor reflects exactly what was persisted.
      const next = fromStory(updated);
      setNodes(next.nodes);
      setChoices(next.choices);
      setSelectedKey((cur) => (cur && next.nodes.some((n) => n.key === cur) ? cur : next.nodes.find((n) => n.is_start)?.key ?? next.nodes[0]?.key ?? null));
      onSaved(updated);
      setSaved(true);
    } catch (e) {
      setError(typeof e === 'string' ? e : 'Could not save the graph.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-lg font-semibold">Passages &amp; choices</h3>
        <div className="flex items-center gap-2">
          {saved && <span className="text-sm text-muted-foreground">Saved</span>}
          <Button type="button" variant="outline" size="sm" onClick={addNode}>
            <Plus className="mr-1 h-4 w-4" /> Add passage
          </Button>
          <Button type="button" size="sm" onClick={() => void save()} disabled={saving}>
            {saving ? 'Saving…' : 'Save graph'}
          </Button>
        </div>
      </div>
      {error && <p className="text-sm text-destructive">{error}</p>}
      {warnings.length > 0 && (
        <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100">
          <p className="font-medium">Graph warnings</p>
          <ul className="mt-1 list-disc space-y-1 pl-5">
            {warnings.map((warning) => (
              <li key={warning}>{warning}</li>
            ))}
          </ul>
        </div>
      )}

      <div className="grid gap-4 md:grid-cols-[220px_1fr]">
        {/* Passage list */}
        <div className="space-y-1 rounded-md border border-border p-2">
          {nodes.length === 0 && <p className="p-2 text-sm text-muted-foreground">No passages yet.</p>}
          {nodes.map((n) => (
            <button
              key={n.key}
              type="button"
              onClick={() => setSelectedKey(n.key)}
              className={`flex w-full items-center justify-between rounded px-2 py-1 text-left text-sm ${
                n.key === selectedKey ? 'bg-accent text-accent-foreground' : 'hover:bg-muted'
              }`}
            >
              <span className="truncate">{n.title || 'Untitled passage'}</span>
              {n.is_start && <span className="ml-2 rounded bg-primary px-1 text-xs text-primary-foreground">start</span>}
            </button>
          ))}
        </div>

        {/* Selected passage editor */}
        <div className="space-y-3">
          {selected === null ? (
            <p className="text-sm text-muted-foreground">Select or add a passage to edit it.</p>
          ) : (
            <>
              <div className="flex items-center gap-2">
                <Input
                  value={selected.title}
                  placeholder="Passage title"
                  onChange={(e) => updateNode(selected.key, { title: e.target.value })}
                />
                <Button
                  type="button"
                  variant={selected.is_start ? 'default' : 'outline'}
                  size="sm"
                  onClick={() => setStart(selected.key)}
                  title="Mark as the starting passage"
                >
                  {selected.is_start ? <Check className="mr-1 h-4 w-4" /> : null} Start
                </Button>
                <Button type="button" variant="destructive" size="sm" onClick={() => removeNode(selected.key)}>
                  <Trash2 className="h-4 w-4" />
                </Button>
              </div>
              <Textarea
                value={selected.body}
                placeholder="Passage text (markdown)…"
                rows={6}
                onChange={(e) => updateNode(selected.key, { body: e.target.value })}
              />

              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <h4 className="text-sm font-medium">Choices from this passage</h4>
                  <Button type="button" variant="outline" size="sm" onClick={() => addChoice(selected.key)}>
                    <Plus className="mr-1 h-4 w-4" /> Add choice
                  </Button>
                </div>
                {selectedChoices.length === 0 && (
                  <p className="text-sm text-muted-foreground">No choices — this passage is an ending.</p>
                )}
                {choices.map((c, index) =>
                  c.fromKey === selected.key ? (
                    <div key={index} className="flex items-center gap-2">
                      <Input
                        value={c.label}
                        placeholder="Choice label"
                        onChange={(e) => updateChoice(index, { label: e.target.value })}
                      />
                      <select
                        className="h-9 rounded-md border border-input bg-background px-2 text-sm"
                        value={c.toKey ?? ''}
                        onChange={(e) => updateChoice(index, { toKey: e.target.value || null })}
                      >
                        <option value="">(Ending)</option>
                        {nodes
                          .filter((n) => n.key !== selected.key)
                          .map((n) => (
                            <option key={n.key} value={n.key}>
                              → {n.title || 'Untitled passage'}
                            </option>
                          ))}
                      </select>
                      <Button type="button" variant="ghost" size="sm" onClick={() => removeChoice(index)}>
                        <Trash2 className="h-4 w-4" />
                      </Button>
                    </div>
                  ) : null,
                )}
              </div>
            </>
          )}
        </div>
      </div>

      <CyoaGraphDiagram
        nodes={nodes.map((n) => ({
          key: n.key,
          title: n.title || 'Untitled',
          is_start: n.is_start,
          position_x: n.position_x,
          position_y: n.position_y,
        }))}
        edges={choices.map((c) => ({ from: c.fromKey, to: c.toKey, label: c.label }))}
        selectedKey={selectedKey}
        onSelect={setSelectedKey}
        onMove={(key, position) => updateNode(key, { position_x: position.x, position_y: position.y })}
      />
    </div>
  );
}
