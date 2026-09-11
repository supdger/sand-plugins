import 'dart:convert';

import 'package:sand_iam/sand_iam.dart';
import 'package:test/test.dart';

void main() {
  group('SandIamClient', () {
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

    test('issues and verifies workload context without leaking credential or scope IDs',
        () async {
      final requests = <SandIamHttpRequest>[];
      final client = SandIamClient(
        baseUrl: 'https://iam.example.test', organizationCode: 'sand', applicationCode: 'lvxu', accessToken: () => 'unused',
        transport: (SandIamHttpRequest request) async {
          requests.add(request);
          final data = request.uri.path == SandIamApi.runtimeContextIssue
              ? <String, Object?>{'context': 'signed-context', 'context_id': 'ctx-1', 'expire_time': '2026-08-22 12:00:00'}
              : <String, Object?>{'context_id': 'ctx-1', 'service_code': 'sand-ai', 'audience': 'sand-ai', 'actions': <String>['inference.chat', 'inference.embed']};
          return SandIamHttpResponse(status: 200, body: jsonEncode(<String, Object?>{'code': 200, 'data': data}));
        },
      );
      final issued = await client.issueContext(credential: 'siam_wc_secret', serviceCode: 'sand-ai', audience: 'sand-ai', actions: <String>['inference.chat'], requestId: 'workload-issue-1');
      expect(issued.context, 'signed-context');
      final issueBody = jsonDecode(requests.first.body!) as Map<String, Object?>;
      expect(requests.first.headers['Authorization'], 'Bearer siam_wc_secret');
      expect(requests.first.headers['Cache-Control'], 'no-store');
      expect(issueBody.containsKey('organization_id'), isFalse);
      expect(requests.first.body!.contains('siam_wc_secret'), isFalse);
      final claims = await client.verifyContext(context: 'signed-context', serviceCode: 'sand-ai', audience: 'sand-ai', actions: <String>['inference.chat', 'inference.embed'], sourceIp: '127.0.0.1', requestId: 'workload-verify-1');
      expect(claims.contextId, 'ctx-1');
      expect(requests, hasLength(3));
      for (final request in requests.skip(1)) {
        final body = jsonDecode(request.body!) as Map<String, Object?>;
        expect(request.headers.containsKey('Authorization'), isFalse);
        expect(body.containsKey('source_ip'), isFalse);
      }
    });

    test('maps malformed workload success payload to stable SDK error', () async {
      final client = SandIamClient(
        baseUrl: 'https://iam.example.test', organizationCode: 'sand', applicationCode: 'lvxu', accessToken: () => 'unused',
        transport: (_) async => const SandIamHttpResponse(status: 200,
            body: '{"code":200,"data":{"context_id":"ctx-1","service_code":"sand-ai","audience":"sand-ai","actions":"inference.chat"}}'),
      );
      await expectLater(
        client.verifyContext(context: 'signed-context', serviceCode: 'sand-ai', audience: 'sand-ai', actions: <String>['inference.chat']),
        throwsA(isA<SandIamException>().having((SandIamException error) => error.code,
            'code', 'SAND_IAM_SDK_INVALID_RESPONSE')),
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
