import axios from "../axios";
import { cached } from "./sharedCache";

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
  return cached("my-overdue", fetchMyOverdue);
}

async function fetchMyOverdue() {
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
// itemIds (optional): confirm only those lines; omit to confirm everything left.
export async function confirmReceived(id, itemIds = []) {
    const response = await axios.put(`/requests/${id}/confirm-received`, { item_ids: itemIds });
    return response.data;
}

// AMO: cancel an approved request nothing was handed over for (frees held stock).
export async function cancelApprovedRequest(id, reason) {
    const response = await axios.put(`/requests/${id}/cancel`, { reason });
    return response.data;
}

// releaseMethod: "For Pick Up" | "For Delivery" (roadmap #15 / #19).
export async function releaseRequest(id, releaseMethod, studentName = "", studentId = "") {
  const payload = { release_method: releaseMethod };
  if (studentName) payload.released_to_student = studentName;
  if (studentId) payload.released_student_id = studentId;
  const { data } = await axios.put(`/requests/${id}/release`, payload);
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