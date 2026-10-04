import axios from "../axios";

export async function getLogs() {
    return (await axios.get("/logs")).data;
}