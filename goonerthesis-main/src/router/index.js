import { createRouter, createWebHistory } from "vue-router"
import AppLayout from "../layouts/AppLayout.vue"

// Views (create empty placeholder files for now if you haven't yet)
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

import { getCurrentUser } from "../services/storage"

const authGuard = (to) => {
  const user = getCurrentUser()
  if (!user && to.path !== "/login" && to.path !== "/signup") return "/login"
}

export default createRouter({
  history: createWebHistory(),
  routes: [
    { path: "/login", component: LoginView, beforeEnter: authGuard },
    { path: "/signup", component: SignupView, beforeEnter: authGuard },

    {
      path: "/",
      component: AppLayout,
      beforeEnter: authGuard,
      children: [
        { path: "", redirect: "/dashboard" },
        { path: "dashboard", component: DashboardView },
        { path: "inventory", component: InventoryView },
        { path: "uniforms", component: UniformsView },
        { path: "office-supplies", component: OfficeSuppliesView },
        { path: "school-equipment", component: SchoolEquipmentView },
        { path: "request", component: RequestView },
        { path: "pending-requests", component: PendingRequestsView },
        { path: "reports", component: ReportsView },
        { path: "logs", component: ActiveLogsView },
      ],
    },
  ],
})
