import axios from "../axios";

// Everything (the Reports page builds its Excel sheet from this), or with
// { limit: N } just the newest N.
export async function getLogs(params = {}) {
    return (await axios.get("/logs", { params })).data;
}

// One page for the Active Logs table: { data, meta }. The server does the
// filtering, sorting and paging.
export async function getLogsPage(params) {
    return (await axios.get("/logs", { params })).data;
}

// Everyone who appears in the logs (for the User filter).
export async function getLogUsers() {
    return (await axios.get("/logs/users")).data;
}
