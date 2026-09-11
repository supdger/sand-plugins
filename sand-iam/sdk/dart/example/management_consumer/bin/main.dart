import 'package:sand_iam/sand_iam.dart';

Future<void> main() async {
  final client = SandIamManagementClient(
    baseUrl: 'https://iam.example.test',
    administratorToken: () => 'admin-session-token',
    transport: (SandIamHttpRequest request) async {
      if (request.headers['Authorization'] != 'Bearer admin-session-token' ||
          request.headers['Cache-Control'] != 'no-store') {
        throw StateError('management request headers are invalid');
      }
      return const SandIamHttpResponse(
          status: 200,
          body: '{"code":200,"data":{"id":1,"credential":"one-time"}}');
    },
  );
  final credential = await client.credentialIssue(
      const SandIamCredentialIssueInput(
          workloadClientId: 1, name: 'smoke', requestId: 'dart-consumer-1'));
  if (!credential.secretAvailable || credential.revealSecretOnce() != 'one-time') {
    throw StateError('management SDK consumer smoke failed');
  }
}
