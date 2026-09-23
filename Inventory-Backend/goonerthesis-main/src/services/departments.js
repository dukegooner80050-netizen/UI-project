import axios from "../axios";

export async function getDepartments() {
    return (await axios.get("/departments")).data;
}

export async function getDepartment(id) {
    return (await axios.get(`/departments/${id}`)).data;
}

export async function createDepartment(data) {
    return (await axios.post("/departments", data)).data;
}

export async function updateDepartment(id, data) {
    return (await axios.put(`/departments/${id}`, data)).data;
}

export async function deleteDepartment(id) {
    return (await axios.delete(`/departments/${id}`)).data;
}