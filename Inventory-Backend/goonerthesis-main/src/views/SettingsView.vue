<script setup>
import { ref, reactive, computed, onMounted, watch } from "vue";
import { useRoute } from "vue-router";
import {
  getMonthlyRequestLimit,
  updateMonthlyRequestLimit,
  getReturnPeriods,
  updateReturnPeriods,
  getStockLevels,
  updateStockLevels,
} from "../services/settings";
import { getTheme, setTheme } from "../services/theme";
import { getCurrentUser } from "../services/storage";
import { isAdmin } from "../services/session";
import {
  listAccountRequests,
  approveAccountRequest,
  declineAccountRequest,
} from "../services/accountRequests";
import { getUsers, updateUser, deleteUser, resetUserPassword } from "../services/users";
import ChangePasswordForm from "../components/ChangePasswordForm.vue";
import { getErrorMessage } from "../services/errors";
import AlertMessage from "../components/AlertMessage.vue";

// Only admins get the Account Requests / Account Management tabs. Everyone
// else only ever sees General. The server enforces this too (those
// endpoints are role:amo-gated) -- this just keeps the page from
// showing tabs/controls a non-admin couldn't use anyway.
const canEditLimit = computed(() => isAdmin(getCurrentUser()));
const currentUser = getCurrentUser();

const activeTab = ref("GENERAL"); // GENERAL | ACCOUNT_REQUESTS | ACCOUNT_MANAGEMENT

// The bell links here with ?tab=ACCOUNT_REQUESTS (AMO only).
const route = useRoute();
function openTabFromLink() {
  if (route.query.tab === "ACCOUNT_REQUESTS" && canEditLimit.value) {
    activeTab.value = "ACCOUNT_REQUESTS";
  }
}
openTabFromLink();
watch(() => route.query.tab, openTabFromLink);

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

// Default return periods (days) for borrowed items; blank = no default.
const rpOffice = ref("");
const rpEquipment = ref("");
const rpSaving = ref(false);

function validDays(value) {
  if (value === "" || value === null) return true;
  const n = Number(value);
  return Number.isInteger(n) && n >= 1 && n <= 3650;
}

async function saveReturnPeriods() {
  if (!validDays(rpOffice.value) || !validDays(rpEquipment.value)) {
    notify("warning", "Return periods must be whole days (1 to 3650), or left blank.");
    return;
  }

  rpSaving.value = true;

  try {
    const res = await updateReturnPeriods({
      office_supplies: rpOffice.value === "" ? null : Number(rpOffice.value),
      school_equipment: rpEquipment.value === "" ? null : Number(rpEquipment.value),
    });
    rpOffice.value = res.office_supplies ?? "";
    rpEquipment.value = res.school_equipment ?? "";
    notify("success", "Default return periods updated successfully.");
  } catch (e) {
    notify("danger", getErrorMessage(e));
  } finally {
    rpSaving.value = false;
  }
}

// Default stock levels for School Equipment and Type B office supplies.
const slEquipment = reactive({ critical: 2, minimum: 5, optimal: 20 });
const slTypeB = reactive({ critical: 2, minimum: 5, optimal: 20 });
const slApply = ref(true);
const slSaving = ref(false);
const slConfirmOpen = ref(false);

function slProblem() {
  for (const [name, g] of [["School Equipment", slEquipment], ["Type B office supplies", slTypeB]]) {
    const vals = [g.critical, g.minimum, g.optimal].map((v) => Number(v));
    if (vals.some((n) => !Number.isInteger(n) || n < 0)) return `${name}: use whole numbers, 0 or more.`;
    if (vals[0] > vals[1] || vals[1] > vals[2]) return `${name}: Critical must be ≤ Minimum, and Minimum ≤ Optimal.`;
  }
  return "";
}

function askSaveStockLevels() {
  const problem = slProblem();
  if (problem) {
    notify("warning", problem);
    return;
  }
  if (slApply.value) slConfirmOpen.value = true;
  else saveStockLevels();
}

async function saveStockLevels() {
  slConfirmOpen.value = false;
  slSaving.value = true;
  try {
    const num = (g) => ({ critical: Number(g.critical), minimum: Number(g.minimum), optimal: Number(g.optimal) });
    const res = await updateStockLevels({
      school_equipment: num(slEquipment),
      type_b: num(slTypeB),
      apply_to_existing: slApply.value,
    });
    notify(
      "success",
      slApply.value
        ? `Stock levels saved and applied to ${res.updated.school_equipment} equipment and ${res.updated.type_b} Type B item(s).`
        : "Default stock levels saved for new items.",
    );
  } catch (e) {
    notify("danger", getErrorMessage(e));
  } finally {
    slSaving.value = false;
  }
}

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

    const levels = await getStockLevels();
    Object.assign(slEquipment, levels.school_equipment);
    Object.assign(slTypeB, levels.type_b);

    const periods = await getReturnPeriods();
    rpOffice.value = periods.office_supplies ?? "";
    rpEquipment.value = periods.school_equipment ?? "";
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
const arRoleChoice = reactive({}); // { [idUsers]: "amo" | "department_head" | "admin" | "instructor" | "cashier" }
const ROLE_LABELS = {
  amo: "AMO",
  department_head: "Department Head",
  admin: "Admin",
  instructor: "Instructor",
  cashier: "Cashier",
};

async function loadAccountRequests() {
  if (!canEditLimit.value) {
    arLoading.value = false;
    return;
  }

  arLoading.value = true;

  try {
    arRequests.value = await listAccountRequests();
    arRequests.value.forEach((r) => {
      if (!arRoleChoice[r.idUsers]) arRoleChoice[r.idUsers] = "instructor";
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
const arConfirmRole = ref("instructor");
const arWorking = ref(false);

function arOpenApprove(request) {
  arConfirmAction.value = "approve";
  arConfirmTarget.value = request;
  arConfirmRole.value = arRoleChoice[request.idUsers] || "instructor";
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
    window.dispatchEvent(new Event("cims:accounts-changed"));
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
const amRoleChoice = reactive({}); // { [idUsers]: "amo" | "department_head" | "admin" | "instructor" | "cashier" }

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
const amConfirmAction = ref(""); // "role" | "delete" | "reset"
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

function amOpenReset(user) {
  if (isSelf(user)) return;
  amConfirmAction.value = "reset";
  amConfirmTarget.value = user;
  amConfirmOpen.value = true;
}

// Shown once after a reset so the AMO can pass it on.
const amTemp = ref(null); // { name, username, password }
const amCopied = ref(false);

async function amCopyTemp() {
  try {
    await navigator.clipboard.writeText(amTemp.value.password);
    amCopied.value = true;
  } catch {
    /* clipboard blocked: the password is still on screen to read out */
  }
}

function amCloseTemp() {
  amTemp.value = null;
  amCopied.value = false;
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
    } else if (amConfirmAction.value === "reset") {
      const res = await resetUserPassword(user.idUsers);
      amTemp.value = {
        name: user.full_name,
        username: user.username,
        password: res.temporary_password,
      };
      amCopied.value = false;
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
    <div class="row g-3 align-items-start">

      <div class="col-12 col-md-6 col-xl-4">
      <!-- APPEARANCE -->
      <div class="card shadow-sm mb-3">
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

      <div v-else-if="canEditLimit" class="card shadow-sm mb-3">
        <div class="card-body">

          <h6 class="mb-2">Monthly Request Limit</h6>

          <p class="text-muted small">
            Maximum number of requests anyone except the AMO can submit
            per calendar month. Currently:
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

      </div>
      <div class="col-12 col-md-6 col-xl-4">
      <!-- CHANGE PASSWORD (everyone) -->
      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <h6 class="mb-2">Change Password</h6>
          <p class="text-muted small">
            Choose a new password for your own account. Your other devices
            will be signed out.
          </p>
          <ChangePasswordForm />
        </div>
      </div>

      </div>
      <div class="col-12 col-md-6 col-xl-4">
      <div v-if="canEditLimit && !loading" class="card shadow-sm mb-3">
        <div class="card-body">

          <h6 class="mb-2">Default Return Periods</h6>

          <p class="text-muted small">
            How many days a borrower may keep a borrowed item before it is
            overdue. Applies to items that have no return period of their
            own (set per item from its Edit dialog). Leave blank for no
            default.
          </p>

          <label class="form-label">Office Supplies (Type B), days</label>
          <input
            type="number"
            min="1"
            class="form-control mb-3"
            v-model="rpOffice"
            :disabled="rpSaving"
            placeholder="No default"
          />

          <label class="form-label">School Equipment, days</label>
          <input
            type="number"
            min="1"
            class="form-control mb-3"
            v-model="rpEquipment"
            :disabled="rpSaving"
            placeholder="No default"
          />

          <button
            class="btn btn-primary"
            :disabled="rpSaving"
            @click="saveReturnPeriods"
          >
            Save
          </button>

        </div>
      </div>

      <!-- DEFAULT STOCK LEVELS (AMO) -->
      <div v-if="canEditLimit && !loading" class="card shadow-sm mb-3">
        <div class="card-body">
          <h6 class="mb-2">Stock Levels</h6>
          <p class="text-muted small">
            Critical, Minimum and Optimal levels for School Equipment and
            Type B office supplies. They are also the starting values when you
            add a new item.
          </p>

          <div class="fw-semibold small mb-1">School Equipment</div>
          <div class="row g-2 mb-3">
            <div class="col-4">
              <label class="form-label small mb-0" for="sl-eq-critical">Critical</label>
              <input id="sl-eq-critical" name="sl_eq_critical" type="number" min="0" class="form-control" v-model="slEquipment.critical" :disabled="slSaving" />
            </div>
            <div class="col-4">
              <label class="form-label small mb-0" for="sl-eq-minimum">Minimum</label>
              <input id="sl-eq-minimum" name="sl_eq_minimum" type="number" min="0" class="form-control" v-model="slEquipment.minimum" :disabled="slSaving" />
            </div>
            <div class="col-4">
              <label class="form-label small mb-0" for="sl-eq-optimal">Optimal</label>
              <input id="sl-eq-optimal" name="sl_eq_optimal" type="number" min="0" class="form-control" v-model="slEquipment.optimal" :disabled="slSaving" />
            </div>
          </div>

          <div class="fw-semibold small mb-1">Type B Office Supplies</div>
          <div class="row g-2 mb-3">
            <div class="col-4">
              <label class="form-label small mb-0" for="sl-b-critical">Critical</label>
              <input id="sl-b-critical" name="sl_b_critical" type="number" min="0" class="form-control" v-model="slTypeB.critical" :disabled="slSaving" />
            </div>
            <div class="col-4">
              <label class="form-label small mb-0" for="sl-b-minimum">Minimum</label>
              <input id="sl-b-minimum" name="sl_b_minimum" type="number" min="0" class="form-control" v-model="slTypeB.minimum" :disabled="slSaving" />
            </div>
            <div class="col-4">
              <label class="form-label small mb-0" for="sl-b-optimal">Optimal</label>
              <input id="sl-b-optimal" name="sl_b_optimal" type="number" min="0" class="form-control" v-model="slTypeB.optimal" :disabled="slSaving" />
            </div>
          </div>

          <div class="form-check mb-3">
            <input id="sl-apply" name="sl_apply" class="form-check-input" type="checkbox" v-model="slApply" :disabled="slSaving" />
            <label class="form-check-label small" for="sl-apply">
              Also apply to the items that already exist
            </label>
          </div>

          <button class="btn btn-primary" :disabled="slSaving" @click="askSaveStockLevels">Save</button>
        </div>
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
                      <option value="amo">AMO</option>
                      <option value="department_head">Department Head</option>
                      <option value="admin">Admin</option>
                      <option value="instructor">Instructor</option>
                      <option value="cashier">Cashier</option>
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
                  <th style="width: 340px"></th>
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
                      <option value="amo">AMO</option>
                      <option value="department_head">Department Head</option>
                      <option value="admin">Admin</option>
                      <option value="instructor">Instructor</option>
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
                        class="btn btn-sm btn-outline-secondary"
                        :disabled="isSelf(u)"
                        :title="isSelf(u) ? 'Use Change Password for your own account.' : 'Set a temporary password'"
                        @click="amOpenReset(u)"
                      >
                        Reset Password
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
              limit for everyone except the AMO.
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

    <div v-if="slConfirmOpen" class="modal-backdrop-custom">
      <div class="modal-custom" style="max-width: 440px">
        <div class="modal-header">
          <h5 class="mb-0">Apply Stock Levels</h5>
          <button class="btn-close" @click="slConfirmOpen = false"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2">
            This replaces the Critical, Minimum and Optimal levels of
            <strong>every</strong> School Equipment item and every Type B
            office supply, including any you set individually.
          </p>
          <p class="text-muted small mb-0">
            Untick "Also apply to the items that already exist" to change
            only the starting values for new items.
          </p>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="slConfirmOpen = false">Cancel</button>
          <button class="btn btn-primary" :disabled="slSaving" @click="saveStockLevels">Apply</button>
        </div>
      </div>
    </div>

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

          <p v-if="arConfirmAction === 'approve' && arConfirmRole === 'amo'" class="small text-danger mb-0">
            The AMO can change stock, approve requests and manage accounts.
            Only one AMO account is allowed.
          </p>
          <p v-else-if="arConfirmAction === 'approve' && arConfirmRole === 'admin'" class="small text-muted mb-0">
            Admins are office staff. They can submit requests but cannot
            manage inventory or accounts.
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
            {{
              amConfirmAction === "role"
                ? "Change Role"
                : amConfirmAction === "reset"
                  ? "Reset Password"
                  : "Remove Account"
            }}
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
          <p v-else-if="amConfirmAction === 'reset'" class="mb-2">
            Reset the password for <strong>{{ amConfirmTarget?.full_name }}</strong>
            ({{ amConfirmTarget?.username }})? They will be signed out
            everywhere and must choose a new password at their next login.
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
            :class="amConfirmAction === 'role' || amConfirmAction === 'reset' ? 'btn-primary' : 'btn-danger'"
            :disabled="amWorking"
            @click="amRunConfirmed"
          >
            {{
              amWorking
                ? "Please wait..."
                : amConfirmAction === "role"
                  ? "Confirm Change"
                  : amConfirmAction === "reset"
                    ? "Reset Password"
                    : "Remove"
            }}
          </button>
        </div>
      </div>
    </div>

    <!-- ============================================================ -->
    <!-- TEMPORARY PASSWORD (shown once after a reset) -->
    <!-- ============================================================ -->

    <div v-if="amTemp" class="modal-backdrop-custom">
      <div class="modal-custom" style="max-width: 440px">
        <div class="modal-header">
          <h5 class="mb-0">Temporary Password</h5>
          <button type="button" class="btn-close" @click="amCloseTemp"></button>
        </div>

        <div class="modal-body">
          <p class="mb-2">
            Give this to <strong>{{ amTemp.name }}</strong> ({{ amTemp.username }}).
            They must change it when they sign in.
          </p>
          <div class="input-group mb-2">
            <input
              id="temp-password"
              name="temp-password"
              class="form-control font-monospace"
              :value="amTemp.password"
              readonly
              aria-label="Temporary password"
            />
            <button type="button" class="btn btn-outline-secondary" @click="amCopyTemp">
              {{ amCopied ? "Copied" : "Copy" }}
            </button>
          </div>
          <p class="text-danger small mb-0">
            This is shown only once. If it is lost, reset the password again.
          </p>
        </div>

        <div class="modal-footer">
          <button class="btn btn-primary" @click="amCloseTemp">Done</button>
        </div>
      </div>
    </div>

  </div>
</template>

<style scoped>
</style>
