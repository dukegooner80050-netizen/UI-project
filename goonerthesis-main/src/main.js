import { createApp } from "vue"
import App from "./App.vue"
import router from "./router"


import "bootstrap/dist/css/bootstrap.min.css"
import "bootstrap/dist/js/bootstrap.bundle.min.js"

// --- MOCK USERS (for testing only) ---
(function seedMockUsers() {
  const USERS_KEY = "users";
  const existing = (() => {
    try { return JSON.parse(localStorage.getItem(USERS_KEY)) || []; }
    catch { return []; }
  })();


  if (existing.length > 0) return;

  const mockUsers = [
    { name: "Admin", username: "admin", password: "admin123", role: "admin" },
    { name: "User",  username: "user",  password: "user123",  role: "user"  },
  ];

  localStorage.setItem(USERS_KEY, JSON.stringify(mockUsers));
})();
createApp(App).use(router).mount("#app")
