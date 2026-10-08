<script setup>
import { ref, reactive, computed, onMounted } from "vue";
import {
  getMonthlyRequestLimit,
  updateMonthlyRequestLimit,
} from "../services/settings";
import { getTheme, setTheme } from "../services/theme";
import { getCurrentUser } from "../services/storage";
import { isAdmin } from "../services/session";
import {
  listAccountRequests,
  approveAccountRequest,
  declineAccountRequest,
} from "../services/accountRequests";
import { getUsers, updateUser, deleteUser } from "../services/users";
import { getErrorMessage } from "../services/errors";
import AlertMessage from "../components/AlertMessage.vue";

// Only admins get the Account Requests / Account Management tabs. Everyone
// else only ever sees General. The server enforces this too (those
// endpoints are role:admin-gated) -- this just keeps the page from
// showing tabs/controls a non-admin couldn't use anyway.
const canEditLimit = computed(() => isAdmin(getCurrentUser()));
const currentUser = getCurrentUser();

const activeTab = ref("GENERAL"); // GENERAL | ACCOUNT_REQUESTS | ACCOUNT_MANAGEMENT

// ===== SHARED ALERT (used by every tab) =====

const showAlert = ref(false);
const alertType = ref("success");
const alertMessage = ref("");

function notify(type, message) {
  alertType.value = type;
  alertMessage.value = message;
  showAlert.value = true;
}

/* ============================================================
 * GENERAL TAB -- Appearance + Monthly Request Limit
 * ============================================================ */

const theme = ref(getTheme());

function chooseTheme(value) {
  if (theme.value === value) return;
  theme.value = value;
  setTheme(value);
  notify("success", value === "dark" ? "Dark mode enabled." : "Light mode enabled.");
}

const loading = ref(true);
const saving = ref(false);
const currentLimit = ref(null);
const newLimit = ref(5);
const showConfirm = ref(false);

async function loadGeneral() {
  if (!canEditLimit.value) {
    loading.value = false;
    return;
  }

  loading.value = true;

  try {
    const res = await getMonthlyRequestLimit();

    currentLimit.value = res.monthly_request_limit;
    newLimit.value = res.monthly_request_limit;
  } catch (e) {
    notify("danger", getErrorMessage(e));
  } finally {
    loading.value = false;
  }
}

function openConfirm() {
  if (!canEditLimit.value) return;

  if (!newLimit.value || newLimit.value < 1) {
    notify("warning", "Limit must be at least 1.");
    return;
  }

  if (Number(newLimit.value) === Number(currentLimit.value)) {
    notify("info", "The monthly request limit is already set to this value.");
    return;
  }

  showConfirm.value = true;
}

function closeConfirm() {
  if (saving.value) return;
  showConfirm.value = false;
}

async function confirmSave() {
  if (!newLimit.value || newLimit.value < 1) {
    showConfirm.value = false;
    notify("warning", "Limit must be at least 1.");
    return;
  }

  saving.value = true;

  try {
    const res = await updateMonthlyRequestLimit(newLimit.value);
    currentLimit.value = res.monthly_request_limit;
    showConfirm.value = false;
    notify("success", "Monthly request limit updated successfully.");
  } catch (e) {
    showConfirm.value = false;
    notify("danger", getErrorMessage(e));
  } finally {
    saving.value = false;
  }
}

/* ============================================================
 * ACCOUNT REQUESTS TAB -- people who signed up, waiting approval
 * ============================================================ */

const arLoading = ref(true);
const arRequests = ref([]);
const arRoleChoice = reactive({}); // { [idUsers]: "dean" | "cashier" | "admin" }
const ROLE_LABELS = { dean: "Dean", cashier: "Cashier", admin: "Admin" };

async function loadAccountRequests() {
  if (!canEditLimit.value) {
    arLoading.value = false;
    return;
  }

  arLoading.value = true;

  try {
    arRequests.value = await listAccountRequests();
    arRequests.value.forEach((r) => {
      if (!arRoleChoice[r.idUsers]) arRoleChoice[r.idUsers] = "dean";
    });
  } catch (e) {
    notify("danger", getErrorMessage(e));
  } finally {
    arLoading.value = false;
  }
}

function formatDate(date) {
  if (!date) return "-";
  return new Date(date).toLocaleString("en-PH", {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  });
}

const arConfirmOpen = ref(false);
const arConfirmAction = ref(""); // "approve" | "decline"
const arConfirmTarget = ref(null);
const arConfirmRole = ref("dean");
const arWorking = ref(false);

function arOpenApprove(request) {
  arConfirmAction.value = "approve";
  arConfirmTarget.value = request;
  arConfirmRole.value = arRoleChoice[request.idUsers] || "dean";
  arConfirmOpen.value = true;
}

function arOpenDecline(request) {
  arConfirmAction.value = "decline";
  arConfirmTarget.value = request;
  arConfirmOpen.value = true;
}

function arCloseConfirm() {
  if (arWorking.value) return;
  arConfirmOpen.value = false;
  arConfirmTarget.value = null;
}

async function arRunConfirmed() {
  if (!arConfirmTarget.value || arWorking.value) return;

  const target = arConfirmTarget.value;
  arWorking.value = true;

  try {
    if (arConfirmAction.value === "approve") {
      await approveAccountRequest(target.idUsers, arConfirmRole.value);
      notify("success", `Approved "${target.full_name}" as ${ROLE_LABELS[arConfirmRole.value]}.`);
    } else {
      await declineAccountRequest(target.idUsers);
      notify("success", `Declined the request from "${target.full_name}".`);
    }

    arWorking.value = false;
    arConfirmOpen.value = false;
    arConfirmTarget.value = null;
    await loadAccountRequests();
  } catch (e) {
    arWorking.value = false;
    arConfirmOpen.value = false;
    arConfirmTarget.value = null;
    notify("danger", getErrorMessage(e));
    await loadAccountRequests(); // may have been handled by another admin already
  }
}

/* ============================================================
 * ACCOUNT MANAGEMENT TAB -- reassign roles / remove users
 * ============================================================ */

const amLoading = ref(true);
const amUsers = ref([]);
const amRoleChoice = reactive({}); // { [idUsers]: "admin" | "dean" | "cashier" }

async function loadUsers() {
  if (!canEditLimit.value) {
    amLoading.value = false;
    return;
  }

  amLoading.value = true;

  try {
    amUsers.value = await getUsers();
    amUsers.value.forEach((u) => {
      amRoleChoice[u.idUsers] = u.role;
    });
  } catch (e) {
    notify("danger", getErrorMessage(e));
  } finally {
    amLoading.value = false;
  }
}

function isSelf(user) {
  return currentUser && Number(user.idUsers) === Number(currentUser.idUsers);
}

function amRoleChanged(user) {
  return amRoleChoice[user.idUsers] !== user.role;
}

const amConfirmOpen = ref(false);
const amConfirmAction = ref(""); // "role" | "delete"
const amConfirmTarget = ref(null);
const amWorking = ref(false);

function amOpenRoleChange(user) {
  if (isSelf(user)) return;
  amConfirmAction.value = "role";
  amConfirmTarget.value = user;
  amConfirmOpen.value = true;
}

function amOpenDelete(user) {
  if (isSelf(user)) return;
  amConfirmAction.value = "delete";
  amConfirmTarget.value = user;
  amConfirmOpen.value = true;
}

function amCloseConfirm() {
  if (amWorking.value) return;
  amConfirmOpen.value = false;
  amConfirmTarget.value = null;
}

async function amRunConfirmed() {
  if (!amConfirmTarget.value || amWorking.value) return;

  const user = amConfirmTarget.value;
  amWorking.value = true;

  try {
    if (amConfirmAction.value === "role") {
      const newRole = amRoleChoice[user.idUsers];

      await updateUser(user.idUsers, {
        full_name: user.full_name,
        username: user.username,
        role: newRole,
      });

      notify("success", `${user.full_name}'s role is now ${ROLE_LABELS[newRole] || newRole}.`);
    } else {
      await deleteUser(user.idUsers);
      notify("success", `Removed "${user.full_name}".`);
    }

    amWorking.value = false;
    amConfirmOpen.value = false;
    amConfirmTarget.value = null;
    await loadUsers();
  } catch (e) {
    amWorking.value = false;
    amConfirmOpen.value = false;
    amConfirmTarget.value = null;
    notify("danger", getErrorMessage(e));
    await loadUsers();
  }
}

/* ============================================================ */

onMounted(async () => {
  await Promise.all([loadGeneral(), loadAccountRequests(), loadUsers()]);
});
</script>

<template>
  <div>
    <!-- ALERT MESSAGE (shared across every tab) -->
    <AlertMessage
      v-model:show="showAlert"
      :type="alertType"
      :message="alertMessage"
    />

    <h3 class="mb-1">Settings</h3>

    <p class="text-muted mb-3">
      {{ canEditLimit ? "System-wide configuration." : "Your display preferences." }}
    </p>

    <!-- TABS (General is always available; the other two are admin-only) -->
    <div class="d-flex gap-2 mb-3">
      <button
        type="button"
        class="btn"
        :class="activeTab === 'GENERAL' ? 'btn-primary' : 'btn-outline-primary'"
        @click="activeTab = 'GENERAL'"
      >
        General
      </button>

      <button
        v-if="canEditLimit"
        type="button"
        class="btn d-flex align-items-center gap-2"
        :class="activeTab === 'ACCOUNT_REQUESTS' ? 'btn-primary' : 'btn-outline-primary'"
        @click="activeTab = 'ACCOUNT_REQUESTS'"
      >
        Account Requests
        <span v-if="arRequests.length > 0" class="badge bg-danger">{{ arRequests.length }}</span>
      </button>

      <button
        v-if="canEditLimit"
        type="button"
        class="btn"
        :class="activeTab === 'ACCOUNT_MANAGEMENT' ? 'btn-primary' : 'btn-outline-primary'"
        @click="activeTab = 'ACCOUNT_MANAGEMENT'"
      >
        Account Management
      </button>
    </div>

    <!-- ============================================================ -->
    <!-- GENERAL TAB -->
    <!-- ============================================================ -->

    <template v-if="activeTab === 'GENERAL'">

      <!-- APPEARANCE -->
      <div class="card shadow-sm mb-3" style="max-width: 480px">
        <div class="card-body">

          <h6 class="mb-2">Appearance</h6>

          <p class="text-muted small">
            Choose how CIMS looks on this device.
          </p>

          <div class="d-flex gap-2">
            <button
              type="button"
              class="btn"
              :class="theme === 'light' ? 'btn-primary' : 'btn-outline-primary'"
              @click="chooseTheme('light')"
            >
              <i class="bi bi-sun-fill me-1"></i> Light Mode
            </button>

            <button
              type="button"
              class="btn"
              :class="theme === 'dark' ? 'btn-primary' : 'btn-outline-primary'"
              @click="chooseTheme('dark')"
            >
              <i class="bi bi-moon-stars-fill me-1"></i> Dark Mode
            </button>
          </div>

        </div>
      </div>

      <div v-if="canEditLimit && loading" class="text-center text-muted py-4">
        Loading...
      </div>

      <div v-else-if="canEditLimit" class="card shadow-sm" style="max-width: 480px">
        <div class="card-body">

          <h6 class="mb-2">Monthly Request Limit</h6>

          <p class="text-muted small">
            Maximum number of requests a cashier or dean can submit per
            calendar month. Currently:
            <strong>{{ currentLimit }}</strong> per month.
          </p>

          <div class="input-group">
            <input
              type="number"
              min="1"
              class="form-control"
              v-model.number="newLimit"
              :disabled="saving"
            />

            <button class="btn btn-primary" :disabled="saving" @click="openConfirm">
              Save
            </button>
          </div>

        </div>
      </div>

    </template>

    <!-- ============================================================ -->
    <!-- ACCOUNT REQUESTS TAB -->
    <!-- ============================================================ -->

    <template v-else-if="activeTab === 'ACCOUNT_REQUESTS'">

      <p class="text-muted mb-3">
        People who signed up and are waiting to be approved. They cannot
        sign in until you approve them.
      </p>

      <div v-if="arLoading && arRequests.length === 0" class="text-center text-muted py-4">
        Loading...
      </div>

      <div v-else class="card shadow-sm">
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Name</th>
                  <th>Username</th>
                  <th>Signed up</th>
                  <th style="width: 150px">Role</th>
                  <th style="width: 200px"></th>
                </tr>
              </thead>

              <tbody>
                <tr v-for="r in arRequests" :key="r.idUsers">
                  <td>{{ r.full_name }}</td>
                  <td>{{ r.username }}</td>
                  <td>{{ formatDate(r.requested_at) }}</td>
                  <td>
                    <select
                      class="form-select form-select-sm"
                      v-model="arRoleChoice[r.idUsers]"
                      :aria-label="'Role for ' + r.full_name"
                    >
                      <option value="dean">Dean</option>
                      <option value="cashier">Cashier</option>
                      <option value="admin">Admin</option>
                    </select>
                  </td>
                  <td>
                    <div class="d-flex gap-2">
                      <button class="btn btn-sm btn-success" @click="arOpenApprove(r)">
                        Approve
                      </button>
                      <button class="btn btn-sm btn-danger text-white" @click="arOpenDecline(r)">
                        Decline
                      </button>
                    </div>
                  </td>
                </tr>

                <tr v-if="arRequests.length === 0">
                  <td colspan="5" class="text-center text-muted py-4">
                    No accounts are waiting for approval.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </template>

    <!-- ============================================================ -->
    <!-- ACCOUNT MANAGEMENT TAB -->
    <!-- ============================================================ -->

    <template v-else-if="activeTab === 'ACCOUNT_MANAGEMENT'">

      <p class="text-muted mb-3">
        Every account in the system. Reassign a role or remove an account
        that no longer needs access.
      </p>

      <div v-if="amLoading && amUsers.length === 0" class="text-center text-muted py-4">
        Loading...
      </div>

      <div v-else class="card shadow-sm">
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Name</th>
                  <th>Username</th>
                  <th style="width: 170px">Role</th>
                  <th style="width: 230px"></th>
                </tr>
              </thead>

              <tbody>
                <tr v-for="u in amUsers" :key="u.idUsers">
                  <td>
                    {{ u.full_name }}
                    <span v-if="isSelf(u)" class="badge bg-secondary ms-1">You</span>
                  </td>
                  <td>{{ u.username }}</td>
                  <td>
                    <select
                      class="form-select form-select-sm"
                      v-model="amRoleChoice[u.idUsers]"
                      :disabled="isSelf(u)"
                      :aria-label="'Role for ' + u.full_name"
                    >
                      <option value="admin">Admin</option>
                      <option value="dean">Dean</option>
                      <option value="cashier">Cashier</option>
                    </select>
                  </td>
                  <td>
                    <div class="d-flex gap-2">
                      <button
                        class="btn btn-sm btn-primary"
                        :disabled="isSelf(u) || !amRoleChanged(u)"
                        @click="amOpenRoleChange(u)"
                      >
                        Save Role
                      </button>
                      <button
                        class="btn btn-sm btn-danger text-white"
                        :disabled="isSelf(u)"
                        :title="isSelf(u) ? 'You can\'t remove your own account.' : ''"
                        @click="amOpenDelete(u)"
                      >
                        Remove
                      </button>
                    </div>
                  </td>
                </tr>

                <tr v-if="amUsers.length === 0">
                  <td colspan="4" class="text-center text-muted py-4">
                    No accounts found.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </template>

    <!-- ============================================================ -->
    <!-- CONFIRMATION MODAL -- Monthly Request Limit -->
    <!-- ============================================================ -->

    <div
      v-if="canEditLimit && showConfirm"
      class="modal fade show d-block"
      tabindex="-1"
      style="background: rgba(0, 0, 0, 0.5);"
    >
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

          <div class="modal-header">
            <h5 class="modal-title">Confirm Change</h5>
            <button
              type="button"
              class="btn-close"
              @click="closeConfirm"
              :disabled="saving"
            ></button>
          </div>

          <div class="modal-body">
            <p class="mb-3">
              Are you sure you want to change the monthly request limit?
            </p>

            <div class="bg-light rounded p-3">
              <div class="d-flex justify-content-between mb-2">
                <span class="text-muted">Current limit</span>
                <strong>{{ currentLimit }}</strong>
              </div>
              <div class="d-flex justify-content-between">
                <span class="text-muted">New limit</span>
                <strong>{{ newLimit }}</strong>
              </div>
            </div>

            <p class="text-muted small mt-3 mb-0">
              This setting applies system-wide to the monthly request
              limit for cashiers and deans.
            </p>
          </div>

          <div class="modal-footer">
            <button
              type="button"
              class="btn btn-secondary"
              @click="closeConfirm"
              :disabled="saving"
            >
              Cancel
            </button>
            <button
              type="button"
              class="btn btn-primary"
              @click="confirmSave"
              :disabled="saving"
            >
              {{ saving ? "Saving..." : "Confirm Change" }}
            </button>
          </div>

        </div>
      </div>
    </div>

    <!-- ============================================================ -->
    <!-- CONFIRMATION MODAL -- Account Requests (approve / decline) -->
    <!-- ============================================================ -->

    <div v-if="arConfirmOpen" class="modal-backdrop-custom">
      <div class="modal-custom" style="max-width: 440px">
        <div class="modal-header">
          <h5 class="mb-0">
            {{ arConfirmAction === "approve" ? "Approve Account" : "Decline Account" }}
          </h5>
          <button
            type="button"
            class="btn-close"
            :disabled="arWorking"
            @click="arCloseConfirm"
          ></button>
        </div>

        <div class="modal-body">
          <p v-if="arConfirmAction === 'approve'" class="mb-2">
            Approve <strong>{{ arConfirmTarget?.full_name }}</strong>
            ({{ arConfirmTarget?.username }}) as
            <strong>{{ ROLE_LABELS[arConfirmRole] }}</strong>? They will be able
            to sign in right away.
          </p>
          <p v-else class="mb-2">
            Decline the request from
            <strong>{{ arConfirmTarget?.full_name }}</strong>
            ({{ arConfirmTarget?.username }})? The sign-up will be removed. They
            can sign up again if this was a mistake.
          </p>

          <p v-if="arConfirmAction === 'approve' && arConfirmRole === 'admin'" class="small text-danger mb-0">
            Admins can change stock, approve requests and manage accounts.
          </p>
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" :disabled="arWorking" @click="arCloseConfirm">
            Cancel
          </button>
          <button
            class="btn"
            :class="arConfirmAction === 'approve' ? 'btn-success' : 'btn-danger'"
            :disabled="arWorking"
            @click="arRunConfirmed"
          >
            {{
              arWorking
                ? "Please wait..."
                : arConfirmAction === "approve"
                  ? "Approve"
                  : "Decline"
            }}
          </button>
        </div>
      </div>
    </div>

    <!-- ============================================================ -->
    <!-- CONFIRMATION MODAL -- Account Management (role change / remove) -->
    <!-- ============================================================ -->

    <div v-if="amConfirmOpen" class="modal-backdrop-custom">
      <div class="modal-custom" style="max-width: 440px">
        <div class="modal-header">
          <h5 class="mb-0">
            {{ amConfirmAction === "role" ? "Change Role" : "Remove Account" }}
          </h5>
          <button
            type="button"
            class="btn-close"
            :disabled="amWorking"
            @click="amCloseConfirm"
          ></button>
        </div>

        <div class="modal-body">
          <p v-if="amConfirmAction === 'role'" class="mb-2">
            Change <strong>{{ amConfirmTarget?.full_name }}</strong>'s role from
            <strong>{{ ROLE_LABELS[amConfirmTarget?.role] || amConfirmTarget?.role }}</strong>
            to
            <strong>{{ ROLE_LABELS[amRoleChoice[amConfirmTarget?.idUsers]] || amRoleChoice[amConfirmTarget?.idUsers] }}</strong>?
          </p>
          <p v-else class="mb-2">
            Remove <strong>{{ amConfirmTarget?.full_name }}</strong>
            ({{ amConfirmTarget?.username }})? They will no longer be able to
            sign in, and this cannot be undone.
          </p>
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" :disabled="amWorking" @click="amCloseConfirm">
            Cancel
          </button>
          <button
            class="btn"
            :class="amConfirmAction === 'role' ? 'btn-primary' : 'btn-danger'"
            :disabled="amWorking"
            @click="amRunConfirmed"
          >
            {{
              amWorking
                ? "Please wait..."
                : amConfirmAction === "role"
                  ? "Confirm Change"
                  : "Remove"
            }}
          </button>
        </div>
      </div>
    </div>

  </div>
</template>

<style scoped>
.modal-backdrop-custom {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.35);
  display: grid;
  place-items: center;
  z-index: 2000;
  padding: 16px;
}

.modal-custom {
  background: var(--bs-body-bg, white);
  color: var(--bs-body-color, inherit);
  width: 100%;
  max-width: 500px;
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 18px 50px rgba(0, 0, 0, 0.25);
}

.modal-header,
.modal-footer {
  padding: 12px 16px;
  border-bottom: 1px solid var(--bs-border-color, #dee2e6);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.modal-footer {
  border-top: 1px solid var(--bs-border-color, #dee2e6);
  border-bottom: none;
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.modal-body {
  padding: 16px;
}
</style>
