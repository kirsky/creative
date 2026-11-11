import {
  defaultKeyboardCoordinateGetter,
  KeyboardCode,
  pointerWithin,
  rectIntersection,
} from '@dnd-kit/core';

/**
 * Left and right arrows carry the dragged card to the next column that accepts a drop, centred on
 * it; the default getter would need dozens of presses to cross a column. Other keys keep the
 * default behaviour.
 * @type {import('@dnd-kit/core').KeyboardCoordinateGetter}
 */
export function columnKeyboardCoordinates(event, args) {
  const { code } = event;
  if (code !== KeyboardCode.Right && code !== KeyboardCode.Left) {
    return defaultKeyboardCoordinateGetter(event, args);
  }
  const { droppableContainers, droppableRects, collisionRect } = args.context;
  if (!collisionRect) {
    return undefined;
  }
  const direction = code === KeyboardCode.Right ? 1 : -1;
  const currentCentre = collisionRect.left + collisionRect.width / 2;

  /** @type {{rect: import('@dnd-kit/core').ClientRect, distance: number} | undefined} */
  let nearest;
  for (const container of droppableContainers.getEnabled()) {
    const rect = droppableRects.get(container.id);
    if (rect === undefined) {
      continue;
    }
    const distance = (rect.left + rect.width / 2 - currentCentre) * direction;
    if (distance > 1 && (nearest === undefined || distance < nearest.distance)) {
      nearest = { rect, distance };
    }
  }
  if (nearest === undefined) {
    return undefined;
  }
  event.preventDefault();
  return {
    x: nearest.rect.left + nearest.rect.width / 2 - collisionRect.width / 2,
    y: nearest.rect.top + 8,
  };
}

/**
 * Under the pointer a column counts when the pointer is inside it; by keyboard, when the card
 * overlaps it.
 * @type {import('@dnd-kit/core').CollisionDetection}
 */
export function boardCollisions(args) {
  return args.pointerCoordinates ? pointerWithin(args) : rectIntersection(args);
}
