<script setup>
import { ref, computed, onMounted } from "vue";
import {
  getLowStock,
  getRestockRequests,
  createRestockRequest,
  updateRestockStatus,
} from "../services/restock";

const lowStock = ref([]);
const requests = ref([]);
const tab = ref("low");
const loading = ref(false);
const loadError = ref("");

// Dialog (create request / mark received / confirm a status change)
const dialog = ref({ open: false, mode: "", target: null, status: "" });
const dialogQty = ref(1);
const dialogNotes = ref("");
const dialogError = ref("");
const saving = ref(false);

const needsAction = computed(() =>
  lowStock.value.filter((i) => !i.active_request_id),
);

async function load() {
  loading.value = true;
  loadError.value = "";
  try {
    const [low, reqs] = await Promise.all([getLowStock(), getRestockRequests()]);
    lowStock.value = low;
    requests.value = reqs;
  } catch (e) {
    loadError.value =
      e.response?.data?.message || "Unable to load restock information.";
  } finally {
    loading.value = false;
  }
}

function openCreate(item) {
  dialog.value = { open: true, mode: "create", target: item };
  dialogQty.value = item.suggested_quantity;
  dialogNotes.value = "";
  dialogError.value = "";
}

function openReceive(req) {
  dialog.value = { open: true, mode: "receive", target: req };
  dialogQty.value = req.quantity_needed;
  dialogNotes.value = "";
  dialogError.value = "";
}

function closeDialog() {
  dialog.value.open = false;
}

async function submitDialog() {
  // Confirming "Mark ordered" / "Cancel" needs no quantity.
  if (dialog.value.mode === "status") {
    saving.value = true;
    dialogError.value = "";
    try {
      await updateRestockStatus(dialog.value.target.id, dialog.value.status);
      closeDialog();
      await load();
    } catch (e) {
      dialogError.value =
        e.response?.data?.message || e.message || "Something went wrong.";
    } finally {
      saving.value = false;
    }
    return;
  }

  const qty = Number(dialogQty.value);
  if (!Number.isInteger(qty) || qty < 1) {
    dialogError.value = "Quantity must be a whole number of at least 1.";
    return;
  }
  saving.value = true;
  dialogError.value = "";
  try {
    if (dialog.value.mode === "create") {
      await createRestockRequest(
        dialog.value.target.iditems,
        qty,
        dialogNotes.value.trim(),
      );
    } else {
      await updateRestockStatus(dialog.value.target.id, "Received", qty);
    }
    closeDialog();
    await load();
  } catch (e) {
    dialogError.value =
      e.response?.data?.message || e.message || "Something went wrong.";
  } finally {
    saving.value = false;
  }
}

// Asks first (in the dialog below), then changes the status.
function setStatus(req, status) {
  dialog.value = { open: true, mode: "status", target: req, status };
  dialogError.value = "";
}

onMounted(load);
</script>

<template>
  <div class="card shadow-sm mb-3">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <h6 class="mb-0">Needs Restocking</h6>
      <div class="btn-group btn-group-sm">
        <button
          class="btn"
          :class="tab === 'low' ? 'btn-primary' : 'btn-outline-primary'"
          @click="tab = 'low'"
        >
          Low stock ({{ needsAction.length }})
        </button>
        <button
          class="btn"
          :class="tab === 'requests' ? 'btn-primary' : 'btn-outline-primary'"
          @click="tab = 'requests'"
        >
          Requests ({{ requests.length }})
        </button>
      </div>
    </div>

    <div class="card-body p-0">
      <div v-if="loadError" class="alert alert-danger m-3">{{ loadError }}</div>
      <div v-else-if="loading" class="text-muted p-3">Loading...</div>

      <!-- LOW STOCK -->
      <ul v-else-if="tab === 'low'" class="list-group list-group-flush panel-scroll">
        <li v-for="i in needsAction" :key="i.iditems" class="list-group-item">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div class="rs-name">
              <div class="fw-semibold text-truncate" :title="i.item_name">
                {{ i.item_name }}
              </div>
              <div class="small text-muted">
                {{ i.category }} · Qty {{ i.quantity }}
              </div>
            </div>
            <span
              class="badge flex-shrink-0"
              :class="i.level === 'Critical' ? 'text-bg-danger' : 'text-bg-warning'"
            >
              {{ i.level }}
            </span>
          </div>
          <button class="btn btn-primary btn-sm w-100 mt-2" @click="openCreate(i)">
            Request restock
          </button>
        </li>
        <li v-if="!needsAction.length" class="list-group-item text-center text-muted">
          Nothing needs restocking right now.
        </li>
      </ul>

      <!-- REQUESTS -->
      <ul v-else class="list-group list-group-flush panel-scroll">
        <li v-for="r in requests" :key="r.id" class="list-group-item">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div class="rs-name">
              <div class="fw-semibold text-truncate" :title="r.item_name">
                {{ r.item_name }}
              </div>
              <div class="small text-muted">
                Needed {{ r.quantity_needed }} · Now {{ r.current_quantity }}
              </div>
              <div v-if="r.requested_by" class="small text-muted">
                By {{ r.requested_by }}
              </div>
            </div>
            <span
              class="badge flex-shrink-0"
              :class="r.status === 'Ordered' ? 'text-bg-info' : 'text-bg-secondary'"
            >
              {{ r.status }}
            </span>
          </div>
          <div class="d-flex flex-wrap gap-2 mt-2">
            <button
              v-if="r.status === 'Open'"
              class="btn btn-outline-primary btn-sm"
              @click="setStatus(r, 'Ordered')"
            >
              Mark ordered
            </button>
            <button class="btn btn-success btn-sm" @click="openReceive(r)">
              Receive
            </button>
            <button class="btn btn-outline-danger btn-sm" @click="setStatus(r, 'Cancelled')">
              Cancel
            </button>
          </div>
        </li>
        <li v-if="!requests.length" class="list-group-item text-center text-muted">
          No active restock requests.
        </li>
      </ul>
    </div>
  </div>

  <!-- DIALOG -->
  <div v-if="dialog.open" class="rs-backdrop">
    <div class="rs-modal">
      <div class="rs-header">
        <h5 class="mb-0">
          {{
            dialog.mode === "create"
              ? `Request restock: ${dialog.target.item_name}`
              : dialog.mode === "status"
                ? dialog.status === "Cancelled"
                  ? "Cancel restock request"
                  : "Mark as ordered"
                : `Receive stock: ${dialog.target.item_name}`
          }}
        </h5>
        <button class="btn-close" @click="closeDialog"></button>
      </div>
      <div class="rs-body">
        <p v-if="dialog.mode === 'status'" class="mb-0">
          {{
            dialog.status === "Cancelled"
              ? "Do you want to cancel the restock request for"
              : "Do you want to mark the restock request as ordered for"
          }}
          <strong>{{ dialog.target.item_name }}</strong>?
        </p>
        <template v-if="dialog.mode === 'create'">
          <div class="small text-muted mb-3">
            Current stock {{ dialog.target.quantity }}, optimal level
            {{ dialog.target.optimal_stock }}.
          </div>
        </template>
        <label v-if="dialog.mode !== 'status'" class="form-label">
          {{ dialog.mode === "create" ? "Quantity needed" : "Quantity received" }}
        </label>
        <input
          v-if="dialog.mode !== 'status'"
          type="number"
          min="1"
          class="form-control"
          v-model="dialogQty"
        />
        <div v-if="dialog.mode === 'receive'" class="small text-muted mt-2">
          This quantity is added to the item's stock and the request is closed.
        </div>
        <template v-if="dialog.mode === 'create'">
          <label class="form-label mt-3">
            Notes <span class="text-muted">(optional)</span>
          </label>
          <textarea
            class="form-control"
            rows="2"
            maxlength="255"
            v-model="dialogNotes"
          ></textarea>
        </template>
        <div v-if="dialogError" class="alert alert-danger py-2 mt-3 mb-0">
          {{ dialogError }}
        </div>
      </div>
      <div class="rs-footer">
        <button class="btn btn-secondary" :disabled="saving" @click="closeDialog">
          Cancel
        </button>
        <button class="btn btn-primary" :disabled="saving" @click="submitDialog">
          {{
            saving
              ? "Saving..."
              : dialog.mode === "create"
                ? "Create request"
                : dialog.mode === "status"
                  ? "Confirm"
                  : "Confirm receipt"
          }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.panel-scroll {
  max-height: 320px;
  overflow-y: auto;
}
.rs-name {
  min-width: 0;
}
.rs-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.35);
  display: grid;
  place-items: center;
  z-index: 2100;
}
.rs-modal {
  background: var(--bs-body-bg, #fff);
  color: var(--bs-body-color, inherit);
  width: 100%;
  max-width: 460px;
  border-radius: 12px;
  overflow: hidden;
}
.rs-header,
.rs-footer {
  padding: 12px 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.rs-body {
  padding: 16px;
}
</style>
