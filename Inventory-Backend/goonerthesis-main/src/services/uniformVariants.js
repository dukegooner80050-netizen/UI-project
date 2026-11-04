import axios from "../axios";

export async function getUniformVariants() {
    return (await axios.get("/uniformvariants")).data;
}

export async function getUniformVariant(id) {
    return (await axios.get(`/uniformvariants/${id}`)).data;
}

export async function createUniformVariant(data) {
    return (await axios.post("/uniformvariants", data)).data;
}

export async function updateUniformVariant(id, data) {
    return (await axios.put(`/uniformvariants/${id}`, data)).data;
}

export async function deleteUniformVariant(id) {
    return (await axios.delete(`/uniformvariants/${id}`)).data;
}