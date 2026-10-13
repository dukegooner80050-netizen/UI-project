import axios from "../axios";

// Rooms that have equipment, with how many units can still be reported.
export async function getReportLocations() {
  return (await axios.get("/equipment-reports/locations")).data;
}

// Admin: every report. Everyone else: their own.
export async function getEquipmentReports() {
  return (await axios.get("/equipment-reports")).data;
}

export async function createEquipmentReport(payload) {
  return (await axios.post("/equipment-reports", payload)).data;
}

// Admin only.
export async function sendReportToInspection(id) {
  return (await axios.put(`/equipment-reports/${id}/send-to-inspection`)).data;
}

export async function dismissReport(id, notes) {
  return (await axios.put(`/equipment-reports/${id}/dismiss`, { notes })).data;
}
