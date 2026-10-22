import { createRouter, createWebHistory } from "vue-router";
import { getCurrentUser } from "../services/storage";
import AppLayout from "../layouts/AppLayout.vue";

// Every page is loaded the first time it is opened (not all up front), so
// the login screen and the first page appear much sooner.

// Views
const DashboardView = () => import("../views/DashboardView.vue");
const InventoryView = () => import("../views/InventoryView.vue");
const DisposalRecordsView = () => import("../views/DisposalRecordsView.vue");
const UniformsView = () => import("../views/UniformsView.vue");
const OfficeSuppliesView = () => import("../views/OfficeSuppliesView.vue");
const SchoolEquipmentView = () => import("../views/SchoolEquipmentView.vue");
const RequestView = () => import("../views/RequestView.vue");
const PendingRequestsView = () => import("../views/PendingRequestsView.vue");
const CoordinatorRequestsView = () => import("../views/CoordinatorRequestsView.vue");
const ReportsView = () => import("../views/ReportsView.vue");
const ActiveLogsView = () => import("../views/ActiveLogsView.vue");
const ConsumptionReportView = () => import("../views/ConsumptionReportView.vue");
const ChangePasswordView = () => import("../views/ChangePasswordView.vue");
const EquipmentDistributionView = () => import("../views/EquipmentDistributionView.vue");
const PendingInspectionView = () => import("../views/PendingInspectionView.vue");
const EquipmentReportsView = () => import("../views/EquipmentReportsView.vue");
const SettingsView = () => import("../views/SettingsView.vue");
const LoginView = () => import("../views/LoginView.vue");
const SignupView = () => import("../views/SignupView.vue");

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


  // Forced after the AMO resets a password (see the guard below).
  {
    path: "/change-password",
    component: ChangePasswordView,
    meta: {
      requiresAuth: true,
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
          roles: ["amo", "department_head", "admin", "instructor", "cashier"],
        },
      },

    
      // ADMIN ONLY
    

      {
        path: "inventory",
        component: InventoryView,
        meta: {
          requiresAuth: true,
          roles: ["amo"],
        },
      },

      {
        path: "disposal-records",
        component: DisposalRecordsView,
        meta: {
          requiresAuth: true,
          roles: ["amo"],
        },
      },

      {
        path: "uniforms",
        component: UniformsView,
        meta: {
          requiresAuth: true,
          roles: ["amo"],
        },
      },

      {
        path: "office-supplies",
        component: OfficeSuppliesView,
        meta: {
          requiresAuth: true,
          roles: ["amo"],
        },
      },

      {
        path: "school-equipment",
        component: SchoolEquipmentView,
        meta: {
          requiresAuth: true,
          roles: ["amo"],
        },
      },

      {
        path: "coordinator-requests",
        component: CoordinatorRequestsView,
        meta: {
          requiresAuth: true,
          roles: ["department_head"],
        },
      },

      {
        path: "pending-requests",
        component: PendingRequestsView,
        meta: {
          requiresAuth: true,
          roles: ["amo"],
        },
      },

      {
        path: "reports",
        component: ReportsView,
        meta: {
          requiresAuth: true,
          roles: ["amo"],
        },
      },

      {
        path: "logs",
        component: ActiveLogsView,
        meta: {
          requiresAuth: true,
          roles: ["amo"],
        },
      },

      {
        path: "consumption-report",
        component: ConsumptionReportView,
        meta: {
          requiresAuth: true,
          roles: ["amo", "department_head", "admin", "instructor", "cashier"],
        },
      },

      {
        path: "equipment-distribution",
        component: EquipmentDistributionView,
        meta: {
          requiresAuth: true,
          roles: ["amo"],
        },
      },

      {
        path: "equipment-reports",
        component: EquipmentReportsView,
        meta: {
          requiresAuth: true,
          roles: ["amo", "department_head", "admin", "instructor", "cashier"],
        },
      },

      {
        path: "pending-inspection",
        component: PendingInspectionView,
        meta: {
          requiresAuth: true,
          roles: ["amo"],
        },
      },

      {
        path: "settings",
        component: SettingsView,
        meta: {
          requiresAuth: true,
          roles: ["amo", "department_head", "admin", "instructor", "cashier"],
        },
      },

    
      // REQUEST
    

      {
        path: "request",
        component: RequestView,
        meta: {
          requiresAuth: true,
          roles: ["amo", "department_head", "admin", "instructor", "cashier"],
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

  // TEMPORARY PASSWORD: nothing but the change-password page until it is changed

  if (user?.must_change_password && to.path !== "/change-password") {
    return "/change-password";
  }

  if (user && !user.must_change_password && to.path === "/change-password") {
    return "/dashboard";
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