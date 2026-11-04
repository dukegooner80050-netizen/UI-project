import axios from "../axios";

export async function getReports() {
    return (await axios.get("/reports")).data;
}