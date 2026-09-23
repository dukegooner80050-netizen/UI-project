import axios from "../axios";

export async function getPendingInspections() {
    return (await axios.get("/inspections")).data;
}

export async function inspectItem(id, outcome) {
    return (await axios.put(`/inspections/${id}`, { outcome })).data;
}
