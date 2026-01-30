import { getInventory, getRequests, getLogs } from "./storage";

function parseDateLike(d) {
  if (!d) return null;
  const dt = new Date(d);
  return Number.isNaN(dt.getTime()) ? null : dt;
}

function withinDays(dateObj, days) {
  if (!dateObj) return true;
  const end = new Date();
  const start = new Date();
  start.setHours(0, 0, 0, 0);
  start.setDate(end.getDate() - (days - 1));
  return dateObj >= start && dateObj <= end;
}

export function computeReports(days = 7) {
  const items = getInventory();
  const requests = getRequests();
  const logs = getLogs();

  const totalQty = items.reduce((a, i) => a + (Number(i.qty) || 0), 0);
  const lowStock = items.filter(i => (Number(i.qty) || 0) <= 5).length;
  const categoriesTracked = new Set(items.map(i => i.category || "Uncategorized")).size;

  const recentReq = requests.filter(r => withinDays(parseDateLike(r.date), days));
  const pendingInRange = recentReq.filter(r => (r.status || "").toLowerCase() === "pending").length;

  const recentLogs = logs.filter(l => {
    const d =
      parseDateLike(l.timestamp) ||
      parseDateLike(`${l.date || ""} ${l.time || ""}`) ||
      parseDateLike(l.date);
    return withinDays(d, days);
  });

  const byAction = {};
  for (const l of recentLogs) {
    const a = l.action || "ACTION";
    byAction[a] = (byAction[a] || 0) + (Number(l.quantity) || 1);
  }

  const actions = Object.entries(byAction)
    .sort((a, b) => b[1] - a[1])
    .map(([action, count]) => ({ action, count }));

  return {
    rangeDays: days,
    inventory: { totalQty, lowStock, categoriesTracked },
    requests: { inRange: recentReq.length, pendingInRange },
    logs: { inRange: recentLogs.length, actions },
  };
}
