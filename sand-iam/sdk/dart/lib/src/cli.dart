import 'dart:async';

import 'package:args/command_runner.dart';

import 'client.dart';

typedef SandIamCliOutput = void Function(String line);
typedef SandIamCliClientFactory = SandIamClient Function(
    String baseUrl, String organizationCode, String applicationCode);

final class SandIamCliRunner extends CommandRunner<int> {
  SandIamCliRunner(
      {required SandIamCliOutput output,
      SandIamCliClientFactory? clientFactory})
      : super('sand-iam', 'SandIAM 接入诊断与配置示例工具。命令行不接收或打印用户密码、访问令牌和共享密钥。') {
    addCommand(_DoctorCommand(output, clientFactory ?? _client));
    addCommand(_SnippetCommand(output));
  }

  static SandIamClient _client(
          String baseUrl, String organizationCode, String applicationCode) =>
      SandIamClient(
        baseUrl: baseUrl,
        organizationCode: organizationCode,
        applicationCode: applicationCode,
        accessToken: () => '',
      );
}

final class _DoctorCommand extends Command<int> {
  _DoctorCommand(this.output, this.clientFactory) {
    argParser
      ..addOption('url',
          help: 'SandIAM 对外 HTTPS 地址，例如 https://iam.example.com。',
          mandatory: true)
      ..addOption('organization', help: '客户主体系统代码。', mandatory: true)
      ..addOption('application', help: '接入应用系统代码。', mandatory: true);
  }

  final SandIamCliOutput output;
  final SandIamCliClientFactory clientFactory;

  @override
  String get name => 'doctor';

  @override
  String get description => '检查 OIDC 发现文档和指定应用登录外观是否可访问。';

  @override
  Future<int> run() async {
    final result = await clientFactory(
            _option('url'), _option('organization'), _option('application'))
        .diagnose();
    output('OIDC 发现文档：${result.discoveryAvailable ? '可访问' : '不可访问'}');
    output('应用登录配置：${result.experienceAvailable ? '可访问' : '不可访问'}');
    if (result.issuer != null) {
      output('签发方：${result.issuer}');
    }
    if (result.applicationName != null) {
      output('应用名称：${result.applicationName}');
    }
    output(result.healthy ? '诊断通过：可以开始应用接入。' : '诊断未通过：请检查地址、应用代码、功能开关和部署状态。');
    return result.healthy ? 0 : 2;
  }

  String _option(String name) => argResults![name]! as String;
}

final class _SnippetCommand extends Command<int> {
  _SnippetCommand(this.output) {
    argParser
      ..addOption('language',
          allowed: const <String>['flutter', 'php', 'typescript'],
          defaultsTo: 'flutter',
          help: '示例语言。')
      ..addOption('url',
          defaultsTo: 'https://iam.example.com', help: '示例中的 SandIAM 地址。')
      ..addOption('organization',
          defaultsTo: 'your_organization', help: '示例中的客户主体代码。')
      ..addOption('application',
          defaultsTo: 'your_application', help: '示例中的接入应用代码。');
  }

  final SandIamCliOutput output;

  @override
  String get name => 'snippet';

  @override
  String get description => '生成不含密钥和令牌的最小接入配置示例。';

  @override
  int run() {
    final language = argResults!['language']! as String;
    final url = argResults!['url']! as String;
    final organization = argResults!['organization']! as String;
    final application = argResults!['application']! as String;
    final client = SandIamCliRunner._client(url, organization, application);
    final urlLiteral = _literal(client.baseUri.toString(), language);
    final organizationLiteral = _literal(organization, language);
    final applicationLiteral = _literal(application, language);
    output(switch (language) {
      'php' => 'new SandIamClient($urlLiteral, $organizationLiteral, $applicationLiteral);',
      'typescript' =>
        'new SandIamClient({ baseUrl: $urlLiteral, organizationCode: $organizationLiteral, applicationCode: $applicationLiteral, accessToken: () => tokenStore.read() });',
      _ =>
        'SandIamClient(baseUrl: $urlLiteral, organizationCode: $organizationLiteral, applicationCode: $applicationLiteral, accessToken: tokenStore.read);',
    });
    return 0;
  }

  String _literal(String value, String language) {
    var escaped = value.replaceAll(r'\', r'\\').replaceAll("'", r"\'");
    if (language != 'php') {
      escaped = escaped.replaceAll('\n', r'\n').replaceAll('\r', r'\r')
          .replaceAll('\t', r'\t').replaceAll('\b', r'\b').replaceAll('\f', r'\f')
          .replaceAll('\u2028', r'\u2028').replaceAll('\u2029', r'\u2029');
    }
    if (language == 'flutter') escaped = escaped.replaceAll(r'$', r'\$');
    return "'$escaped'";
  }
}
