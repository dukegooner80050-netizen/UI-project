import axios from "../axios";

export async function getUniformTypes() {
    return (await axios.get("/uniformtypes")).data;
}

export async function getUniformType(id) {
    return (await axios.get(`/uniformtypes/${id}`)).data;
}

export async function createUniformType(data) {
    return (await axios.post("/uniformtypes", data)).data;
}

export async function updateUniformType(id, data) {
    return (await axios.put(`/uniformtypes/${id}`, data)).data;
}

export async function deleteUniformType(id) {
    return (await axios.delete(`/uniformtypes/${id}`)).data;
}