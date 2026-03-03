<script setup>
import { computed, ref, onMounted, onBeforeUnmount, watch } from "vue"
import { useRoute, useRouter } from "vue-router"
import { getCurrentUser, getRequests } from "../services/storage"
import { logout as doLogout } from "../services/auth"

const showUniforms = ref(false)

const toggleUniforms = () => {
  if (!props.collapsed) {
  showUniforms.value = !showUniforms.value
  }
}

const props = defineProps({
  collapsed: { type: Boolean, default: false },
  mobileOpen: { type: Boolean, default: false },
})
const emit = defineEmits(["toggle", "closeMobile"])

const pendingCount = ref(0)

function refreshPendingCount() {
  try {
    const reqs = getRequests() || []
    pendingCount.value = reqs.filter(r => (r.status || "").toLowerCase() === "pending").length
  } catch {
    pendingCount.value = 0
  }
}

const router = useRouter()
const route = useRoute()

const user = computed(() => getCurrentUser())
const isAdmin = computed(() => (user.value?.role || "").toLowerCase() === "admin")

function logout() {
  doLogout()
  router.push("/login")
}

const active = (path) => route.path === path

watch(() => route.fullPath, () => refreshPendingCount())

function onStorage(e) {
  if (!e || e.key === "requests") refreshPendingCount()
}

onMounted(() => {
  refreshPendingCount()
  window.addEventListener("storage", onStorage)
})

onBeforeUnmount(() => {
  window.removeEventListener("storage", onStorage)
})
</script>

<template>
  <aside class="sidebar" :class="{ collapsed: props.collapsed, mobileOpen: props.mobileOpen }">
    <!-- TOP BAR -->
    <div class="topbar">
      <button class="toggle-btn" type="button" @click="emit('toggle')" :title="props.collapsed ? 'Expand' : 'Collapse'">
        ☰
      </button>

      <h5 v-if="!props.collapsed" class="title text-white mb-0">C.I.M.S</h5>
    </div>

    <!-- Profile (hide when collapsed) -->
    <div v-if="!props.collapsed" class="text-center">
      <img
        src="../assets/testimg.png"
        class="rounded-circle mb-2"
        width="70"
        height="70"
        alt="User"
      />

      <h6 class="text-white mb-0">{{ user?.name || user?.username || "" }}</h6>
      <small class="text-light">{{ user?.role || "" }}</small>

      <div class="dropdown mt-2">
        <a class="text-white text-decoration-none dropdown-toggle" href="#" data-bs-toggle="dropdown">
          Account
        </a>

        <ul class="dropdown-menu dropdown-menu-dark">
          <li><hr class="dropdown-divider" /></li>
          <li>
            <a class="dropdown-item text-danger" href="#" @click.prevent="logout">Logout</a>
          </li>
          <li><hr class="dropdown-divider" /></li>
        </ul>
      </div>
    </div>

    <hr v-if="!props.collapsed" class="text-light" />

    <!-- Links -->
    <RouterLink class="sidebar-link" :class="{ active: active('/dashboard') }" to="/dashboard">
      <span class="icon">🏠</span>
      <span class="label" v-if="!props.collapsed">Dashboard</span>
    </RouterLink>

    <RouterLink v-if="isAdmin" class="sidebar-link" :class="{ active: active('/inventory') }" to="/inventory">
      <span class="icon">📦</span>
      <span class="label" v-if="!props.collapsed">Inventory</span>
    </RouterLink>

<!-- Uniforms dropdown (CLICK VERSION) -->
<div v-if="isAdmin" class="sidebar-item">

  <!-- Clickable Parent -->
  <div
    class="sidebar-link"
    :class="{ active: active('/uniforms') }"
    @click="toggleUniforms"
  >
    <span class="icon">👕</span>
    <span class="label" v-if="!props.collapsed">Uniforms</span>

    <!-- Arrow -->
    <span
      v-if="!props.collapsed"
      style="margin-left:auto; transition:0.2s"
      :style="{ transform: showUniforms ? 'rotate(180deg)' : 'rotate(0deg)' }"
    >
      ▼
    </span>
  </div>

  <!-- Dropdown -->
  <div
    v-if="showUniforms && !props.collapsed"
    class="dropdown-menu-click"
  >  <RouterLink class="dropdown-link" to="/uniforms">All Uniforms</RouterLink>
    <RouterLink class="dropdown-link" to="/uniforms?course=BSMT">BSMT</RouterLink>
    <RouterLink class="dropdown-link" to="/uniforms?course=BSCrim">BSCrim</RouterLink>
    <RouterLink class="dropdown-link" to="/uniforms?course=BSHM">BSHM</RouterLink>
    <RouterLink class="dropdown-link" to="/uniforms?course=BSIT">BSIT</RouterLink>
    <RouterLink class="dropdown-link" to="/uniforms?course=Senior%20High%20School">
      Senior High School
    </RouterLink>
  </div>

</div>

    <RouterLink v-if="isAdmin" class="sidebar-link" :class="{ active: active('/office-supplies') }" to="/office-supplies">
      <span class="icon">🧾</span>
      <span class="label" v-if="!props.collapsed">Office Supplies</span>
    </RouterLink>

    <RouterLink v-if="isAdmin" class="sidebar-link" :class="{ active: active('/school-equipment') }" to="/school-equipment">
      <span class="icon">🎒</span>
      <span class="label" v-if="!props.collapsed">School Equipments</span>
    </RouterLink>

    <RouterLink class="sidebar-link" :class="{ active: active('/request') }" to="/request">
      <span class="icon">📝</span>
      <span class="label" v-if="!props.collapsed">Request Item</span>
    </RouterLink>

    <RouterLink
      v-if="isAdmin"
      class="sidebar-link d-flex justify-content-between align-items-center"
      :class="{ active: active('/pending-requests') }"
      to="/pending-requests"
    >
      <span class="d-flex align-items-center gap-2">
        <span class="icon">⏳</span>
        <span class="label" v-if="!props.collapsed">Pending Requests</span>
      </span>

      <span v-if="!props.collapsed" class="badge bg-danger">{{ pendingCount }}</span>
    </RouterLink>

    <RouterLink v-if="isAdmin" class="sidebar-link" :class="{ active: active('/reports') }" to="/reports">
      <span class="icon">📊</span>
      <span class="label" v-if="!props.collapsed">Reports</span>
    </RouterLink>

    <RouterLink v-if="isAdmin" class="sidebar-link" :class="{ active: active('/logs') }" to="/logs">
      <span class="icon">📜</span>
      <span class="label" v-if="!props.collapsed">Logs</span>
    </RouterLink>
  </aside>
</template>

<style scoped>
.sidebar {
  position: sticky;
  top: 0;
  height: 100vh;
  background-color: #31ce12;
  padding: 12px 8px;
  overflow-y: auto;
  overflow-x: visible;
  z-index: 9999;

  width: 260px;
  transition: width 0.2s ease;
}

/* Mobile drawer behavior */
@media (max-width: 767.98px) {
  .sidebar {
    position: fixed;
    left: 0;
    top: 0;
    height: 100vh;
    z-index: 1600;
    transform: translateX(-105%);
    transition: transform 0.2s ease;
    width: 260px; /* keep normal width */
  }
  .sidebar.mobileOpen {
    transform: translateX(0);
  }
}

/* collapsed width */
.sidebar.collapsed {
  width: 74px;
}

/* top bar */
.topbar {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 6px 14px 6px;
}

.toggle-btn {
  border: none;
  border-radius: 10px;
  padding: 6px 10px;
  background: rgba(255, 255, 255, 0.2);
  color: #fff;
  cursor: pointer;
}
.toggle-btn:hover {
  background: rgba(255, 255, 255, 0.28);
}

.title {
  font-weight: 700;
}

/* links */
.sidebar-link {
  display: flex;
  align-items: center;
  gap: 10px;

  padding: 10px 12px;
  margin: 4px 6px;
  border-radius: 10px;
  color: #e5e7eb;
  text-decoration: none;
  white-space: nowrap;
}

.sidebar-link:hover {
  background: #374151;
  color: #fff;
}

.sidebar-link.active {
  background: #2563eb;
  color: #fff;
}

.icon {
  width: 22px;
  display: inline-flex;
  justify-content: center;
}

/* dropdown hover menu */
.sidebar-item {
  position: relative;
}

.dropdown-menu-click {
  background-color: #1f2937;
  margin: 4px 16px;
  border-radius: 6px;
  padding: 5px 0;
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

/* when collapsed, disable hover dropdown entirely */
.disabledDropdown .dropdown-menu-hover {
  display: none !important;
}
</style>
