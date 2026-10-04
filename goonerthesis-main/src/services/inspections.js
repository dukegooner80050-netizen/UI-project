import axios from "../axios";

export async function getPendingInspections() {
    return (await axios.get("/inspections")).data;
}

export async function evaluateInspection(id, { fitForUse, needMaintenance, disposal }) {
    return (await axios.put(`/inspections/${id}`, {
        fit_for_use: fitForUse,
        need_maintenance: needMaintenance,
        disposal: disposal,
    })).data;
}

export async function returnToService(itemId, quantity) {
    return (await axios.put(`/items/${itemId}/return-to-service`, { quantity })).data;
}
