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

// AMO: set an instructor's / department head's department (null clears it).
export async function setUserDepartment(id, iddept) {
  const { data } = await axios.put(`/users/${id}/department`, { iddept: iddept || null });
  return data;
}

export async function deleteUser(id) {
    return (await axios.delete(`/users/${id}`)).data;
}

// AMO only. Returns { temporary_password } -- shown once.
export async function resetUserPassword(id) {
    return (await axios.post(`/users/${id}/reset-password`)).data;
}
