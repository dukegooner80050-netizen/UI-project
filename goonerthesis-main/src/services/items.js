import axios from "../axios";

function normalize(item) {
  return {
    ...item,

    id: item.iditems ?? item.id,
    name: item.item_name,
    description: item.description,
    price: Number(item.price),
    qty: Number(item.quantity),
    subCategory: item.item_type,
  };
}

export async function getItems() {
  const response = await axios.get("/items");
  return response.data.map(normalize);
}

export async function getItem(id) {
  const response = await axios.get(`/items/${id}`);
  return normalize(response.data);
}

export async function createItem(data) {
  const response = await axios.post("/items", data);
  return normalize(response.data.item);
}

export async function updateItem(id, data) {
  const response = await axios.put(`/items/${id}`, data);
  return normalize(response.data.item);
}

export async function deleteItem(id) {
  const response = await axios.delete(`/items/${id}`);
  return response.data;
}

export async function restockItem(id, quantity) {
  return (
    await axios.put(`/items/${id}/restock`, {
      quantity,
    })
  ).data;
}

export async function releaseItem(id, quantity) {
  return (
    await axios.put(`/items/${id}/release`, {
      quantity,
    })
  ).data;
}

export async function borrowItem(id, quantity) {
  return (
    await axios.put(`/items/${id}/borrow`, {
      quantity,
    })
  ).data;
}

export async function returnItem(id, quantity) {
  return (
    await axios.put(`/items/${id}/return`, {
      quantity,
    })
  ).data;
}
