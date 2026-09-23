import axios from "../axios";

export async function getRoomEquipment(roomId) {
    return (await axios.get(`/rooms/${roomId}/equipment`)).data;
}

export async function assignRoomEquipment(roomId, data) {
    return (await axios.post(`/rooms/${roomId}/equipment`, data)).data;
}

export async function updateRoomEquipment(roomId, id, data) {
    return (await axios.put(`/rooms/${roomId}/equipment/${id}`, data)).data;
}

export async function removeRoomEquipment(roomId, id) {
    return (await axios.delete(`/rooms/${roomId}/equipment/${id}`)).data;
}
