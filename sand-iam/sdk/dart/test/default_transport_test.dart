import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:sand_iam/sand_iam.dart';
import 'package:test/test.dart';

final class RedirectProbe extends http.BaseClient {
  RedirectProbe(this.status);
  final int status;
  final requests = <http.BaseRequest>[];
  final bodies = <String>[];

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    requests.add(request);
    bodies.add(await request.finalize().bytesToString());
    return http.StreamedResponse(
      Stream.value(utf8.encode('{"code":200,"data":{"unexpected":"success"}}')),
      status,
      headers: {'location': 'http://external.example/collect'},
      isRedirect: true,
      request: request,
    );
  }
}

void main() {
  for (final status in [301, 302, 303, 307, 308]) {
    for (final kind in ['session', 'credential', 'management']) {
      test('$kind default transport refuses $status redirects', () async {
        final probe = RedirectProbe(status);
        await http.runWithClient(() async {
          // Deliberately omit SDK transport: exercise its real http.Request path.
          final client = SandIamClient(
            baseUrl: 'https://iam.example.test',
            organizationCode: 'sand',
            applicationCode: 'app',
            accessToken: () => 'session-secret',
          );
          final management = SandIamManagementClient(
            baseUrl: 'https://iam.example.test',
            administratorToken: () => 'admin-secret',
          );
          final operation = switch (kind) {
            'session' => client.decide(apiCode: 'order.read'),
            'credential' => client.issueContext(
                credential: 'credential-secret', serviceCode: 'orders',
                audience: 'orders-api', actions: ['order.read']),
            _ => management.onboardingPreview({'application_code': 'app'}),
          };
          await expectLater(operation, throwsA(isA<SandIamException>()));
        }, () => probe);
        expect(probe.requests, hasLength(1));
        final request = probe.requests.single;
        expect(request.followRedirects, false);
        expect(request.url.host, 'iam.example.test');
        final secret = switch (kind) {
          'session' => 'session-secret',
          'credential' => 'credential-secret',
          _ => 'admin-secret',
        };
        expect(request.headers['Authorization'], 'Bearer $secret');
        expect(request.url.toString().contains(secret), false);
        expect(probe.bodies.single.contains(secret), false);
      });
    }
  }
}
