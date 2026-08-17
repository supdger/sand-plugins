import ArcoVue from "@arco-design/web-vue";
import "@arco-design/web-vue/dist/arco.css";
import type { App } from "vue";

const installedApps = new WeakSet<App>();

export function installArco(app: App): void {
  if (installedApps.has(app)) return;
  app.use(ArcoVue);
  installedApps.add(app);
}
