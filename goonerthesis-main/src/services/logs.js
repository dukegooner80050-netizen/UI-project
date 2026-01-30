// src/services/logs.js
import { getLogs, saveLogs } from "./storage";
import { requireUser } from "./session";

export function logAction(action, item = {}, qty = 0) {
  const user = requireUser();
  const logs = getLogs();

  logs.unshift({
    id: Date.now(),
    action,
    item: item?.name ?? "",
    category: item?.category ?? "",
    quantity: Number(qty) || 0,
    performedBy: user.name || user.username || "User",
    role: user.role,
    date: new Date().toLocaleDateString(),
    time: new Date().toLocaleTimeString(),
  });

  saveLogs(logs);
  return logs;
}
