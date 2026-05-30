import {
  getRequests,
  saveRequests,
  getInventory,
  saveInventory,
} from "./storage";
import { logAction } from "./logs";

export function listRequests() {
  const reqs = getRequests();

  return reqs.filter(r => {
    return (
      r &&
      typeof r === "object" &&
      Array.isArray(r.items) &&
      r.items.length > 0 &&
      r.requester
    );
  });
}

export function createRequest({
  itemId = null,
  itemName,
  category,
  itemType,
  qty,
  location,
  purpose,
  requester,
  role,
}) {
  const q = Number(qty) || 0;

  if (!itemName || !category || !itemType || !purpose || !location || q <= 0) {
    throw new Error("INVALID_REQUEST");
  }

  const requests = getRequests();

  const req = {
    id: Date.now(),
    itemId,
    itemName,
    category,
    itemType,
    qty: q,
    location,
    purpose,
    requester: requester || "User",
    role: role || "",
    status: "Pending",
    date: new Date().toLocaleDateString(),
    createdAt: new Date().toISOString(),

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
  const req = requests.find((r) => r.id === id);

  if (!req) throw new Error("REQUEST_NOT_FOUND");

  req.status = status;
  saveRequests(requests);
  return req;
}

export function approveRequest(id, adminName = "") {
  const requests = getRequests();
  const req = requests.find((r) => r.id === id);

  if (!req) throw new Error("REQUEST_NOT_FOUND");
  if (req.status !== "Pending") {
    throw new Error("REQUEST_ALREADY_PROCESSED");
  }

const inv = getInventory();

// ✅ SUPPORT BOTH: batch AND old single requests
if (req.items) {
  // 🔥 NEW BATCH SYSTEM
  for (const reqItem of req.items) {
    const item = inv.find((i) => i.id === reqItem.itemId);

    if (!item) throw new Error("ITEM_NOT_FOUND");

    const qty = Number(reqItem.qty) || 0;

    if (qty > (Number(item.qty) || 0)) {
      throw new Error("NOT_ENOUGH_STOCK");
    }

    const isConsumable =
      item.category === "Office Supplies" &&
      item.subCategory === "Consumables";

    if (!isConsumable) {
      if (!item.transactions) item.transactions = [];

      item.transactions.push({
        borrower: req.requester,
        qty,
        location: req.location,
        borrowedAt: new Date().toISOString(),
        returnedAt: null,
      });
    }

    item.borrower = req.requester;

    // 🔥 UPDATE STOCK
    item.qty -= qty;

    if (!isConsumable) {
      item.borrowedQty = (Number(item.borrowedQty) || 0) + qty;
    }

    autoStatus(item);
    logAction("APPROVE_REQUEST", item, qty);
  }

} else {
  // 🟡 OLD SINGLE REQUEST (fallback)
  const item = inv.find((i) => i.id === req.itemId);

  if (!item) throw new Error("ITEM_NOT_FOUND");

  const qty = Number(req.qty) || 0;

  if (qty > (Number(item.qty) || 0)) {
    throw new Error("NOT_ENOUGH_STOCK");
  }

  item.qty -= qty;
  autoStatus(item);

  logAction("APPROVE_REQUEST", item, qty);
}

req.status = "Approved";
req.processedAt = new Date().toISOString();
req.processedBy = adminName;

saveInventory(inv);
saveRequests(requests);

return req;
}

//  CLEAN BORROW FUNCTION (Placed OUTSIDE approveRequest)
function applyBorrow(item, qty) {
  item.qty = (Number(item.qty) || 0) - qty;
  item.borrowedQty = (Number(item.borrowedQty) || 0) + qty;

  autoStatus(item);
}

//  Auto status helper (needed for applyBorrow)
function autoStatus(item) {
  const qty = Number(item.qty) || 0;
  const borrowed = Number(item.borrowedQty) || 0;

  const isConsumable =
    item.category === "Office Supplies" &&
    item.subCategory === "Consumables";

  if (isConsumable) {
    //  Consumables NEVER use Borrowed status
    item.borrowedQty = 0;
    item.borrower = "";
    item.location = "";

    if (qty === 0) {
      item.status = "Out of Stock";
    } else if (qty <= 5) {
      item.status = "Low Stock";
    } else {
      item.status = "Available";
    }

    return;
  }

  //  Non-Consumables
  if (borrowed > 0) {
    item.status = "Borrowed";
  } else if (qty === 0) {
    item.status = "Out of Stock";
  } else if (qty <= 5) {
    item.status = "Low Stock";
  } else {
    item.status = "Available";
  }
}


export function rejectRequest(id, reason = "", adminName = "") {
  const requests = getRequests();
  const req = requests.find((r) => r.id === id);

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

export function createRequestBatch(batch) {
  if (!batch || !Array.isArray(batch.items) || batch.items.length === 0) {
    throw new Error("INVALID_BATCH");
  }

  const requests = getRequests();

  requests.push(batch);

  saveRequests(requests);

  return batch;
}