import { TYPE_B } from "./itemTypes";

// Shared helpers for borrowed-item due dates (dueAt is "YYYY-MM-DD" or null).

// Only school equipment and Type B non-consumables are lent out and need a
// due date. Takes a request item as returned by GET /requests.
export function isDueEligible(requestItem) {
  return (
    requestItem?.category === "School Equipment" ||
    requestItem?.itemType === TYPE_B
  );
}

export function addDaysISO(days) {
  const d = new Date();
  d.setDate(d.getDate() + Number(days));
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${d.getFullYear()}-${m}-${day}`;
}

export function todayISO() {
  const d = new Date();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${d.getFullYear()}-${m}-${day}`;
}

// A borrowing is overdue if something is still out and the due date has passed.
export function isOverdue(borrower) {
  return (
    !!borrower?.dueAt &&
    Number(borrower.borrowedQty) > 0 &&
    borrower.dueAt < todayISO()
  );
}

export function daysOverdue(borrower) {
  if (!isOverdue(borrower)) return 0;
  const due = new Date(`${borrower.dueAt}T00:00:00`);
  const now = new Date(`${todayISO()}T00:00:00`);
  return Math.round((now - due) / 86400000);
}

export function formatDue(dueAt) {
  if (!dueAt) return "No due date";
  return new Date(`${dueAt}T00:00:00`).toLocaleDateString("en-PH", {
    year: "numeric",
    month: "long",
    day: "numeric",
  });
}

export function formatReturnDays(days) {
  if (!days) return "-";
  return `${days} day${Number(days) === 1 ? "" : "s"}`;
}

// "" -> null, otherwise a whole number, for sending to the API.
export function parseReturnDays(value) {
  if (value === "" || value === null || value === undefined) return null;
  const n = Number(value);
  return Number.isFinite(n) && n >= 1 ? Math.floor(n) : null;
}
