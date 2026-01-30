// src/services/storage.js
const INVENTORY_KEY = "inventory";
const REQUESTS_KEY = "requests";
const LOG_KEY = "activeLogs";
const USER_KEY = "currentUser";

export const keys = {
  INVENTORY_KEY,
  REQUESTS_KEY,
  LOG_KEY,
  USER_KEY,
};

function readJSON(key, fallback) {
  try {
    const raw = localStorage.getItem(key);
    return raw ? JSON.parse(raw) : fallback;
  } catch {
    return fallback;
  }
}

function writeJSON(key, value) {
  localStorage.setItem(key, JSON.stringify(value));
}

export function getInventory() {
  return readJSON(INVENTORY_KEY, []);
}
export function saveInventory(items) {
  writeJSON(INVENTORY_KEY, items);
}
export function clearInventory() {
  localStorage.removeItem(INVENTORY_KEY);
}

export function getRequests() {
  return readJSON(REQUESTS_KEY, []);
}
export function saveRequests(requests) {
  writeJSON(REQUESTS_KEY, requests);
}

export function getLogs() {
  return readJSON(LOG_KEY, []);
}
export function saveLogs(logs) {
  writeJSON(LOG_KEY, logs);
}

export function getCurrentUser() {
  return readJSON(USER_KEY, null);
}
export function setCurrentUser(user) {
  writeJSON(USER_KEY, user);
}
export function clearCurrentUser() {
  localStorage.removeItem(USER_KEY);
}
