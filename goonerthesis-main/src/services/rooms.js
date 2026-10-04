import axios from "../axios";

export async function getRooms(idbuilding) {
    const params = idbuilding ? { idbuilding } : {};
    return (await axios.get("/rooms", { params })).data;
}

export async function createRoom(data) {
    return (await axios.post("/rooms", data)).data;
}

export async function updateRoom(id, data) {
    return (await axios.put(`/rooms/${id}`, data)).data;
}

export async function deleteRoom(id) {
    return (await axios.delete(`/rooms/${id}`)).data;
}
