import axios from "../axios";

export async function listRequests() {
    const response = await axios.get("/requests");
    return response.data;
}

export async function getRequest(id) {
    const response = await axios.get(`/requests/${id}`);
    return response.data;
}

export async function createRequest(requestData) {
    const response = await axios.post(
        "/requests",
        requestData
    );

    return response.data;
}

// Alias used by RequestView.vue
export async function getRequests() {
    return await listRequests();
}

// Alias used by RequestView.vue
export async function createRequestBatch(requestData) {
    return await createRequest(requestData);
}

// dueDates: { [requestItemId]: "YYYY-MM-DD" } for school equipment / Type B items.
export async function approveRequest(id, dueDates = {}) {
    const response = await axios.put(
        `/requests/${id}/approve`,
        { due_dates: dueDates }
    );

    return response.data;
}

export async function rejectRequest(id, rejectReason) {
    const response = await axios.put(
        `/requests/${id}/reject`,
        {
            rejectReason,
        }
    );

    return response.data;
}

export async function returnEquipment(
    requestItemId,
    quantity
) {
    const response = await axios.put(
        `/requests/items/${requestItemId}/return`,
        {
            quantity,
        }
    );

    return response.data;
}
// Per-item limits plus how much the logged-in user already has:
// [{ itemId, limit, held, remaining }] (only items that have a limit).
export async function getItemUsage() {
    const response = await axios.get("/item-usage");
    return response.data;
}
