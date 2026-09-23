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

/** LOGOUT */
export async function logout(){

    await axios.post("/logout");

    localStorage.removeItem("token");

    clearCurrentUser();
}

/** HELPERS */
export function isLoggedIn() {
  return !!getCurrentUser();
}
