<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from "vue";
import { getRequests } from "../services/requests";
import { getCurrentUser } from "../services/storage";
import { isAdmin } from "../services/session";
import { isOverdue, daysOverdue, formatDue } from "../services/dueDates";

// Tells people about borrowed items that are past their due date.
//  - A borrower (dean / cashier) sees their own overdue items, in a banner on
//    every page, plus a pop-up the first time it is noticed in a session.
//  - An admin sees everybody's overdue items in the banner (no pop-up).
// It uses the same overdue rule as the Office Supplies / School Equipment
// pages (dueDates.js), so the numbers always agree.

const REFRESH_MS = 60000; // looks again every minute
const SEEN_KEY = "overdueNoticeSeen";

const me = getCurrentUser();
const admin = isAdmin(me);

const requests = ref([]);
const expanded = ref(false);
const noticeOpen = ref(false);
let timer = null;

async function load() {
  try {
    requests.value = await getRequests();
  } catch {
    // keep the last list; the pages report their own loading errors
  }
}

// One row for every borrowed line that is still out and past its due date.
const rows = computed(() => {
  const out = [];

  for (const request of requests.value) {
    if (String(request.status || "").toLowerCase() !== "approved") continue;

    // Borrowers only see their own; admins see everyone's.
    if (!admin && Number(request.idUsers) !== Number(me?.idUsers)) continue;

    for (const item of request.items || []) {
      if (!isOverdue(item)) continue;

      out.push({
        key: `${request.id}-${item.requestItemId}`,
        requester: request.requester,
        itemName: item.itemName,
        qty: Number(item.borrowedQty),
        dueAt: item.dueAt,
        days: daysOverdue(item),
        place: [request.location, request.room].filter(Boolean).join(" / "),
      });
    }
  }

  return out.sort((a, b) => b.days - a.days);
});

const people = computed(() => new Set(rows.value.map((r) => r.requester)).size);

function s(n) {
  return n === 1 ? "" : "s";
}

function daysText(n) {
  return `${n} day${s(n)} overdue`;
}

// Pop-up for borrowers, once per session, and again only if a new item
// becomes overdue.
function signature(list) {
  return list
    .map((r) => r.key)
    .sort()
    .join("|");
}

watch(rows, (list) => {
  if (admin || list.length === 0) return;

  if (sessionStorage.getItem(SEEN_KEY) !== signature(list)) {
    noticeOpen.value = true;
  }
});

function acknowledge() {
  sessionStorage.setItem(SEEN_KEY, signature(rows.value));
  noticeOpen.value = false;
}

onMounted(() => {
  load();
  timer = setInterval(load, REFRESH_MS);
});

onBeforeUnmount(() => {
  if (timer) clearInterval(timer);
});
</script>

<template>
  <div>
    <!-- BANNER (every page) -->
    <div v-if="rows.length > 0" class="alert alert-danger" role="alert">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
          <i class="bi bi-exclamation-octagon-fill me-2"></i>
          <strong v-if="!admin">
            You have {{ rows.length }} overdue item{{ s(rows.length) }} to return.
          </strong>
          <strong v-else>
            {{ rows.length }} overdue borrowing{{ s(rows.length) }} from
            {{ people }} requester{{ s(people) }}.
          </strong>
          <span v-if="!admin" class="d-block small">
            Please return {{ rows.length === 1 ? "it" : "them" }} to the
            administrator as soon as possible.
          </span>
        </div>

        <button
          type="button"
          class="btn btn-sm btn-outline-danger"
          @click="expanded = !expanded"
        >
          {{ expanded ? "Hide details" : "View details" }}
        </button>
      </div>

      <ul v-if="expanded" class="overdue-list list-unstyled small mb-0 mt-2">
        <li v-for="r in rows" :key="r.key">
          <strong v-if="admin">{{ r.requester }}: </strong>
          {{ r.itemName }} &times; {{ r.qty }}
          &mdash; due {{ formatDue(r.dueAt) }}
          <span class="badge bg-danger ms-1">{{ daysText(r.days) }}</span>
          <span v-if="r.place" class="text-muted"> &middot; {{ r.place }}</span>
        </li>
      </ul>
    </div>

    <!-- POP-UP (borrowers, once per session) -->
    <div
      v-if="noticeOpen"
      class="overdue-backdrop"
      @click.self="acknowledge"
    >
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
            You still have {{ rows.length }} borrowed item{{ s(rows.length) }}
            past the due date. Please return
            {{ rows.length === 1 ? "it" : "them" }} to the administrator as
            soon as possible.
          </p>

          <ul class="overdue-list list-unstyled mb-0">
            <li v-for="r in rows" :key="r.key" class="mb-2">
              <strong>{{ r.itemName }}</strong> &times; {{ r.qty }}
              <div class="small text-muted">
                Due {{ formatDue(r.dueAt) }} &middot;
                <span class="text-danger">{{ daysText(r.days) }}</span>
                <span v-if="r.place"> &middot; {{ r.place }}</span>
              </div>
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
.overdue-list {
  max-height: 220px;
  overflow-y: auto;
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
