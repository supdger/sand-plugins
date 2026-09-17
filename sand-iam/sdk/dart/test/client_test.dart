import 'dart:convert';

import 'package:sand_iam/sand_iam.dart';
import 'package:test/test.dart';

void main() {
  group('SandIamClient', () {
    test('registration verification derives purpose and never authenticates or logs in', () async {
      for (final confirm in [false, true]) {
        for (final channel in ['email', 'phone']) {
          var calls = 0;
          final client = SandIamClient(
            baseUrl: 'https://iam.example.test', organizationCode: 'sand', applicationCode: 'app',
            accessToken: () => throw StateError('verification must not read session'),
            transport: (request) async {
              calls++;
              expect(request.uri.path, '/api/sand-iam/v1/auth/verification/${confirm ? 'confirm' : 'request'}');
              expect(request.method, 'POST');
              expect(request.headers['Authorization'], isNull);
              expect(request.headers['X-Request-Id'], 'verify-request');
              final body = jsonDecode(request.body!) as Map<String, Object?>;
              expect(body['organization_code'], 'sand');
              expect(body['application_code'], 'app');
              expect(body['identifier'], 'alice');
              expect(body['channel'], channel);
              expect(body['purpose'], channel == 'email' ? 'email_verify' : 'phone_verify');
              expect(body['code'], confirm ? '123456' : isNull);
              expect(body.containsKey('_password_reset_endpoint'), false);
              return const SandIamHttpResponse(status: 200, body: '{"code":200,"data":"generic success"}');
            },
          );
          if (confirm) {
            await client.confirmVerification(identifier: 'alice', channel: channel, code: '123456', requestId: 'verify-request');
          } else {
            await client.requestVerification(identifier: 'alice', channel: channel, requestId: 'verify-request');
          }
          expect(calls, 1);
        }
        var calls = 0;
        final client = SandIamClient(baseUrl: 'https://iam.example.test',
          organizationCode: 'sand', applicationCode: 'app', accessToken: () => '',
          transport: (_) async {
            calls++;
            return const SandIamHttpResponse(status: 400, body: '{"code":400,"msg":"SAND_IAM_AUTH_VERIFICATION_INVALID"}');
          });
        for (final channel in ['sms', 'email']) {
          await expectLater(confirm
              ? client.confirmVerification(identifier: 'alice', channel: channel, code: '123456')
              : client.requestVerification(identifier: 'alice', channel: channel),
              throwsA(isA<SandIamException>()));
        }
        expect(calls, 1);
      }
    });
    test('password recovery uses fixed anonymous scope, exact fields and no login', () async {
      for (final channel in ['email', 'phone']) {
        for (final reset in [false, true]) {
          var calls = 0;
          final client = SandIamClient(
            baseUrl: 'https://iam.example.test', organizationCode: 'sand', applicationCode: 'app',
            accessToken: () => throw StateError('Recovery must not read session'),
            transport: (request) async {
              calls++;
              expect(request.uri.path, '/api/sand-iam/v1/auth/password/${reset ? 'reset' : 'forgot'}');
              expect(request.method, 'POST');
              expect(request.headers['Authorization'], isNull);
              expect(request.headers['X-Request-Id'], 'recovery-request');
              final body = jsonDecode(request.body!) as Map<String, Object?>;
              expect(body['identifier'], 'alice');
              expect(body['channel'], channel);
              expect(body['organization_code'], 'sand');
              expect(body['application_code'], 'app');
              expect(body.containsKey('new_password'), false);
              expect(body['password'], reset ? 'new-password' : isNull);
              expect(body['code'], reset ? '123456' : isNull);
              return const SandIamHttpResponse(status: 200, body: '{"code":200,"data":"generic success"}');
            },
          );
          if (reset) {
            await client.resetPassword(identifier: 'alice', channel: channel,
              code: '123456', password: 'new-password', requestId: 'recovery-request');
          } else {
            await client.forgotPassword(identifier: 'alice', channel: channel, requestId: 'recovery-request');
          }
          expect(calls, 1);
        }
      }
    });
    test('password recovery rejects invalid channel and propagates service errors', () async {
      for (final reset in [false, true]) {
        var calls = 0;
        final client = SandIamClient(baseUrl: 'https://iam.example.test',
          organizationCode: 'sand', applicationCode: 'app', accessToken: () => '',
          transport: (_) async {
            calls++;
            return const SandIamHttpResponse(status: 400,
              body: '{"code":400,"msg":"SAND_IAM_AUTH_VERIFICATION_INVALID"}');
          });
        for (final channel in ['sms', 'email']) {
          final operation = reset
              ? client.resetPassword(identifier: 'alice', channel: channel, code: '123456', password: 'new-password')
              : client.forgotPassword(identifier: 'alice', channel: channel);
          await expectLater(operation, throwsA(isA<SandIamException>()));
        }
        expect(calls, 1);
      }
    });
    test('verifies three MFA methods without session auth and preserves step-up', () async {
      for (final method in ['totp', 'recovery_code', 'passkey']) {
        final response = <String, Object?>{
          'clientDataJSON': 'client', 'authenticatorData': 'auth',
          'signature': 'signature', 'userHandle': 'handle',
          'application_code': 'cannot-override-top-level',
        };
        final client = SandIamClient(
          baseUrl: 'https://iam.example.test', organizationCode: 'sand', applicationCode: 'app',
          accessToken: () => throw StateError('MFA must not read session token'),
          transport: (request) async {
            expect(request.method, 'POST');
            expect(request.uri.path, '/api/sand-iam/v1/auth/mfa/challenge/verify');
            expect(request.headers['Authorization'], isNull);
            expect(request.headers['X-Request-Id'], 'mfa-request');
            final body = jsonDecode(request.body!) as Map<String, Object?>;
            expect(body['organization_code'], 'sand');
            expect(body['application_code'], 'app');
            expect(body['challenge_token'], 'challenge');
            expect(body['method'], method);
            if (method == 'passkey') {
              expect(body['rawId'], 'credential');
              expect(body['response'], response);
              expect(body.containsKey('code'), false);
            } else {
              expect(body['code'], '123456');
              expect(body.containsKey('response'), false);
            }
            return SandIamHttpResponse(status: 200, body: jsonEncode({
              'code': 200, 'data': method == 'passkey'
                  ? {'step_up': true, 'expires_in': 300}
                  : {'access_token': 'session', 'session_id': 9}
            }));
          },
        );
        final result = await client.verifyMfaChallenge(
          challengeToken: 'challenge', method: method, code: '123456',
          rawId: 'credential', response: response, requestId: 'mfa-request',
        );
        expect(result.stepUp, method == 'passkey' ? true : isNull);
        expect(result.accessToken, method == 'passkey' ? isNull : 'session');
        if (method == 'passkey') expect(result.expiresIn, 300);
      }
    });
    test('propagates MFA verification errors', () async {
      final client = SandIamClient(
        baseUrl: 'https://iam.example.test', organizationCode: 'sand', applicationCode: 'app',
        accessToken: () => '',
        transport: (_) async => const SandIamHttpResponse(status: 401,
          body: '{"code":401,"msg":"SAND_IAM_MFA_CHALLENGE_INVALID"}'),
      );
      await expectLater(client.verifyMfaChallenge(challengeToken: 'bad',
        method: 'totp', code: '123456'), throwsA(isA<SandIamException>()));
    });
    test('preserves MFA challenge details and rejects malformed fields',
        () async {
      final challenge = <String, Object?>{
        'mfa_required': true,
        'challenge_token': 'challenge',
        'methods': ['totp', 'recovery_code', 'passkey'],
        'expires_in': 300,
        'public_key': {
          'challenge': 'encoded-challenge',
          'rpId': 'example.test',
          'allowCredentials': <Object?>[]
        },
      };
      Future<SandIamAuthResult> login(Map<String, Object?> data) {
        return SandIamClient(
          baseUrl: 'https://iam.example.test',
          organizationCode: 'sand',
          applicationCode: 'app',
          accessToken: () => '',
          transport: (_) async => SandIamHttpResponse(
              status: 200, body: jsonEncode({'code': 200, 'data': data})),
        ).login(identifier: 'user', password: 'password');
      }

      final result = await login(challenge);
      expect(result.methods, ['totp', 'recovery_code', 'passkey']);
      expect(result.expiresIn, 300);
      expect(result.publicKey?['challenge'], 'encoded-challenge');
      expect(result.accessToken, isNull);
      expect(result.mfaRequired, isTrue);
      for (final invalid in <Map<String, Object?>>[
        {'methods': 'totp'},
        {
          'methods': [1]
        },
        {
          'methods': ['']
        },
        {'expires_in': 0},
        {'expires_in': '300'},
        {'public_key': []},
      ]) {
        await expectLater(
            login({...challenge, ...invalid}),
            throwsA(isA<SandIamException>().having((error) => error.code,
                'code', 'SAND_IAM_SDK_INVALID_RESPONSE')));
      }
    });
    test('login and registration forward captcha without authentication',
        () async {
      final requests = <SandIamHttpRequest>[];
      final client = SandIamClient(
        baseUrl: 'https://iam.example.test',
        organizationCode: 'sand',
        applicationCode: 'app',
        accessToken: () => 'existing-session',
        transport: (request) async {
          requests.add(request);
          return const SandIamHttpResponse(
              status: 200,
              body:
                  '{"code":200,"data":{"access_token":"token","refresh_token":"refresh","identity":{"id":9,"display_name":"User"}}}');
        },
      );
      await client.login(
          identifier: 'user',
          password: 'password',
          captchaToken: 'login-proof');
      await client.register(
          username: 'user',
          password: 'password',
          captchaToken: 'register-proof');
      await client.login(identifier: 'user', password: 'password');
      for (var index = 0; index < requests.length; index++) {
        final body = jsonDecode(requests[index].body!) as Map<String, Object?>;
        expect(body['captcha_token'],
            ['login-proof', 'register-proof', ''][index]);
        expect(body['organization_code'], 'sand');
        expect(body['application_code'], 'app');
        expect(requests[index].headers.containsKey('Authorization'), isFalse);
        expect(requests[index].uri.query, isEmpty);
      }
    });
    test('rejects non-TLS production URL', () {
      expect(
        () => SandIamClient(
            baseUrl: 'http://iam.example.com',
            organizationCode: 'sand',
            applicationCode: 'lvxu',
            accessToken: () => ''),
        throwsA(isA<SandIamException>().having(
            (SandIamException error) => error.code,
            'code',
            'SAND_IAM_SDK_INVALID_CONFIGURATION')),
      );
    });

    test('sends application-scoped authorization request and maps decision',
        () async {
      SandIamHttpRequest? observed;
      final client = SandIamClient(
        baseUrl: 'https://iam.example.test',
        organizationCode: 'sand',
        applicationCode: 'lvxu',
        accessToken: () async => 'siam_at_test',
        transport: (SandIamHttpRequest request) async {
          observed = request;
          return SandIamHttpResponse(
              status: 200,
              body: jsonEncode(<String, Object?>{
                'code': 200,
                'data': <String, Object?>{
                  'allowed': true,
                  'code': 'SAND_IAM_ALLOWED',
                  'policy_ids': <int>[1],
                  'scope': <String, Object?>{'organization_id': 9},
                  'application_id': 2,
                  'identity_id': 3,
                  'api_code': 'case.document.export',
                  'api_version': 'v1',
                  'resource_code': 'case.document',
                  'action': 'export',
                  'operation': 'export',
                  'risk_level': 'high',
                },
              }));
        },
      );
      final decision = await client.authorize(
          apiCode: 'case.document.export', requestId: 'request-1234');
      expect(decision.allowed, isTrue);
      expect(observed?.uri.path, SandIamApi.authorizationDecision);
      expect(observed?.headers['Authorization'], 'Bearer siam_at_test');
      final body = jsonDecode(observed!.body!) as Map<String, Object?>;
      expect(body['organization_code'], 'sand');
      expect(body['application_code'], 'lvxu');
    });

    test('throws typed denial without losing decision', () async {
      final client = _clientWith(<String, Object?>{
        'allowed': false,
        'code': 'SAND_IAM_DENIED',
        'policy_ids': <int>[],
        'scope': <String, Object?>{},
        'application_id': 2,
        'identity_id': 3,
        'api_code': 'stock.cost.read',
        'api_version': 'v1',
        'resource_code': 'stock.cost',
        'action': 'read',
        'operation': 'read',
        'risk_level': 'high',
      });
      await expectLater(client.authorize(apiCode: 'stock.cost.read'),
          throwsA(isA<SandIamDeniedException>()));
    });

    test(
        'issues and verifies workload context without leaking credential or scope IDs',
        () async {
      final requests = <SandIamHttpRequest>[];
      final client = SandIamClient(
        baseUrl: 'https://iam.example.test',
        organizationCode: 'sand',
        applicationCode: 'lvxu',
        accessToken: () => 'unused',
        transport: (SandIamHttpRequest request) async {
          requests.add(request);
          final data = request.uri.path == SandIamApi.runtimeContextIssue
              ? <String, Object?>{
                  'context': 'signed-context',
                  'context_id': 'ctx-1',
                  'expire_time': '2026-08-22 12:00:00'
                }
              : <String, Object?>{
                  'context_id': 'ctx-1',
                  'service_code': 'sand-ai',
                  'audience': 'sand-ai',
                  'actions': <String>['inference.chat', 'inference.embed']
                };
          return SandIamHttpResponse(
              status: 200,
              body: jsonEncode(<String, Object?>{'code': 200, 'data': data}));
        },
      );
      final issued = await client.issueContext(
          credential: 'siam_wc_secret',
          serviceCode: 'sand-ai',
          audience: 'sand-ai',
          actions: <String>['inference.chat'],
          requestId: 'workload-issue-1');
      expect(issued.context, 'signed-context');
      final issueBody =
          jsonDecode(requests.first.body!) as Map<String, Object?>;
      expect(requests.first.headers['Authorization'], 'Bearer siam_wc_secret');
      expect(requests.first.headers['Cache-Control'], 'no-store');
      expect(issueBody.containsKey('organization_id'), isFalse);
      expect(requests.first.body!.contains('siam_wc_secret'), isFalse);
      final action96 = List<String>.filled(96, 'a').join();
      await client.issueContext(
          credential: 'siam_wc_secret',
          serviceCode: 'sand-ai',
          audience: 'sand-ai',
          actions: <String>[action96],
          requestId: 'workload-action-96');
      expect(
          (jsonDecode(requests[1].body!) as Map<String, Object?>)['actions'],
          <String>[action96]);
      await expectLater(
          client.issueContext(
              credential: 'siam_wc_secret',
              serviceCode: 'sand-ai',
              audience: 'sand-ai',
              actions: <String>[List<String>.filled(97, 'a').join()],
              requestId: 'workload-action-97'),
          throwsA(isA<SandIamException>().having(
              (SandIamException error) => error.code,
              'code',
              'SAND_IAM_SDK_INVALID_ARGUMENT')));
      final claims = await client.verifyContext(
          context: 'signed-context',
          serviceCode: 'sand-ai',
          audience: 'sand-ai',
          actions: <String>['inference.chat', 'inference.embed'],
          sourceIp: '127.0.0.1',
          requestId: 'workload-verify-1');
      expect(claims.contextId, 'ctx-1');
      expect(requests, hasLength(4));
      for (final request in requests.skip(2)) {
        final body = jsonDecode(request.body!) as Map<String, Object?>;
        expect(request.headers.containsKey('Authorization'), isFalse);
        expect(body.containsKey('source_ip'), isFalse);
      }
    });

    test('maps malformed workload success payload to stable SDK error',
        () async {
      final client = SandIamClient(
        baseUrl: 'https://iam.example.test',
        organizationCode: 'sand',
        applicationCode: 'lvxu',
        accessToken: () => 'unused',
        transport: (_) async => const SandIamHttpResponse(
            status: 200,
            body:
                '{"code":200,"data":{"context_id":"ctx-1","service_code":"sand-ai","audience":"sand-ai","actions":"inference.chat"}}'),
      );
      await expectLater(
        client.verifyContext(
            context: 'signed-context',
            serviceCode: 'sand-ai',
            audience: 'sand-ai',
            actions: <String>['inference.chat']),
        throwsA(isA<SandIamException>().having(
            (SandIamException error) => error.code,
            'code',
            'SAND_IAM_SDK_INVALID_RESPONSE')),
      );
    });

    test('maps stable remote error and never returns malformed data', () async {
      final client = SandIamClient(
        baseUrl: 'https://iam.example.test',
        organizationCode: 'sand',
        applicationCode: 'lvxu',
        accessToken: () => '',
        transport: (_) async => const SandIamHttpResponse(
            status: 401,
            body: '{"msg":"SAND_IAM_AUTHENTICATION_FAILED: 登录失败"}'),
      );
      await expectLater(
        client.login(identifier: 'lawyer', password: 'wrong'),
        throwsA(isA<SandIamException>()
            .having((SandIamException error) => error.code, 'code',
                'SAND_IAM_AUTHENTICATION_FAILED')
            .having((SandIamException error) => error.status, 'status', 401)),
      );
    });

    test('diagnose checks discovery and application experience', () async {
      final client = SandIamClient(
        baseUrl: 'https://iam.example.test',
        organizationCode: 'sand',
        applicationCode: 'lvxu',
        accessToken: () => '',
        transport: (SandIamHttpRequest request) async {
          if (request.uri.path == SandIamApi.discovery) {
            return const SandIamHttpResponse(
                status: 200,
                body: '{"issuer":"https://iam.example.test/api/sand-iam/v1"}');
          }
          expect(request.uri.queryParameters,
              containsPair('application_code', 'lvxu'));
          return const SandIamHttpResponse(
              status: 200, body: '{"code":200,"data":{"brand_name":"律序"}}');
        },
      );
      final result = await client.diagnose();
      expect(result.healthy, isTrue);
      expect(result.applicationName, '律序');
    });
  });
}

SandIamClient _clientWith(Map<String, Object?> decision) => SandIamClient(
      baseUrl: 'https://iam.example.test',
      organizationCode: 'sand',
      applicationCode: 'lvxu',
      accessToken: () => 'siam_at_test',
      transport: (_) async => SandIamHttpResponse(
          status: 200,
          body: jsonEncode(<String, Object?>{'code': 200, 'data': decision})),
    );
