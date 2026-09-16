import 'dart:convert';

import 'package:sand_iam/sand_iam.dart';
import 'package:test/test.dart';

void main() {
  group('SandIamManagementClient', () {
    test('returns onboarding preview and preserves request identity', () async {
      SandIamHttpRequest? observed;
      final client = SandIamManagementClient(
        baseUrl: 'https://iam.example.test',
        administratorToken: () => 'admin-token',
        transport: (SandIamHttpRequest request) async {
          observed = request;
          return const SandIamHttpResponse(
            status: 200,
            body: '{"code":200,"data":{"preview_hash":"preview-1"}}',
          );
        },
      );

      final result = await client.onboardingPreview(
        <String, Object?>{'operation_id': 'onboarding-1'},
        requestId: 'request-1234',
      );

      expect(result['preview_hash'], 'preview-1');
      expect(observed?.method, 'POST');
      expect(observed?.uri.path, SandIamApi.onboardingPreview);
      expect(observed?.headers['Authorization'], 'Bearer admin-token');
      expect(observed?.headers['X-Request-Id'], 'request-1234');
      expect(
        jsonDecode(observed!.body!) as Map<String, Object?>,
        <String, Object?>{
          'manifest': <String, Object?>{'operation_id': 'onboarding-1'},
        },
      );
    });

    test('rejects a non-object onboarding preview response', () async {
      final client = SandIamManagementClient(
        baseUrl: 'https://iam.example.test',
        administratorToken: () => 'admin-token',
        transport: (_) async => const SandIamHttpResponse(
          status: 200,
          body: '{"code":200,"data":[]}',
        ),
      );

      await expectLater(
        client.onboardingPreview(<String, Object?>{}),
        throwsA(
          isA<SandIamException>().having(
            (SandIamException error) => error.code,
            'code',
            'SAND_IAM_SDK_INVALID_RESPONSE',
          ),
        ),
      );
    });

    test('previews and applies a route manifest with confirmation hash', () async {
      final observed = <SandIamHttpRequest>[];
      final previewHash = List<String>.filled(64, 'a').join();
      final manifest = <String, Object?>{
        'format': 'sand-iam.route-sync/v1',
        'organization_code': 'sand',
        'application_code': 'lawyer',
        'environment_code': 'production',
        'routes': <Object?>[],
      };
      final client = SandIamManagementClient(
        baseUrl: 'https://iam.example.test',
        administratorToken: () => 'admin-token',
        transport: (SandIamHttpRequest request) async {
          observed.add(request);
          return SandIamHttpResponse(
            status: 200,
            body: observed.length == 1
                ? '{"code":200,"data":{"preview_hash":"$previewHash"}}'
                : '{"code":200,"data":{"application_id":2}}',
          );
        },
      );

      await client.routeSyncPreview(
        manifest,
        disableMissing: true,
        requestId: 'route-preview-1',
      );
      await client.routeSyncApply(SandIamRouteSyncOperation(
        manifest: manifest,
        previewHash: previewHash,
        requestId: 'route-apply-1',
        disableMissing: true,
      ));

      expect(observed[0].uri.path, SandIamApi.routeManifestPreview);
      expect(observed[1].uri.path, SandIamApi.routeManifestApply);
      expect(
        jsonDecode(observed[1].body!) as Map<String, Object?>,
        <String, Object?>{
          'manifest': manifest,
          'disable_missing': true,
          'preview_hash': previewHash,
          'apply': true,
        },
      );
    });
  });
}
