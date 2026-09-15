const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const os = require('node:os')
const { pathToFileURL } = require('node:url')
const { createRequire } = require('node:module')
const { execFileSync } = require('node:child_process')
const req = createRequire(path.join(process.env.SAND_IAM_FRONTEND_ROOT, 'package.json'))
const ts = req('typescript')
const sdk = path.resolve(__dirname, '../../../../../../sdk')
const source = fs.readFileSync(path.join(__dirname, 'index.vue'), 'utf8')
function example(label) {
  const section = source.slice(source.indexOf(`>${label}</h3>`))
  const match = section.match(/<pre[^>]*>([\s\S]*?)<\/pre/)
  assert.ok(match, label)
  return match[1].trim()
}
const decision = allowed => ({
  allowed, code: allowed ? 'allowed' : 'SAND_IAM_POLICY_DENIED',
  policy_ids: [], scope: {}, application_id: 3, identity_id: 101,
  api_code: 'order.detail', api_version: 'v1', resource_code: 'order',
  action: 'order.read', operation: 'read', risk_level: 'low'
})
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'sand-iam-sdk-examples-'))
async function run() {
  // Transpile the current SDK sources; never substitute a fake SandIamClient.
  fs.writeFileSync(path.join(directory, 'package.json'), '{"type":"module"}')
  for (const name of fs.readdirSync(path.join(sdk, 'typescript/src')).filter(name => name.endsWith('.ts'))) {
    fs.writeFileSync(path.join(directory, name.replace(/\.ts$/, '.js')),
      ts.transpileModule(fs.readFileSync(path.join(sdk, 'typescript/src', name), 'utf8'),
        { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } }).outputText)
  }
  const { SandIamClient } = await import(pathToFileURL(path.join(directory, 'index.js')))
  const snippet = example('TypeScript SDK').replace('applicationCode: "customer-service",', 'applicationCode: "customer-service", fetch: transport,')
  const execute = new (Object.getPrototypeOf(async function () {}).constructor)('SandIamClient', 'session', 'transport', snippet + '\nreturn decision')
  for (const allowed of [true, false]) {
    let calls = 0
    const pending = execute(SandIamClient, { accessToken: 'offline-token' }, async (url, init) => {
      calls++
      assert.ok(url.endsWith('/api/sand-iam/v1/authorization/decide'))
      const body = JSON.parse(init.body)
      assert.equal(body.application_code, 'customer-service')
      assert.equal(body.api_code, 'order.detail')
      assert.equal(body.attributes.organization_id, 42)
      return new Response(JSON.stringify({ code: 200, data: decision(allowed) }), { status: 200 })
    })
    if (allowed) assert.equal((await pending).allowed, true)
    else await assert.rejects(pending)
    assert.equal(calls, 1)
  }
  const php = example('PHP SDK').replace("applicationCode: 'customer-service',", "applicationCode: 'customer-service', transport: $transport,")
  const phpFile = path.join(directory, 'example.php')
  fs.writeFileSync(phpFile, `<?php
require ${JSON.stringify(path.join(sdk, 'php/src/SandIamException.php'))};
require ${JSON.stringify(path.join(sdk, 'php/src/SandIamClient.php'))};
$accessToken = 'offline-token';
foreach ([true, false] as $allowed) {
  $transport = static function (array $request) use ($allowed): array {
    $body = json_decode($request['body'], true);
    if ($body['api_code'] !== 'order.detail' || $body['application_code'] !== 'customer-service' || $body['attributes']['organization_id'] !== 42) throw new LogicException('payload mismatch');
    $decision = json_decode('${JSON.stringify(decision(true))}', true);
    $decision['allowed'] = $allowed;
    return ['status' => 200, 'body' => json_encode(['code' => 200, 'data' => $decision])];
  };
  $continued = false;
  try {
${php}
    $continued = true;
  } catch (RuntimeException $error) { if ($allowed || $error->getMessage() !== '访问被拒绝') throw $error; }
  if ($continued !== $allowed) throw new LogicException('deny continued');
}
`)
  execFileSync('php', [phpFile], { stdio: 'pipe' })
  const dart = process.env.SAND_IAM_DART
  assert.ok(dart, 'Set SAND_IAM_DART to the existing Dart executable; no dependency install is performed')
  const dartSnippet = example('Dart / Flutter SDK').replace("applicationCode: 'customer-service',", "applicationCode: 'customer-service', transport: transport,")
  const dartFile = path.join(directory, 'example.dart')
  fs.writeFileSync(dartFile, `import 'dart:convert';
import '${pathToFileURL(path.join(sdk, 'dart/lib/sand_iam.dart'))}';
Future<void> main() async {
  final session = (accessToken: 'offline-token');
  for (final allowed in [true, false]) {
    Future<SandIamHttpResponse> transport(SandIamHttpRequest request) async {
      final body = jsonDecode(request.body!) as Map<String, dynamic>;
      if (body['api_code'] != 'order.detail' || body['application_code'] != 'customer-service' || body['attributes']['organization_id'] != 42) throw StateError('payload mismatch');
      final data = jsonDecode('${JSON.stringify(decision(true))}') as Map<String, dynamic>;
      data['allowed'] = allowed;
      return SandIamHttpResponse(status: 200, body: jsonEncode({'code': 200, 'data': data}));
    }
    var continued = false;
    try {
${dartSnippet}
      if (!decision.allowed) throw StateError('deny returned');
      continued = true;
    } on SandIamDeniedException { if (allowed) rethrow; }
    if (continued != allowed) throw StateError('deny continued');
  }
}
`)
  execFileSync(dart, [`--packages=${path.join(sdk, 'dart/.dart_tool/package_config.json')}`, dartFile], { stdio: 'pipe' })
  console.log('Developer page PHP/TypeScript/Dart examples PASS (real SDKs, offline transports)')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
  .finally(() => fs.rmSync(directory, { recursive: true, force: true }))
