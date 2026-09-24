import axios from "../axios";

export async function getPendingInspections() {
    return (await axios.get("/inspections")).data;
}

export async function inspectItem(id, outcome) {
    return (await axios.put(`/inspections/${id}`, { outcome })).data;
}

export async function returnToService(itemId, quantity) {
    return (await axios.put(`/items/${itemId}/return-to-service`, { quantity })).data;
}
