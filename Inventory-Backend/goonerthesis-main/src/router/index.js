import { createRouter, createWebHistory } from "vue-router";
import { getCurrentUser } from "../services/storage";
import AppLayout from "../layouts/AppLayout.vue";

// Views
import DashboardView from "../views/DashboardView.vue";
import InventoryView from "../views/InventoryView.vue";
import UniformsView from "../views/UniformsView.vue";
import OfficeSuppliesView from "../views/OfficeSuppliesView.vue";
import SchoolEquipmentView from "../views/SchoolEquipmentView.vue";
import RequestView from "../views/RequestView.vue";
import PendingRequestsView from "../views/PendingRequestsView.vue";
import ReportsView from "../views/ReportsView.vue";
import ActiveLogsView from "../views/ActiveLogsView.vue";
import ItemLocatorView from "../views/ItemLocatorView.vue";
import ConsumptionReportView from "../views/ConsumptionReportView.vue";
import EquipmentDistributionView from "../views/EquipmentDistributionView.vue";
import PendingInspectionView from "../views/PendingInspectionView.vue";
import SettingsView from "../views/SettingsView.vue";
import LoginView from "../views/LoginView.vue";
import SignupView from "../views/SignupView.vue";

// Routes
const routes = [

  // PUBLIC ROUTES

  {
    path: "/login",
    component: LoginView,
    meta: {
      public: true,
    },
  },

  {
    path: "/signup",
    component: SignupView,
    meta: {
      public: true,
    },
  },


  // PROTECTED ROUTES


  {
    path: "/",
    component: AppLayout,

    children: [
      // Root route
      // The navigation guard below decides whether
      // this goes to Login or Dashboard.
      {
        path: "",
        meta: {
          root: true,
        },
      },

    
      // DASHBOARD
    

      {
        path: "dashboard",
        component: DashboardView,
        meta: {
          requiresAuth: true,
          roles: ["admin", "dean", "cashier"],
        },
      },

    
      // ADMIN ONLY
    

      {
        path: "inventory",
        component: InventoryView,
        meta: {
          requiresAuth: true,
          roles: ["admin"],
        },
      },

      {
        path: "uniforms",
        component: UniformsView,
        meta: {
          requiresAuth: true,
          roles: ["admin"],
        },
      },

      {
        path: "office-supplies",
        component: OfficeSuppliesView,
        meta: {
          requiresAuth: true,
          roles: ["admin"],
        },
      },

      {
        path: "school-equipment",
        component: SchoolEquipmentView,
        meta: {
          requiresAuth: true,
          roles: ["admin"],
        },
      },

      {
        path: "pending-requests",
        component: PendingRequestsView,
        meta: {
          requiresAuth: true,
          roles: ["admin"],
        },
      },

      {
        path: "reports",
        component: ReportsView,
        meta: {
          requiresAuth: true,
          roles: ["admin"],
        },
      },

      {
        path: "logs",
        component: ActiveLogsView,
        meta: {
          requiresAuth: true,
          roles: ["admin"],
        },
      },

      {
        path: "item-locator",
        component: ItemLocatorView,
        meta: {
          requiresAuth: true,
          roles: ["admin"],
        },
      },

      {
        path: "consumption-report",
        component: ConsumptionReportView,
        meta: {
          requiresAuth: true,
          roles: ["admin", "dean", "cashier"],
        },
      },

      {
        path: "equipment-distribution",
        component: EquipmentDistributionView,
        meta: {
          requiresAuth: true,
          roles: ["admin"],
        },
      },

      {
        path: "pending-inspection",
        component: PendingInspectionView,
        meta: {
          requiresAuth: true,
          roles: ["admin"],
        },
      },

      {
        path: "settings",
        component: SettingsView,
        meta: {
          requiresAuth: true,
          roles: ["admin"],
        },
      },

    
      // REQUEST
    

      {
        path: "request",
        component: RequestView,
        meta: {
          requiresAuth: true,
          roles: ["admin", "dean", "cashier"],
        },
      },
    ],
  },


  // INVALID ROUTES


  {
    path: "/:pathMatch(.*)*",
    meta: {
      notFound: true,
    },
  },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
});

// NAVIGATION GUARD

router.beforeEach((to) => {
  const user = getCurrentUser();

  // ROOT (/)

  if (to.path === "/") {
    if (user) {
      return "/dashboard";
    }

    return "/login";
  }

  // PUBLIC PAGES

  if (to.meta?.public) {
    return true;
  }

  // INVALID / UNKNOWN ROUTE

  if (to.meta?.notFound) {
    if (user) {
      return "/dashboard";
    }

    return "/login";
  }

  // AUTHENTICATION CHECK

  if (to.meta?.requiresAuth && !user) {
    return "/login";
  }

  // ROLE CHECKING

  const roles = to.meta?.roles;

  if (roles && user) {
    const role = (user.role || "").toLowerCase();

    if (!roles.includes(role)) {
      // Prevent infinite redirect
      if (to.path !== "/dashboard") {
        return "/dashboard";
      }

      return false;
    }
  }

  // ALLOW NAVIGATION

  return true;
});

export default router;