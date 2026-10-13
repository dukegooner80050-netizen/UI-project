import axios from "../axios";

export async function getMonthlyRequestLimit() {
    return (await axios.get("/settings/monthly-request-limit")).data;
}

// Default return period (days) per category for borrowed items (roadmap #8).
// Both values are a whole number of days, or null for "no default".
export async function getReturnPeriods() {
    return (await axios.get("/settings/return-periods")).data;
}

export async function updateReturnPeriods({ office_supplies, school_equipment }) {
    return (
        await axios.put("/settings/return-periods", {
            office_supplies,
            school_equipment,
        })
    ).data;
}

export async function updateMonthlyRequestLimit(limit) {
    return (
        await axios.put("/settings/monthly-request-limit", {
            monthly_request_limit: limit,
        })
    ).data;
}
