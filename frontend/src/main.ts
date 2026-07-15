import { createApp } from "vue";
import { createPinia } from "pinia";
import router from "./routes/index";

import "./style.css";
import App from "./App.vue";
import { useThemeStore } from "./stores/theme";

const pinia = createPinia();
const app = createApp(App);

app.use(pinia);
app.use(router);

// Stamp the active theme on <html> before mount, then keep following the OS
// until the user makes an explicit choice.
const theme = useThemeStore(pinia);
theme.apply();
theme.watchSystem();

app.mount("#app");
