import 'dart:io';

import '../../../sdk/dart/lib/sand_iam.dart';
import '../generated/sand_iam.dart';

Future<void> main() async {
  final token = Platform.environment['SAND_IAM_ACCESS_TOKEN'];
  if (token == null || token.isEmpty) {
    throw StateError('SAND_IAM_ACCESS_TOKEN 必须由运行环境或密钥管理系统注入');
  }
  final client = SandIamClient(
    baseUrl: Platform.environment['SAND_IAM_BASE_URL'] ?? '',
    organizationCode: sandIamOrganizationCode,
    applicationCode: sandIamApplicationCode,
    accessToken: () => token,
  );
  await client.authorize(
    apiCode: sandIamActions['WORK_ITEM_READ']!,
    requestId: 'work-item-dart-read-001',
  );
  stdout.writeln(sandIamAudience);
}
