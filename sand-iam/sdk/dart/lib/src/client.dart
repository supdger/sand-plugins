import 'dart:async';
import 'dart:convert';
import 'dart:math';

import 'package:http/http.dart' as http;

import 'api_constants.dart';
import 'errors.dart';
import 'models.dart';

typedef SandIamAccessTokenProvider = FutureOr<String> Function();
typedef SandIamTransport = Future<SandIamHttpResponse> Function(
    SandIamHttpRequest request);

final class SandIamHttpRequest {
  const SandIamHttpRequest(
      {required this.method,
      required this.uri,
      required this.headers,
      this.body});
  final String method;
  final Uri uri;
  final Map<String, String> headers;
  final String? body;
}

final class SandIamHttpResponse {
  const SandIamHttpResponse({required this.status, required this.body});
  final int status;
  final String body;
}

final class SandIamClient {
  SandIamClient({
    required String baseUrl,
    required this.organizationCode,
    required this.applicationCode,
    required this.accessToken,
    SandIamTransport? transport,
    this.timeout = const Duration(seconds: 5),
  })  : baseUri = _baseUri(baseUrl),
        _transport = transport ?? _defaultTransport {
    if (!_stableCode.hasMatch(organizationCode) ||
        !_stableCode.hasMatch(applicationCode)) {
      throw const SandIamException(
          'SAND_IAM_SDK_INVALID_CONFIGURATION', '客户主体代码和接入应用代码格式不正确', 0);
    }
    if (timeout < const Duration(seconds: 1) ||
        timeout > const Duration(seconds: 30)) {
      throw const SandIamException(
          'SAND_IAM_SDK_INVALID_CONFIGURATION', '请求超时必须在 1–30 秒之间', 0);
    }
  }

  static final RegExp _stableCode = RegExp(r'^[a-z0-9][a-z0-9_-]{1,63}$');
  final Uri baseUri;
  final String organizationCode;
  final String applicationCode;
  final SandIamAccessTokenProvider accessToken;
  final Duration timeout;
  final SandIamTransport _transport;

  Future<SandIamDecision> decide(
      {required String apiCode,
      String apiVersion = 'v1',
      SandIamJson attributes = const <String, Object?>{},
      String? requestId}) async {
    if (apiCode.trim().isEmpty) {
      throw const SandIamException(
          'SAND_IAM_SDK_INVALID_ARGUMENT', '接口代码不能为空', 0);
    }
    return SandIamDecision.fromJson(_object(await _request(
        'POST', SandIamApi.authorizationDecision,
        authenticated: true,
        requestId: requestId,
        body: <String, Object?>{
          ..._applicationPayload(),
          'api_code': apiCode,
          'api_version': apiVersion,
          'attributes': attributes,
        })));
  }

  Future<SandIamDecision> authorize(
      {required String apiCode,
      String apiVersion = 'v1',
      SandIamJson attributes = const <String, Object?>{},
      String? requestId}) async {
    final decision = await decide(
        apiCode: apiCode,
        apiVersion: apiVersion,
        attributes: attributes,
        requestId: requestId);
    if (!decision.allowed) throw SandIamDeniedException(decision);
    return decision;
  }

  /// 签发短期工作负载上下文。credential 只发送到 Authorization 头，绝不进入 body、URL 或日志。
  Future<SandIamWorkloadContext> issueContext(
      {required String credential,
      required String serviceCode,
      required String audience,
      required List<String> actions,
      SandIamJson? subjectScope,
      String? requestId}) async {
    _validateWorkloadInput(credential, serviceCode, audience, actions);
    try {
      return SandIamWorkloadContext.issue(_object(await _request(
          'POST', SandIamApi.runtimeContextIssue,
          bearerToken: credential,
          noStore: true,
          requestId: requestId,
          body: <String, Object?>{
            'service_code': serviceCode,
            'audience': audience,
            'actions': actions,
            'subject_scope': subjectScope,
          })));
    } on FormatException catch (_) {
      throw const SandIamException(
          'SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的工作负载上下文不正确', 200);
    } on TypeError catch (_) {
      throw const SandIamException(
          'SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的工作负载上下文不正确', 200);
    }
  }

  /// 公共 HTTP 路由从连接对端获取可信 IP；sourceIp 仅为服务端适配器保留，绝不写入请求。
  Future<SandIamWorkloadContext> verifyContext(
      {required String context,
      required String serviceCode,
      required String audience,
      required List<String> actions,
      String? sourceIp,
      String? requestId}) async {
    _validateWorkloadInput(context, serviceCode, audience, actions);
    if (sourceIp != null &&
        Uri.tryParse('http://[$sourceIp]') == null &&
        !RegExp(r'^\d{1,3}(?:\.\d{1,3}){3}$').hasMatch(sourceIp)) {
      throw const SandIamException(
          'SAND_IAM_SDK_INVALID_ARGUMENT', 'sourceIp 必须是服务器已验证的 IP 地址', 0);
    }
    final baseRequestId = _requestId(requestId);
    SandIamWorkloadContext? claims;
    for (var index = 0; index < actions.length; index++) {
      final action = actions[index];
      late SandIamWorkloadContext current;
      try {
        current = SandIamWorkloadContext.verify(_object(await _request(
            'POST', SandIamApi.runtimeContextVerify,
            noStore: true,
            requestId: _derivedRequestId(baseRequestId, index),
            body: <String, Object?>{
              'context': context,
              'audience': audience,
              'action': action
            })));
      } on FormatException catch (_) {
        throw const SandIamException(
            'SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的工作负载上下文不正确', 200);
      } on TypeError catch (_) {
        throw const SandIamException(
            'SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的工作负载上下文不正确', 200);
      }
      if (current.serviceCode != serviceCode ||
          current.actions == null ||
          !current.actions!.contains(action)) {
        throw const SandIamException(
            'SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的上下文与请求的服务或动作不一致', 200);
      }
      claims = current;
    }
    return claims!;
  }

  /// Accepting an invitation does not start a session.
  Future<SandIamInvitationIdentity> acceptInvitation({
    required String token, required String username, required String password,
    String? displayName, String? requestId,
  }) async {
    if (token.trim().isEmpty || username.trim().isEmpty || password.isEmpty) {
      throw const SandIamException('SAND_IAM_SDK_INVALID_ARGUMENT', '邀请令牌、账号和密码不能为空', 0);
    }
    final data = await _request('POST', SandIamApi.acceptInvitation,
      requestId: requestId, noStore: true,
      body: {'token': token, 'username': username, 'password': password, 'display_name': displayName ?? ''});
    if (data is! SandIamJson) throw const SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的邀请用户信息不正确', 200);
    final id = data['id'];
    final name = data['display_name'];
    if (id is! int || id <= 0 || name is! String) {
      throw const SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的邀请用户信息不正确', 200);
    }
    return SandIamInvitationIdentity(id: id, displayName: name);
  }

  Future<SandIamAuthResult> register(
      {required String username,
      required String password,
      String? displayName,
      String? email,
      String? phone,
      String? captchaToken,
      String? userAgent,
      String? requestId}) async {
    final data = await _request('POST', SandIamApi.register,
        requestId: requestId,
        body: <String, Object?>{
          ..._applicationPayload(),
          'username': username,
          'password': password,
          'display_name': displayName ?? username,
          'email': email ?? '',
          'phone': phone ?? '',
          'captcha_token': captchaToken ?? '',
          'user_agent': userAgent ?? '',
        });
    return _authResult(data);
  }

  Future<SandIamAuthResult> login(
      {required String identifier,
      required String password,
      String? captchaToken,
      String? userAgent,
      String? requestId}) async {
    if (identifier.trim().isEmpty || password.isEmpty) {
      throw const SandIamException(
          'SAND_IAM_SDK_INVALID_ARGUMENT', '登录账号和密码不能为空', 0);
    }
    return _authResult(await _request('POST', SandIamApi.login,
        requestId: requestId,
        body: <String, Object?>{
          ..._applicationPayload(),
          'identifier': identifier,
          'captcha_token': captchaToken ?? '',
          'password': password,
          'user_agent': userAgent ?? '',
        }));
  }

  /// Submit an application-bound MFA challenge; a step-up result is not a login.
  Future<SandIamAuthResult> verifyMfaChallenge({
    required String challengeToken,
    required String method,
    String? code,
    String? rawId,
    SandIamJson? response,
    String? userAgent,
    String? requestId,
  }) async {
    if (challengeToken.trim().isEmpty ||
        !['totp', 'recovery_code', 'passkey'].contains(method) ||
        (method == 'passkey'
            ? rawId == null || rawId.isEmpty || response == null
            : code == null || code.isEmpty)) {
      throw const SandIamException(
          'SAND_IAM_SDK_INVALID_ARGUMENT', 'MFA 挑战和验证字段不完整', 0);
    }
    return _authResult(await _request(
      'POST', SandIamApi.verifyMfaChallenge,
      requestId: requestId,
      body: <String, Object?>{
        ..._applicationPayload(),
        'challenge_token': challengeToken,
        'method': method,
        'user_agent': userAgent ?? '',
        if (method == 'passkey') 'rawId': rawId,
        if (method == 'passkey') 'response': response,
        if (method != 'passkey') 'code': code,
      },
    ));
  }

  /// The platform performs WebAuthn and serializes binary response fields.
  Future<SandIamPasskeyOptions> passkeyRegistrationOptions({String name = '',
      required String currentPassword, String? requestId}) async {
    if (currentPassword.isEmpty) throw _invalidMfaInput();
    final data = await _request('POST', SandIamApi.passkeyRegistrationOptions,
        authenticated: true, requestId: requestId,
        body: {'name': name, 'current_password': currentPassword});
    return _mfaResult(() => SandIamPasskeyOptions.fromJson(_object(data)));
  }

  Future<void> passkeyRegistrationFinish({required String challengeToken,
      required String rawId, required SandIamJson response, String? requestId}) async {
    final proof = _passkeyProof(challengeToken, rawId, response,
        ['clientDataJSON', 'attestationObject']);
    await _request('POST', SandIamApi.passkeyRegistrationFinish,
        authenticated: true, requestId: requestId,
        body: {'challenge_token': challengeToken, 'rawId': rawId, 'response': proof});
  }

  Future<SandIamPasskeyOptions> passkeyAuthenticationOptions({String? requestId}) async {
    final data = await _request('POST', SandIamApi.passkeyAuthenticationOptions,
        requestId: requestId, body: _applicationPayload());
    return _mfaResult(() => SandIamPasskeyOptions.fromJson(_object(data)));
  }

  Future<SandIamAuthResult> passkeyAuthenticationFinish({required String challengeToken,
      required String rawId, required SandIamJson response,
      String? userAgent, String? requestId}) async {
    final proof = _passkeyProof(challengeToken, rawId, response,
        ['clientDataJSON', 'authenticatorData', 'signature', 'userHandle']);
    return _authResult(await _request('POST', SandIamApi.passkeyAuthenticationFinish,
        requestId: requestId, body: {..._applicationPayload(),
          'challenge_token': challengeToken, 'rawId': rawId, 'response': proof,
          'user_agent': userAgent ?? ''}));
  }

  Future<SandIamCaptchaConfiguration> captchaConfiguration(String action, {String? requestId}) async {
    if (!['login', 'register'].contains(action)) throw _invalidMfaInput();
    final data = await _request('GET', SandIamApi.captchaConfiguration,
        requestId: requestId, noStore: true, query: {..._applicationPayload(), 'action': action});
    return _mfaResult(() => SandIamCaptchaConfiguration.fromJson(_object(data), action));
  }

  Future<SandIamAuthResult> stepUpPassword(String password, {String? requestId}) async {
    if (password.isEmpty) throw _invalidMfaInput();
    final result = _authResult(await _request('POST', SandIamApi.stepUpPassword,
        authenticated: true, requestId: requestId, body: {'password': password}));
    if (result.stepUp != true || result.expiresIn == null) {
      throw const SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', '密码升级结果不正确', 200);
    }
    return result;
  }

  Future<SandIamAuthResult> startMfaStepUp({String? requestId}) async {
    final result = _authResult(await _request('POST', SandIamApi.startMfaStepUp,
        authenticated: true, requestId: requestId, body: <String, Object?>{}));
    if (result.mfaRequired != true || (result.challengeToken?.trim().isEmpty ?? true) ||
        (result.methods?.isEmpty ?? true) || result.expiresIn == null) {
      throw const SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', 'MFA 升级挑战不正确', 200);
    }
    return result;
  }

  Future<void> unlinkFederation(int bindingId, {String? requestId}) async {
    if (bindingId <= 0) throw _invalidMfaInput();
    await _request('POST', SandIamApi.unlinkFederation, authenticated: true,
        requestId: requestId, body: {'binding_id': bindingId});
  }

  Future<List<SandIamMfaFactor>> mfaFactors({String? requestId}) async {
    final data = await _request('GET', SandIamApi.mfaFactors,
        authenticated: true, requestId: requestId);
    return _mfaResult(() => _list(data).map(SandIamMfaFactor.fromJson).toList());
  }

  Future<SandIamTotpSetup> startTotp({String name = '',
      required String currentPassword, String? requestId}) async {
    if (currentPassword.isEmpty) throw _invalidMfaInput();
    final data = await _request('POST', SandIamApi.startTotp,
        authenticated: true, requestId: requestId,
        body: {'name': name, 'current_password': currentPassword});
    return _mfaResult(() => SandIamTotpSetup.fromJson(_object(data)));
  }

  Future<SandIamRecoveryCodes> confirmTotp({required int factorId,
      required String code, String? requestId}) async {
    _validateMfaFactor(factorId, 'totp');
    if (code.trim().isEmpty) throw _invalidMfaInput();
    final data = await _request('POST', SandIamApi.confirmTotp,
        authenticated: true, requestId: requestId,
        body: {'factor_id': factorId, 'code': code});
    return _mfaResult(() => SandIamRecoveryCodes.fromJson(_object(data), confirmation: true));
  }

  Future<void> renameMfaFactor({required int factorId, required String type,
      required String name, String? requestId}) async {
    _validateMfaFactor(factorId, type);
    await _request('POST', SandIamApi.renameMfaFactor, authenticated: true,
        requestId: requestId, body: {'factor_id': factorId, 'type': type, 'name': name});
  }

  Future<void> revokeMfaFactor({required int factorId, required String type,
      required String password, String? requestId}) async {
    _validateMfaFactor(factorId, type);
    if (password.isEmpty) throw _invalidMfaInput();
    await _request('POST', SandIamApi.revokeMfaFactor, authenticated: true,
        requestId: requestId, body: {'factor_id': factorId, 'type': type, 'password': password});
  }

  Future<SandIamRecoveryCodes> regenerateRecoveryCodes({
      required String password, String? requestId}) async {
    if (password.isEmpty) throw _invalidMfaInput();
    final data = await _request('POST', SandIamApi.regenerateRecoveryCodes,
        authenticated: true, requestId: requestId, body: {'password': password});
    return _mfaResult(() => SandIamRecoveryCodes.fromJson(_object(data)));
  }

  Future<void> requestVerification({
    required String identifier,
    required String channel,
    String? requestId,
  }) async {
    _validateRecoveryInput(identifier, channel);
    await _request('POST', SandIamApi.requestVerification,
        requestId: requestId, body: <String, Object?>{
          ..._applicationPayload(), 'identifier': identifier, 'channel': channel,
          'purpose': channel == 'email' ? 'email_verify' : 'phone_verify',
        });
  }

  Future<void> confirmVerification({
    required String identifier,
    required String channel,
    required String code,
    String? requestId,
  }) async {
    _validateRecoveryInput(identifier, channel);
    if (code.trim().isEmpty) {
      throw const SandIamException(
          'SAND_IAM_SDK_INVALID_ARGUMENT', '验证码不能为空', 0);
    }
    await _request('POST', SandIamApi.confirmVerification,
        requestId: requestId, body: <String, Object?>{
          ..._applicationPayload(), 'identifier': identifier, 'channel': channel,
          'code': code,
          'purpose': channel == 'email' ? 'email_verify' : 'phone_verify',
        });
  }

  Future<void> forgotPassword({
    required String identifier,
    required String channel,
    String? requestId,
  }) async {
    _validateRecoveryInput(identifier, channel);
    await _request('POST', SandIamApi.forgotPassword,
        requestId: requestId, body: <String, Object?>{
          ..._applicationPayload(), 'identifier': identifier, 'channel': channel,
        });
  }

  Future<void> resetPassword({
    required String identifier,
    required String channel,
    required String code,
    required String password,
    String? requestId,
  }) async {
    _validateRecoveryInput(identifier, channel);
    if (code.trim().isEmpty || password.isEmpty) {
      throw const SandIamException(
          'SAND_IAM_SDK_INVALID_ARGUMENT', '验证码和新密码不能为空', 0);
    }
    await _request('POST', SandIamApi.resetPassword,
        requestId: requestId, body: <String, Object?>{
          ..._applicationPayload(), 'identifier': identifier, 'channel': channel,
          'code': code, 'password': password,
        });
  }

  void _validateRecoveryInput(String identifier, String channel) {
    if (identifier.trim().isEmpty || !['email', 'phone'].contains(channel)) {
      throw const SandIamException(
          'SAND_IAM_SDK_INVALID_ARGUMENT', '账号和验证通道不正确', 0);
    }
  }

  Future<SandIamAuthResult> refresh(String refreshToken,
      {String? requestId}) async {
    if (refreshToken.isEmpty) {
      throw const SandIamException(
          'SAND_IAM_SDK_INVALID_ARGUMENT', '刷新令牌不能为空', 0);
    }
    return _authResult(await _request('POST', SandIamApi.refresh,
        requestId: requestId,
        body: <String, Object?>{'refresh_token': refreshToken}));
  }

  Future<SandIamProfile> profile({String? requestId}) async =>
      SandIamProfile.fromJson(_object(await _request('GET', SandIamApi.profile,
          authenticated: true, requestId: requestId)));

  Future<SandIamProfile> updateProfile(String displayName,
      {String? requestId}) async {
    if (displayName.trim().isEmpty) {
      throw const SandIamException(
          'SAND_IAM_SDK_INVALID_ARGUMENT', '显示名称不能为空', 0);
    }
    return SandIamProfile.fromJson(_object(await _request(
        'PATCH', SandIamApi.profile,
        authenticated: true,
        requestId: requestId,
        body: <String, Object?>{'display_name': displayName})));
  }

  Future<SandIamSecurityOverview> securityOverview({String? requestId}) async =>
      SandIamSecurityOverview.fromJson(_object(await _request(
          'GET', SandIamApi.security,
          authenticated: true, requestId: requestId)));

  Future<List<SandIamConnection>> connections({String? requestId}) async =>
      (_list(await _request('GET', SandIamApi.connections,
              authenticated: true, requestId: requestId)))
          .map(SandIamConnection.fromJson)
          .toList(growable: false);

  Future<List<SandIamSession>> sessions({String? requestId}) async =>
      (_list(await _request('GET', SandIamApi.sessions,
              authenticated: true, requestId: requestId)))
          .map(SandIamSession.fromJson)
          .toList(growable: false);

  Future<void> revokeSession(int sessionId, {String? requestId}) async {
    if (sessionId <= 0) {
      throw const SandIamException(
          'SAND_IAM_SDK_INVALID_ARGUMENT', '会话编号无效', 0);
    }
    await _request('POST', SandIamApi.revokeSession,
        authenticated: true,
        requestId: requestId,
        body: <String, Object?>{'id': sessionId});
  }

  Future<void> changePassword(String currentPassword, String newPassword,
      {String? requestId}) async {
    if (currentPassword.isEmpty || newPassword.isEmpty) {
      throw const SandIamException(
          'SAND_IAM_SDK_INVALID_ARGUMENT', '当前密码和新密码不能为空', 0);
    }
    await _request('POST', SandIamApi.changePassword,
        authenticated: true,
        requestId: requestId,
        body: <String, Object?>{
          'current_password': currentPassword,
          'new_password': newPassword
        });
  }

  Future<void> logout({String? requestId}) async {
    await _request('POST', SandIamApi.logout,
        authenticated: true,
        requestId: requestId,
        body: const <String, Object?>{});
  }

  Future<SandIamDiagnosticResult> diagnose({String? requestId}) async {
    String? issuer;
    String? applicationName;
    var discoveryAvailable = false;
    var experienceAvailable = false;
    try {
      final discovery = _object(await _request('GET', SandIamApi.discovery,
          requestId: requestId, rawProtocolResponse: true));
      issuer =
          discovery['issuer'] is String ? discovery['issuer']! as String : null;
      discoveryAvailable = issuer != null && issuer.isNotEmpty;
    } on SandIamException {
      discoveryAvailable = false;
    }
    try {
      final experience = _object(await _request('GET', SandIamApi.experience,
          requestId: requestId, query: _applicationPayload()));
      applicationName = experience['brand_name'] is String
          ? experience['brand_name']! as String
          : null;
      experienceAvailable =
          applicationName != null && applicationName.isNotEmpty;
    } on SandIamException {
      experienceAvailable = false;
    }
    return SandIamDiagnosticResult(
        discoveryAvailable: discoveryAvailable,
        experienceAvailable: experienceAvailable,
        issuer: issuer,
        applicationName: applicationName);
  }

  SandIamJson _applicationPayload() => <String, Object?>{
        'organization_code': organizationCode,
        'application_code': applicationCode
      };

  Future<Object?> _request(String method, String path,
      {bool authenticated = false,
      String? bearerToken,
      String? requestId,
      SandIamJson? body,
      SandIamJson? query,
      bool rawProtocolResponse = false,
      bool noStore = false}) async {
    final headers = <String, String>{
      'Accept': 'application/json',
      'X-Request-Id': _requestId(requestId)
    };
    if (bearerToken != null) {
      headers['Authorization'] = 'Bearer $bearerToken';
    } else if (authenticated) {
      final token = (await accessToken()).trim();
      if (token.isEmpty) {
        throw const SandIamException(
            'SAND_IAM_AUTHENTICATION_FAILED', '尚未登录或登录凭证已丢失', 401);
      }
      headers['Authorization'] = 'Bearer $token';
    }
    if (body != null) headers['Content-Type'] = 'application/json';
    if (noStore) headers['Cache-Control'] = 'no-store';
    final uri = baseUri.replace(
        path: '${baseUri.path}$path',
        queryParameters: query?.map((String key, Object? value) =>
            MapEntry<String, String>(key, value.toString())));
    final request = SandIamHttpRequest(
        method: method,
        uri: uri,
        headers: headers,
        body: body == null ? null : jsonEncode(body));
    SandIamHttpResponse response;
    try {
      response = await _transport(request).timeout(timeout);
    } on TimeoutException {
      throw const SandIamException(
          'SAND_IAM_SDK_TIMEOUT', '连接 SandIAM 超时，请检查地址和网络', 0);
    } on SandIamException {
      rethrow;
    } on Object {
      throw const SandIamException(
          'SAND_IAM_SDK_TRANSPORT_FAILED', '无法连接 SandIAM，请检查地址和网络', 0);
    }
    Object? decoded;
    try {
      decoded = jsonDecode(response.body);
    } on FormatException {
      throw SandIamException('SAND_IAM_SDK_INVALID_RESPONSE',
          'SandIAM 返回了无法解析的数据', response.status);
    }
    if (response.status < 200 || response.status >= 300) {
      throw _remoteError(decoded, response.status);
    }
    if (rawProtocolResponse) {
      return decoded;
    }
    if (decoded is! Map<String, Object?> || !decoded.containsKey('data')) {
      throw SandIamException('SAND_IAM_SDK_INVALID_RESPONSE',
          'SandIAM 返回的数据结构不正确', response.status);
    }
    return decoded['data'];
  }

  static Future<SandIamHttpResponse> _defaultTransport(
      SandIamHttpRequest request) async {
    final outgoing = http.Request(request.method, request.uri)
      ..followRedirects = false
      ..headers.addAll(request.headers);
    if (request.body != null) outgoing.body = request.body!;
    final streamed = await outgoing.send();
    return SandIamHttpResponse(
        status: streamed.statusCode,
        body: await streamed.stream.bytesToString());
  }
}

void _validateWorkloadInput(String secretOrContext, String serviceCode,
    String audience, List<String> actions) {
  final action = RegExp(r'^[a-z0-9][a-z0-9._-]{1,95}$');
  if (secretOrContext.isEmpty ||
      !RegExp(r'^[a-z0-9][a-z0-9._-]{1,63}$').hasMatch(serviceCode) ||
      audience.trim().isEmpty ||
      actions.isEmpty ||
      actions.any((String value) => !action.hasMatch(value))) {
    throw const SandIamException(
        'SAND_IAM_SDK_INVALID_ARGUMENT', '工作负载服务、受众和动作必须使用已声明的稳定代码', 0);
  }
}

Uri _baseUri(String value) {
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
        'SandIAM 地址必须使用 HTTPS；仅本机开发允许 HTTP', 0);
  }
  return uri;
}

String _requestId(String? value) {
  if (value != null && RegExp(r'^[A-Za-z0-9_.:-]{8,96}$').hasMatch(value)) {
    return value;
  }
  final random = Random.secure();
  return List<int>.generate(16, (_) => random.nextInt(256))
      .map((int byte) => byte.toRadixString(16).padLeft(2, '0'))
      .join();
}

String _derivedRequestId(String requestId, int index) =>
    '${requestId.substring(0, requestId.length > 88 ? 88 : requestId.length)}-v${index + 1}';

SandIamException _remoteError(Object? value, int status) {
  var message = 'SandIAM 请求失败';
  if (value is Map<String, Object?>) {
    if (value['msg'] is String) message = value['msg']! as String;
    if (value['message'] is String) message = value['message']! as String;
  }
  final match = RegExp(r'^(SAND_IAM_[A-Z0-9_]+)').firstMatch(message);
  return SandIamException(
      match?.group(1) ?? 'SAND_IAM_REQUEST_FAILED', message, status);
}

SandIamException _invalidMfaInput() => const SandIamException(
    'SAND_IAM_SDK_INVALID_ARGUMENT', 'MFA 参数不正确', 0);
void _validateMfaFactor(int id, String type) {
  if (id <= 0 || !['totp', 'passkey'].contains(type)) throw _invalidMfaInput();
}
T _mfaResult<T>(T Function() parse) {
  try { return parse(); }
  on FormatException {
    throw const SandIamException('SAND_IAM_SDK_INVALID_RESPONSE', 'MFA 返回结果不正确', 200);
  }
}

SandIamAuthResult _authResult(Object? value) {
  try {
    return SandIamAuthResult.fromJson(_object(value));
  } on FormatException {
    throw const SandIamException(
        'SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的认证结果不正确', 200);
  }
}

SandIamJson _object(Object? value) {
  if (value is! Map<String, Object?>) {
    throw const SandIamException(
        'SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的数据不是对象', 200);
  }
  return value;
}

List<SandIamJson> _list(Object? value) {
  if (value is! List<Object?> ||
      value.any((Object? item) => item is! Map<String, Object?>)) {
    throw const SandIamException(
        'SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的数据不是列表', 200);
  }
  return value.cast<SandIamJson>();
}

SandIamJson _passkeyProof(String challenge, String rawId, SandIamJson response, List<String> fields) {
  if (challenge.trim().isEmpty || rawId.trim().isEmpty) throw _invalidMfaInput();
  final proof = <String, Object?>{};
  for (final field in fields) {
    final value = response[field];
    if (value is! String || value.trim().isEmpty) throw _invalidMfaInput();
    proof[field] = value;
  }
  return proof;
}
