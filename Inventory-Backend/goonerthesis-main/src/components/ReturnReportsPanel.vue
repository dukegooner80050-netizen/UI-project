<script setup>
import { ref, onMounted, onBeforeUnmount } from "vue";
import { startPolling } from "../services/poller";
import {
  listReturnReports,
  confirmReturnReport,
  rejectReturnReport,
} from "../services/returnReports";

// AMO dashboard panel: returns the borrowers say they made that nobody has
// checked yet. The longer one waits, the redder it gets, so a forgotten
// return can't sit unnoticed. Confirming records the return exactly like
// the normal Return button (the units go to inspection).

const URGENT_HOURS = 24;
const REFRESH_MS = 60000;

const rows = ref([]);
const loading = ref(true);
const busyId = ref(null);
const message = ref({ type: "", text: "" });

const rejectId = ref(null);
const rejectNote = ref("");

let stopPolling = null;

async function load() {
  try {
    rows.value = await listReturnReports();
  } catch (e) {
    console.error("Failed to load return reports:", e);
  } finally {
    loading.value = false;
  }
}

function waiting(hours) {
  if (hours < 1) return "just now";
  if (hours < 24) return `${hours}h`;
  const d = Math.floor(hours / 24);
  return `${d}d ${hours % 24}h`;
}

function place(r) {
  return [r.location, r.room].filter(Boolean).join(" / ");
}

async function confirm(r) {
  busyId.value = r.id;
  message.value = { type: "", text: "" };
  try {
    const res = await confirmReturnReport(r.id);
    message.value = { type: "success", text: res.message };
    await load();
  } catch (e) {
    message.value = {
      type: "danger",
      text: e.response?.data?.message || "Unable to confirm this return.",
    };
  } finally {
    busyId.value = null;
  }
}

function startReject(r) {
  rejectId.value = r.id;
  rejectNote.value = "";
  message.value = { type: "", text: "" };
}

async function sendReject() {
  if (!rejectNote.value.trim()) {
    message.value = { type: "danger", text: "Please give a reason." };
    return;
  }
  busyId.value = rejectId.value;
  try {
    const res = await rejectReturnReport(rejectId.value, rejectNote.value.trim());
    message.value = { type: "success", text: res.message };
    rejectId.value = null;
    await load();
  } catch (e) {
    message.value = {
      type: "danger",
      text: e.response?.data?.message || "Unable to update this report.",
    };
  } finally {
    busyId.value = null;
  }
}

onMounted(() => {
  load();
  stopPolling = startPolling(load, REFRESH_MS);
});
onBeforeUnmount(() => stopPolling && stopPolling());
</script>

<template>
  <div v-if="rows.length || message.text" class="card shadow-sm mb-3">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="mb-0">Returns to check</h6>
        <span class="badge bg-warning text-dark">{{ rows.length }}</span>
      </div>
      <p class="text-muted small mb-2">
        Borrowers say they returned these. Please check the items and confirm.
      </p>

      <div
        v-if="message.text"
        class="alert py-1 small"
        :class="message.type === 'success' ? 'alert-success' : 'alert-danger'"
      >
        {{ message.text }}
      </div>

      <div v-if="loading" class="text-muted small">Loading...</div>

      <ul class="list-unstyled mb-0">
        <li v-for="r in rows" :key="r.id" class="border-top pt-2 mt-2">
          <div class="d-flex justify-content-between gap-2">
            <div>
              <strong>{{ r.itemName }}</strong> &times; {{ r.quantity }}
              <div class="small text-muted">
                {{ r.requester }} &middot; Request #{{ r.requestId }}
                <span v-if="place(r)"> &middot; {{ place(r) }}</span>
              </div>
            </div>
            <span
              class="badge align-self-start"
              :class="r.hoursWaiting >= URGENT_HOURS ? 'bg-danger' : 'bg-secondary'"
              :title="`Reported ${r.reportedAt}`"
            >
              waiting {{ waiting(r.hoursWaiting) }}
            </span>
          </div>

          <div v-if="rejectId === r.id" class="mt-2">
            <input
              v-model="rejectNote"
              class="form-control form-control-sm mb-2"
              maxlength="255"
              placeholder="Why was it not received? (shown to the borrower)"
            />
            <button
              class="btn btn-sm btn-danger me-2"
              :disabled="busyId === r.id"
              @click="sendReject"
            >
              Send
            </button>
            <button class="btn btn-sm btn-light" @click="rejectId = null">
              Cancel
            </button>
          </div>

          <div v-else class="mt-2">
            <button
              class="btn btn-sm btn-success me-2"
              :disabled="busyId === r.id"
              @click="confirm(r)"
            >
              Confirm return
            </button>
            <button
              class="btn btn-sm btn-outline-danger"
              :disabled="busyId === r.id"
              @click="startReject(r)"
            >
              Not received
            </button>
          </div>
        </li>
      </ul>
    </div>
  </div>
</template>
