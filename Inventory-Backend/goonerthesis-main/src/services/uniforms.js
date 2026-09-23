import axios from "../axios";

/* UNIFORM TYPES */

export async function listUniformTypes() {
  const response = await axios.get("/uniformtypes");
  return response.data;
}

export async function getUniformType(id) {
  const response = await axios.get(`/uniformtypes/${id}`);
  return response.data;
}

export async function createUniformType(data) {
  const response = await axios.post("/uniformtypes", data);
  return response.data;
}

export async function updateUniformType(id, data) {
  const response = await axios.put(
    `/uniformtypes/${id}`,
    data
  );

  return response.data;
}

export async function deleteUniformType(id) {
  const response = await axios.delete(
    `/uniformtypes/${id}`
  );

  return response.data;
}

/* UNIFORM VARIANTS */

export async function listUniformVariants() {
  const response = await axios.get("/uniformvariants");
  return response.data;
}

export async function getUniformVariant(id) {
  const response = await axios.get(
    `/uniformvariants/${id}`
  );

  return response.data;
}

export async function createUniformVariant(data) {
  const response = await axios.post(
    "/uniformvariants",
    data
  );

  return response.data;
}

export async function updateUniformVariant(id, data) {
  const response = await axios.put(
    `/uniformvariants/${id}`,
    data
  );

  return response.data;
}

export async function deleteUniformVariant(id) {
  const response = await axios.delete(
    `/uniformvariants/${id}`
  );

  return response.data;
}

/* RESTOCK UNIFORM VARIANT */

export async function restockUniformVariant(id, quantity) {
  const response = await axios.put(
    `/uniformvariants/${id}/restock`,
    {
      quantity: Number(quantity),
    }
  );

  return response.data;
}

/* RELEASE UNIFORM VARIANT */

export async function releaseUniformVariant(id, quantity) {
  const response = await axios.put(
    `/uniformvariants/${id}/release`,
    {
      quantity: Number(quantity),
    }
  );

  return response.data;
}