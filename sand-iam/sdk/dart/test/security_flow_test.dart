import 'dart:convert';
import 'package:sand_iam/sand_iam.dart';
import 'package:test/test.dart';

Future<Object?> invoke(SandIamClient client, int operation) async {
  switch (operation) {
    case 0: return client.captchaConfiguration('login', requestId: 'security-request');
    case 1: return client.stepUpPassword('password', requestId: 'security-request');
    case 2: return client.startMfaStepUp(requestId: 'security-request');
    default:
      await client.unlinkFederation(42, requestId: 'security-request');
      return null;
  }
}

void main() {
  test('invitation acceptance is anonymous and returns identity, not a session', () async {
    var calls = 0;
    var status = 200;
    Object? data = {'id': 42, 'display_name': '访客', 'access_token': 'must-not-return'};
    final client = SandIamClient(baseUrl: 'https://iam.example.test/prefix', organizationCode: 'example', applicationCode: 'business',
      accessToken: () => throw StateError('invitation read session'),
      transport: (request) async {
        calls++;
        expect(request.uri.toString(), 'https://iam.example.test/prefix/api/sand-iam/v1/invitations/accept');
        expect(request.method, 'POST');
        expect(request.headers['Authorization'], isNull);
        expect(request.headers['Cache-Control'], 'no-store');
        expect(request.headers['X-Request-Id'], 'invite-request');
        expect(jsonDecode(request.body!), {'token': 'invite-token', 'username': 'new-user', 'password': 'password', 'display_name': '访客'});
        return SandIamHttpResponse(status: status, body: jsonEncode({'data': data, 'msg': 'SAND_IAM_INVITATION_EXPIRED'}));
      });
    Future<SandIamInvitationIdentity> accept() => client.acceptInvitation(token: 'invite-token', username: 'new-user', password: 'password', displayName: '访客', requestId: 'invite-request');
    final result = await accept();
    expect(result.id, 42);
    expect(result.displayName, '访客');
    for (final invalid in <Object?>[null, <Object?>[], {'id': 0, 'display_name': 'x'}, {'id': '42', 'display_name': 'x'}, {'id': 42}]) {
      data = invalid;
      await expectLater(accept(), throwsA(isA<SandIamException>().having((e) => e.code, 'code', 'SAND_IAM_SDK_INVALID_RESPONSE')));
    }
    final before = calls;
    status = 410;
    await expectLater(accept(), throwsA(isA<SandIamException>().having((e) => e.code, 'code', 'SAND_IAM_INVITATION_EXPIRED')));
    for (final field in ['token', 'username', 'password']) {
      await expectLater(client.acceptInvitation(token: field == 'token' ? '' : 'invite-token', username: field == 'username' ? '' : 'new-user', password: field == 'password' ? '' : 'password'),
        throwsA(isA<SandIamException>().having((e) => e.code, 'code', 'SAND_IAM_SDK_INVALID_ARGUMENT')));
    }
    expect(calls, before + 1);
  });
  test('MFA step-up challenge completion upgrades without a login token', () async {
    var calls = 0;
    final client = SandIamClient(baseUrl: 'https://iam.example.test', organizationCode: 'org', applicationCode: 'app',
      accessToken: () => 'session', transport: (request) async {
        calls++;
        final starting = request.uri.path.endsWith('/step-up/mfa/start');
        expect(request.headers['Authorization'], starting ? 'Bearer session' : isNull);
        if (!starting) {
          expect(request.uri.path, '/api/sand-iam/v1/auth/mfa/challenge/verify');
          expect(jsonDecode(request.body!), {
            'organization_code': 'org', 'application_code': 'app', 'challenge_token': 'challenge',
            'method': 'totp', 'code': '123456', 'user_agent': '',
          });
        }
        return SandIamHttpResponse(status: 200, body: jsonEncode({'data': starting
          ? {'mfa_required': true, 'challenge_token': 'challenge', 'methods': ['totp'], 'expires_in': 300}
          : {'step_up': true, 'expires_in': 300}}));
      });
    final challenge = await client.startMfaStepUp(requestId: 'security-request');
    final result = await client.verifyMfaChallenge(challengeToken: challenge.challengeToken!,
      method: 'totp', code: '123456', requestId: 'verify-request');
    expect(result.stepUp, true);
    expect(result.expiresIn, 300);
    expect(result.accessToken, isNull);
    expect(calls, 2);
  });
  const widget = <String, Object?>{'kind': 'turnstile', 'site_key': 'public-key', 'action': 'login', 'application_binding': 'binding'};
  test('captcha branches and actions use anonymous fixed GET query', () async {
    for (final action in ['login', 'register']) {
      for (final data in <SandIamJson>[
        {'required': false}, {'required': true, 'available': false},
        {'required': true, 'available': true, 'widget': {...widget, 'action': action}},
      ]) {
        var count = 0;
        final client = SandIamClient(baseUrl: 'https://iam.example.test',
          organizationCode: 'org-one', applicationCode: 'app-two',
          accessToken: () => throw StateError('captcha read session'),
          transport: (request) async {
            count++;
            expect(request.uri.path, '/api/sand-iam/v1/auth/captcha/config');
            expect(request.uri.queryParameters, {'organization_code': 'org-one', 'application_code': 'app-two', 'action': action});
            expect(request.method, 'GET');
            expect(request.body, isNull);
            expect(request.headers['Authorization'], isNull);
            expect(request.headers['X-Request-Id'], 'security-request');
            expect(request.headers['Cache-Control'], 'no-store');
            return SandIamHttpResponse(status: 200, body: jsonEncode({'data': data}));
          });
        final result = await client.captchaConfiguration(action, requestId: 'security-request');
        expect(result.required, data['required']);
        expect(result.available, data['available']);
        if (result.available == true) {
          expect(result.widget?.kind, 'turnstile');
          expect(result.widget?.action, action);
          expect(result.widget?.siteKey, 'public-key');
          expect(result.widget?.applicationBinding, 'binding');
        } else { expect(result.widget, isNull); }
        expect(count, 1);
      }
    }
  });
  const paths = ['/auth/captcha/config', '/auth/step-up/password', '/auth/step-up/mfa/start', '/auth/federation/unlink'];
  final bodies = <SandIamJson>[{}, {'password': 'password'}, {}, {'binding_id': 42}];
  final replies = <Object>[
    {'required': false}, {'step_up': true, 'expires_in': 300},
    {'mfa_required': true, 'challenge_token': 'challenge', 'methods': ['totp', 'passkey'], 'expires_in': 300, 'public_key': {'challenge': 'encoded'}},
    'unlinked',
  ];
  for (var operation = 1; operation < 4; operation++) {
    test('session operation ${paths[operation]} uses exact fields and result', () async {
      var count = 0;
      final client = SandIamClient(baseUrl: 'https://iam.example.test', organizationCode: 'org', applicationCode: 'app',
        accessToken: () => 'session', transport: (request) async {
          count++;
          expect(request.uri.path, '/api/sand-iam/v1${paths[operation]}');
          expect(request.uri.query, isEmpty);
          expect(request.method, 'POST');
          expect(request.headers['Authorization'], 'Bearer session');
          expect(request.headers['X-Request-Id'], 'security-request');
          expect(jsonDecode(request.body!), bodies[operation]);
          return SandIamHttpResponse(status: 200, body: jsonEncode({'data': replies[operation]}));
        });
      final result = await invoke(client, operation);
      if (result is SandIamAuthResult) {
        expect(result.accessToken, isNull);
        expect(result.expiresIn, 300);
        if (operation == 1) { expect(result.stepUp, true); }
        else {
          expect(result.mfaRequired, true);
          expect(result.challengeToken, 'challenge');
          expect(result.methods, ['totp', 'passkey']);
          expect(result.publicKey, {'challenge': 'encoded'});
        }
      } else { expect(operation, 3); expect(result, isNull); }
      expect(count, 1);
      final missing = SandIamClient(baseUrl: 'https://iam.example.test', organizationCode: 'org', applicationCode: 'app',
        accessToken: () => '', transport: (_) async => throw StateError('missing session request'));
      await expectLater(invoke(missing, operation), throwsA(isA<SandIamException>().having((e) => e.status, 'status', 401)));
    });
  }
  test('service errors do not trigger upgrade, login or retries', () async {
    for (var operation = 0; operation < 4; operation++) {
      var count = 0;
      final client = SandIamClient(baseUrl: 'https://iam.example.test', organizationCode: 'org', applicationCode: 'app',
        accessToken: () => 'session', transport: (_) async {
          count++;
          return const SandIamHttpResponse(status: 403, body: '{"msg":"SAND_IAM_STEP_UP_REQUIRED"}');
        });
      await expectLater(invoke(client, operation), throwsA(isA<SandIamException>().having((e) => e.status, 'status', 403)));
      expect(count, 1);
    }
  });
  test('invalid inputs do not send', () async {
    final client = SandIamClient(baseUrl: 'https://iam.example.test', organizationCode: 'org', applicationCode: 'app',
      accessToken: () => 'session', transport: (_) async => throw StateError('invalid input sent'));
    for (final call in <Future<Object?> Function()>[
      () => client.captchaConfiguration('reset'), () => client.stepUpPassword(''),
      () async { await client.unlinkFederation(0); return null; },
      () async { await client.unlinkFederation(-1); return null; },
    ]) {
      await expectLater(call(), throwsA(isA<SandIamException>().having((e) => e.code, 'code', 'SAND_IAM_SDK_INVALID_ARGUMENT')));
    }
  });
  test('malformed captcha and upgrade results reject', () async {
    for (final data in <Object?>[null, <Object?>[], <String, Object?>{}, {'required': 'false'}, {'required': true},
      {'required': true, 'available': true},
      for (final change in <SandIamJson>[{'action': 'register'}, {'kind': 'other'}, {'site_key': List.filled(256, 'x').join()}, {'application_binding': 'x y'}])
        {'required': true, 'available': true, 'widget': {...widget, ...change}},
    ]) {
      final client = SandIamClient(baseUrl: 'https://iam.example.test', organizationCode: 'org', applicationCode: 'app',
        accessToken: () => 'session', transport: (_) async => SandIamHttpResponse(status: 200, body: jsonEncode({'data': data})));
      await expectLater(invoke(client, 0), throwsA(isA<SandIamException>().having((e) => e.code, 'code', 'SAND_IAM_SDK_INVALID_RESPONSE')));
    }
    final client = SandIamClient(baseUrl: 'https://iam.example.test', organizationCode: 'org', applicationCode: 'app',
      accessToken: () => 'session', transport: (_) async => const SandIamHttpResponse(status: 200, body: '{"data":{}}'));
    for (final operation in [1, 2]) {
      await expectLater(invoke(client, operation), throwsA(isA<SandIamException>().having((e) => e.code, 'code', 'SAND_IAM_SDK_INVALID_RESPONSE')));
    }
  });
}
