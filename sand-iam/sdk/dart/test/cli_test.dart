import 'package:sand_iam/sand_iam.dart';
import 'package:sand_iam/src/cli.dart';
import 'package:test/test.dart';

void main() {
  for (final failure in ['network', 'malformed', 'discovery', 'experience']) {
    test('doctor reports $failure failure without credentials or error body',
        () async {
      final lines = <String>[];
      final requests = <SandIamHttpRequest>[];
      var tokenReads = 0;
      final runner = SandIamCliRunner(
        output: lines.add,
        clientFactory: (url, organization, application) => SandIamClient(
          baseUrl: url,
          organizationCode: organization,
          applicationCode: application,
          accessToken: () {
            tokenReads++;
            return 'private-diagnostic-marker';
          },
          transport: (request) async {
            requests.add(request);
            if (failure == 'network') {
              throw StateError('private-diagnostic-marker');
            }
            if (failure == 'malformed') {
              return const SandIamHttpResponse(
                  status: 200, body: 'private-diagnostic-marker');
            }
            final discovery = request.uri.path == SandIamApi.discovery;
            if ((discovery && failure == 'discovery') ||
                (!discovery && failure == 'experience')) {
              return const SandIamHttpResponse(
                  status: 503,
                  body: '{"code":503,"message":"private-diagnostic-marker"}');
            }
            return discovery
                ? const SandIamHttpResponse(
                    status: 200,
                    body: '{"issuer":"https://iam.example.test"}')
                : const SandIamHttpResponse(
                    status: 200,
                    body: '{"code":200,"data":{"brand_name":"Example"}}');
          },
        ),
      );
      final code = await runner.run([
        'doctor', '--url', 'https://iam.example.test',
        '--organization', 'sand', '--application', 'app',
      ]);
      expect(code, 2);
      expect(requests, hasLength(2));
      expect(tokenReads, 0);
      expect(requests.every((request) =>
          !request.headers.containsKey('Authorization')), isTrue);
      expect(requests.last.uri.queryParameters, {
        'organization_code': 'sand', 'application_code': 'app',
      });
      expect(lines[0], 'OIDC 发现文档：${failure == 'experience' ? '可访问' : '不可访问'}');
      expect(lines[1], '应用登录配置：${failure == 'discovery' ? '可访问' : '不可访问'}');
      expect(lines.last, startsWith('诊断未通过'));
      expect(lines.join('\n'), isNot(contains('private-diagnostic-marker')));
    });
  }

  test('snippet escapes quoted URLs and Dart interpolation', () async {
    for (final language in ['php', 'typescript', 'flutter']) {
      final lines = <String>[];
      final code = await SandIamCliRunner(output: lines.add).run([
        'snippet', '--language', language, '--url', r"https://iam.example.test/it's/$tenant",
        '--organization', 'sand', '--application', 'app',
      ]);
      expect(code, 0);
      expect(lines.single, contains(language == 'flutter'
          ? r"https://iam.example.test/it\'s/\$tenant"
          : r"https://iam.example.test/it\'s/$tenant"));
    }
  });

  test('snippet rejects invalid configuration before printing code', () async {
    for (final option in <List<String>>[
      ['--url', 'http://external.example.test'],
      ['--url', 'https://user:password@iam.example.test'],
      ['--url', 'https://iam.example.test?token=value'],
      ['--organization', "org'bad"],
      ['--application', r'app$bad'],
    ]) {
      final lines = <String>[];
      await expectLater(SandIamCliRunner(output: lines.add).run(['snippet', ...option]),
          throwsA(isA<SandIamException>().having((error) => error.code,
              'code', 'SAND_IAM_SDK_INVALID_CONFIGURATION')));
      expect(lines, isEmpty);
    }
  });

  test('snippet emits the validated normalized URL without raw line breaks', () async {
    for (final language in ['php', 'typescript', 'flutter']) {
      final lines = <String>[];
      await SandIamCliRunner(output: lines.add).run([
        'snippet', '--language', language,
        '--url', ' \nhttps://iam.example.test/line\nbreak/ \n',
      ]);
      expect(lines.single, contains('https://iam.example.test/line%0Abreak'));
      expect(lines.single, isNot(contains('\n')));
    }
  });

  test('snippet prints a secret-free Flutter example', () async {
    final lines = <String>[];
    final code = await SandIamCliRunner(output: lines.add)
        .run(<String>['snippet', '--language', 'flutter']);
    expect(code, 0);
    expect(lines.single, contains('SandIamClient('));
    expect(lines.single, isNot(contains('accessToken: \'siam_')));
  });

  test('doctor reports both checks and returns success', () async {
    final lines = <String>[];
    final runner = SandIamCliRunner(
      output: lines.add,
      clientFactory: (String url, String organization, String application) =>
          SandIamClient(
        baseUrl: url,
        organizationCode: organization,
        applicationCode: application,
        accessToken: () => '',
        transport: (SandIamHttpRequest request) async => request.uri.path ==
                SandIamApi.discovery
            ? const SandIamHttpResponse(
                status: 200,
                body: '{"issuer":"https://iam.example.test/api/sand-iam/v1"}')
            : const SandIamHttpResponse(
                status: 200, body: '{"code":200,"data":{"brand_name":"律序"}}'),
      ),
    );
    final code = await runner.run(<String>[
      'doctor',
      '--url',
      'https://iam.example.test',
      '--organization',
      'sand',
      '--application',
      'lvxu'
    ]);
    expect(code, 0);
    expect(lines, contains('OIDC 发现文档：可访问'));
    expect(lines, contains('应用登录配置：可访问'));
    expect(lines.last, contains('诊断通过'));
  });
}
