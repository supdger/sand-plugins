import 'dart:convert';
import 'package:sand_iam/sand_iam.dart';
import 'package:test/test.dart';

void main() {
  test('explicit secret-free replay retains status without inventing secrets', () async {
    final results = [
      {'factor_id': 4, 'secret_available': false},
      {'enabled': true, 'secret_available': false},
      {'secret_available': false},
    ];
    var index = 0;
    final client = SandIamClient(baseUrl: 'https://iam.example.test', organizationCode: 'sand',
      applicationCode: 'app', accessToken: () => 'session', transport: (_) async =>
        SandIamHttpResponse(status: 200, body: jsonEncode({'code':200,'data':results[index++]})));
    final setup = await client.startTotp(currentPassword: 'password');
    expect(setup.factorId, 4); expect(setup.secretAvailable, false); expect(setup.secret, isNull);
    final confirmed = await client.confirmTotp(factorId: 4, code: '123456');
    expect(confirmed.enabled, true); expect(confirmed.secretAvailable, false); expect(confirmed.recoveryCodes, isNull);
    final codes = await client.regenerateRecoveryCodes(password: 'password');
    expect(codes.secretAvailable, false); expect(codes.recoveryCodes, isNull);
  });
  test('six MFA management calls retain typed results and require session auth', () async {
    final bodies = <Object?>[
      null, {'name': 'Phone', 'current_password': 'password'},
      {'factor_id': 4, 'code': '123456'}, {'factor_id': 4, 'type': 'passkey', 'name': 'New'},
      {'factor_id': 4, 'type': 'totp', 'password': 'password'}, {'password': 'password'},
    ];
    final paths = ['/factors','/totp/start','/totp/confirm','/factors/rename','/factors/revoke','/recovery/regenerate'];
    final responses = <Object?>[
      [{'id': 4, 'type': 'totp', 'name': 'Phone', 'status': 1, 'create_time': '2026-09-14', 'last_used_time': null}],
      {'factor_id': 4, 'secret': 'BASE32', 'otpauth_uri': 'otpauth://totp/test'},
      {'enabled': true, 'recovery_codes': ['code-1']}, 'renamed', 'revoked', {'recovery_codes': ['code-2']},
    ];
    var calls = 0;
    final client = SandIamClient(baseUrl: 'https://iam.example.test', organizationCode: 'sand',
      applicationCode: 'app', accessToken: () => 'session', transport: (request) async {
        final index = calls++;
        expect(request.uri.path, '/api/sand-iam/v1/auth/mfa${paths[index]}');
        expect(request.method, index == 0 ? 'GET' : 'POST');
        expect(request.headers['Authorization'], 'Bearer session');
        expect(request.headers['X-Request-Id'], 'mfa-management');
        expect(request.body == null ? null : jsonDecode(request.body!), bodies[index]);
        return SandIamHttpResponse(status: 200, body: jsonEncode({'code':200,'data':responses[index]}));
      });
    final factors = await client.mfaFactors(requestId: 'mfa-management');
    expect(factors.single.id, 4); expect(factors.single.type, 'totp'); expect(factors.single.lastUsedTime, isNull);
    final setup = await client.startTotp(name: 'Phone', currentPassword: 'password', requestId: 'mfa-management');
    expect(setup.factorId, 4); expect(setup.secret, 'BASE32'); expect(setup.otpauthUri, 'otpauth://totp/test');
    final confirmation = await client.confirmTotp(factorId: 4, code: '123456', requestId: 'mfa-management');
    expect(confirmation.enabled, true); expect(confirmation.recoveryCodes, ['code-1']);
    await client.renameMfaFactor(factorId: 4, type: 'passkey', name: 'New', requestId: 'mfa-management');
    await client.revokeMfaFactor(factorId: 4, type: 'totp', password: 'password', requestId: 'mfa-management');
    expect((await client.regenerateRecoveryCodes(password: 'password', requestId: 'mfa-management')).recoveryCodes, ['code-2']);
    expect(calls, 6);
  });
  test('MFA invalid arguments, missing session, service errors and malformed results reject', () async {
    var calls = 0;
    var token = '';
    var status = 400;
    final client = SandIamClient(baseUrl: 'https://iam.example.test', organizationCode: 'sand',
      applicationCode: 'app', accessToken: () => token, transport: (_) async {
        calls++;
        return SandIamHttpResponse(status: status, body: '{"code":200,"data":{},"msg":"SAND_IAM_MFA_FACTOR_NOT_FOUND"}');
      });
    final operations = <Future<Object?> Function()>[
      () => client.mfaFactors(), () => client.startTotp(currentPassword: 'p'),
      () => client.confirmTotp(factorId: 4, code: '123456'),
      () => client.renameMfaFactor(factorId: 4, type: 'totp', name: 'Name'),
      () => client.revokeMfaFactor(factorId: 4, type: 'passkey', password: 'p'),
      () => client.regenerateRecoveryCodes(password: 'p'),
    ];
    for (final operation in operations) {
      await expectLater(operation(), throwsA(isA<SandIamException>()));
    }
    expect(calls, 0);
    token = 'session';
    await expectLater(client.revokeMfaFactor(factorId: 0, type: 'totp', password: 'p'), throwsA(isA<SandIamException>()));
    await expectLater(client.revokeMfaFactor(factorId: 4, type: 'other', password: 'p'), throwsA(isA<SandIamException>()));
    expect(calls, 0);
    await expectLater(client.confirmTotp(factorId: 4, code: 'bad'), throwsA(isA<SandIamException>()));
    expect(calls, 1);
    status = 200;
    for (final index in [0, 1, 2, 5]) {
      await expectLater(operations[index](), throwsA(isA<SandIamException>()));
    }
  });
}
