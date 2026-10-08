<script setup>
import { ref, reactive, onMounted } from "vue";
import {
  listAccountRequests,
  approveAccountRequest,
  declineAccountRequest,
} from "../services/accountRequests";
import { getErrorMessage } from "../services/errors";
import AlertMessage from "../components/AlertMessage.vue";

const showAlert = ref(false);
const alertType = ref("success");
const alertMessage = ref("");

function notify(type, message) {
  alertType.value = type;
  alertMessage.value = message;
  showAlert.value = true;
}

const loading = ref(true);
const requests = ref([]);

// Role picked in each row (everyone starts as Dean).
const roleChoice = reactive({});

const ROLE_LABELS = { dean: "Dean", cashier: "Cashier", admin: "Admin" };

async function load() {
  loading.value = true;
  try {
    requests.value = await listAccountRequests();
    requests.value.forEach((r) => {
      if (!roleChoice[r.idUsers]) roleChoice[r.idUsers] = "dean";
    });
  } catch (e) {
    notify("danger", getErrorMessage(e));
  } finally {
    loading.value = false;
  }
}

onMounted(load);

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

/* ===== CONFIRMATION (approve / decline) ===== */

const confirmOpen = ref(false);
const confirmAction = ref(""); // "approve" | "decline"
const confirmTarget = ref(null);
const confirmRole = ref("dean");
const working = ref(false);

function openApprove(request) {
  confirmAction.value = "approve";
  confirmTarget.value = request;
  confirmRole.value = roleChoice[request.idUsers] || "dean";
  confirmOpen.value = true;
}

function openDecline(request) {
  confirmAction.value = "decline";
  confirmTarget.value = request;
  confirmOpen.value = true;
}

function closeConfirm() {
  if (working.value) return;
  confirmOpen.value = false;
  confirmTarget.value = null;
}

async function runConfirmed() {
  if (!confirmTarget.value || working.value) return;

  const target = confirmTarget.value;
  working.value = true;

  try {
    if (confirmAction.value === "approve") {
      await approveAccountRequest(target.idUsers, confirmRole.value);
      notify(
        "success",
        `Approved "${target.full_name}" as ${ROLE_LABELS[confirmRole.value]}.`,
      );
    } else {
      await declineAccountRequest(target.idUsers);
      notify("success", `Declined the request from "${target.full_name}".`);
    }

    working.value = false;
    confirmOpen.value = false;
    confirmTarget.value = null;
    await load();
  } catch (e) {
    working.value = false;
    confirmOpen.value = false;
    confirmTarget.value = null;
    notify("danger", getErrorMessage(e));
    await load(); // it may have been handled by another admin already
  }
}
</script>

<template>
  <div>
    <AlertMessage v-model:show="showAlert" :type="alertType" :message="alertMessage" />

    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
      <div>
        <h3 class="mb-1">Account Requests</h3>
        <p class="text-muted mb-0">
          People who signed up and are waiting to be approved. They cannot
          sign in until you approve them.
        </p>
      </div>

      <button class="btn btn-outline-secondary" :disabled="loading" @click="load">
        <i class="bi bi-arrow-clockwise me-1"></i>Refresh
      </button>
    </div>

    <div v-if="loading && requests.length === 0" class="text-center text-muted py-4">
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
              <tr v-for="r in requests" :key="r.idUsers">
                <td>{{ r.full_name }}</td>
                <td>{{ r.username }}</td>
                <td>{{ formatDate(r.requested_at) }}</td>
                <td>
                  <select
                    class="form-select form-select-sm"
                    v-model="roleChoice[r.idUsers]"
                    :aria-label="'Role for ' + r.full_name"
                  >
                    <option value="dean">Dean</option>
                    <option value="cashier">Cashier</option>
                    <option value="admin">Admin</option>
                  </select>
                </td>
                <td>
                  <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-success" @click="openApprove(r)">
                      Approve
                    </button>
                    <button class="btn btn-sm btn-outline-danger" @click="openDecline(r)">
                      Decline
                    </button>
                  </div>
                </td>
              </tr>

              <tr v-if="requests.length === 0">
                <td colspan="5" class="text-center text-muted py-4">
                  No accounts are waiting for approval.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- CONFIRMATION MODAL -->
    <div v-if="confirmOpen" class="modal-backdrop-custom">
      <div class="modal-custom" style="max-width: 440px">
        <div class="modal-header">
          <h5 class="mb-0">
            {{ confirmAction === "approve" ? "Approve Account" : "Decline Account" }}
          </h5>
          <button
            type="button"
            class="btn-close"
            :disabled="working"
            @click="closeConfirm"
          ></button>
        </div>

        <div class="modal-body">
          <p v-if="confirmAction === 'approve'" class="mb-2">
            Approve <strong>{{ confirmTarget?.full_name }}</strong>
            ({{ confirmTarget?.username }}) as
            <strong>{{ ROLE_LABELS[confirmRole] }}</strong>? They will be able
            to sign in right away.
          </p>
          <p v-else class="mb-2">
            Decline the request from
            <strong>{{ confirmTarget?.full_name }}</strong>
            ({{ confirmTarget?.username }})? The sign-up will be removed. They
            can sign up again if this was a mistake.
          </p>

          <p v-if="confirmAction === 'approve' && confirmRole === 'admin'" class="small text-danger mb-0">
            Admins can change stock, approve requests and manage accounts.
          </p>
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" :disabled="working" @click="closeConfirm">
            Cancel
          </button>
          <button
            class="btn"
            :class="confirmAction === 'approve' ? 'btn-success' : 'btn-danger'"
            :disabled="working"
            @click="runConfirmed"
          >
            {{
              working
                ? "Please wait..."
                : confirmAction === "approve"
                  ? "Approve"
                  : "Decline"
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
  background: white;
  width: 100%;
  max-width: 500px;
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 18px 50px rgba(0, 0, 0, 0.25);
}

.modal-header,
.modal-footer {
  padding: 12px 16px;
  border-bottom: 1px solid #dee2e6;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.modal-footer {
  border-top: 1px solid #dee2e6;
  border-bottom: none;
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.modal-body {
  padding: 16px;
}
</style>
