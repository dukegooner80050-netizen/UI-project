<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from "vue";
import { useRoute } from "vue-router";
import { getMyOverdue } from "../services/requests";
import { getCurrentUser } from "../services/storage";
import DueBadge from "./DueBadge.vue";
import { startPolling, throttled } from "../services/poller";

// Roadmap #8: tells whoever borrowed something that it is overdue.
//  - A red banner on every page while anything of theirs is overdue.
//  - A pop-up the first time it is noticed in a login session (and again if a
//    new item becomes overdue), so it cannot be missed.
// It only ever shows the signed-in user's own items. What the admin sees
// about everybody is the Overdue Returns card on the dashboard.

const REFRESH_MS = 300000; // looks again every 5 minutes (the bell checks every minute)

const route = useRoute();

const lines = ref([]);
const expanded = ref(false);
const noticeOpen = ref(false);
// The banner can be closed; the bell in the top corner still lists the items.
const bannerDismissed = ref(readBannerDismissed());
let stopPolling = null;

function seenKey() {
  return `overdueNoticeSeen:${getCurrentUser()?.idUsers ?? "anon"}`;
}

function bannerKey() {
  return `overdueBannerDismissed:${getCurrentUser()?.idUsers ?? "anon"}`;
}

function readBannerDismissed() {
  try {
    return sessionStorage.getItem(bannerKey()) || "";
  } catch {
    return "";
  }
}

function dismissBanner() {
  bannerDismissed.value = signature.value;
  try {
    sessionStorage.setItem(bannerKey(), signature.value);
  } catch {
    /* ignore: the banner just shows again next time */
  }
}

function readSeen() {
  try {
    return sessionStorage.getItem(seenKey()) || "";
  } catch {
    return "";
  }
}

function writeSeen(value) {
  try {
    sessionStorage.setItem(seenKey(), value);
  } catch {
    /* ignore: the pop-up just shows again next time */
  }
}

async function load() {
  try {
    lines.value = await getMyOverdue();
  } catch (e) {
    // An alert must never break the page it sits on.
    console.error("Failed to load overdue items:", e);
  }
}

const signature = computed(() =>
  lines.value
    .map((l) => l.key)
    .sort()
    .join("|"),
);

// Pop-up when there is something the user has not been told about yet.
watch(signature, (value) => {
  if (value && readSeen() !== value) {
    noticeOpen.value = true;
  }
});

function acknowledge() {
  writeSeen(signature.value);
  noticeOpen.value = false;
}

function s(n) {
  return n === 1 ? "" : "s";
}

function place(line) {
  return [line.location, line.room].filter(Boolean).join(" / ");
}

onMounted(() => {
  load();
  stopPolling = startPolling(load, REFRESH_MS);
});

onBeforeUnmount(() => {
  if (stopPolling) stopPolling();
});

// Refresh when moving between pages, at most once every 15 s.
watch(() => route.fullPath, throttled(load, 15000));
</script>

<template>
  <div>
    <!-- BANNER (every page) -->
    <div
      v-if="lines.length > 0 && bannerDismissed !== signature"
      class="alert alert-danger shadow-sm mb-3"
      role="alert"
    >
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
          <i class="bi bi-exclamation-octagon-fill me-2"></i>
          <strong>
            You have {{ lines.length }} overdue item{{ s(lines.length) }} to return.
          </strong>
          <span class="d-block small">
            Please return {{ lines.length === 1 ? "it" : "them" }} to the
            administrator as soon as possible.
          </span>
        </div>

        <div class="d-flex align-items-center gap-2">
          <button
            type="button"
            class="btn btn-sm btn-danger"
            @click="expanded = !expanded"
          >
            {{ expanded ? "Hide details" : "View details" }}
          </button>
          <button
            type="button"
            class="btn-close"
            aria-label="Close"
            title="Close (the bell still lists it)"
            @click="dismissBanner"
          ></button>
        </div>
      </div>

      <div v-if="expanded" class="table-responsive mt-3">
        <table class="table table-sm table-borderless align-middle mb-0 overdue-table">
          <thead>
            <tr>
              <th>Item</th>
              <th>Qty</th>
              <th>Due</th>
              <th>Request</th>
              <th>Location</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="l in lines" :key="l.key">
              <td>{{ l.itemName }}</td>
              <td>{{ l.borrowedQty }}</td>
              <td>
                <DueBadge
                  :due-date="l.dueDate"
                  :overdue="true"
                  :days-overdue="l.daysOverdue"
                />
              </td>
              <td>#{{ l.requestId }}</td>
              <td>{{ place(l) || "-" }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- POP-UP (once per session, again if something new becomes overdue) -->
    <div v-if="noticeOpen" class="overdue-backdrop" @click.self="acknowledge">
      <div
        class="overdue-modal"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="overdue-title"
      >
        <div class="overdue-head">
          <i class="bi bi-exclamation-octagon-fill"></i>
          <h5 id="overdue-title" class="mb-0">Items to return</h5>
        </div>

        <div class="overdue-body">
          <p>
            You still have {{ lines.length }} borrowed item{{ s(lines.length) }}
            past the due date. Please return
            {{ lines.length === 1 ? "it" : "them" }} to the administrator as
            soon as possible.
          </p>

          <ul class="list-unstyled mb-0">
            <li v-for="l in lines" :key="l.key" class="mb-2">
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
        </div>

        <div class="overdue-foot">
          <button type="button" class="btn btn-danger" @click="acknowledge">
            I understand
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.overdue-table th {
  font-weight: 600;
}

.overdue-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  display: grid;
  place-items: center;
  z-index: 2100;
  padding: 16px;
}

.overdue-modal {
  width: 100%;
  max-width: 480px;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  background: var(--bs-body-bg);
  color: var(--bs-body-color);
  border-radius: 12px;
  box-shadow: 0 18px 50px rgba(0, 0, 0, 0.35);
  overflow: hidden;
}

.overdue-head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 18px;
  background: var(--bs-danger);
  color: #fff;
}

.overdue-body {
  padding: 18px;
  overflow-y: auto;
}

.overdue-foot {
  padding: 12px 18px;
  display: flex;
  justify-content: flex-end;
  border-top: 1px solid var(--bs-border-color);
}
</style>
