import { createApp } from "vue"
import App from "./App.vue"
import router from "./router"
import "./axios"
import "./assets/main.css"
import "bootstrap-icons/font/bootstrap-icons.css";


import "bootstrap/dist/css/bootstrap.min.css"
import "bootstrap/dist/js/bootstrap.bundle.min.js"
import { initTheme } from "./services/theme"

// Apply the saved light/dark preference before the app renders, so there's
// no flash of the wrong theme on load.
initTheme()

const app = createApp(App);
app.use(router)

app.mount('#app')