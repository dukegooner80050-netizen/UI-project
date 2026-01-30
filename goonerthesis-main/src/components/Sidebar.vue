<script setup>
import { computed, ref, onMounted } from "vue"
import { useRoute, useRouter } from "vue-router"
import { getCurrentUser } from "../services/storage"
import { logout as doLogout } from "../services/auth"

// Pending count is optional—wire later.
// For now keep it reactive with a placeholder.
const pendingCount = ref(0)

const router = useRouter()
const route = useRoute()

const user = computed(() => getCurrentUser())
const isAdmin = computed(() => (user.value?.role || "").toLowerCase() === "admin")

function logout() {
  doLogout()
  router.push("/login")
}

const active = (path) => route.path === path
</script>

<template>
  <aside class="sidebar">
    <h5 class="text-center mb-4 text-white">C.I.M.S</h5>

    <!-- Profile -->
    <div class="text-center mb-4">
      <img
        src="../assets/testimg.png"
        class="rounded-circle mb-2"
        width="70"
        height="70"
        alt="Admin"
      />

      <h6 class="text-white mb-0">{{ user?.name || user?.username || "" }}</h6>
      <small class="text-light">{{ user?.role || "" }}</small>

      <div class="dropdown mt-2">
        <a
          class="text-white text-decoration-none dropdown-toggle"
          href="#"
          data-bs-toggle="dropdown"
        >
          Account
        </a>

        <ul class="dropdown-menu dropdown-menu-dark">
          <li><a class="dropdown-item" href="#">Profile</a></li>
          <li><a class="dropdown-item" href="#">Change Password</a></li>
          <li><hr class="dropdown-divider" /></li>
          <li>
            <a class="dropdown-item text-danger" href="#" @click.prevent="logout">
              Logout
            </a>
          </li>
        </ul>
      </div>
    </div>

    <hr class="text-light" />

    <!-- Links -->
    <RouterLink class="sidebar-link" :class="{ active: active('/dashboard') }" to="/dashboard">
      Dashboard
    </RouterLink>

    <RouterLink class="sidebar-link" :class="{ active: active('/inventory') }" to="/inventory">
      Inventory
    </RouterLink>

    <!-- Uniforms dropdown (hover like your HTML) -->
    <div class="sidebar-item dropdown-hover">
      <RouterLink class="sidebar-link" :class="{ active: active('/uniforms') }" to="/uniforms">
        Uniforms
      </RouterLink>

      <div class="dropdown-menu-hover">
        <RouterLink class="dropdown-link" to="/uniforms?course=BSMT">BSMT</RouterLink>
        <RouterLink class="dropdown-link" to="/uniforms?course=BSCrim">BSCrim</RouterLink>
        <RouterLink class="dropdown-link" to="/uniforms?course=BSHM">BSHM</RouterLink>
        <RouterLink class="dropdown-link" to="/uniforms?course=BSIT">BSIT</RouterLink>
        <RouterLink class="dropdown-link" to="/uniforms?course=Senior%20High%20School">
          Senior High School
        </RouterLink>
      </div>
    </div>

    <RouterLink
      class="sidebar-link"
      :class="{ active: active('/office-supplies') }"
      to="/office-supplies"
    >
      Office Supplies
    </RouterLink>

    <RouterLink
      class="sidebar-link"
      :class="{ active: active('/school-equipment') }"
      to="/school-equipment"
    >
      School Equipments
    </RouterLink>

    <RouterLink
      class="sidebar-link d-flex justify-content-between align-items-center"
      :class="{ active: active('/pending-requests') }"
      to="/pending-requests"
    >
      Pending Requests
      <span class="badge bg-danger" id="pendingCount">{{ pendingCount }}</span>
    </RouterLink>

    <RouterLink
      v-if="isAdmin"
      class="sidebar-link"
      :class="{ active: active('/reports') }"
      to="/reports"
    >
      Reports
    </RouterLink>

    <RouterLink class="sidebar-link" :class="{ active: active('/logs') }" to="/logs">
      Logs
    </RouterLink>
  </aside>
</template>

<style scoped>
.sidebar {
  position: sticky;
  top: 0;
  height: 100vh;
  background-color: #31ce12;
  padding-top: 20px;
  overflow-y: auto;
  overflow-x: visible;
  z-index: 9999;
}

.sidebar-link {
  display: block;
  padding: 10px 15px;
  margin: 4px 8px;
  border-radius: 8px;
  color: #e5e7eb;
  text-decoration: none;
}

.sidebar-link:hover {
  background: #374151;
  color: #fff;
}

.sidebar-link.active {
  background: #2563eb;
  color: #fff;
}

.sidebar-item {
  position: relative;
}

.dropdown-hover .dropdown-menu-hover {
  display: none;
  position: absolute;
  left: 30%;
  top: 70%;
  background-color: #1f2937;
  min-width: 200px;
  border-radius: 6px;
  padding: 5px 0;
  z-index: 1000;
}

.dropdown-hover:hover .dropdown-menu-hover {
  display: block;
}

.dropdown-link {
  display: block;
  padding: 10px 15px;
  color: #ffffff;
  text-decoration: none;
  font-size: 14px;
}

.dropdown-link:hover {
  background-color: rgba(255, 255, 255, 0.15);
}
</style>
