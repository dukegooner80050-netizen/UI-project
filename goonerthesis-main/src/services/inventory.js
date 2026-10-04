import { getItems, createItem, updateItem as updateItemApi, deleteItem, restockItem,
    releaseItem, borrowItem, returnItem,} from "./items";

export function autoStatus(item) {
  const qty = Number(item.qty) || 0;

  if (item.category === "Office Supplies" && item.subCategory === "Consumables") {
    if (qty <= 0) item.status = "Out of Stock";
    else if (qty <= 5) item.status = "Low Stock";
    else item.status = "Available";
    return item;
  }

  if (qty <= 0) item.status = "Borrowed";
  else item.status = "Available";
  return item;
}

export async function listInventory() {
  return await getItems();
}

export async function addItem(item) {
    return await createItem(item);
}

export async function updateItem(id, patch) {
  return await updateItemApi(id, patch);
}

export async function removeItem(id) {
  return await deleteItem(id);
}

export async function restockOfficeSupplies(id, quantity) {
    return await restockItem(id, quantity);
}

export async function releaseOfficeConsumables(id, quantity) {
    return await releaseItem(id, quantity);
}

export async function returnUniform(id, quantity) {
    return await returnItem(id, quantity);
}

export async function borrowNonConsumables(id, quantity) {
    return await borrowItem(id, quantity);
}

export async function returnNonConsumables(id, quantity) {
    return await returnItem(id, quantity);
}

export async function borrowEquipment(id, quantity) {
    return await borrowItem(id, quantity);
}

export async function returnEquipment(id, quantity) {
    return await returnItem(id, quantity);
}