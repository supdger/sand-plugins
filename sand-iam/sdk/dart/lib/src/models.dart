typedef SandIamJson = Map<String, Object?>;

final class SandIamWorkloadContext {
  const SandIamWorkloadContext({
    required this.contextId,
    this.context,
    this.expireTime,
    this.serviceCode,
    this.audience,
    this.actions,
    required this.claims,
  });

  factory SandIamWorkloadContext.issue(SandIamJson json) => SandIamWorkloadContext(
        context: _string(json, 'context'),
        contextId: _string(json, 'context_id'),
        expireTime: _string(json, 'expire_time'),
        claims: json,
      );

  factory SandIamWorkloadContext.verify(SandIamJson json) => SandIamWorkloadContext(
        contextId: _string(json, 'context_id'),
        serviceCode: _string(json, 'service_code'),
        audience: _string(json, 'audience'),
        actions: _stringList(json, 'actions'),
        claims: json,
      );

  final String? context;
  final String contextId;
  final String? expireTime;
  final String? serviceCode;
  final String? audience;
  final List<String>? actions;
  final SandIamJson claims;
}

final class SandIamDecision {
  const SandIamDecision({
    required this.allowed,
    required this.code,
    required this.policyIds,
    required this.scope,
    required this.applicationId,
    required this.identityId,
    required this.apiCode,
    required this.apiVersion,
    required this.resourceCode,
    required this.action,
    required this.operation,
    required this.riskLevel,
  });

  factory SandIamDecision.fromJson(SandIamJson json) {
    return SandIamDecision(
      allowed: _boolean(json, 'allowed'),
      code: _string(json, 'code'),
      policyIds: _integerList(json, 'policy_ids'),
      scope: _map(json, 'scope'),
      applicationId: _integer(json, 'application_id'),
      identityId: _integer(json, 'identity_id'),
      apiCode: _string(json, 'api_code'),
      apiVersion: _string(json, 'api_version'),
      resourceCode: _string(json, 'resource_code'),
      action: _string(json, 'action'),
      operation: _string(json, 'operation'),
      riskLevel: _string(json, 'risk_level'),
    );
  }

  final bool allowed;
  final String code;
  final List<int> policyIds;
  final SandIamJson scope;
  final int applicationId;
  final int identityId;
  final String apiCode;
  final String apiVersion;
  final String resourceCode;
  final String action;
  final String operation;
  final String riskLevel;
}

final class SandIamIdentity {
  const SandIamIdentity(
      {required this.id, required this.displayName, this.code});

  factory SandIamIdentity.fromJson(SandIamJson json) => SandIamIdentity(
        id: _integer(json, 'id'),
        displayName: _string(json, 'display_name'),
        code: _nullableString(json, 'code'),
      );

  final int id;
  final String? code;
  final String displayName;
}

final class SandIamAuthResult {
  const SandIamAuthResult({
    this.identity,
    this.accessToken,
    this.refreshToken,
    this.sessionId,
    this.accessExpireTime,
    this.refreshExpireTime,
    this.verificationRequired,
    this.mfaRequired,
    this.challengeToken,
  });

  factory SandIamAuthResult.fromJson(SandIamJson json) {
    final identityValue = json['identity'];
    return SandIamAuthResult(
      identity: identityValue == null
          ? null
          : SandIamIdentity.fromJson(_asMap(identityValue, 'identity')),
      accessToken: _nullableString(json, 'access_token'),
      refreshToken: _nullableString(json, 'refresh_token'),
      sessionId: _nullableInteger(json, 'session_id'),
      accessExpireTime: _nullableString(json, 'access_expire_time'),
      refreshExpireTime: _nullableString(json, 'refresh_expire_time'),
      verificationRequired: _nullableBoolean(json, 'verification_required'),
      mfaRequired: _nullableBoolean(json, 'mfa_required'),
      challengeToken: _nullableString(json, 'challenge_token'),
    );
  }

  final SandIamIdentity? identity;
  final String? accessToken;
  final String? refreshToken;
  final int? sessionId;
  final String? accessExpireTime;
  final String? refreshExpireTime;
  final bool? verificationRequired;
  final bool? mfaRequired;
  final String? challengeToken;
}

final class SandIamProfile {
  const SandIamProfile(
      {required this.identityId,
      required this.displayName,
      required this.organization,
      required this.application,
      this.createTime});

  factory SandIamProfile.fromJson(SandIamJson json) => SandIamProfile(
        identityId: _integer(json, 'identity_id'),
        displayName: _string(json, 'display_name'),
        organization: _namedCode(json, 'organization'),
        application: _namedCode(json, 'application'),
        createTime: _nullableString(json, 'create_time'),
      );

  final int identityId;
  final String displayName;
  final SandIamNamedCode organization;
  final SandIamNamedCode application;
  final String? createTime;
}

final class SandIamNamedCode {
  const SandIamNamedCode({required this.code, required this.name});
  final String code;
  final String name;
}

final class SandIamSecurityOverview {
  const SandIamSecurityOverview(
      {required this.passwordEnabled,
      required this.activeSessions,
      required this.totpFactors,
      required this.passkeys,
      required this.connectedAccounts});

  factory SandIamSecurityOverview.fromJson(SandIamJson json) =>
      SandIamSecurityOverview(
        passwordEnabled: _boolean(json, 'password_enabled'),
        activeSessions: _integer(json, 'active_sessions'),
        totpFactors: _integer(json, 'totp_factors'),
        passkeys: _integer(json, 'passkeys'),
        connectedAccounts: _integer(json, 'connected_accounts'),
      );

  final bool passwordEnabled;
  final int activeSessions;
  final int totpFactors;
  final int passkeys;
  final int connectedAccounts;
}

final class SandIamConnection {
  const SandIamConnection(
      {required this.bindingId,
      required this.providerName,
      required this.providerType,
      required this.accountHint,
      required this.sourceState,
      this.linkedTime});

  factory SandIamConnection.fromJson(SandIamJson json) => SandIamConnection(
        bindingId: _integer(json, 'binding_id'),
        providerName: _string(json, 'provider_name'),
        providerType: _string(json, 'provider_type'),
        accountHint: _string(json, 'account_hint'),
        sourceState: _string(json, 'source_state'),
        linkedTime: _nullableString(json, 'linked_time'),
      );

  final int bindingId;
  final String providerName;
  final String providerType;
  final String accountHint;
  final String sourceState;
  final String? linkedTime;
}

final class SandIamSession {
  const SandIamSession(
      {required this.id,
      required this.current,
      this.createTime,
      this.lastUsedTime,
      this.accessExpireTime,
      this.refreshExpireTime});

  factory SandIamSession.fromJson(SandIamJson json) => SandIamSession(
        id: _integer(json, 'id'),
        current: _boolean(json, 'current'),
        createTime: _nullableString(json, 'create_time'),
        lastUsedTime: _nullableString(json, 'last_used_time'),
        accessExpireTime: _nullableString(json, 'access_expire_time'),
        refreshExpireTime: _nullableString(json, 'refresh_expire_time'),
      );

  final int id;
  final bool current;
  final String? createTime;
  final String? lastUsedTime;
  final String? accessExpireTime;
  final String? refreshExpireTime;
}

final class SandIamDiagnosticResult {
  const SandIamDiagnosticResult(
      {required this.discoveryAvailable,
      required this.experienceAvailable,
      required this.issuer,
      required this.applicationName});

  final bool discoveryAvailable;
  final bool experienceAvailable;
  final String? issuer;
  final String? applicationName;

  bool get healthy => discoveryAvailable && experienceAvailable;
}

SandIamJson _asMap(Object? value, String field) {
  if (value is! Map<String, Object?>) throw FormatException('字段 $field 不是对象');
  return value;
}

String _string(SandIamJson json, String key) {
  final value = json[key];
  if (value is! String) throw FormatException('字段 $key 不是字符串');
  return value;
}

String? _nullableString(SandIamJson json, String key) {
  final value = json[key];
  if (value == null) return null;
  if (value is! String) throw FormatException('字段 $key 不是字符串');
  return value;
}

int _integer(SandIamJson json, String key) {
  final value = json[key];
  if (value is! int) throw FormatException('字段 $key 不是整数');
  return value;
}

int? _nullableInteger(SandIamJson json, String key) {
  final value = json[key];
  if (value == null) return null;
  if (value is! int) throw FormatException('字段 $key 不是整数');
  return value;
}

bool _boolean(SandIamJson json, String key) {
  final value = json[key];
  if (value is! bool) throw FormatException('字段 $key 不是布尔值');
  return value;
}

bool? _nullableBoolean(SandIamJson json, String key) {
  final value = json[key];
  if (value == null) return null;
  if (value is! bool) throw FormatException('字段 $key 不是布尔值');
  return value;
}

List<int> _integerList(SandIamJson json, String key) {
  final value = json[key];
  if (value is! List<Object?> || value.any((Object? item) => item is! int)) {
    throw FormatException('字段 $key 不是整数列表');
  }
  return value.cast<int>();
}

List<String> _stringList(SandIamJson json, String key) {
  final value = json[key];
  if (value is! List<Object?> || value.any((Object? item) => item is! String)) {
    throw FormatException('字段 $key 不是字符串列表');
  }
  return value.cast<String>();
}

SandIamJson _map(SandIamJson json, String key) => _asMap(json[key], key);

SandIamNamedCode _namedCode(SandIamJson json, String key) {
  final value = _map(json, key);
  return SandIamNamedCode(
      code: _string(value, 'code'), name: _string(value, 'name'));
}
