import axios from "../axios";

export async function getUsers() {
    return (await axios.get("/users")).data;
}

export async function getUser(id) {
    return (await axios.get(`/users/${id}`)).data;
}

export async function createUser(data) {
    return (await axios.post("/users", data)).data;
}

export async function updateUser(id, data) {
    return (await axios.put(`/users/${id}`, data)).data;
}

export async function deleteUser(id) {
    return (await axios.delete(`/users/${id}`)).data;
}