import 'dart:async';
import 'dart:convert';
import 'dart:math';

import 'package:http/http.dart' as http;

import 'api_constants.dart';
import 'client.dart';
import 'errors.dart';
import 'models.dart';

typedef SandIamAdministratorTokenProvider = FutureOr<String> Function();

final class SandIamOnboardingOperation {
  const SandIamOnboardingOperation(
      {required this.manifest, required this.previewHash, required this.requestId});

  final SandIamJson manifest;
  final String previewHash;
  final String requestId;
}

final class SandIamCredentialIssueInput {
  const SandIamCredentialIssueInput(
      {required this.workloadClientId,
      required this.name,
      required this.requestId,
      this.expireTime});

  final int workloadClientId;
  final String name;
  final String requestId;
  final String? expireTime;
}

final class SandIamCredentialRotateInput {
  const SandIamCredentialRotateInput(
      {required this.credentialId,
      required this.name,
      required this.requestId,
      this.expireTime});

  final int credentialId;
  final String name;
  final String requestId;
  final String? expireTime;
}

final class SandIamCredentialRevokeInput {
  const SandIamCredentialRevokeInput(
      {required this.credentialId, required this.requestId});

  final int credentialId;
  final String requestId;
}

final class SandIamProviderPresetDraftInput {
  const SandIamProviderPresetDraftInput(
      {required this.code,
      required this.clientId,
      required this.redirectUri,
      required this.handoffReturnUris,
      this.tenantId,
      this.requestId});

  final String code;
  final String clientId;
  final String redirectUri;
  final List<String> handoffReturnUris;
  final String? tenantId;
  final String? requestId;
}

/// One-time secret wrapper. Do not print or serialize it; reveal it exactly once
/// at the secret-store handoff boundary.
final class SandIamOneTimeSecret {
  SandIamOneTimeSecret(String value) : _value = value;

  String? _value;

  bool get secretAvailable => _value != null;

  String revealOnce() {
    final value = _value;
    if (value == null) {
      throw const SandIamException('SAND_IAM_SDK_SECRET_UNAVAILABLE',
          '一次性密钥已读取或当前响应不含密钥', 0);
    }
    _value = null;
    return value;
  }

  @override
  String toString() {
    throw StateError('SandIAM 一次性密钥禁止转换为字符串或写入日志');
  }
}

final class SandIamCredentialResult {
  const SandIamCredentialResult(
      {required this.metadata,
      required this.secretAvailable,
      required this.replayed,
      SandIamOneTimeSecret? secret})
      : _secret = secret;

  final SandIamJson metadata;
  final bool secretAvailable;
  final bool replayed;
  final SandIamOneTimeSecret? _secret;

  String revealSecretOnce() {
    final secret = _secret;
    if (secret == null) {
      throw const SandIamException('SAND_IAM_SDK_SECRET_UNAVAILABLE',
          '本次响应不含一次性调用凭证', 0);
    }
    return secret.revealOnce();
  }

  SandIamJson toJson() => <String, Object?>{
        'metadata': metadata,
        'secret_available': secretAvailable,
        'replayed': replayed,
      };

  @override
  String toString() {
    throw StateError('SandIAM 一次性凭证结果禁止转换为字符串或写入日志');
  }
}

/// SandAdmin management-plane client. Keep it separate from SandIamClient:
/// administrator Bearer/session tokens are neither application-user tokens nor
/// workload credentials.
final class SandIamManagementClient {
  SandIamManagementClient(
      {required String baseUrl,
      required this.administratorToken,
      SandIamTransport? transport,
      this.timeout = const Duration(seconds: 5)})
      : baseUri = _managementBaseUri(baseUrl),
        _transport = transport ?? _defaultTransport {
    if (timeout < const Duration(seconds: 1) ||
        timeout > const Duration(seconds: 30)) {
      throw const SandIamException(
          'SAND_IAM_SDK_INVALID_CONFIGURATION', '请求超时必须在 1–30 秒之间', 0);
    }
  }

  final Uri baseUri;
  final SandIamAdministratorTokenProvider administratorToken;
  final SandIamTransport _transport;
  final Duration timeout;

  Future<SandIamJson> onboardingPreview(SandIamJson manifest,
          {String? requestId}) async =>
      _object(await _request('POST', SandIamApi.onboardingPreview,
          body: <String, Object?>{'manifest': manifest}, requestId: requestId));

  Future<SandIamJson> onboardingApply(SandIamOnboardingOperation input) async {
    _assertOperation(input.manifest, input.requestId);
    if (input.previewHash.trim().isEmpty) {
      throw const SandIamException('SAND_IAM_SDK_INVALID_ARGUMENT',
          '应用接入草稿必须提供预检哈希', 0);
    }
    return _object(await _request('POST', SandIamApi.onboardingApply,
        body: <String, Object?>{
          'manifest': input.manifest,
          'preview_hash': input.previewHash,
          'apply': true,
        },
        requestId: input.requestId,
        write: true));
  }

  /// Route sync is an onboarding-manifest phase; no nonexistent standalone API
  /// is guessed by this SDK.
  Future<SandIamJson> routeSyncPreview(SandIamJson manifest,
          {String? requestId}) =>
      onboardingPreview(manifest, requestId: requestId);

  Future<SandIamJson> routeSyncApply(SandIamOnboardingOperation input) =>
      onboardingApply(input);

  Future<SandIamJson> policySimulate(SandIamJson input,
          {String? requestId}) async =>
      _object(await _request('POST', SandIamApi.policySimulate,
          body: input, requestId: requestId));

  Future<SandIamJson> policyRollback(
      {required int policyId,
      required int versionId,
      required String requestId}) async {
    if (policyId <= 0 || versionId <= 0) {
      throw const SandIamException(
          'SAND_IAM_SDK_INVALID_ARGUMENT', '策略和版本编号必须为正整数', 0);
    }
    return _object(await _request('POST', SandIamApi.policyRollback,
        body: <String, Object?>{'id': policyId, 'version_id': versionId},
        requestId: requestId,
        write: true));
  }

  Future<SandIamCredentialResult> credentialIssue(
      SandIamCredentialIssueInput input) async {
    if (input.workloadClientId <= 0 || input.name.trim().isEmpty) {
      throw const SandIamException('SAND_IAM_SDK_INVALID_ARGUMENT',
          '服务调用身份和凭证名称不能为空', 0);
    }
    return _credentialResult(_object(await _request(
        'POST', SandIamApi.credentialIssue,
        body: <String, Object?>{
          'workload_client_id': input.workloadClientId,
          'name': input.name,
          'expire_time': input.expireTime,
        },
        requestId: input.requestId,
        write: true)));
  }

  Future<SandIamCredentialResult> credentialRotate(
      SandIamCredentialRotateInput input) async {
    if (input.credentialId <= 0 || input.name.trim().isEmpty) {
      throw const SandIamException('SAND_IAM_SDK_INVALID_ARGUMENT',
          '调用凭证编号和名称不能为空', 0);
    }
    return _credentialResult(_object(await _request(
        'POST', SandIamApi.credentialRotate,
        body: <String, Object?>{
          'id': input.credentialId,
          'name': input.name,
          'expire_time': input.expireTime,
        },
        requestId: input.requestId,
        write: true)));
  }

  Future<SandIamJson> credentialRevoke(
      SandIamCredentialRevokeInput input) async {
    if (input.credentialId <= 0) {
      throw const SandIamException(
          'SAND_IAM_SDK_INVALID_ARGUMENT', '调用凭证编号必须为正整数', 0);
    }
    return _object(await _request('POST', SandIamApi.credentialRevoke,
        body: <String, Object?>{'id': input.credentialId},
        requestId: input.requestId,
        write: true));
  }

  Future<List<SandIamJson>> presetList({String? requestId}) async =>
      _list(await _request('GET', SandIamApi.providerPresetList,
          requestId: requestId));

  Future<SandIamJson> presetDraft(SandIamProviderPresetDraftInput input) async {
    if (input.code.trim().isEmpty ||
        input.clientId.trim().isEmpty ||
        input.redirectUri.trim().isEmpty ||
        input.handoffReturnUris.isEmpty) {
      throw const SandIamException('SAND_IAM_SDK_INVALID_ARGUMENT',
          '预设代码、客户端编号、回调地址和回跳白名单不能为空', 0);
    }
    final body = <String, Object?>{
      'code': input.code,
      'client_id': input.clientId,
      'redirect_uri': input.redirectUri,
      'handoff_return_uris': input.handoffReturnUris,
    };
    if (input.tenantId != null) body['tenant_id'] = input.tenantId;
    return _object(await _request('POST', SandIamApi.providerPresetDraft,
        body: body, requestId: input.requestId));
  }

  Future<Object?> _request(String method, String path,
      {SandIamJson? body, String? requestId, bool write = false}) async {
    if (write) _assertRequestId(requestId);
    final token = (await administratorToken()).trim();
    if (token.isEmpty) {
      throw const SandIamException('SAND_IAM_AUTHENTICATION_FAILED',
          '管理员登录态或 Bearer 令牌不能为空', 401);
    }
    final headers = <String, String>{
      'Accept': 'application/json',
      'Authorization': 'Bearer $token',
      'X-Request-Id': requestId ?? _requestId(),
      'Cache-Control': 'no-store',
    };
    if (body != null) headers['Content-Type'] = 'application/json';
    final request = SandIamHttpRequest(
        method: method,
        uri: baseUri.replace(path: '${baseUri.path}$path'),
        headers: headers,
        body: body == null ? null : jsonEncode(body));
    SandIamHttpResponse response;
    try {
      response = await _transport(request).timeout(timeout);
    } on TimeoutException {
      throw const SandIamException(
          'SAND_IAM_SDK_TIMEOUT', '连接 SandIAM 管理 API 超时，请检查地址和网络', 0);
    } on SandIamException {
      rethrow;
    } on Object {
      throw const SandIamException('SAND_IAM_SDK_TRANSPORT_FAILED',
          '无法连接 SandIAM 管理 API，请检查地址和网络', 0);
    }
    Object? decoded;
    try {
      decoded = jsonDecode(response.body);
    } on FormatException {
      throw SandIamException('SAND_IAM_SDK_INVALID_RESPONSE',
          'SandIAM 管理 API 返回了无法解析的数据', response.status);
    }
    if (response.status < 200 || response.status >= 300) {
      throw _remoteError(decoded, response.status);
    }
    if (decoded is! Map<String, Object?> || !decoded.containsKey('data')) {
      throw SandIamException('SAND_IAM_SDK_INVALID_RESPONSE',
          'SandIAM 管理 API 返回的数据结构不正确', response.status);
    }
    return decoded['data'];
  }

  void _assertOperation(SandIamJson manifest, String requestId) {
    final operationId = manifest['operation_id'];
    if (operationId is! String || operationId.trim().isEmpty) {
      throw const SandIamException('SAND_IAM_SDK_INVALID_ARGUMENT',
          '应用或路由同步必须在 manifest 中提供 operation_id', 0);
    }
    _assertRequestId(requestId);
  }

  void _assertRequestId(String? value) {
    if (value == null ||
        !RegExp(r'^[A-Za-z0-9_.:-]{8,96}$').hasMatch(value)) {
      throw const SandIamException('SAND_IAM_SDK_REQUEST_ID_REQUIRED',
          '写操作必须显式提供 8–96 位 request_id', 0);
    }
  }

  SandIamCredentialResult _credentialResult(SandIamJson data) {
    final replayed = data['replayed'] == true;
    final value = data['credential'];
    if (value != null && (value is! String || value.isEmpty || replayed)) {
      throw const SandIamException('SAND_IAM_SDK_INVALID_RESPONSE',
          '重放响应不得包含一次性调用凭证', 200);
    }
    final metadata = Map<String, Object?>.from(data)..remove('credential');
    return SandIamCredentialResult(
        metadata: metadata,
        secretAvailable: value is String,
        replayed: replayed,
        secret: value is String ? SandIamOneTimeSecret(value) : null);
  }

  static Future<SandIamHttpResponse> _defaultTransport(
      SandIamHttpRequest request) async {
    final outgoing = http.Request(request.method, request.uri)
      ..headers.addAll(request.headers);
    if (request.body != null) outgoing.body = request.body!;
    final streamed = await outgoing.send();
    return SandIamHttpResponse(
        status: streamed.statusCode,
        body: await streamed.stream.bytesToString());
  }
}

Uri _managementBaseUri(String value) {
  final trimmed = value.trim().replaceAll(RegExp(r'/+$'), '');
  final uri = Uri.tryParse(trimmed);
  if (uri == null ||
      !uri.hasAuthority ||
      uri.userInfo.isNotEmpty ||
      uri.hasQuery ||
      uri.fragment.isNotEmpty ||
      (uri.scheme != 'https' &&
          !(uri.scheme == 'http' &&
              (uri.host == 'localhost' ||
                  uri.host == '127.0.0.1' ||
                  uri.host == '::1')))) {
    throw const SandIamException('SAND_IAM_SDK_INVALID_CONFIGURATION',
        '管理 API 地址必须使用 HTTPS；仅本机开发允许 HTTP', 0);
  }
  return uri;
}

String _requestId() {
  final random = Random.secure();
  return List<int>.generate(16, (_) => random.nextInt(256))
      .map((int byte) => byte.toRadixString(16).padLeft(2, '0'))
      .join();
}

SandIamException _remoteError(Object? value, int status) {
  var message = 'SandIAM 管理 API 请求失败';
  if (value is Map<String, Object?>) {
    if (value['msg'] is String) message = value['msg']! as String;
    if (value['message'] is String) message = value['message']! as String;
  }
  final match = RegExp(r'^(SAND_IAM_[A-Z0-9_]+)').firstMatch(message);
  return SandIamException(
      match?.group(1) ?? 'SAND_IAM_REQUEST_FAILED', message, status);
}

SandIamJson _object(Object? value) {
  if (value is! Map<String, Object?>) {
    throw const SandIamException('SAND_IAM_SDK_INVALID_RESPONSE',
        'SandIAM 管理 API 返回的数据不是对象', 200);
  }
  return value;
}

List<SandIamJson> _list(Object? value) {
  if (value is! List<Object?> ||
      value.any((Object? item) => item is! Map<String, Object?>)) {
    throw const SandIamException('SAND_IAM_SDK_INVALID_RESPONSE',
        'SandIAM 管理 API 返回的数据不是列表', 200);
  }
  return value.cast<SandIamJson>();
}
