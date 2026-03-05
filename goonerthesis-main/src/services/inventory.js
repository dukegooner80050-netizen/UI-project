import { getInventory, saveInventory, clearInventory } from "./storage";
import { logAction } from "./logs";
import { getCurrentUser } from "./storage"

export function autoStatus(item) {
  const qty = Number(item.qty) || 0;

  if (item.category === "Office Supplies" && item.subCategory === "Consumables") {
    if (qty <= 0) item.status = "Out of Stock";
    else if (qty <= 5) item.status = "Low Stock";
    else item.status = "Available";
    return item;
  }

  if (qty <= 0) item.status = "Borrowed";
  else item.status = "Available";
  return item;
}

export function listInventory() {
  return getInventory();
}

export function addItem(item) {
  const all = getInventory();
  const newItem = { id: Date.now(), ...item };
  const nameKey = (item.name || "").trim().toLowerCase();
  const catKey = (item.category || "").trim().toLowerCase();
  const subKey = (item.subCategory || "").trim().toLowerCase();
    const existing = all.find(i =>
    (i.name || "").trim().toLowerCase() === nameKey &&
    (i.category || "").trim().toLowerCase() === catKey &&
    (i.subCategory || "").trim().toLowerCase() === subKey
  );
  const incomingQty = Number(item.qty) || 0;

  if (existing) {
    existing.qty = (Number(existing.qty) || 0) + incomingQty;

    existing.borrowedQty = Number(existing.borrowedQty) || 0;

    autoStatus(existing);
    saveInventory(all);
    return existing;
  }

  newItem.borrowedQty = Number(newItem.borrowedQty) || 0;
  autoStatus(newItem);

  all.push(newItem);
  saveInventory(all);
  return newItem;
}

export function updateItem(id, patch) {
  const all = getInventory();
  const idx = all.findIndex(i => i.id === id);
  if (idx === -1) return null;
  all[idx] = { ...all[idx], ...patch };
  saveInventory(all);
  return all[idx];
}

export function removeItem(id) {
  const all = getInventory().filter(i => i.id !== id);
  saveInventory(all);
  return all;
}

export function clearAllInventory() {
  clearInventory();
}

export function borrowEquipment(id, qty) {
  const all = getInventory();
  const item = all.find(i => i.id === id);
  const q = Number(qty) || 0;

  const user = getCurrentUser(); // get who is borrowing

  if (!item) throw new Error("ITEM_NOT_FOUND");
  if (q <= 0) throw new Error("INVALID_QTY");
  if (q > (Number(item.qty) || 0)) throw new Error("NOT_ENOUGH_STOCK");

  // reduce stock
  item.qty = (Number(item.qty) || 0) - q;
  item.borrowedQty = (Number(item.borrowedQty) || 0) + q;

  // track borrower
  item.borrower = user?.name || user?.username || "Unknown";

  // mark where it moved
  item.location = `Borrowed by ${item.borrower}`;

  item.status = item.qty === 0 ? "Borrowed" : "Available";

  saveInventory(all);

  logAction("BORROW", item, q);

  return item;
}

export function returnEquipment(itemId, qtyToReturn) {
  const inventory = getInventory();
  const item = inventory.find(i => i.id === itemId);

  if (!item) throw new Error("ITEM_NOT_FOUND");

  const returnQty = Number(qtyToReturn) || 0;
  if (returnQty <= 0) throw new Error("INVALID_QTY");

  if (returnQty > (Number(item.borrowedQty) || 0)) {
    throw new Error("RETURN_TOO_MUCH");
  }

  let remaining = returnQty;

  if (item.transactions) {
    for (const t of item.transactions) {
      if (remaining <= 0) break;

      if (!t.returnedAt) {
        const borrowedQty = Number(t.qty);

        if (borrowedQty <= remaining) {
          // Fully return this transaction
          t.returnedAt = new Date().toISOString();
          remaining -= borrowedQty;
        } else {
          // Partial return
          t.qty -= remaining;
          remaining = 0;
        }
      }
    }
  }

  item.borrowedQty -= returnQty;
  item.qty += returnQty;

  // Clean borrower/location if nothing borrowed anymore
  if (item.borrowedQty <= 0) {
    item.borrower = "";
    item.location = "";
  }

  autoStatus(item);

  saveInventory(inventory);

  logAction("RETURN", item, returnQty);

  return item;
}

export function restockOfficeSupplies(ids, qty) {
  const all = getInventory();
  const q = Number(qty) || 0;
  if (q <= 0) throw new Error("INVALID_QTY");

  ids.forEach(id => {
    const item = all.find(i => i.id === id);
    if (!item) return;
    item.qty = (Number(item.qty) || 0) + q;
    autoStatus(item);
    logAction("RESTOCK", item, q);
  });

  saveInventory(all);
  return all;
}

export function releaseOfficeConsumables(ids, qty) {
  const all = getInventory();
  const q = Number(qty) || 0;
  if (q <= 0) throw new Error("INVALID_QTY");

  ids.forEach(id => {
    const item = all.find(i => i.id === id);
    if (!item) return;
    item.qty = Math.max(0, (Number(item.qty) || 0) - q);
    autoStatus(item);
    logAction("RELEASE", item, q);
  });

  saveInventory(all);
  return all;
}

export function borrowNonConsumables(ids, qty) {
  const all = getInventory();
  const q = Number(qty) || 0;
  if (q <= 0) throw new Error("INVALID_QTY");

  for (const id of ids) {
    const item = all.find(i => i.id === id);
    if (!item) continue;
    const available = Number(item.qty) || 0;
    if (q > available) throw new Error(`NOT_ENOUGH_STOCK:${item.name}:${available}`);
  }

  ids.forEach(id => {
    const item = all.find(i => i.id === id);
    if (!item) return;
    item.qty = (Number(item.qty) || 0) - q;
    item.borrowedQty = (Number(item.borrowedQty) || 0) + q;
    item.status = "Borrowed";
    logAction("BORROW", item, q);
  });

  saveInventory(all);
  return all;
}

export function returnNonConsumables(ids, qty) {
  const all = getInventory();
  const q = Number(qty) || 0;
  if (q <= 0) throw new Error("INVALID_QTY");

  const hasReturnable = ids.some(id => {
    const item = all.find(i => i.id === id);
    return item && (Number(item.borrowedQty) || 0) > 0;
  });
  if (!hasReturnable) throw new Error("NOTHING_TO_RETURN");

  ids.forEach(id => {
    const item = all.find(i => i.id === id);
    if (!item) return;

    const borrowed = Number(item.borrowedQty) || 0;
    if (q > borrowed) throw new Error(`RETURN_TOO_MUCH:${item.name}:${borrowed}`);

    item.qty = (Number(item.qty) || 0) + q;
    item.borrowedQty = borrowed - q;
    item.status = item.borrowedQty > 0 ? "Borrowed" : "Available";
    logAction("RETURN", item, q);
  });

  saveInventory(all);
  return all;
}
