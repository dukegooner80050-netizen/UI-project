/* Badge text for what an approved request is waiting on (null if nothing). */
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
