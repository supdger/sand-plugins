import 'package:sand_iam/sand_iam.dart';
import 'package:sand_iam/src/cli.dart';
import 'package:test/test.dart';

void main() {
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
