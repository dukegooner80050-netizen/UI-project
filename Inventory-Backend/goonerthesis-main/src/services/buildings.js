import axios from "../axios";

export async function getBuildings() {
    return (await axios.get("/buildings")).data;
}

export async function createBuilding(data) {
    return (await axios.post("/buildings", data)).data;
}

export async function updateBuilding(id, data) {
    return (await axios.put(`/buildings/${id}`, data)).data;
}

export async function deleteBuilding(id) {
    return (await axios.delete(`/buildings/${id}`)).data;
}
