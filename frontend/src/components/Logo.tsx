import { useId } from 'react';

/**
 * Visionary Lab brand mark — "the gate".
 *
 * A square plate (the camera gate / VR viewport) with the V cut clean through
 * it as negative space, and the L standing beside it as a solid accent pillar.
 * The V is a real knockout: whatever surface the logo sits on shows through it,
 * so the mark adapts to white headers, dark heroes and browser tabs alike.
 *
 * Geometry lives on a 64x64 grid, hand-built from a handful of anchor points so
 * the mark stays crisp from 16px (favicon) up to poster size.
 * Keep these paths in sync with `public/favicon.svg` and `docs/logo*.svg`.
 */

export type LogoVariant = 'mark' | 'full';
export type LogoTone = 'dark-bg' | 'light-bg' | 'mono';

export interface LogoProps {
  /** `mark` = square plate only. `full` = plate + "Visionary Lab" lockup. */
  variant?: LogoVariant;
  /**
   * Ink set: `light-bg` = structural blue plate + accent L, `dark-bg` = light
   * plate + accent L, `mono` = flat white with both letters knocked out.
   * Colours come from the design tokens, so a palette change retints the mark.
   */
  tone?: LogoTone;
  /** Square side for `mark`, total height for `full`. Defaults to 38 / 44. */
  size?: number;
  /**
   * Plate corner radius in 64-grid units (4 ≈ 2.4px at the 38px header size).
   * Lower it if the design system moves to tighter radii.
   */
  radius?: number;
  /**
   * Accessible name. When provided the SVG becomes `role="img"`; when omitted
   * the logo is decorative (`aria-hidden`) — use that next to visible text.
   */
  title?: string;
  className?: string;
}

/**
 * Ink is expressed as design tokens with hard-coded fallbacks, so a palette
 * change (e.g. retinting the accent to the Politecnico orange) retints
 * the mark without touching its geometry. The fallbacks keep the component
 * self-contained wherever the stylesheet is absent (tests, isolated renders).
 */
const PLATE_PRIMARY = 'var(--color-primary, #00284B)';
const PLATE_LIGHT = 'var(--color-surface, #FFFFFF)';
const ACCENT = 'var(--color-accent, #EF7B02)';
const WORD_ON_LIGHT = 'var(--color-primary, #00284B)';
const WORD_ON_DARK = 'var(--color-on-dark, #FFFFFF)';
const SUB_ON_LIGHT = 'var(--color-ink-subtle, #6A7A8C)';
const SUB_ON_DARK = 'var(--color-on-dark-muted, #B6C8D6)';
/** Monochrome stays literal white: it must never be retinted. */
const MONO = '#FFFFFF';

/** The V, as a window cut out of the plate. Seven anchors, flat-cut terminals. */
const V_PATH = 'M7.5 11H16L20.25 34.8L24.5 11H33L23.5 53H17Z';
/** The L, one stroked polyline: same 8.5 weight as the V's stems. */
const L_PATH = 'M40.75 11V48.75H56.5';
const L_WEIGHT = 8.5;
/** Plate corner, in 64-grid units — 4 ≈ 2.4px at the 38px header size, in
    keeping with the near-square 3px geometry of the rest of the system. */
const PLATE_RADIUS = 4;

/** Same stack as --font-display: the wordmark is structural type, so Poppins. */
const FONT_STACK =
  "Poppins, Roboto, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', 'Helvetica Neue', Arial, sans-serif";

const LOCKUP_WIDTH = 252;
const LOCKUP_HEIGHT = 64;

interface ToneInk {
  /** Plate colour — the mark's dominant ink. */
  plate: string;
  /** Accent L, or `null` when the L is knocked out of the plate too (mono). */
  accent: string | null;
  word: string;
  sub: string;
  subOpacity?: number;
}

const TONES: Record<LogoTone, ToneInk> = {
  'light-bg': { plate: PLATE_PRIMARY, accent: ACCENT, word: WORD_ON_LIGHT, sub: SUB_ON_LIGHT },
  'dark-bg': { plate: PLATE_LIGHT, accent: ACCENT, word: WORD_ON_DARK, sub: SUB_ON_DARK },
  mono: { plate: MONO, accent: null, word: MONO, sub: MONO, subOpacity: 0.72 },
};

export function Logo({
  variant = 'mark',
  tone = 'light-bg',
  size,
  radius = PLATE_RADIUS,
  title,
  className,
}: LogoProps) {
  // useId() emits ":r0:"; strip the punctuation so the id is safe inside url(#…).
  const maskId = `vl-logo-${useId().replace(/[^a-zA-Z0-9]/g, '')}`;
  const ink = TONES[tone];
  const knockoutL = ink.accent === null;

  const height = size ?? (variant === 'full' ? 44 : 38);
  const width = variant === 'full' ? (height * LOCKUP_WIDTH) / LOCKUP_HEIGHT : height;

  const a11y: { role?: 'img'; 'aria-label'?: string; 'aria-hidden'?: boolean } = title
    ? { role: 'img', 'aria-label': title }
    : { 'aria-hidden': true };

  const plate = (
    <>
      <mask id={maskId} maskUnits="userSpaceOnUse" x="0" y="0" width="64" height="64">
        <rect width="64" height="64" rx={radius} fill="#fff" />
        <path d={V_PATH} fill="#000" />
        {knockoutL ? <path d={L_PATH} fill="none" stroke="#000" strokeWidth={L_WEIGHT} /> : null}
      </mask>
      <rect width="64" height="64" rx={radius} fill={ink.plate} mask={`url(#${maskId})`} />
      {knockoutL ? null : <path d={L_PATH} fill="none" stroke={ink.accent ?? ACCENT} strokeWidth={L_WEIGHT} />}
    </>
  );

  if (variant === 'mark') {
    return (
      <svg
        className={className}
        width={width}
        height={height}
        viewBox="0 0 64 64"
        focusable="false"
        {...a11y}
      >
        {plate}
      </svg>
    );
  }

  return (
    <svg
      className={className}
      width={width}
      height={height}
      viewBox={`0 0 ${LOCKUP_WIDTH} ${LOCKUP_HEIGHT}`}
      focusable="false"
      {...a11y}
    >
      {plate}
      <text
        x="80"
        y="32"
        fontFamily={FONT_STACK}
        fontSize="22"
        fontWeight="700"
        letterSpacing="-0.4"
        fill={ink.word}
      >
        Visionary Lab
      </text>
      <text
        x="81"
        y="48.5"
        fontFamily={FONT_STACK}
        fontSize="8.6"
        fontWeight="600"
        letterSpacing="1.75"
        fill={ink.sub}
        opacity={ink.subOpacity}
      >
        POLITECNICO DI TORINO
      </text>
    </svg>
  );
}
