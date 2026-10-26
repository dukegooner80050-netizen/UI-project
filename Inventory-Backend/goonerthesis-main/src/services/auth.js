// src/services/auth.js
import axios from "../axios";
import { setCurrentUser, getCurrentUser, clearCurrentUser } from "./storage";

/** SIGN UP */
export async function signup({ full_name, username, password }) {
  const response = await axios.post("/register", {
        full_name,
        username,
        password,
  });

  return response.data;
}

/** LOGIN */
export async function login({ username, password }) {
  const response = await axios.post("/login", {
    username,
    password,
  });

  localStorage.setItem("token", response.data.token);
  setCurrentUser(response.data.user);

  return response.data.user;
}

/** CHANGE OWN PASSWORD */
export async function changePassword({ current_password, password, password_confirmation }) {
  const response = await axios.post("/change-password", {
    current_password,
    password,
    password_confirmation,
  });

  // The stored user carries the "must change password" flag; refresh it.
  if (response.data.user) {
    setCurrentUser(response.data.user);
  }

  return response.data;
}

/** LOGOUT */
export async function logout(){

    // Leave even if the server can't be reached or the session already
    // ended: the local sign-out must always happen.
    try {
        await axios.post("/logout");
    } finally {
        localStorage.removeItem("token");

        clearCurrentUser();
    }
}

/** HELPERS */
export function isLoggedIn() {
  return !!getCurrentUser();
}
