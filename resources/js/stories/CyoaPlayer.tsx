import { RotateCcw } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Markdown } from '@/components/Markdown';
import { Button } from '@/components/ui/button';

import type { StoryReader } from './types';

interface CyoaPlayerProps {
  story: StoryReader;
}

/**
 * Reader-facing player for a choose-your-own-adventure story. Starts at the
 * start passage and follows the reader's choices through the graph. A choice
 * with no target is a story ending.
 */
export function CyoaPlayer({ story }: CyoaPlayerProps) {
  const startNode = useMemo(() => story.nodes.find((n) => n.is_start) ?? story.nodes[0] ?? null, [story.nodes]);
  const [currentId, setCurrentId] = useState<number | null>(startNode ? startNode.id : null);
  const [ended, setEnded] = useState(false);

  if (startNode === null) {
    return <p className="text-sm text-muted-foreground">This adventure has no passages yet.</p>;
  }

  const restart = (): void => {
    setCurrentId(startNode.id);
    setEnded(false);
  };

  const current = story.nodes.find((n) => n.id === currentId) ?? startNode;
  const choices = story.choices
    .filter((c) => c.from_node_id === current.id)
    .sort((a, b) => a.position - b.position);

  const choose = (toId: number | null): void => {
    if (toId === null) {
      setEnded(true);
    } else {
      setCurrentId(toId);
    }
  };

  const atEnding = ended || choices.length === 0;

  return (
    <div className="space-y-6">
      {!ended && current.title && <h2 className="text-xl font-semibold">{current.title}</h2>}
      {!ended && <Markdown source={current.body} />}

      <div className="space-y-2">
        {atEnding ? (
          <div className="space-y-3">
            <p className="text-sm font-medium text-muted-foreground">The End.</p>
            <Button type="button" variant="outline" onClick={restart}>
              <RotateCcw className="mr-1 h-4 w-4" /> Start over
            </Button>
          </div>
        ) : (
          choices.map((choice, i) => (
            <Button
              key={choice.id ?? i}
              type="button"
              variant="outline"
              className="w-full justify-start"
              onClick={() => choose(choice.to_node_id)}
            >
              {choice.label}
            </Button>
          ))
        )}
      </div>

      {!atEnding && current.id !== startNode.id && (
        <button type="button" className="text-xs text-muted-foreground underline underline-offset-4" onClick={restart}>
          Restart from the beginning
        </button>
      )}
    </div>
  );
}
