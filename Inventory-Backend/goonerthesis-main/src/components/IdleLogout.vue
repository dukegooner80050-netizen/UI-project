<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from "vue";
import { useRouter } from "vue-router";
import { getCurrentUser } from "../services/storage";
import { logout } from "../services/auth";

/*  SETTINGS  */

const AMO_LIMIT_MIN = 10;
const OTHER_LIMIT_MIN = 20;
const WARN_SECONDS = 60;
const CHECK_MS = 5000;
const SAVE_EVERY_MS = 5000;
const KEY = "cims:lastActivity";

const router = useRouter();

const limitMs = computed(() => {
  const role = String(getCurrentUser()?.role || "").toLowerCase();
  return (role === "amo" ? AMO_LIMIT_MIN : OTHER_LIMIT_MIN) * 60 * 1000;
});

const warning = ref(false);
const secondsLeft = ref(WARN_SECONDS);
let timer = null;
let lastSaved = 0;

/*  SHARED ACTIVITY (all open tabs use the same clock)  */

function readLast() {
  try {
    return Number(localStorage.getItem(KEY)) || Date.now();
  } catch {
    return Date.now();
  }
}

function markActive() {
  const now = Date.now();
  if (now - lastSaved < SAVE_EVERY_MS) return;
  lastSaved = now;
  try {
    localStorage.setItem(KEY, String(now));
  } catch {
    /* the timer still works per tab */
  }
}

function stayIn() {
  lastSaved = 0;
  markActive();
  warning.value = false;
}

/*  CHECK  */

async function endSession() {
  clearInterval(timer);
  warning.value = false;
  try {
    sessionStorage.setItem(
      "sessionEndedMessage",
      "You were signed out after a period of inactivity. Please log in again.",
    );
  } catch {
    /* the login page just won't show the note */
  }
  try {
    await logout();
  } catch {
    /* the local sign-out already happened; still go to the login page */
  }
  router.push("/login");
}

function check() {
  if (!localStorage.getItem("token")) return;

  const idle = Date.now() - readLast();
  const left = Math.ceil((limitMs.value - idle) / 1000);

  if (left <= 0) {
    endSession();
  } else if (left <= WARN_SECONDS) {
    secondsLeft.value = left;
    warning.value = true;
  } else {
    warning.value = false;
  }
}

const EVENTS = ["mousemove", "mousedown", "keydown", "scroll", "touchstart", "click"];

onMounted(() => {
  lastSaved = 0;
  markActive();
  EVENTS.forEach((e) => window.addEventListener(e, onActivity, { passive: true }));
  timer = setInterval(check, CHECK_MS);
});

onBeforeUnmount(() => {
  clearInterval(timer);
  EVENTS.forEach((e) => window.removeEventListener(e, onActivity));
});

// The warning stays up until the user clicks "Stay signed in".
function onActivity() {
  if (!warning.value) markActive();
}
</script>

<template>
  <div v-if="warning" class="idle-backdrop">
    <div class="idle-box" role="alertdialog" aria-live="assertive">
      <h5 class="mb-2">Still there?</h5>
      <p class="mb-3">
        You will be signed out in <strong>{{ secondsLeft }}</strong> seconds because of inactivity.
      </p>
      <button class="btn btn-primary" @click="stayIn">Stay signed in</button>
    </div>
  </div>
</template>

<style scoped>
.idle-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 5000;
}
.idle-box {
  background: #fff;
  color: #212529;
  border-radius: 10px;
  padding: 24px;
  width: min(92vw, 380px);
  text-align: center;
}
</style>
