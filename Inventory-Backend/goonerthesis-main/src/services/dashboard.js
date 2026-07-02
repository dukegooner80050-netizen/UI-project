
import { getInventory, getRequests } from "./storage";

export function computeDashboardStats() {
  const items = getInventory();
  const requests = getRequests();

  const total = items.reduce((acc, i) => acc + (Number(i.qty) || 0), 0);
  const available = items.filter(i => i.status === "Available").reduce((acc, i) => acc + (Number(i.qty) || 0), 0);
  const borrowed = items.filter(i => i.status === "Borrowed").reduce((acc, i) => acc + (Number(i.qty) || 0), 0);
  const damaged = items.filter(i => i.status === "Damaged").reduce((acc, i) => acc + (Number(i.qty) || 0), 0);

  const categoryCounts = {};
  const statusCounts = { Available: 0, Borrowed: 0, Damaged: 0 };

  items.forEach(i => {
    const qty = Number(i.qty) || 0;
    const cat = i.category || "Unknown";
    categoryCounts[cat] = (categoryCounts[cat] || 0) + qty;

    if (statusCounts[i.status] !== undefined) statusCounts[i.status] += qty;
  });

  const lowStock = items.filter(i => (Number(i.qty) || 0) <= 5).slice(0, 20);
  const recentRequests = (requests || []).slice(-5).reverse();

  return {
    totals: { total, available, borrowed, damaged },
    charts: { categoryCounts, statusCounts },
    lowStock,
    recentRequests,
  };
}
