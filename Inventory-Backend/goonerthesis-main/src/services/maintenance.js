import axios from "../axios";

// { open: [...], history: [...] }
export async function getMaintenanceRecords() {
  return (await axios.get("/maintenance-records")).data;
}

// outcome: "repaired" | "unrepairable"; notes required for "unrepairable".
export async function resolveMaintenance(id, { outcome, quantity, notes }) {
  return (
    await axios.put(`/maintenance-records/${id}/resolve`, {
      outcome,
      quantity,
      notes: notes || null,
    })
  ).data;
}
