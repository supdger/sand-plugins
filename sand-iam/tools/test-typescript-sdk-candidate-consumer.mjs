#!/usr/bin/env node

/**
 * Final-package consumer behavior test.
 *
 * It intentionally imports only the SDK extracted from a candidate ZIP and
 * uses Node's native fetch against a real loopback HTTP server. No source-tree
 * import, injected transport, database, host, or service process is involved.
 *
 * Usage:
 *   node sand-iam/tools/test-typescript-sdk-candidate-consumer.mjs /absolute/candidate.zip
 */

import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { mkdtempSync, rmSync } from 'node:fs'
import { createServer } from 'node:http'
import { tmpdir } from 'node:os'
import { basename, join, resolve } from 'node:path'
import { pathToFileURL } from 'node:url'

const archive = process.argv[2]
if (typeof archive !== 'string' || archive === '') {
  throw new Error('usage: test-typescript-sdk-candidate-consumer.mjs /absolute/candidate.zip')
}

const temporaryRoot = mkdtempSync(join(tmpdir(), 'sand-iam-sdk-consumer-'))
const extracted = join(temporaryRoot, 'package')
const requests = []
let mutations = 0
let server

function writeJson(response, status, payload) {
  response.writeHead(status, { 'content-type': 'application/json' })
  response.end(JSON.stringify(payload))
}

function readBody(request) {
  return new Promise((resolveBody, reject) => {
    let body = ''
    request.setEncoding('utf8')
    request.on('data', (chunk) => { body += chunk })
    request.on('end', () => {
      try {
        resolveBody(body === '' ? undefined : JSON.parse(body))
      } catch (error) {
        reject(error)
      }
    })
    request.on('error', reject)
  })
}

function listenLoopback() {
  return new Promise((resolveAddress, reject) => {
    server.listen(0, '127.0.0.1', () => {
      const address = server.address()
      if (address === null || typeof address === 'string') {
        reject(new Error('loopback server did not provide a TCP address'))
        return
      }
      resolveAddress(`http://127.0.0.1:${address.port}`)
    })
    server.once('error', reject)
  })
}

function closeServer() {
  return new Promise((resolveClose, reject) => {
    server.close((error) => (error === undefined ? resolveClose() : reject(error)))
  })
}

try {
  const absoluteArchive = resolve(archive)
  execFileSync('unzip', ['-q', absoluteArchive, '-d', extracted], { stdio: 'pipe' })

  server = createServer(async (request, response) => {
    try {
      const body = await readBody(request)
      const record = {
        method: request.method,
        path: request.url,
        authorization: request.headers.authorization,
        requestId: request.headers['x-request-id'],
        body,
      }
      requests.push(record)

      if (request.method === 'POST' && request.url === '/api/sand-iam/v1/authorization/decide') {
        writeJson(response, 200, { data: { allowed: true, code: 'SAND_IAM_ALLOWED', policy_ids: [11], scope: { organization_code: 'consumer-org' }, application_id: 12, identity_id: 13, api_code: 'matter.write', api_version: 'v3', resource_code: 'matter', action: 'write', operation: 'create', risk_level: 'medium' } })
        return
      }
      if (request.method === 'POST' && request.url === '/app/sand-iam/admin/developer/onboarding/apply') {
        mutations += 1
        writeJson(response, 200, { data: { applied: true, operation_id: body?.manifest?.operation_id } })
        return
      }
      if (request.method === 'POST' && request.url === '/app/sand-iam/admin/credential/revoke') {
        writeJson(response, 403, { msg: 'SAND_IAM_POLICY_DENIED credential revoke denied' })
        return
      }
      if (request.method === 'GET' && request.url === '/api/sand-iam/v1/me/profile') {
        writeJson(response, 401, { msg: 'SAND_IAM_AUTHENTICATION_FAILED expired test credential' })
        return
      }
      writeJson(response, 404, { msg: 'SAND_IAM_ROUTE_NOT_FOUND unexpected consumer request' })
    } catch (error) {
      writeJson(response, 500, { msg: `loopback test server failure: ${error instanceof Error ? error.message : String(error)}` })
    }
  })
  const baseUrl = await listenLoopback()

  const sdk = await import(pathToFileURL(join(extracted, 'sdk/typescript/dist/index.js')).href)
  assert.equal(typeof sdk.SandIamClient, 'function', 'candidate SDK root export must provide SandIamClient')
  assert.equal(typeof sdk.SandIamManagementClient, 'function', 'candidate SDK root export must provide SandIamManagementClient')

  const client = new sdk.SandIamClient({ baseUrl, organizationCode: 'consumer-org', applicationCode: 'consumer-app', accessToken: () => 'consumer-token' })
  const decision = await client.authorize({ apiCode: 'matter.write', apiVersion: 'v3', attributes: { matter_id: 'matter-001' }, requestId: 'consumer-decide-0001' })
  assert.equal(decision.allowed, true)

  const management = new sdk.SandIamManagementClient({ baseUrl, administratorToken: () => 'administrator-token' })
  const applied = await management.onboardingApply({ manifest: { operation_id: 'consumer-operation-0001', application_code: 'consumer-app' }, previewHash: 'consumer-preview-0001', requestId: 'consumer-apply-0001' })
  assert.deepEqual(applied, { applied: true, operation_id: 'consumer-operation-0001' })
  assert.equal(mutations, 1, 'allow response must produce exactly one isolated test mutation')

  await assert.rejects(() => management.credentialRevoke({ credentialId: 7, requestId: 'consumer-deny-0001' }), (error) => error instanceof sdk.SandIamManagementError && error.code === 'SAND_IAM_POLICY_DENIED' && error.status === 403, 'deny response must keep its stable error code and status')
  await assert.rejects(() => client.profile('consumer-profile-0001'), (error) => error instanceof sdk.SandIamError && error.code === 'SAND_IAM_AUTHENTICATION_FAILED' && error.status === 401, '401 response must keep its stable error code and status')
  assert.equal(mutations, 1, 'deny and 401 responses must not mutate loopback state')

  const decide = requests.find((item) => item.path === '/api/sand-iam/v1/authorization/decide')
  assert.deepEqual(decide, { method: 'POST', path: '/api/sand-iam/v1/authorization/decide', authorization: 'Bearer consumer-token', requestId: 'consumer-decide-0001', body: { organization_code: 'consumer-org', application_code: 'consumer-app', api_code: 'matter.write', api_version: 'v3', attributes: { matter_id: 'matter-001' } } }, 'candidate SDK must send the documented URL, Authorization, request ID, organization, application, and API body')

  const apply = requests.find((item) => item.path === '/app/sand-iam/admin/developer/onboarding/apply')
  assert.deepEqual(apply, { method: 'POST', path: '/app/sand-iam/admin/developer/onboarding/apply', authorization: 'Bearer administrator-token', requestId: 'consumer-apply-0001', body: { manifest: { operation_id: 'consumer-operation-0001', application_code: 'consumer-app' }, preview_hash: 'consumer-preview-0001', apply: true } }, 'candidate management SDK must send its documented mutation URL, Authorization, request ID, and body')

  console.log(`SandIAM TypeScript SDK candidate consumer test passed: ${basename(absoluteArchive)}`)
} finally {
  if (server !== undefined && server.listening) await closeServer()
  rmSync(temporaryRoot, { recursive: true, force: true })
}
