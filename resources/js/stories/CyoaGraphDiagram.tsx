import type { PointerEvent } from 'react';
import { useMemo, useState } from 'react';

interface DiagramNode {
  key: string;
  title: string;
  is_start: boolean;
  position_x: number;
  position_y: number;
}

interface DiagramEdge {
  from: string;
  to: string | null;
  label: string;
}

interface CyoaGraphDiagramProps {
  nodes: DiagramNode[];
  edges: DiagramEdge[];
  selectedKey?: string | null;
  onSelect?: (key: string) => void;
  onMove?: (key: string, position: { x: number; y: number }) => void;
}

const BOX_W = 150;
const BOX_H = 44;
const PAD = 24;

function pointerPosition(event: PointerEvent<SVGElement>): { x: number; y: number } {
  const svg = event.currentTarget.ownerSVGElement ?? (event.currentTarget as SVGSVGElement);
  const rect = svg.getBoundingClientRect();

  return {
    x: event.clientX - rect.left,
    y: event.clientY - rect.top,
  };
}

/**
 * Draggable branch canvas for a CYOA graph. Authors drag passages directly; the
 * editor persists those coordinates on the next graph save.
 */
export function CyoaGraphDiagram({ nodes, edges, selectedKey, onSelect, onMove }: CyoaGraphDiagramProps) {
  const [drag, setDrag] = useState<{ key: string; dx: number; dy: number } | null>(null);

  const layout = useMemo(() => {
    if (nodes.length === 0) return null;

    const pos = new Map<string, { x: number; y: number }>();
    for (const node of nodes) {
      pos.set(node.key, {
        x: Math.max(PAD, node.position_x),
        y: Math.max(PAD, node.position_y),
      });
    }

    const maxX = Math.max(...Array.from(pos.values()).map((p) => p.x));
    const maxY = Math.max(...Array.from(pos.values()).map((p) => p.y));

    return {
      pos,
      width: Math.max(BOX_W + PAD * 2, maxX + BOX_W + PAD),
      height: Math.max(BOX_H + PAD * 2, maxY + BOX_H + PAD),
    };
  }, [nodes]);

  if (layout === null) {
    return <p className="text-sm text-muted-foreground">Add passages to see the story graph.</p>;
  }

  const startDrag = (event: PointerEvent<SVGGElement>, key: string): void => {
    const current = layout.pos.get(key);
    if (!current) return;
    const point = pointerPosition(event);
    event.currentTarget.setPointerCapture(event.pointerId);
    onSelect?.(key);
    setDrag({ key, dx: point.x - current.x, dy: point.y - current.y });
  };

  const moveDrag = (event: PointerEvent<SVGSVGElement>): void => {
    if (drag === null || onMove === undefined) return;
    const point = pointerPosition(event);
    onMove(drag.key, {
      x: Math.max(PAD, Math.round(point.x - drag.dx)),
      y: Math.max(PAD, Math.round(point.y - drag.dy)),
    });
  };

  return (
    <div className="overflow-auto rounded-md border border-border bg-muted/30 p-2">
      <svg
        width={layout.width}
        height={layout.height}
        role="img"
        aria-label="Story branch canvas"
        onPointerMove={moveDrag}
        onPointerUp={() => setDrag(null)}
        onPointerCancel={() => setDrag(null)}
      >
        <defs>
          <marker id="arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto">
            <path d="M0,0 L8,4 L0,8 Z" className="fill-muted-foreground" />
          </marker>
        </defs>
        {edges.map((edge, index) => {
          const from = layout.pos.get(edge.from);
          if (!from) return null;
          const x1 = from.x + BOX_W;
          const y1 = from.y + BOX_H / 2;
          if (!edge.to) {
            return (
              <g key={`end-${index}`}>
                <line x1={x1} y1={y1} x2={x1 + 30} y2={y1} className="stroke-muted-foreground" strokeWidth={1} strokeDasharray="3 3" />
                <text x={x1 + 34} y={y1 + 4} className="fill-muted-foreground text-[10px]">END</text>
              </g>
            );
          }

          const to = layout.pos.get(edge.to);
          if (!to) return null;
          const x2 = to.x;
          const y2 = to.y + BOX_H / 2;
          const mx = (x1 + x2) / 2;

          return (
            <g key={`edge-${index}`}>
              <path d={`M${x1},${y1} C${mx},${y1} ${mx},${y2} ${x2},${y2}`} className="stroke-muted-foreground" fill="none" strokeWidth={1.2} markerEnd="url(#arrow)" />
              <text x={mx} y={(y1 + y2) / 2 - 4} textAnchor="middle" className="fill-muted-foreground text-[10px]">
                {edge.label.length > 16 ? `${edge.label.slice(0, 15)}...` : edge.label}
              </text>
            </g>
          );
        })}
        {nodes.map((node) => {
          const point = layout.pos.get(node.key);
          if (!point) return null;

          return (
            <g
              key={node.key}
              className="cursor-grab active:cursor-grabbing"
              onClick={() => onSelect?.(node.key)}
              onPointerDown={(event) => startDrag(event, node.key)}
            >
              <rect
                x={point.x}
                y={point.y}
                width={BOX_W}
                height={BOX_H}
                rx={6}
                className={node.is_start ? 'fill-primary/20 stroke-primary' : 'fill-background stroke-border'}
                strokeWidth={selectedKey === node.key ? 2.4 : 1.2}
              />
              <text x={point.x + BOX_W / 2} y={point.y + BOX_H / 2 + 4} textAnchor="middle" className="select-none fill-foreground text-xs">
                {node.title.length > 18 ? `${node.title.slice(0, 17)}...` : node.title}
              </text>
            </g>
          );
        })}
      </svg>
    </div>
  );
}
