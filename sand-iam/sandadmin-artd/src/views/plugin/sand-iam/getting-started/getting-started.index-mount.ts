/**
 * 将真实 getting-started/index.vue 挂到宿主节点。
 * 仅替换 HTTP 与 useAuth 模块边界；不手写向导壳。
 */
import { createApp, type App } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
import GettingStartedPage from './index.vue'
import 'element-plus/dist/index.css'

export interface GettingStartedHarnessWindow extends Window {
  __wizardRoute?: string
}

/**
 * 读取当前行为窗口上的路由记录。未挂载时返回空字符串。
 */
export function wizardHarnessWindow(): GettingStartedHarnessWindow {
  return window
}

/**
 * 挂载真实第一次使用页，并用内存路由记录完成页跳转。
 */
export function mountGettingStartedPage(host: HTMLElement): App {
  const app = createApp(GettingStartedPage)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: GettingStartedPage },
      { path: '/sand-iam/people-access', component: GettingStartedPage },
      { path: '/sand-iam/connection', component: GettingStartedPage },
      { path: '/sand-iam/event-notification', component: GettingStartedPage }
    ]
  })
  router.afterEach((to) => {
    wizardHarnessWindow().__wizardRoute = to.fullPath
  })
  app.use(router)
  app.use(ElementPlus, { locale: zhCn })
  app.mount(host)
  return app
}
