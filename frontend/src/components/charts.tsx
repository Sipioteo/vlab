import { useId, type ReactNode } from 'react';

/**
 * Hand-rolled, dependency-free SVG charts.
 * Every chart carries a text alternative (table or list) for screen readers.
 */

export function StatCard({
  label,
  value,
  hint,
  tone,
}: {
  label: string;
  value: ReactNode;
  hint?: string;
  tone?: 'warning' | 'danger' | 'success' | 'accent';
}) {
  return (
    <div className={`vl-stat${tone ? ` vl-stat--${tone}` : ''}`}>
      <span className="vl-stat__label">{label}</span>
      <span className="vl-stat__value">{value}</span>
      {hint ? <span className="vl-stat__hint">{hint}</span> : null}
    </div>
  );
}

export interface Series {
  key: string;
  label: string;
  color: string;
}

/* --------------------------------------------------------------- bar chart */

export function GroupedBarChart({
  data,
  series,
  height = 220,
  caption,
}: {
  data: { bucket: string; [key: string]: number | string }[];
  series: Series[];
  height?: number;
  caption: string;
}) {
  const width = 720;
  const padding = { top: 12, right: 8, bottom: 28, left: 34 };
  const innerW = width - padding.left - padding.right;
  const innerH = height - padding.top - padding.bottom;

  const max = Math.max(
    1,
    ...data.flatMap((row) => series.map((s) => Number(row[s.key] ?? 0))),
  );
  const groupW = data.length > 0 ? innerW / data.length : innerW;
  const barW = Math.max(2, (groupW - 6) / Math.max(1, series.length));
  const ticks = [0, 0.5, 1].map((f) => Math.round(max * f));
  const labelEvery = Math.ceil(data.length / 12);

  return (
    <figure style={{ margin: 0 }}>
      <svg
        className="vl-chart"
        viewBox={`0 0 ${width} ${height}`}
        preserveAspectRatio="xMidYMid meet"
        role="img"
        aria-label={caption}
      >
        {ticks.map((tick, i) => {
          const y = padding.top + innerH - (tick / max) * innerH;
          return (
            <g key={i}>
              <line className="vl-chart__grid" x1={padding.left} x2={width - padding.right} y1={y} y2={y} />
              <text x={padding.left - 6} y={y + 3} textAnchor="end">
                {tick}
              </text>
            </g>
          );
        })}
        {data.map((row, index) => {
          const gx = padding.left + index * groupW;
          return (
            <g key={row.bucket}>
              {series.map((s, si) => {
                const value = Number(row[s.key] ?? 0);
                const h = (value / max) * innerH;
                return (
                  <rect
                    key={s.key}
                    x={gx + 3 + si * barW}
                    y={padding.top + innerH - h}
                    width={Math.max(1, barW - 1)}
                    height={h}
                    fill={s.color}
                    rx={1}
                  >
                    <title>{`${row.bucket} · ${s.label}: ${value}`}</title>
                  </rect>
                );
              })}
              {index % labelEvery === 0 ? (
                <text x={gx + groupW / 2} y={height - 8} textAnchor="middle">
                  {String(row.bucket).slice(-5)}
                </text>
              ) : null}
            </g>
          );
        })}
      </svg>
      <div className="vl-chart-legend">
        {series.map((s) => (
          <span key={s.key}>
            <i style={{ background: s.color }} />
            {s.label}
          </span>
        ))}
      </div>
      <figcaption className="vl-sr-only">{caption}</figcaption>
    </figure>
  );
}

/* -------------------------------------------------------------- line chart */

export function LineChart({
  points,
  height = 180,
  caption,
  color = 'var(--color-primary-500)',
}: {
  points: { label: string; value: number }[];
  height?: number;
  caption: string;
  color?: string;
}) {
  const width = 720;
  const padding = { top: 12, right: 8, bottom: 26, left: 34 };
  const innerW = width - padding.left - padding.right;
  const innerH = height - padding.top - padding.bottom;
  const max = Math.max(1, ...points.map((p) => p.value));
  const gradientId = useId();

  const coords = points.map((p, i) => {
    const x = padding.left + (points.length > 1 ? (i / (points.length - 1)) * innerW : innerW / 2);
    const y = padding.top + innerH - (p.value / max) * innerH;
    return { x, y, ...p };
  });
  const path = coords.map((c, i) => `${i === 0 ? 'M' : 'L'}${c.x.toFixed(1)},${c.y.toFixed(1)}`).join(' ');
  const area =
    coords.length > 0
      ? `${path} L${coords[coords.length - 1]!.x.toFixed(1)},${padding.top + innerH} L${coords[0]!.x.toFixed(1)},${padding.top + innerH} Z`
      : '';

  return (
    <figure style={{ margin: 0 }}>
      <svg
        className="vl-chart"
        viewBox={`0 0 ${width} ${height}`}
        preserveAspectRatio="xMidYMid meet"
        role="img"
        aria-label={caption}
      >
        <defs>
          <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor={color} stopOpacity="0.22" />
            <stop offset="100%" stopColor={color} stopOpacity="0" />
          </linearGradient>
        </defs>
        <line
          className="vl-chart__grid"
          x1={padding.left}
          x2={width - padding.right}
          y1={padding.top + innerH}
          y2={padding.top + innerH}
        />
        <text x={padding.left - 6} y={padding.top + 4} textAnchor="end">
          {max}
        </text>
        {area ? <path d={area} fill={`url(#${gradientId})`} /> : null}
        <path d={path} fill="none" stroke={color} strokeWidth={2} strokeLinejoin="round" />
        {coords.map((c) => (
          <circle key={c.label} cx={c.x} cy={c.y} r={2.5} fill={color}>
            <title>{`${c.label}: ${c.value}`}</title>
          </circle>
        ))}
      </svg>
      <figcaption className="vl-sr-only">{caption}</figcaption>
    </figure>
  );
}

/* ------------------------------------------------------------- donut chart */

export function DonutChart({
  slices,
  caption,
  size = 190,
}: {
  slices: { label: string; value: number; color: string }[];
  caption: string;
  size?: number;
}) {
  const total = slices.reduce((sum, s) => sum + s.value, 0);
  const radius = size / 2 - 12;
  const circumference = 2 * Math.PI * radius;
  let offset = 0;

  return (
    <figure style={{ margin: 0, display: 'flex', gap: 'var(--sp-5)', alignItems: 'center', flexWrap: 'wrap' }}>
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} role="img" aria-label={caption}>
        <g transform={`rotate(-90 ${size / 2} ${size / 2})`}>
          {total === 0 ? (
            <circle
              cx={size / 2}
              cy={size / 2}
              r={radius}
              fill="none"
              stroke="var(--color-surface-sunken)"
              strokeWidth={18}
            />
          ) : (
            slices.map((slice) => {
              const fraction = slice.value / total;
              const dash = fraction * circumference;
              const el = (
                <circle
                  key={slice.label}
                  cx={size / 2}
                  cy={size / 2}
                  r={radius}
                  fill="none"
                  stroke={slice.color}
                  strokeWidth={18}
                  strokeDasharray={`${dash} ${circumference - dash}`}
                  strokeDashoffset={-offset}
                >
                  <title>{`${slice.label}: ${slice.value}`}</title>
                </circle>
              );
              offset += dash;
              return el;
            })
          )}
        </g>
      </svg>
      <ul className="vl-stack" style={{ gap: 'var(--sp-2)', fontSize: 'var(--fs-sm)' }}>
        {slices.map((slice) => (
          <li key={slice.label} style={{ display: 'flex', alignItems: 'center', gap: 'var(--sp-2)' }}>
            <i
              aria-hidden="true"
              style={{ width: 10, height: 10, borderRadius: 2, background: slice.color, display: 'inline-block' }}
            />
            <span style={{ flex: 1 }}>{slice.label}</span>
            <strong>{slice.value}</strong>
          </li>
        ))}
      </ul>
      <figcaption className="vl-sr-only">{caption}</figcaption>
    </figure>
  );
}

/* --------------------------------------------------------- horizontal bars */

export function RankedBars({
  rows,
  caption,
}: {
  rows: { label: string; value: number; hint?: string }[];
  caption: string;
}) {
  const max = Math.max(1, ...rows.map((r) => r.value));
  return (
    <div role="table" aria-label={caption} className="vl-stack" style={{ gap: 'var(--sp-3)' }}>
      {rows.map((row) => (
        <div key={row.label} role="row">
          <div
            style={{ display: 'flex', justifyContent: 'space-between', gap: 'var(--sp-3)', fontSize: 'var(--fs-sm)' }}
          >
            <span role="cell" style={{ minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
              {row.label}
            </span>
            <strong role="cell" style={{ fontVariantNumeric: 'tabular-nums' }}>
              {row.value}
            </strong>
          </div>
          <div className="vl-meter" style={{ marginTop: 4 }}>
            <span className="vl-meter__fill" style={{ width: `${(row.value / max) * 100}%` }} />
          </div>
          {row.hint ? <span className="vl-subtle">{row.hint}</span> : null}
        </div>
      ))}
    </div>
  );
}
