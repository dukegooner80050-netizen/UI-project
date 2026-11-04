import axios from "axios";
import { loadingStarted, loadingFinished } from "./services/loading";
import { getCurrentUser, setCurrentUser, clearCurrentUser } from "./services/storage";
import { clearSharedCache } from "./services/sharedCache";

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

/* Every request ends here, so the loading counter always comes back down. */
axios.interceptors.response.use(
    (response) => {
        loadingFinished(response.config);

        /* Data changed on the server: drop the cached lists so the next view is fresh. */
        if ((response.config?.method || "get").toLowerCase() !== "get") {
            clearSharedCache();
        }

        return response;
    },
    (error) => {
        loadingFinished(error.config);

        const status = error.response?.status;
        const url = error.config?.url || "";

        /*
         * Lost session (timeout, signed out elsewhere, password reset) returns to login; a /login 401 is just a
         * wrong password.
         */
        if (status === 401 && !url.includes("/login") && localStorage.getItem("token")) {
            endSession(error.response?.data?.message);
        }

        // A temporary password is still set: only the change-password page works.
        if (status === 403 && error.response?.data?.code === "password_change_required") {
            const user = getCurrentUser();
            if (user) setCurrentUser({ ...user, must_change_password: true });
            goTo("/change-password");
        }

        return Promise.reject(error);
    }
);

let redirecting = false;

// Router is loaded lazily: it imports the views, which import this file.
function goTo(path) {
    import("./router").then(({ default: router }) => {
        if (router.currentRoute.value.path !== path) router.push(path);
    });
}

function endSession(message) {
    if (redirecting) return;
    redirecting = true;

    localStorage.removeItem("token");
    clearCurrentUser();

    try {
        sessionStorage.setItem(
            "sessionEndedMessage",
            message || "Your session has ended. Please log in again."
        );
    } catch {
        /* the login page just won't show the note */
    }

    goTo("/login");
    setTimeout(() => (redirecting = false), 1500);
}

export default axios;