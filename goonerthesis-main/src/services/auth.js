// src/services/auth.js
import { setCurrentUser, getCurrentUser, clearCurrentUser } from "./storage";

/** Users are stored the same way as before */
const USERS_KEY = "users";

function getUsers() {
  try {
    return JSON.parse(localStorage.getItem(USERS_KEY)) || [];
  } catch {
    return [];
  }
}

function saveUsers(users) {
  localStorage.setItem(USERS_KEY, JSON.stringify(users));
}

/** SIGN UP */
export function signup({ name, username, password, role = "user" }) {
  if (!name || !username || !password) {
    throw new Error("COMPLETE_ALL_FIELDS");
  }

  const users = getUsers();

  if (users.some(u => u.username === username)) {
    throw new Error("USERNAME_EXISTS");
  }

  const newUser = { name, username, password, role };
  users.push(newUser);
  saveUsers(users);

  return newUser;
}

/** LOGIN */
export function login({ username, password }) {
  if (!username || !password) {
    throw new Error("COMPLETE_ALL_FIELDS");
  }

  const users = getUsers();
  const foundUser = users.find(
    u => u.username === username && u.password === password
  );

  if (!foundUser) {
    throw new Error("INVALID_CREDENTIALS");
  }

  setCurrentUser(foundUser);
  return foundUser;
}

/** LOGOUT */
export function logout() {
  clearCurrentUser();
}

/** HELPERS */
export function isLoggedIn() {
  return !!getCurrentUser();
}
