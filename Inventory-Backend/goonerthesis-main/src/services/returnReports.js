import axios from "../axios";

// Borrower: "I returned this" for a line of one of their own approved
// requests. quantity = how many units are being reported.
export async function reportReturn(requestItemId, quantity) {
  return (
    await axios.post(`/requests/items/${requestItemId}/report-return`, {
      quantity,
    })
  ).data;
}

// AMO: reports waiting to be checked, oldest first.
export async function listReturnReports() {
  const { data } = await axios.get("/return-reports", { silent: true });
  return Array.isArray(data) ? data : [];
}

// AMO: records the return (units go to inspection) and tells the borrower.
export async function confirmReturnReport(id) {
  return (await axios.put(`/return-reports/${id}/confirm`)).data;
}

// AMO: "not received". A reason is required; the borrower is notified and
// the units go back to counting as borrowed.
export async function rejectReturnReport(id, note) {
  return (await axios.put(`/return-reports/${id}/reject`, { note })).data;
}
