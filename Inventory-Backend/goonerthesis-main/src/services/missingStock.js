import axios from "../axios";

// { open, history, missing_total }
export async function getMissingStock() {
  return (await axios.get("/missing-stock")).data;
}

export async function markStockFound(id, quantity) {
  return (await axios.put(`/missing-stock/${id}/found`, { quantity })).data;
}
