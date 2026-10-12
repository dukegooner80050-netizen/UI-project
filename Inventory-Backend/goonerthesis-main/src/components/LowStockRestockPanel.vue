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

// Dialog (create request / mark received)
const dialog = ref({ open: false, mode: "", target: null });
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

async function setStatus(req, status) {
  const label = status === "Cancelled" ? "cancel" : "mark as ordered";
  if (!confirm(`Do you want to ${label} the restock request for ${req.item_name}?`))
    return;
  try {
    await updateRestockStatus(req.id, status);
    await load();
  } catch (e) {
    alert(e.response?.data?.message || e.message);
  }
}

onMounted(load);
</script>

<template>
  <div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
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
          Restock requests ({{ requests.length }})
        </button>
      </div>
    </div>

    <div class="card-body p-0">
      <div v-if="loadError" class="alert alert-danger m-3">{{ loadError }}</div>
      <div v-else-if="loading" class="text-muted p-3">Loading...</div>

      <!-- LOW STOCK -->
      <div v-else-if="tab === 'low'" class="table-responsive panel-scroll">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Item</th>
              <th>Category</th>
              <th style="width: 80px">Qty</th>
              <th style="width: 110px">Level</th>
              <th style="width: 170px; text-align: center">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="i in needsAction" :key="i.iditems">
              <td>{{ i.item_name }}</td>
              <td>{{ i.category }}</td>
              <td>{{ i.quantity }}</td>
              <td>
                <span
                  class="badge"
                  :class="i.level === 'Critical' ? 'text-bg-danger' : 'text-bg-warning'"
                >
                  {{ i.level }}
                </span>
              </td>
              <td class="text-center">
                <button class="btn btn-primary btn-sm" @click="openCreate(i)">
                  Request restock
                </button>
              </td>
            </tr>
            <tr v-if="!needsAction.length">
              <td colspan="5" class="text-center text-muted">
                Nothing needs restocking right now.
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- REQUESTS -->
      <div v-else class="table-responsive panel-scroll">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Item</th>
              <th style="width: 90px">Needed</th>
              <th style="width: 110px">Status</th>
              <th>Requested by</th>
              <th style="width: 250px; text-align: center">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in requests" :key="r.id">
              <td>
                {{ r.item_name }}
                <div class="small text-muted">Now: {{ r.current_quantity }}</div>
              </td>
              <td>{{ r.quantity_needed }}</td>
              <td>
                <span
                  class="badge"
                  :class="r.status === 'Ordered' ? 'text-bg-info' : 'text-bg-secondary'"
                >
                  {{ r.status }}
                </span>
              </td>
              <td>{{ r.requested_by || "-" }}</td>
              <td class="text-center">
                <div class="d-flex justify-content-center gap-2">
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
                  <button
                    class="btn btn-outline-danger btn-sm"
                    @click="setStatus(r, 'Cancelled')"
                  >
                    Cancel
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!requests.length">
              <td colspan="5" class="text-center text-muted">
                No active restock requests.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
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
              : `Receive stock: ${dialog.target.item_name}`
          }}
        </h5>
        <button class="btn-close" @click="closeDialog"></button>
      </div>
      <div class="rs-body">
        <template v-if="dialog.mode === 'create'">
          <div class="small text-muted mb-3">
            Current stock {{ dialog.target.quantity }}, optimal level
            {{ dialog.target.optimal_stock }}.
          </div>
        </template>
        <label class="form-label">
          {{ dialog.mode === "create" ? "Quantity needed" : "Quantity received" }}
        </label>
        <input type="number" min="1" class="form-control" v-model="dialogQty" />
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
          {{ saving ? "Saving..." : dialog.mode === "create" ? "Create request" : "Confirm receipt" }}
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
.rs-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.35);
  display: grid;
  place-items: center;
  z-index: 2100;
}
.rs-modal {
  background: #fff;
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
