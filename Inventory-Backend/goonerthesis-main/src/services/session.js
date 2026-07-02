import { getCurrentUser, clearCurrentUser } from "./storage";

export function requireUser() {
  const user = getCurrentUser();
  if (!user) throw new Error("NOT_AUTHENTICATED");
  return user;
}

export function isAdmin(user) {
  return (user?.role || "").toLowerCase() === "admin";
}

export function requireAdmin() {
  const user = requireUser();
  if (!isAdmin(user)) throw new Error("NOT_AUTHORIZED");
  return user;
}

export function logout() {
  clearCurrentUser();
}
