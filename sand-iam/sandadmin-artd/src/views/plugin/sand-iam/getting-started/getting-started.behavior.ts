/**
 * 挂载真实 index.vue 的行为检查：保存后重读/ID 级联、防重复创建、返回修改、刷新恢复、错误恢复、完成下一步。
 * 只驱动公开按钮、输入框和可见文案，不读取生产源码做文本匹配。
 */
import { configureWizardAuth } from './getting-started.auth-mock'
import { configureWizardHttpMock, wizardHttpSavePostCount } from './getting-started.http-mock'
import { mountGettingStartedPage, wizardHarnessWindow } from './getting-started.index-mount'

const CONTEXT_KEY = 'sand-iam.getting-started.v1'

function textOf(el: Element | null): string {
  return el?.textContent?.replace(/\s+/g, ' ').trim() ?? ''
}

function buttonsOf(root: ParentNode): HTMLButtonElement[] {
  return Array.from(root.querySelectorAll('button')).filter(
    (button): button is HTMLButtonElement => button instanceof HTMLButtonElement
  )
}

function buttonByText(root: ParentNode, label: string): HTMLButtonElement | null {
  for (const button of buttonsOf(root)) {
    if (textOf(button).includes(label)) return button
  }
  return null
}

function expectTrue(condition: boolean, label: string): void {
  if (!condition) throw new Error(label)
}

/**
 * Vue / Element Plus 需要原生 value setter，只改 DOM 属性不会更新 v-model。
 */
function setNativeInputValue(input: HTMLInputElement, value: string): void {
  const descriptor = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')
  descriptor?.set?.call(input, value)
  input.dispatchEvent(new InputEvent('input', { bubbles: true, composed: true, data: value }))
  input.dispatchEvent(new Event('change', { bubbles: true }))
}

function formTextInputs(root: ParentNode): HTMLInputElement[] {
  const inputs: HTMLInputElement[] = []
  for (const input of Array.from(root.querySelectorAll('.sand-iam-getting-started__form input'))) {
    if (!(input instanceof HTMLInputElement)) continue
    if (input.type === 'radio' || input.type === 'checkbox' || input.type === 'hidden') continue
    inputs.push(input)
  }
  return inputs
}

async function waitFor(predicate: () => boolean, label: string, timeoutMs = 4000): Promise<void> {
  const started = Date.now()
  while (Date.now() - started < timeoutMs) {
    if (predicate()) return
    await new Promise((resolve) => window.setTimeout(resolve, 30))
  }
  throw new Error(`timeout: ${label}`)
}

async function fillCurrentForm(root: ParentNode, name: string, code: string): Promise<void> {
  await waitFor(() => formTextInputs(root).length >= 2, `form inputs for ${code}`)
  const inputs = formTextInputs(root)
  setNativeInputValue(inputs[0], name)
  setNativeInputValue(inputs[1], code)
}

function headingText(root: ParentNode): string {
  const heading = root.querySelector('.sand-iam-getting-started__panel h3')
  return textOf(heading)
}

function visibleAlert(root: ParentNode): string {
  return textOf(root.querySelector('.el-alert'))
}

/**
 * 清空浏览器进度并重置权限/HTTP 夹具，避免用例之间串状态。
 */
function resetHarness(options?: {
  readonly denied?: readonly string[]
  readonly http?: Parameters<typeof configureWizardHttpMock>[0]
}): void {
  window.localStorage.removeItem(CONTEXT_KEY)
  configureWizardAuth(options?.denied ?? [])
  configureWizardHttpMock(options?.http ?? {})
  wizardHarnessWindow().__wizardRoute = undefined
}

async function createCurrentStep(
  root: ParentNode,
  name: string,
  code: string,
  createLabel: string,
  nextHeading: string
): Promise<void> {
  await fillCurrentForm(root, name, code)
  const submit = buttonByText(root, createLabel)
  expectTrue(submit !== null && submit.disabled === false, `${createLabel} enabled`)
  submit?.click()
  await waitFor(() => headingText(root) === nextHeading, `advanced to ${nextHeading}`)
}

export async function runGettingStartedBehaviorHarness(): Promise<readonly string[]> {
  const passed: string[] = []
  const host = document.createElement('div')
  document.body.appendChild(host)

  resetHarness()
  let app = mountGettingStartedPage(host)
  await waitFor(() => headingText(host) === '客户主体', 'first step is 客户主体')
  expectTrue(textOf(host).includes('在页面上怎么做'), 'how-to copy visible')
  expectTrue(textOf(host).includes('常见错误如何处理'), 'error copy visible')
  expectTrue(textOf(host).includes('下一步去哪里'), 'next copy visible')
  await createCurrentStep(host, '主体甲', 'subject-a', '创建客户主体', '接入应用')
  expectTrue(textOf(host).includes('所属客户主体：主体甲'), 'application inherits subject id/name')
  await createCurrentStep(host, '应用甲', 'app-a', '创建接入应用', '应用环境')
  expectTrue(textOf(host).includes('所属接入应用：应用甲'), 'environment inherits application')
  await fillCurrentForm(host, '环境甲', 'env-a')
  buttonByText(host, '创建应用环境')?.click()
  await waitFor(() => textOf(host).includes('基础设置已完成'), 'complete after environment save')
  expectTrue(wizardHttpSavePostCount() === 3, 'three creates for three steps')
  passed.push('save then re-read advances with parent id cascade')

  expectTrue(
    buttonByText(host, '前往让员工登录并分配权限') === null,
    'next path hidden until selected'
  )
  const peopleBox = Array.from(host.querySelectorAll('.el-checkbox')).find((item) =>
    textOf(item).includes('让员工登录并分配权限')
  )
  expectTrue(peopleBox instanceof HTMLElement, 'people goal checkbox')
  if (peopleBox instanceof HTMLElement) peopleBox.click()
  await waitFor(
    () => buttonByText(host, '前往让员工登录并分配权限') !== null,
    'selected goal exposes next path'
  )
  buttonByText(host, '前往让员工登录并分配权限')?.click()
  await waitFor(
    () => wizardHarnessWindow().__wizardRoute === '/sand-iam/people-access',
    'complete next path router.push'
  )
  passed.push('complete page only shows selected next path')

  app.unmount()
  resetHarness()
  app = mountGettingStartedPage(host)
  await waitFor(() => headingText(host) === '客户主体', 'fresh wizard after unmount')
  await createCurrentStep(host, '主体乙', 'subject-b', '创建客户主体', '接入应用')
  const postsAfterCreate = wizardHttpSavePostCount()
  buttonByText(host, '上一步')?.click()
  await waitFor(() => headingText(host) === '客户主体', 'back to subject')
  expectTrue(buttonByText(host, '创建客户主体') === null, 'create hidden after confirmed record')
  expectTrue(buttonByText(host, '保存修改') !== null, 'edit save available on back')
  await fillCurrentForm(host, '主体乙已改', 'subject-b')
  buttonByText(host, '保存修改')?.click()
  await waitFor(() => headingText(host) === '接入应用', 'return to application after edit')
  expectTrue(textOf(host).includes('所属客户主体：主体乙已改'), 'edited name cascaded')
  expectTrue(wizardHttpSavePostCount() === postsAfterCreate, 'edit uses update not a second create')
  passed.push('back-to-edit updates without duplicate create')

  app.unmount()
  app = mountGettingStartedPage(host)
  await waitFor(() => headingText(host) === '接入应用', 'refresh restores next incomplete step')
  expectTrue(textOf(host).includes('所属客户主体：主体乙已改'), 'refresh restored exact subject')
  passed.push('refresh restores exact saved records')

  app.unmount()
  resetHarness({ http: { saveMode: 'conflict' } })
  app = mountGettingStartedPage(host)
  await fillCurrentForm(host, '主体丙', 'subject-c')
  buttonByText(host, '创建客户主体')?.click()
  await waitFor(
    () => visibleAlert(host).includes('名称或系统代码已被使用'),
    'conflict recovery copy'
  )
  expectTrue(headingText(host) === '客户主体', 'conflict stays on current step')
  passed.push('conflict error stays recoverable without fake success')

  app.unmount()
  resetHarness({ http: { saveMode: 'network' } })
  app = mountGettingStartedPage(host)
  await fillCurrentForm(host, '主体丁', 'subject-d')
  buttonByText(host, '创建客户主体')?.click()
  await waitFor(() => visibleAlert(host).includes('暂时无法保存'), 'network save recovery copy')
  passed.push('network save error is visible and recoverable')

  app.unmount()
  resetHarness({ http: { readFailures: 1 } })
  app = mountGettingStartedPage(host)
  await fillCurrentForm(host, '主体戊', 'subject-e')
  buttonByText(host, '创建客户主体')?.click()
  await waitFor(() => buttonByText(host, '重试确认') !== null, 'retry confirm after read failure')
  buttonByText(host, '重试确认')?.click()
  await waitFor(() => headingText(host) === '接入应用', 'retry confirm advances')
  expectTrue(wizardHttpSavePostCount() === 1, 'read retry does not create a second record')
  passed.push('read failure retries confirmation without duplicate create')

  app.unmount()
  resetHarness({ http: { listMode: 'empty' } })
  app = mountGettingStartedPage(host)
  buttonByText(host, '选择已有记录')?.click()
  await waitFor(
    () => textOf(host).includes('当前没有可选择的已有记录'),
    'empty candidate list copy'
  )
  passed.push('empty candidate list is explicit')

  app.unmount()
  resetHarness({
    denied: ['sand_iam:organization:save', 'sand_iam:organization:update']
  })
  app = mountGettingStartedPage(host)
  await waitFor(
    () => textOf(host).includes('当前账号没有保存这一步的权限'),
    'permission empty for writes'
  )
  expectTrue(buttonByText(host, '选择已有记录') !== null, 'select existing remains for readers')
  expectTrue(buttonByText(host, '创建客户主体') === null, 'create hidden without save permission')
  passed.push('permission empty vs select existing')

  app.unmount()
  host.remove()
  return passed
}
