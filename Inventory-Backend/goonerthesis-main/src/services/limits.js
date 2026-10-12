// "" / null -> null (no limit), otherwise a whole number >= 1.
export function parseLimit(value) {
  if (value === "" || value === null || value === undefined) return null;
  const n = Number(value);
  return Number.isFinite(n) && n >= 1 ? Math.floor(n) : null;
}
