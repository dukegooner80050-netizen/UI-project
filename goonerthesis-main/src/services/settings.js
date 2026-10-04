import axios from "../axios";

export async function getMonthlyRequestLimit() {
    return (await axios.get("/settings/monthly-request-limit")).data;
}

export async function updateMonthlyRequestLimit(limit) {
    return (
        await axios.put("/settings/monthly-request-limit", {
            monthly_request_limit: limit,
        })
    ).data;
}
