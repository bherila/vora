/**
 * Shared helpers for rendering the interest catalog as a hierarchy. Used by the
 * admin interests view, the user profile interest editor, and the per-character
 * interest editor so all three sort and indent identically.
 */

export interface InterestNodeBase {
  id: number;
  name: string;
  parent_interest_id: number | null;
}

export type InterestTreeNode<T extends InterestNodeBase> = T & {
  children: InterestTreeNode<T>[];
  depth: number;
};

export function sortByName<T extends { name: string }>(items: T[]): T[] {
  return [...items].sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));
}

/**
 * Build an alphabetically sorted, depth-stamped tree. A node whose parent is
 * missing from the input falls back to a root so nothing is dropped silently.
 */
export function buildInterestTree<T extends InterestNodeBase>(interests: T[]): InterestTreeNode<T>[] {
  const nodes = new Map<number, InterestTreeNode<T>>();
  const roots: InterestTreeNode<T>[] = [];

  for (const interest of interests) {
    nodes.set(interest.id, { ...interest, children: [], depth: 0 });
  }

  for (const node of nodes.values()) {
    if (node.parent_interest_id !== null) {
      const parent = nodes.get(node.parent_interest_id);
      if (parent) {
        parent.children.push(node);
        continue;
      }
    }

    roots.push(node);
  }

  const sortBranch = (branch: InterestTreeNode<T>[], depth: number): InterestTreeNode<T>[] =>
    sortByName(branch).map((node) => ({
      ...node,
      depth,
      children: sortBranch(node.children, depth + 1),
    }));

  return sortBranch(roots, 0);
}

export function flattenInterestTree<T extends InterestNodeBase>(nodes: InterestTreeNode<T>[]): InterestTreeNode<T>[] {
  return nodes.flatMap((node) => [node, ...flattenInterestTree(node.children)]);
}

const DEPTH_PADDING_CLASSES = ['pl-0', 'pl-4', 'pl-8', 'pl-12', 'pl-16', 'pl-20', 'pl-24', 'pl-28', 'pl-32'];

export function getDepthPaddingClass(depth: number): string {
  return DEPTH_PADDING_CLASSES[Math.min(depth, DEPTH_PADDING_CLASSES.length - 1)] ?? 'pl-0';
}

export function collectDescendantIds<T extends InterestNodeBase>(node: InterestTreeNode<T>): Set<number> {
  const ids = new Set<number>();

  for (const child of node.children) {
    ids.add(child.id);
    for (const descendantId of collectDescendantIds(child)) {
      ids.add(descendantId);
    }
  }

  return ids;
}
