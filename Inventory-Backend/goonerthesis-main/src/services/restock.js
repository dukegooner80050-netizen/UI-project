import axios from "../axios";

export async function getLowStock() {
  return (await axios.get("/restock/low-stock")).data;
}

export async function getRestockRequests(status) {
  return (
    await axios.get("/restock-requests", {
      params: status ? { status } : {},
    })
  ).data;
}

export async function createRestockRequest(iditems, quantity_needed, notes) {
  return (
    await axios.post("/restock-requests", {
      iditems,
      quantity_needed,
      notes: notes || null,
    })
  ).data;
}

export async function updateRestockStatus(id, status, quantity_received) {
  return (
    await axios.put(`/restock-requests/${id}/status`, {
      status,
      quantity_received: quantity_received || null,
    })
  ).data;
}
