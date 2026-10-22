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

// Monthly Consumption records. The server returns everyone's for the AMO
// and only the caller's own for every other role.
export async function getConsumption() {
    const response = await axios.get("/requests/consumption");
    return response.data;
}

// The signed-in user's own borrowed lines that are past their due date.
export async function getMyOverdue() {
    const response = await axios.get("/requests/overdue/mine", { silent: true });
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

// dueDates: { [requestItemId]: "YYYY-MM-DD" } for borrowed lines (optional).
export async function approveRequest(id, dueDates = {}, approvalRemarks = "") {
    const response = await axios.put(
        `/requests/${id}/approve`,
        { due_dates: dueDates, approval_remarks: approvalRemarks || null }
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

// The requester confirms they received the released items.
export async function confirmReceived(id) {
    const response = await axios.put(`/requests/${id}/confirm-received`);
    return response.data;
}

// releaseMethod: "For Pick Up" | "For Delivery" (roadmap #15 / #19).
export async function releaseRequest(id, releaseMethod, studentName = "") {
  const payload = { release_method: releaseMethod };
  if (studentName) payload.released_to_student = studentName;
  const { data } = await api.put(`/requests/${id}/release`, payload);
  return data;
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