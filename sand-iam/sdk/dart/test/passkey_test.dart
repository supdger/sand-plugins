import 'dart:convert';
import 'package:sand_iam/sand_iam.dart';
import 'package:test/test.dart';

const attestation = <String, Object?>{'clientDataJSON': 'client', 'attestationObject': 'attestation'};
const assertion = <String, Object?>{'clientDataJSON': 'client', 'authenticatorData': 'auth', 'signature': 'sig', 'userHandle': 'user'};

Future<Object?> invoke(SandIamClient client, int operation) async {
  switch (operation) {
    case 0:
      return client.passkeyRegistrationOptions(name: 'Laptop', currentPassword: 'password', requestId: 'passkey-request');
    case 1:
      await client.passkeyRegistrationFinish(challengeToken: 'challenge', rawId: 'raw', response: attestation, requestId: 'passkey-request');
      return null;
    case 2:
      return client.passkeyAuthenticationOptions(requestId: 'passkey-request');
    default:
      return client.passkeyAuthenticationFinish(challengeToken: 'challenge', rawId: 'raw', response: assertion, userAgent: 'platform', requestId: 'passkey-request');
  }
}

void main() {
  const paths = ['registration/options', 'registration/finish', 'authentication/options', 'authentication/finish'];
  final bodies = <SandIamJson>[
    {'name': 'Laptop', 'current_password': 'password'},
    {'challenge_token': 'challenge', 'rawId': 'raw', 'response': attestation},
    {'organization_code': 'org', 'application_code': 'app'},
    {'organization_code': 'org', 'application_code': 'app', 'challenge_token': 'challenge', 'rawId': 'raw', 'response': assertion, 'user_agent': 'platform'},
  ];
  for (var operation = 0; operation < 4; operation++) {
    test('Passkey ${paths[operation]} exact transport and result', () async {
      var requests = 0;
      var tokenReads = 0;
      final client = SandIamClient(baseUrl: 'https://iam.example.test',
        organizationCode: 'org', applicationCode: 'app',
        accessToken: () { tokenReads++; return 'existing-session'; },
        transport: (request) async {
          requests++;
          expect(request.uri.path, '/api/sand-iam/v1/auth/passkeys/${paths[operation]}');
          expect(request.method, 'POST');
          expect(request.headers['X-Request-Id'], 'passkey-request');
          expect(request.headers['Authorization'], operation < 2 ? 'Bearer existing-session' : isNull);
          expect(jsonDecode(request.body!), bodies[operation]);
          final Object data = operation == 1 ? 'registered' : operation == 3
            ? {'access_token': 'session', 'session_id': 42}
            : {'challenge_token': 'challenge', 'public_key': {'challenge': 'encoded'}};
          return SandIamHttpResponse(status: 200, body: jsonEncode({'code': 200, 'data': data}));
        });
      final result = await invoke(client, operation);
      if (result is SandIamPasskeyOptions) {
        expect(result.challengeToken, 'challenge');
        expect(result.publicKey, {'challenge': 'encoded'});
      } else if (result is SandIamAuthResult) {
        expect(result.accessToken, 'session');
        expect(result.sessionId, 42);
      } else {
        expect(operation, 1);
        expect(result, isNull);
      }
      expect(requests, 1);
      expect(tokenReads, operation < 2 ? 1 : 0);
    });
    test('Passkey ${paths[operation]} propagates error without retry', () async {
      var requests = 0;
      final client = SandIamClient(baseUrl: 'https://iam.example.test',
        organizationCode: 'org', applicationCode: 'app', accessToken: () => 'session',
        transport: (_) async {
          requests++;
          return const SandIamHttpResponse(status: 400, body: '{"msg":"SAND_IAM_PASSKEY_INVALID"}');
        });
      await expectLater(invoke(client, operation), throwsA(
        isA<SandIamException>().having((error) => error.status, 'status', 400)));
      expect(requests, 1);
    });
  }
  test('missing session and invalid proofs never send', () async {
    var requests = 0;
    final client = SandIamClient(baseUrl: 'https://iam.example.test',
      organizationCode: 'org', applicationCode: 'app', accessToken: () => '',
      transport: (_) async { requests++; throw StateError('invalid request sent'); });
    await expectLater(client.passkeyRegistrationOptions(currentPassword: ''), throwsA(isA<SandIamException>()));
    await expectLater(invoke(client, 0), throwsA(isA<SandIamException>()));
    await expectLater(invoke(client, 1), throwsA(isA<SandIamException>()));
    await expectLater(client.passkeyRegistrationFinish(challengeToken: '', rawId: 'r', response: attestation), throwsA(isA<SandIamException>()));
    await expectLater(client.passkeyRegistrationFinish(challengeToken: 'c', rawId: 'r', response: {'clientDataJSON': 'c'}), throwsA(isA<SandIamException>()));
    await expectLater(client.passkeyAuthenticationFinish(challengeToken: 'c', rawId: 'r', response: {...assertion, 'userHandle': ''}), throwsA(isA<SandIamException>()));
    expect(requests, 0);
  });
  test('options reject malformed response objects', () async {
    for (final data in <Object?>[null, <Object?>[], <String, Object?>{}, {'challenge_token': 'c', 'public_key': <Object?>[]}, {'challenge_token': ' ', 'public_key': <String, Object?>{}}]) {
      final client = SandIamClient(baseUrl: 'https://iam.example.test',
        organizationCode: 'org', applicationCode: 'app', accessToken: () => 'session',
        transport: (_) async => SandIamHttpResponse(status: 200, body: jsonEncode({'code': 200, 'data': data})));
      await expectLater(invoke(client, 0), throwsA(isA<SandIamException>()));
      await expectLater(invoke(client, 2), throwsA(isA<SandIamException>()));
    }
  });
}
