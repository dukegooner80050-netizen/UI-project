// What an approved request is waiting for, shown as a small badge next to its
// status. Returns null when there is nothing extra to say.
//   uniforms: Paid · ready for pickup (paid at the cashier before the request)
//   items:    Reserved · awaiting release  (stock is held, not yet deducted)
export function fulfilmentState(r) {
  if (!r || String(r.status || "").toLowerCase() !== "approved" || r.releasedAt) {
    return null;
  }
  if ((r.uniforms || []).length > 0) {
    // The student pays at the cashier before the request is made.
    return { text: "Paid · ready for pickup", cls: "bg-success" };
  }
  if (r.stockHeld) {
    return { text: "Reserved · awaiting release", cls: "bg-secondary" };
  }
  return null;
}
