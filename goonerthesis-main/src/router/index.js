import { createRouter, createWebHistory } from "vue-router"
import { getCurrentUser } from "../services/storage"
import AppLayout from "../layouts/AppLayout.vue"

// Views
import DashboardView from "../views/DashboardView.vue"
import InventoryView from "../views/InventoryView.vue"
import UniformsView from "../views/UniformsView.vue"
import OfficeSuppliesView from "../views/OfficeSuppliesView.vue"
import SchoolEquipmentView from "../views/SchoolEquipmentView.vue"
import RequestView from "../views/RequestView.vue"
import PendingRequestsView from "../views/PendingRequestsView.vue"
import ReportsView from "../views/ReportsView.vue"
import ActiveLogsView from "../views/ActiveLogsView.vue"
import LoginView from "../views/LoginView.vue"
import SignupView from "../views/SignupView.vue"

// Routes
const routes = [
  { path: "/login", component: LoginView, meta: { public: true } },
  { path: "/signup", component: SignupView, meta: { public: true } },

  {
    path: "/",
    component: AppLayout,
    children: [
      { path: "", redirect: "/dashboard" },

      { path: "dashboard", component: DashboardView, meta: { requiresAuth: true, roles: ["admin", "user"] } },

      // admin-only
      { path: "inventory", component: InventoryView, meta: { requiresAuth: true, roles: ["admin"] } },
      { path: "uniforms", component: UniformsView, meta: { requiresAuth: true, roles: ["admin"] } },
      { path: "office-supplies", component: OfficeSuppliesView, meta: { requiresAuth: true, roles: ["admin"] } },
      { path: "school-equipment", component: SchoolEquipmentView, meta: { requiresAuth: true, roles: ["admin"] } },
      { path: "pending-requests", component: PendingRequestsView, meta: { requiresAuth: true, roles: ["admin"] } },
      { path: "reports", component: ReportsView, meta: { requiresAuth: true, roles: ["admin"] } },
      { path: "logs", component: ActiveLogsView, meta: { requiresAuth: true, roles: ["admin"] } },

      // both can access
      { path: "request", component: RequestView, meta: { requiresAuth: true, roles: ["admin", "user"] } },
    ],
  },

  { path: "/:pathMatch(.*)*", redirect: "/dashboard" },
]


const router = createRouter({
  history: createWebHistory(),
  routes,
})


router.beforeEach((to) => {
  const user = getCurrentUser()


  if (to.meta?.public) {

    return true
  }


  if (to.meta?.requiresAuth && !user) {
    return "/login"
  }

  const roles = to.meta?.roles
  if (roles && user) {
    const role = (user.role || "").toLowerCase()
    if (!roles.includes(role)) return "/dashboard"
  }

  return true
})

export default router
