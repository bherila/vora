import { useMemo } from 'react';

interface DiagramNode {
  key: string;
  title: string;
  is_start: boolean;
}

interface DiagramEdge {
  from: string;
  to: string | null;
  label: string;
}

interface CyoaGraphDiagramProps {
  nodes: DiagramNode[];
  edges: DiagramEdge[];
  onSelect?: (key: string) => void;
}

const BOX_W = 150;
const BOX_H = 44;
const COL_GAP = 90;
const ROW_GAP = 24;
const PAD = 16;

/**
 * Read-only branch diagram for a CYOA graph. Nodes are laid out in columns by
 * their distance from the start passage (breadth-first), so authors can see how
 * choices flow. Clicking a node selects it in the editor.
 */
export function CyoaGraphDiagram({ nodes, edges, onSelect }: CyoaGraphDiagramProps) {
  const layout = useMemo(() => {
    if (nodes.length === 0) return null;

    const adjacency = new Map<string, string[]>();
    nodes.forEach((n) => adjacency.set(n.key, []));
    edges.forEach((e) => {
      if (e.to && adjacency.has(e.from) && adjacency.has(e.to)) {
        adjacency.get(e.from)!.push(e.to);
      }
    });

    const start = nodes.find((n) => n.is_start) ?? nodes[0];
    if (!start) return null;
    const depth = new Map<string, number>();
    const queue: string[] = [start.key];
    depth.set(start.key, 0);
    while (queue.length > 0) {
      const cur = queue.shift()!;
      for (const next of adjacency.get(cur) ?? []) {
        if (!depth.has(next)) {
          depth.set(next, (depth.get(cur) ?? 0) + 1);
          queue.push(next);
        }
      }
    }
    // Unreached nodes go in a trailing column.
    const maxDepth = Math.max(0, ...Array.from(depth.values()));
    nodes.forEach((n) => {
      if (!depth.has(n.key)) depth.set(n.key, maxDepth + 1);
    });

    const columns = new Map<number, string[]>();
    nodes.forEach((n) => {
      const d = depth.get(n.key) ?? 0;
      if (!columns.has(d)) columns.set(d, []);
      columns.get(d)!.push(n.key);
    });

    const pos = new Map<string, { x: number; y: number }>();
    let maxRows = 0;
    Array.from(columns.entries()).forEach(([col, keys]) => {
      maxRows = Math.max(maxRows, keys.length);
      keys.forEach((key, row) => {
        pos.set(key, {
          x: PAD + col * (BOX_W + COL_GAP),
          y: PAD + row * (BOX_H + ROW_GAP),
        });
      });
    });

    const width = PAD * 2 + (Math.max(...Array.from(columns.keys())) + 1) * (BOX_W + COL_GAP) - COL_GAP;
    const height = PAD * 2 + maxRows * (BOX_H + ROW_GAP) - ROW_GAP;
    return { pos, width: Math.max(width, BOX_W + PAD * 2), height: Math.max(height, BOX_H + PAD * 2) };
  }, [nodes, edges]);

  if (layout === null) {
    return <p className="text-sm text-muted-foreground">Add passages to see the story graph.</p>;
  }

  return (
    <div className="overflow-x-auto rounded-md border border-border bg-muted/30 p-2">
      <svg width={layout.width} height={layout.height} role="img" aria-label="Story branch diagram">
        <defs>
          <marker id="arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto">
            <path d="M0,0 L8,4 L0,8 Z" className="fill-muted-foreground" />
          </marker>
        </defs>
        {edges.map((e, i) => {
          const from = layout.pos.get(e.from);
          if (!from) return null;
          const x1 = from.x + BOX_W;
          const y1 = from.y + BOX_H / 2;
          if (!e.to) {
            // Ending stub.
            return (
              <g key={`end-${i}`}>
                <line x1={x1} y1={y1} x2={x1 + 24} y2={y1} className="stroke-muted-foreground" strokeWidth={1} strokeDasharray="3 3" />
                <text x={x1 + 28} y={y1 + 4} className="fill-muted-foreground text-[10px]">END</text>
              </g>
            );
          }
          const to = layout.pos.get(e.to);
          if (!to) return null;
          const x2 = to.x;
          const y2 = to.y + BOX_H / 2;
          const mx = (x1 + x2) / 2;
          return (
            <g key={`edge-${i}`}>
              <path d={`M${x1},${y1} C${mx},${y1} ${mx},${y2} ${x2},${y2}`} className="stroke-muted-foreground" fill="none" strokeWidth={1.2} markerEnd="url(#arrow)" />
              <text x={mx} y={(y1 + y2) / 2 - 4} textAnchor="middle" className="fill-muted-foreground text-[10px]">
                {e.label.length > 16 ? `${e.label.slice(0, 15)}…` : e.label}
              </text>
            </g>
          );
        })}
        {nodes.map((n) => {
          const p = layout.pos.get(n.key);
          if (!p) return null;
          return (
            <g key={n.key} className="cursor-pointer" onClick={() => onSelect?.(n.key)}>
              <rect
                x={p.x}
                y={p.y}
                width={BOX_W}
                height={BOX_H}
                rx={6}
                className={n.is_start ? 'fill-primary/20 stroke-primary' : 'fill-background stroke-border'}
                strokeWidth={1.2}
              />
              <text x={p.x + BOX_W / 2} y={p.y + BOX_H / 2 + 4} textAnchor="middle" className="fill-foreground text-xs">
                {n.title.length > 18 ? `${n.title.slice(0, 17)}…` : n.title}
              </text>
            </g>
          );
        })}
      </svg>
    </div>
  );
}
