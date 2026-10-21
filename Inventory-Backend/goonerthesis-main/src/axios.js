import axios from "axios";
import { loadingStarted, loadingFinished } from "./services/loading";

axios.defaults.baseURL = import.meta.env.VITE_API_URL;

axios.interceptors.request.use(config => {
    const token = localStorage.getItem("token");

    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }

    config.headers.Accept = "application/json";

    loadingStarted(config);

    return config;
}, (error) => Promise.reject(error));

// Every request ends here, success or failure, so the counter always
// comes back down.
axios.interceptors.response.use(
    (response) => {
        loadingFinished(response.config);
        return response;
    },
    (error) => {
        loadingFinished(error.config);
        return Promise.reject(error);
    }
);

export default axios;