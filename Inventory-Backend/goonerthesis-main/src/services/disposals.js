import axios from "../axios";

export const DISPOSAL_METHODS = [
  "Discarded",
  "Sold as scrap",
  "Donated",
  "Recycled",
  "Other",
];

// Dispose of units that were flagged for disposal at inspection.
// payload: { quantity, method, disposed_on?: "YYYY-MM-DD", notes? }
export async function disposeItem(id, payload) {
  return (await axios.post(`/items/${id}/dispose`, payload)).data;
}

// The permanent disposal history (kept even if the item is later deleted).
export async function getDisposalRecords() {
  return (await axios.get("/disposal-records")).data;
}
