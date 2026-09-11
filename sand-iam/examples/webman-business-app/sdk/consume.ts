import { SandIamClient } from '../../../sdk/typescript/src/index.js'
import { sandIam } from '../generated/sand_iam.js'

const credential = (globalThis as typeof globalThis & { process?: { env?: Record<string, string | undefined> } }).process?.env?.SAND_IAM_ACCESS_TOKEN ?? ''
if (credential === '') throw new Error('SAND_IAM_ACCESS_TOKEN 必须由服务端运行环境注入')

async function main(): Promise<void> {
  const client = new SandIamClient({
    baseUrl: (globalThis as typeof globalThis & { process?: { env?: Record<string, string | undefined> } }).process?.env?.SAND_IAM_BASE_URL ?? '',
    organizationCode: sandIam.organizationCode,
    applicationCode: sandIam.applicationCode,
    accessToken: () => credential,
  })
  await client.authorize({ apiCode: sandIam.actions.MATTER_READ, requestId: 'matter-ts-read-001' })
}

void main()
