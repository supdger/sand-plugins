/**
 * 浏览器行为入口：挂载真实 index.vue 并写出可观察结果。
 */
import { runGettingStartedBehaviorHarness } from './getting-started.behavior'

const out = document.getElementById('out')

runGettingStartedBehaviorHarness()
  .then((passed) => {
    document.body.dataset.result = 'pass'
    if (out) out.textContent = JSON.stringify({ ok: true, passed, count: passed.length }, null, 2)
  })
  .catch((error: unknown) => {
    document.body.dataset.result = 'fail'
    const message = error instanceof Error ? (error.stack ?? error.message) : String(error)
    if (out) out.textContent = message
  })
