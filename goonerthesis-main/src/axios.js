import axios from "axios";

axios.defaults.baseURL = import.meta.env.VITE_API_URL;

axios.interceptors.request.use(config => {
    const token = localStorage.getItem("token");

    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }

    config.headers.Accept = "application/json";

    return config;
});

export default axios;