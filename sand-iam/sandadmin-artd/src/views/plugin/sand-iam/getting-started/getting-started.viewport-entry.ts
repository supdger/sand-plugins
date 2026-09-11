/**
 * 视口入口：挂载真实 index.vue。query.mode 只切换 HTTP/权限夹具，不改页面实现。
 */
import { createWizardContext } from './wizardState'
import { configureWizardAuth } from './getting-started.auth-mock'
import { configureWizardHttpMock } from './getting-started.http-mock'
import { mountGettingStartedPage } from './getting-started.index-mount'

const CONTEXT_KEY = 'sand-iam.getting-started.v1'
const LONG_NAME =
  '客户主体超长名称用于确认窄屏与短视口仍可滚到页底并读完错误提示ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'

const root = document.getElementById('app')
if (root) {
  const mode = new URLSearchParams(window.location.search).get('mode')
  configureWizardAuth([])
  window.localStorage.removeItem(CONTEXT_KEY)
  if (mode === 'complete' || mode === 'long') {
    configureWizardHttpMock({
      seed: [
        { id: 11, name: LONG_NAME, code: 'subject-alpha', status: 1 },
        {
          id: 22,
          name: '接入应用超长名称用于确认完成页目标入口仍可点到',
          code: 'app-alpha',
          status: 1,
          organization_id: 11
        },
        {
          id: 33,
          name: '应用环境超长名称用于确认完成页可滚到底',
          code: 'env-alpha',
          status: 1,
          application_id: 22
        }
      ]
    })
    window.localStorage.setItem(
      CONTEXT_KEY,
      JSON.stringify(
        createWizardContext(
          mode === 'long'
            ? { organization: 11, application: null, environment: null }
            : { organization: 11, application: 22, environment: 33 },
          mode === 'long'
            ? { organization: 'confirmed', application: 'draft', environment: 'draft' }
            : { organization: 'confirmed', application: 'confirmed', environment: 'confirmed' },
          { organization: null, application: null, environment: null },
          Date.now()
        )
      )
    )
  } else if (mode === 'error') {
    configureWizardHttpMock({ saveMode: 'conflict' })
  } else if (mode === 'empty') {
    configureWizardHttpMock({ listMode: 'empty' })
  } else {
    configureWizardHttpMock({})
  }
  mountGettingStartedPage(root)
}
