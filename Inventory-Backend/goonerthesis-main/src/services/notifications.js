import axios from "../axios";

// The signed-in user's request updates (forwarded / approved / rejected),
// newest first.
export async function getNotifications() {
  const { data } = await axios.get("/request-notifications", { silent: true });
  return Array.isArray(data) ? data : [];
}

// Marks all of the user's request updates as read (clears the bell badge).
export async function markNotificationsRead() {
  const { data } = await axios.post("/request-notifications/read");
  return data;
}
