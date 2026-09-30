// Shared device-type → (accent colour, glyph) mapping used everywhere a
// device needs an at-a-glance "what kind of device is this" icon: the
// topology graph page, the device-detail Topology tab's link/LLDP lists,
// and the topology tree. One definition so all three stay visually
// consistent and a new type only needs to be added in one place.
export interface TypeMeta {
  color: string;
  icon: string;
  label: string;
}

export const DEVICE_TYPE_META: Record<string, TypeMeta> = {
  SWITCH: {
    color: '#2f6fed',
    label: 'Switch',
    icon: '<rect x="14" y="24" width="36" height="16" rx="3" fill="none" stroke="#fff" stroke-width="3"/><line x1="20" y1="30" x2="20" y2="34" stroke="#fff" stroke-width="3"/><line x1="27" y1="30" x2="27" y2="34" stroke="#fff" stroke-width="3"/><line x1="34" y1="30" x2="34" y2="34" stroke="#fff" stroke-width="3"/><line x1="41" y1="30" x2="41" y2="34" stroke="#fff" stroke-width="3"/>',
  },
  OLT: {
    color: '#8b5cf6',
    label: 'OLT',
    icon: '<rect x="12" y="30" width="20" height="14" rx="3" fill="none" stroke="#fff" stroke-width="3"/><line x1="32" y1="34" x2="49" y2="20" stroke="#fff" stroke-width="2.5"/><line x1="32" y1="37" x2="49" y2="32" stroke="#fff" stroke-width="2.5"/><line x1="32" y1="40" x2="49" y2="44" stroke="#fff" stroke-width="2.5"/><circle cx="49" cy="20" r="2.4" fill="#fff"/><circle cx="49" cy="32" r="2.4" fill="#fff"/><circle cx="49" cy="44" r="2.4" fill="#fff"/>',
  },
  ONU: {
    color: '#f59e0b',
    label: 'ONU',
    icon: '<rect x="18" y="28" width="24" height="18" rx="2" fill="none" stroke="#fff" stroke-width="3"/><circle cx="24" cy="37" r="1.8" fill="#fff"/><circle cx="30" cy="37" r="1.8" fill="#fff"/><line x1="42" y1="34" x2="50" y2="27" stroke="#fff" stroke-width="2.5"/>',
  },
  ROUTER: {
    color: '#0d9488',
    label: 'Router',
    icon: '<circle cx="32" cy="42" r="3" fill="#fff"/><path d="M22 34 A14 14 0 0 1 42 34" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round"/><path d="M16 27 A22 22 0 0 1 48 27" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round"/>',
  },
  SENSOR: {
    color: '#ec4899',
    label: 'Sensor',
    icon: '<circle cx="32" cy="34" r="12" fill="none" stroke="#fff" stroke-width="3"/><line x1="32" y1="34" x2="39" y2="27" stroke="#fff" stroke-width="3" stroke-linecap="round"/><circle cx="32" cy="34" r="2" fill="#fff"/>',
  },
  UNKNOWN: {
    color: '#94a3b8',
    label: 'Unknown',
    icon: '<text x="32" y="42" font-size="26" font-family="Arial, sans-serif" font-weight="700" fill="#fff" text-anchor="middle">?</text>',
  },
  // An LLDP-discovered neighbor that isn't in this system's own device
  // inventory at all — someone else's switch on a shared uplink, most
  // commonly. A plain up-arrow rather than the generic "?" glyph, since
  // that's genuinely what it is: an uplink out of this network to
  // something unmanaged, not just "a device of unclear type".
  EXTERNAL: {
    color: '#64748b',
    label: 'External / uplink',
    icon: '<path d="M32 45 L32 21" stroke="#fff" stroke-width="4" stroke-linecap="round"/><path d="M22 30 L32 19 L42 30" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>',
  },
};
DEVICE_TYPE_META.ROUTEROS = { ...DEVICE_TYPE_META.ROUTER, label: 'RouterOS' };

export function typeMeta(type: string | undefined | null): TypeMeta {
  return DEVICE_TYPE_META[(type || '').toUpperCase()] || DEVICE_TYPE_META.UNKNOWN;
}

/** Small circular SVG data-URI icon for a device type — usable as an <img src>. */
export function deviceIconUri(type: string | undefined | null): string {
  const meta = typeMeta(type);
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64"><circle cx="32" cy="32" r="32" fill="${meta.color}"/>${meta.icon}</svg>`;
  return `data:image/svg+xml;utf8,${encodeURIComponent(svg)}`;
}

/** Same small device-type glyph, but on a caller-supplied disc colour
 * instead of the fixed per-type accent — used where the disc itself needs
 * to carry the up/down status colour (green/red/grey) and the type glyph
 * is just the small white icon on top of it, not its own colour. */
export function statusIconUri(type: string | undefined | null, bgColor: string): string {
  const meta = typeMeta(type);
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64"><circle cx="32" cy="32" r="32" fill="${bgColor}"/>${meta.icon}</svg>`;
  return `data:image/svg+xml;utf8,${encodeURIComponent(svg)}`;
}

/** Device up/down/unknown → colour, matching the pinger-status convention used across the app. */
export function deviceStatusColor(status: string | null | undefined): string {
  if (status === 'Up') return '#16a34a';
  if (status === 'Down') return '#ef4444';
  return '#c1c4d6';
}

/** Interface (link-end) status colour — same green/up, red/down convention as the topology tree pills. */
export function ifaceStatusColor(status: string | null | undefined): string {
  if (status === 'Up' || status === 'Online') return '#16a34a';
  if (status === 'Down' || status === 'Offline') return '#ef4444';
  return '#c1c4d6';
}

/** `#rrggbb` -> `rgba(r,g,b,alpha)` — used to soften the device up/down
 * status ring to a fraction of full strength without touching the solid
 * colour used for dots/tags/legend swatches elsewhere. */
export function withAlpha(hex: string, alpha: number): string {
  const m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
  if (!m) return hex;
  const r = parseInt(m[1], 16);
  const g = parseInt(m[2], 16);
  const b = parseInt(m[3], 16);
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}
