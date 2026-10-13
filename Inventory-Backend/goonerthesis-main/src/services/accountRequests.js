import axios from "../axios";

// Admin side of account approval (people who signed up and are waiting).

export async function listAccountRequests() {
  return (await axios.get("/account-requests")).data;
}

export async function approveAccountRequest(id, role) {
  return (await axios.post(`/account-requests/${id}/approve`, { role })).data;
}

export async function declineAccountRequest(id) {
  return (await axios.post(`/account-requests/${id}/decline`)).data;
}
