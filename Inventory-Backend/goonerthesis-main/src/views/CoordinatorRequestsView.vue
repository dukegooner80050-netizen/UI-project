<script setup>
import { ref, onMounted, watch } from 'vue'
import axios from '../axios'
import AlertMessage from '../components/AlertMessage.vue'

const requests = ref([])
const directRequests = ref([])
const tab = ref('queue')
const loading = ref(false)
const busyId = ref(null)
const showAlert = ref(false)
const alertType = ref('success')
const alertMessage = ref('')
function notify(type, message) { alertType.value = type; alertMessage.value = message; showAlert.value = true }
async function refresh() {
  loading.value = true
  try {
    const { data } = await axios.get('/requests/coordinator-queue')
    requests.value = Array.isArray(data) ? data : []
    const d = await axios.get('/requests/direct-queue')
    directRequests.value = Array.isArray(d.data) ? d.data : []
  } catch (e) { notify('danger', e.response?.data?.message || 'Could not load requests waiting for coordinator review.') }
  finally { loading.value = false }
}
// Asks first (app modal, not the browser's native confirm), then forwards.
const confirmTarget = ref(null)
function forward(r) { confirmTarget.value = r }
async function doForward() {
  const r = confirmTarget.value
  confirmTarget.value = null
  if (!r) return
  busyId.value = r.id
  try {
    const { data } = await axios.put(`/requests/${r.id}/forward-to-inventory`)
    notify('success', data.message || 'Request forwarded.')
    await refresh()
  } catch (e) { notify('danger', e.response?.data?.message || 'Could not forward request.') }
  finally { busyId.value = null }
}
// Review of requests an instructor sent straight to the AMO. Neither action
// blocks the AMO; Flag also tells the AMO.
const flagTarget = ref(null)
const flagNote = ref('')
async function review(r, action, note = '') {
  busyId.value = r.id
  try {
    const { data } = await axios.put(`/requests/${r.id}/coordinator-review`, { action, note })
    notify('success', data.message || 'Saved.')
    flagTarget.value = null
    flagNote.value = ''
    await refresh()
  } catch (e) { notify('danger', e.response?.data?.message || 'Could not save your review.') }
  finally { busyId.value = null }
}
function openFlag(r) { flagTarget.value = r; flagNote.value = '' }
const unreviewedCount = () => directRequests.value.filter(r => !r.coordReview).length
// History: everything from instructors in the head's own department.
const hist = ref({ requests: [], instructors: [], summary: [], scopedToDepartment: true })
const histLoading = ref(false)
const monthStart = () => { const d = new Date(); return new Date(d.getFullYear(), d.getMonth(), 1).toLocaleDateString('en-CA') }
const today = () => new Date().toLocaleDateString('en-CA')
const hf = ref({ from: monthStart(), to: today(), user_id: '', source: 'all' })
async function loadHistory() {
  histLoading.value = true
  try {
    const params = { source: hf.value.source }
    if (hf.value.from) params.from = hf.value.from
    if (hf.value.to) params.to = hf.value.to
    if (hf.value.user_id) params.user_id = hf.value.user_id
    const { data } = await axios.get('/requests/coordinator-history', { params })
    hist.value = data
  } catch (e) { notify('danger', e.response?.data?.message || 'Could not load the history.') }
  finally { histLoading.value = false }
}
function clearHistoryFilters() { hf.value = { from: '', to: '', user_id: '', source: 'all' } }
watch(hf, loadHistory, { deep: true })
watch(tab, (t) => { if (t === 'history') loadHistory() })
function summary(r) {
  const names = [...(r.items || []).map(i => `${i.itemName} × ${i.qty}`), ...(r.uniforms || []).map(u => `${u.uniformName || 'Uniform'} (${u.size || 'size n/a'}) × ${u.quantity}`)]
  return names.length ? names.join(', ') : 'No line items found'
}
onMounted(refresh)
</script>

<template>
  <div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div><h2 class="mb-1">Coordinator Request Review</h2><p class="text-muted mb-0">Review requests and forward them to Inventory Management. This step does not approve the request or change stock.</p></div>
      <button class="btn btn-outline-secondary" title="Refresh" aria-label="Refresh" @click="refresh" :disabled="loading">
  <i class="bi bi-arrow-clockwise" :class="{ spin: loading }"></i>
</button>
    </div>
    <AlertMessage v-model:show="showAlert" :type="alertType" :message="alertMessage" />
    <div class="d-flex flex-wrap gap-2 mb-3">
      <button class="btn" :class="tab === 'queue' ? 'btn-primary' : 'btn-outline-primary'" @click="tab = 'queue'">Waiting for review ({{ requests.length }})</button>
      <button class="btn" :class="tab === 'direct' ? 'btn-primary' : 'btn-outline-primary'" @click="tab = 'direct'">Sent directly to AMO ({{ unreviewedCount() }} to review)</button>
      <button class="btn" :class="tab === 'history' ? 'btn-primary' : 'btn-outline-primary'" @click="tab = 'history'">History</button>
    </div>

    <template v-if="tab === 'history'">
      <div v-if="!hist.scopedToDepartment" class="alert alert-warning py-2 small">
        Your account has no department set, so every instructor is shown. Ask the AMO to set your department (Settings → Account Management) to see only your department's instructors.
      </div>
      <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
        <div>
          <label for="hf-from" class="form-label small mb-1">From</label>
          <input id="hf-from" name="hf_from" type="date" class="form-control form-control-sm" v-model="hf.from" />
        </div>
        <div>
          <label for="hf-to" class="form-label small mb-1">To</label>
          <input id="hf-to" name="hf_to" type="date" class="form-control form-control-sm" v-model="hf.to" />
        </div>
        <div>
          <label for="hf-user" class="form-label small mb-1">Instructor</label>
          <select id="hf-user" name="hf_user" class="form-select form-select-sm" v-model="hf.user_id">
            <option value="">All instructors</option>
            <option v-for="i in hist.instructors" :key="i.idUsers" :value="i.idUsers">{{ i.full_name }}</option>
          </select>
        </div>
        <div>
          <label for="hf-source" class="form-label small mb-1">Came from</label>
          <select id="hf-source" name="hf_source" class="form-select form-select-sm" v-model="hf.source">
            <option value="all">Both tabs</option>
            <option value="review">Waiting for review</option>
            <option value="direct">Sent directly to AMO</option>
          </select>
        </div>
        <button class="btn btn-sm btn-outline-secondary" @click="clearHistoryFilters">Clear filters</button>
      </div>

      <div v-if="hist.summary.length" class="d-flex flex-wrap gap-2 mb-3">
        <span v-for="s in hist.summary" :key="s.idUsers" class="badge text-bg-light border text-start">
          {{ s.name }}: <strong>{{ s.total }}</strong>
          <span class="text-muted">({{ s.review }} reviewed · {{ s.direct }} direct)</span>
        </span>
      </div>

      <div v-if="histLoading && !hist.requests.length" class="text-muted py-4">Loading history…</div>
      <div v-else-if="!hist.requests.length" class="alert alert-info">No requests match these filters.</div>
      <div v-else class="table-responsive table-scroll bg-body rounded shadow-sm">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light sticky-top"><tr><th>Request</th><th>Instructor</th><th>Date</th><th>Items / uniforms</th><th>Came from</th><th>AMO status</th><th>Review</th></tr></thead>
          <tbody><tr v-for="r in hist.requests" :key="r.id">
            <td class="fw-semibold">#{{ r.id }}</td>
            <td>{{ r.requesterName || r.requester }}</td>
            <td>{{ r.request_date ? new Date(r.request_date).toLocaleString('en-PH') : '—' }}</td>
            <td class="request-summary">{{ summary(r) }}</td>
            <td>
              <span class="badge" :class="r.source === 'direct' ? 'text-bg-warning' : 'text-bg-info'">{{ r.source === 'direct' ? 'Sent directly' : 'Reviewed' }}</span>
              <div v-if="r.directReason" class="small text-muted">{{ r.directReason }}</div>
            </td>
            <td><span class="badge text-bg-secondary">{{ r.status }}</span></td>
            <td>
              <span v-if="r.source === 'direct'" class="badge" :class="!r.coordReview ? 'text-bg-warning' : r.coordReview === 'Flagged' ? 'text-bg-danger' : 'text-bg-success'">{{ r.coordReview || 'Not reviewed' }}</span>
              <span v-else class="text-muted">—</span>
              <div v-if="r.coordReviewNote" class="small text-muted">{{ r.coordReviewNote }}</div>
            </td>
          </tr></tbody>
        </table>
      </div>
    </template>

    <template v-else-if="tab === 'direct'">
      <p class="text-muted small">Instructors can skip the department head when you are away. These already went to the AMO. Acknowledge them, or flag a concern; flagging notifies the AMO. Neither action blocks or undoes the request.</p>
      <div v-if="!directRequests.length" class="alert alert-success">No requests were sent directly to the AMO.</div>
      <div v-else class="table-responsive table-scroll bg-body rounded shadow-sm">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light sticky-top"><tr><th>Request</th><th>Instructor</th><th>Date</th><th>Items / uniforms</th><th>Why skipped</th><th>AMO status</th><th>Your review</th><th>Action</th></tr></thead>
          <tbody><tr v-for="r in directRequests" :key="r.id">
            <td class="fw-semibold">#{{ r.id }}</td><td>{{ r.requester || 'Unknown' }}</td>
            <td>{{ r.request_date ? new Date(r.request_date).toLocaleString('en-PH') : '—' }}</td>
            <td class="request-summary">{{ summary(r) }}</td>
            <td class="request-summary">{{ r.directReason }}</td>
            <td><span class="badge text-bg-secondary">{{ r.status }}</span></td>
            <td>
              <span v-if="!r.coordReview" class="badge text-bg-warning">Not reviewed</span>
              <span v-else class="badge" :class="r.coordReview === 'Flagged' ? 'text-bg-danger' : 'text-bg-success'">{{ r.coordReview }}</span>
              <div v-if="r.coordReviewNote" class="small text-muted mt-1">{{ r.coordReviewNote }}</div>
            </td>
            <td class="text-nowrap">
              <button v-if="r.coordReview !== 'Acknowledged'" class="btn btn-sm btn-success me-1" :disabled="busyId === r.id" @click="review(r, 'acknowledge')">Acknowledge</button>
              <button v-if="r.coordReview !== 'Flagged'" class="btn btn-sm btn-danger text-white" :disabled="busyId === r.id" @click="openFlag(r)">Flag</button>
            </td>
          </tr></tbody>
        </table>
      </div>
    </template>

    <template v-else>
    <div v-if="loading && !requests.length" class="text-muted py-4">Loading coordinator queue…</div>
    <div v-else-if="!requests.length" class="alert alert-success">No requests are currently waiting for coordinator review.</div>
    <div v-else class="table-responsive table-scroll bg-body rounded shadow-sm">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light sticky-top"><tr><th>Request</th><th>Requester</th><th>Date</th><th>Items / uniforms</th><th>Purpose / location</th><th>Status</th><th>Action</th></tr></thead>
        <tbody><tr v-for="r in requests" :key="r.id">
          <td class="fw-semibold">#{{ r.id }}</td><td>{{ r.requester || 'Unknown' }}</td><td>{{ r.request_date ? new Date(r.request_date).toLocaleString('en-PH') : '—' }}</td>
          <td class="request-summary">{{ summary(r) }}</td><td><div>{{ r.purpose || '—' }}</div><small class="text-muted">{{ r.location || '—' }} / {{ r.room || '—' }}</small></td>
          <td><span class="badge text-bg-warning">With Coordinator</span></td>
          <td><button class="btn btn-sm btn-success" :disabled="busyId === r.id" @click="forward(r)">{{ busyId === r.id ? 'Forwarding…' : 'Forward to Inventory' }}</button></td>
        </tr></tbody>
      </table>
    </div>
    </template>

    <div v-if="flagTarget" class="modal-backdrop-custom">
      <div class="modal-custom" style="max-width: 460px">
        <div class="modal-header">
          <h5 class="mb-0">Flag request #{{ flagTarget.id }}</h5>
          <button class="btn-close" @click="flagTarget = null"></button>
        </div>
        <div class="modal-body">
          <label for="flag-note" class="form-label">What is your concern?</label>
          <textarea id="flag-note" name="flag_note" v-model="flagNote" class="form-control" rows="3" maxlength="500" placeholder="The AMO will see this note."></textarea>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="flagTarget = null">Cancel</button>
          <button class="btn btn-danger" :disabled="busyId === flagTarget.id || flagNote.trim().length < 3" @click="review(flagTarget, 'flag', flagNote.trim())">Flag and notify AMO</button>
        </div>
      </div>
    </div>

    <div v-if="confirmTarget" class="modal-backdrop-custom">
      <div class="modal-custom" style="max-width: 420px">
        <div class="modal-header">
          <h5 class="mb-0">Forward Request</h5>
          <button class="btn-close" @click="confirmTarget = null"></button>
        </div>
        <div class="modal-body">
          <p class="mb-0">Forward request #{{ confirmTarget.id }} to Inventory Management?</p>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="confirmTarget = null">Cancel</button>
          <button class="btn btn-success" @click="doForward">Forward</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.table-scroll { max-height: 520px; overflow: auto; }
.request-summary { min-width: 220px; max-width: 330px; white-space: normal; }
.spin { display: inline-block; animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>