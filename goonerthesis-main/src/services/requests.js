import { getRequests, saveRequests, getInventory, saveInventory } from "./storage";
import { logAction } from "./logs";

export function listRequests() {
  return getRequests();
}

export function createRequest({ itemId = null, itemName, category, itemType, qty, purpose, requester, role }) {
  const q = Number(qty) || 0;
  if (!itemName || !category || !itemType || !purpose || q <= 0) throw new Error("INVALID_REQUEST");

  const requests = getRequests();
  const req = {
    id: Date.now(),
    itemId,
    itemName,
    category,
    itemType,
    qty: q,
    purpose,
    requester: requester || "User",
    role: role || "",
    status: "Pending",
    date: new Date().toLocaleDateString(),
    createdAt: new Date().toISOString(),

  // admin action fields (empty at first)
    processedAt: null,
    processedBy: null,
    rejectReason: "",
  };

  requests.push(req);
  saveRequests(requests);
  return req;
}

export function setRequestStatus(id, status) {
  const requests = getRequests();
  const req = requests.find(r => r.id === id);
  if (!req) throw new Error("REQUEST_NOT_FOUND");

  req.status = status;
  saveRequests(requests);
  return req;
}

export function approveRequest(id, adminName = "") {
  const requests = getRequests();
  const req = requests.find(r => r.id === id);
  if (!req) throw new Error("REQUEST_NOT_FOUND");
  if (req.status !== "Pending") return req;

  const inv = getInventory();

  const resolvedItem =
    inv.find(i => i.id === req.itemId) ||
    inv.find(i =>
      (i.name || "").toLowerCase() === (req.itemName || "").toLowerCase() &&
      (i.category || "") === (req.category || "")
    );

  if (!resolvedItem) throw new Error("ITEM_NOT_FOUND");
  if ((Number(resolvedItem.qty) || 0) < (Number(req.qty) || 0)) throw new Error("NOT_ENOUGH_STOCK");

  resolvedItem.qty -= Number(req.qty) || 0;

  if (resolvedItem.category === "Office Supplies" && resolvedItem.subCategory === "Consumables") {
    if (resolvedItem.qty === 0) resolvedItem.status = "Out of Stock";
    else if (resolvedItem.qty <= 5) resolvedItem.status = "Low Stock";
    else resolvedItem.status = "Available";
  } else {
    resolvedItem.status = resolvedItem.qty === 0 ? "Out of Stock" : resolvedItem.qty <= 5 ? "Low Stock" : "Available";
  }

  req.status = "Approved";
  req.processedAt = new Date().toISOString();
  req.processedBy = adminName || "Admin";
  req.rejectReason = "";
  saveInventory(inv);
  saveRequests(requests);

  logAction("APPROVE_REQUEST", resolvedItem, req.qty);
  return req;
}

export function rejectRequest(id, reason = "", adminName = "") {
  const requests = getRequests();
  const req = requests.find(r => r.id === id);
  if (!req) throw new Error("REQUEST_NOT_FOUND");
  if (req.status !== "Pending") return req;

  const r = (reason || "").trim();
  if (!r) throw new Error("REJECT_REASON_REQUIRED");

  req.status = "Rejected";
  req.rejectReason = r;
  req.processedAt = new Date().toISOString();
  req.processedBy = adminName || "Admin";

  saveRequests(requests);
  return req;
}
