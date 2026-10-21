<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from "vue";
import { useRoute, useRouter } from "vue-router";
import { getMyOverdue } from "../services/requests";
import { getLowStock } from "../services/restock";
import {
  getNotifications,
  markNotificationsRead,
} from "../services/notifications";
import { getCurrentUser } from "../services/storage";
import { isAdmin } from "../services/session";
import { listReturnReports } from "../services/returnReports";
import { listAccountRequests } from "../services/accountRequests";
import { startPolling, throttled } from "../services/poller";
import DueBadge from "./DueBadge.vue";

// One bell for everything that used to sit on the page as a banner or panel:
//   - request updates (forwarded / approved / rejected)
//   - the user's own overdue returns
//   - Critical-stock items nobody has raised a restock request for (AMO only)
// The badge counts what still needs attention. Clicking the bell opens the
// list; it can be closed and opened again at any time. Once per login the
// list opens by itself if there are unread request updates.

const REFRESH_MS = 60000;

const route = useRoute();
const router = useRouter();

const root = ref(null);
const open = ref(false);

const notes = ref([]);
const overdue = ref([]);
const critical = ref([]);
const returnsToCheck = ref([]); // AMO: borrowers' return reports awaiting a check
const signups = ref([]); // AMO: new accounts waiting for approval
// Updates that were unread when the panel opened stay highlighted until it
// closes, even though they are marked as read on the server straight away.
const highlighted = ref(new Set());

let stopPolling = null;
let firstLoadDone = false;

const admin = computed(() => isAdmin(getCurrentUser()));

const unreadCount = computed(() => notes.value.filter((n) => !n.read_at).length);

const badgeCount = computed(
  () =>
    unreadCount.value +
    overdue.value.length +
    critical.value.length +
    returnsToCheck.value.length +
    signups.value.length,
);

const recentNotes = computed(() => notes.value.slice(0, 10));

const isEmpty = computed(
  () =>
    !recentNotes.value.length &&
    !overdue.value.length &&
    !critical.value.length &&
    !returnsToCheck.value.length &&
    !signups.value.length,
);

// ---- loading (each source on its own: one failing never hides the rest) ----
async function loadNotes() {
  try {
    notes.value = await getNotifications();
  } catch (e) {
    console.error("Failed to load request updates:", e);
  }
}

async function loadOverdue() {
  try {
    overdue.value = await getMyOverdue();
  } catch (e) {
    console.error("Failed to load overdue items:", e);
  }
}

async function loadCritical() {
  if (!admin.value) {
    critical.value = [];
    return;
  }
  try {
    const rows = await getLowStock();
    critical.value = rows.filter(
      (r) => r.level === "Critical" && !r.active_request_id,
    );
  } catch (e) {
    console.error("Failed to load critical stock:", e);
  }
}

async function loadSignups() {
  if (!admin.value) {
    signups.value = [];
    return;
  }
  try {
    signups.value = await listAccountRequests();
  } catch (e) {
    console.error("Failed to load account requests:", e);
  }
}

async function loadReturns() {
  if (!admin.value) {
    returnsToCheck.value = [];
    return;
  }
  try {
    returnsToCheck.value = await listReturnReports();
  } catch (e) {
    console.error("Failed to load return reports:", e);
  }
}

async function load() {
  await Promise.all([
    loadNotes(),
    loadOverdue(),
    loadCritical(),
    loadReturns(),
    loadSignups(),
  ]);

  if (!firstLoadDone) {
    firstLoadDone = true;
    maybeAutoOpen();
  }
}

// ---- once per login ----
function loginKey() {
  let token = "";
  try {
    token = localStorage.getItem("token") || "";
  } catch {
    /* ignore */
  }
  // A new login gets a new token, so the list opens again after logging in.
  return `bellAutoOpened:${getCurrentUser()?.idUsers ?? "anon"}:${token.slice(-12)}`;
}

function maybeAutoOpen() {
  if (unreadCount.value === 0 && signups.value.length === 0) return;
  try {
    if (sessionStorage.getItem(loginKey())) return;
    sessionStorage.setItem(loginKey(), "1");
  } catch {
    /* if storage is blocked it simply opens once per page load */
  }
  openPanel();
}

// ---- open / close ----
async function openPanel() {
  highlighted.value = new Set(
    notes.value.filter((n) => !n.read_at).map((n) => n.id),
  );
  open.value = true;

  if (highlighted.value.size) {
    try {
      await markNotificationsRead();
      const now = new Date().toISOString();
      notes.value = notes.value.map((n) => (n.read_at ? n : { ...n, read_at: now }));
    } catch (e) {
      console.error("Failed to mark updates as read:", e);
    }
  }
}

function closePanel() {
  open.value = false;
  highlighted.value = new Set();
}

function toggle() {
  if (open.value) closePanel();
  else openPanel();
}

function onDocumentClick(e) {
  if (open.value && root.value && !root.value.contains(e.target)) closePanel();
}

function onKey(e) {
  if (e.key === "Escape" && open.value) closePanel();
}

function goReturns() {
  closePanel();
  router.push("/dashboard");
}

function goSignups() {
  closePanel();
  router.push({ path: "/settings", query: { tab: "ACCOUNT_REQUESTS" } });
}

function goRestock() {
  closePanel();
  router.push("/dashboard");
}

// ---- display helpers ----
function noteLabel(n) {
  if (n.event_type === "forwarded") return "Forwarded";
  if (n.event_type === "released") return "Ready";
  if (n.event_type === "received") return "Received";
  if (n.event_type === "inventory_review") return "To approve";
  if (n.event_type === "coordinator_review") return "To review";
  if (n.event_type === "return_reported") return "Return";
  if (n.event_type === "return_confirmed") return "Confirmed";
  if (n.event_type === "return_rejected") return "Not received";
  if (/rejected/i.test(n.message || "")) return "Rejected";
  if (/approved/i.test(n.message || "")) return "Approved";
  return "Update";
}

function noteBadge(n) {
  const label = noteLabel(n);
  if (label === "Approved" || label === "Confirmed") return "bg-success";
  if (label === "Rejected" || label === "Not received") return "bg-danger";
  if (label === "Return" || label === "To approve" || label === "To review")
    return "bg-warning text-dark";
  if (label === "Ready" || label === "Received") return "bg-success";
  return "bg-info text-dark";
}

function formatWhen(value) {
  if (!value) return "";
  return new Date(String(value).replace(" ", "T")).toLocaleString("en-PH");
}

function place(line) {
  return [line.location, line.room].filter(Boolean).join(" / ");
}

onMounted(() => {
  load();
  stopPolling = startPolling(load, REFRESH_MS);
  document.addEventListener("click", onDocumentClick);
  document.addEventListener("keydown", onKey);
  // The restock panel fires this after a restock request is created/changed.
  window.addEventListener("cims:restock-changed", loadCritical);
  // Settings fires this after an account request is approved or declined.
  window.addEventListener("cims:accounts-changed", loadSignups);
});

onBeforeUnmount(() => {
  if (stopPolling) stopPolling();
  document.removeEventListener("click", onDocumentClick);
  document.removeEventListener("keydown", onKey);
  window.removeEventListener("cims:restock-changed", loadCritical);
  window.removeEventListener("cims:accounts-changed", loadSignups);
});

// Things change while moving around (returns, new requests, stock).
// Navigating refreshes, but not more than once every 15 s.
watch(() => route.fullPath, throttled(load, 15000));
</script>

<template>
  <div ref="root" class="bell-wrap">
    <button
      type="button"
      class="bell-btn"
      :aria-expanded="open"
      aria-label="Notifications"
      title="Notifications"
      @click="toggle"
    >
      <i class="bi bi-bell-fill"></i>
      <span v-if="badgeCount > 0" class="bell-badge">
        {{ badgeCount > 99 ? "99+" : badgeCount }}
      </span>
    </button>

    <div v-if="open" class="bell-panel" role="dialog" aria-label="Notifications">
      <div class="bell-head">
        <strong>Notifications</strong>
        <button
          type="button"
          class="btn-close"
          aria-label="Close"
          @click="closePanel"
        ></button>
      </div>

      <div class="bell-body">
        <div v-if="isEmpty" class="text-center text-muted py-4">
          You're all caught up.
        </div>

        <!-- REQUEST UPDATES -->
        <section v-if="recentNotes.length" class="bell-section">
          <div class="bell-title">Request updates</div>
          <ul class="list-unstyled mb-0">
            <li
              v-for="n in recentNotes"
              :key="n.id"
              class="bell-item"
              :class="{ 'bell-new': highlighted.has(n.id) }"
            >
              <span class="badge me-2" :class="noteBadge(n)">{{ noteLabel(n) }}</span>
              {{ n.message }}
              <div class="small text-muted">{{ formatWhen(n.created_at) }}</div>
            </li>
          </ul>
        </section>

        <!-- NEW SIGN-UPS WAITING FOR THE AMO -->
        <section v-if="signups.length" class="bell-section">
          <div class="bell-title text-warning">
            New users waiting for approval ({{ signups.length }})
          </div>
          <ul class="list-unstyled mb-0">
            <li v-for="u in signups" :key="u.idUsers" class="bell-item">
              <strong>{{ u.full_name }}</strong>
              <span class="text-muted"> ({{ u.username }})</span>
              <div class="small text-muted">{{ formatWhen(u.requested_at) }}</div>
            </li>
          </ul>
          <button type="button" class="btn btn-warning btn-sm mt-2" @click="goSignups">
            Review accounts
          </button>
        </section>

        <!-- RETURNS WAITING FOR THE AMO -->
        <section v-if="returnsToCheck.length" class="bell-section">
          <div class="bell-title text-warning">
            Returns to check ({{ returnsToCheck.length }})
          </div>
          <ul class="list-unstyled mb-0">
            <li v-for="r in returnsToCheck" :key="r.id" class="bell-item">
              <strong>{{ r.itemName }}</strong> &times; {{ r.quantity }}
              <span
                class="badge ms-2"
                :class="r.hoursWaiting >= 24 ? 'bg-danger' : 'bg-secondary'"
              >
                waiting {{ r.hoursWaiting < 24 ? r.hoursWaiting + "h" : Math.floor(r.hoursWaiting / 24) + "d" }}
              </span>
              <div class="small text-muted">
                {{ r.requester }} &middot; Request #{{ r.requestId }}
              </div>
            </li>
          </ul>
          <button type="button" class="btn btn-warning btn-sm mt-2" @click="goReturns">
            Check returns
          </button>
        </section>

        <!-- OVERDUE RETURNS -->
        <section v-if="overdue.length" class="bell-section">
          <div class="bell-title text-danger">
            Overdue returns ({{ overdue.length }})
          </div>
          <ul class="list-unstyled mb-0">
            <li v-for="l in overdue" :key="l.key" class="bell-item">
              <strong>{{ l.itemName }}</strong> &times; {{ l.borrowedQty }}
              <div class="small text-muted">
                Request #{{ l.requestId }}
                <span v-if="place(l)"> &middot; {{ place(l) }}</span>
              </div>
              <DueBadge
                :due-date="l.dueDate"
                :overdue="true"
                :days-overdue="l.daysOverdue"
              />
            </li>
          </ul>
          <div class="small text-muted mt-1">
            Please return them to the administrator as soon as possible.
          </div>
        </section>

        <!-- CRITICAL STOCK (AMO) -->
        <section v-if="critical.length" class="bell-section">
          <div class="bell-title text-danger">
            Critical stock ({{ critical.length }})
          </div>
          <ul class="list-unstyled mb-0">
            <li v-for="i in critical" :key="i.iditems" class="bell-item">
              <strong>{{ i.item_name }}</strong>
              <span v-if="i.quantity <= 0" class="badge bg-dark ms-2">Out of stock</span>
              <span v-else class="text-muted ms-2">Qty {{ i.quantity }}</span>
              <div class="small text-muted">
                {{ i.category }} &middot; suggested restock +{{ i.suggested_quantity }}
              </div>
            </li>
          </ul>
          <button
            type="button"
            class="btn btn-danger btn-sm mt-2"
            @click="goRestock"
          >
            Review restocking
          </button>
        </section>
      </div>
    </div>
  </div>
</template>

<style scoped>
.bell-wrap {
  position: relative;
  display: inline-block;
}
.bell-btn {
  position: relative;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  border: 1px solid rgba(0, 0, 0, 0.12);
  background: #fff;
  color: #104605;
  font-size: 1.1rem;
  display: grid;
  place-items: center;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.12);
  cursor: pointer;
}
.bell-btn:hover {
  background: #f1f8ef;
}
.bell-badge {
  position: absolute;
  top: -4px;
  right: -4px;
  min-width: 20px;
  height: 20px;
  padding: 0 5px;
  border-radius: 10px;
  background: #dc3545;
  color: #fff;
  font-size: 0.7rem;
  font-weight: 700;
  line-height: 20px;
  text-align: center;
}
.bell-panel {
  position: absolute;
  top: 48px;
  right: 0;
  width: 380px;
  max-width: 92vw;
  max-height: 70vh;
  display: flex;
  flex-direction: column;
  background: #fff;
  color: #212529;
  border-radius: 12px;
  box-shadow: 0 8px 28px rgba(0, 0, 0, 0.22);
  z-index: 2200;
  text-align: left;
}
.bell-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 16px;
  border-bottom: 1px solid #e9ecef;
}
.bell-body {
  overflow-y: auto;
  padding: 8px 16px 12px;
}
.bell-section + .bell-section {
  margin-top: 12px;
  padding-top: 8px;
  border-top: 1px solid #e9ecef;
}
.bell-title {
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin: 6px 0;
  color: #6c757d;
}
.bell-item {
  padding: 6px 8px;
  border-radius: 8px;
  font-size: 0.9rem;
}
.bell-item + .bell-item {
  margin-top: 2px;
}
.bell-new {
  background: #eaf4ff;
}
</style>
