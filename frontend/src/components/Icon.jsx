/** @typedef {import('@phosphor-icons/react').Icon} IconComponent */
/** @typedef {import('@phosphor-icons/react').IconWeight} IconWeight */

/**
 * A Phosphor icon with the app's defaults. Icons are decorative unless given a `label`: a
 * decorative icon is hidden from assistive technology (the text or the accessible name next to it
 * says what it means), a labelled one is an image. A control that is only an icon needs a label
 * on the control itself.
 * @param {object} props
 * @param {IconComponent} props.icon the Phosphor component, imported by name
 * @param {number} [props.size] in pixels
 * @param {IconWeight} [props.weight] regular by default; fill for an active state
 * @param {string} [props.label] accessible name; omit for a decorative icon
 */
export function Icon({ icon: Glyph, size = 16, weight = 'regular', label }) {
  if (label === undefined) {
    return <Glyph size={size} weight={weight} aria-hidden="true" />;
  }
  return <Glyph size={size} weight={weight} role="img" aria-label={label} />;
}
