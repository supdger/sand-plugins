typedef SandIamJson = Map<String, Object?>;

final class SandIamInvitationIdentity {
  const SandIamInvitationIdentity({required this.id, required this.displayName});
  final int id;
  final String displayName;
}

final class SandIamCaptchaWidget {
  const SandIamCaptchaWidget({required this.siteKey, required this.action, required this.applicationBinding});
  String get kind => 'turnstile';
  final String siteKey;
  final String action;
  final String applicationBinding;
}

final class SandIamCaptchaConfiguration {
  const SandIamCaptchaConfiguration._(this.required, this.available, this.widget);
  final bool required;
  final bool? available;
  final SandIamCaptchaWidget? widget;

  factory SandIamCaptchaConfiguration.fromJson(SandIamJson json, String action) {
    if (json['required'] == false) return const SandIamCaptchaConfiguration._(false, null, null);
    if (json['required'] != true) throw const FormatException('Invalid captcha requirement');
    if (json['available'] == false) return const SandIamCaptchaConfiguration._(true, false, null);
    final widget = json['widget'];
    if (json['available'] != true || widget is! SandIamJson || widget['kind'] != 'turnstile' ||
        widget['action'] != action || !['login', 'register'].contains(action)) {
      throw const FormatException('Invalid captcha widget');
    }
    final key = widget['site_key'];
    final binding = widget['application_binding'];
    final pattern = RegExp(r'^[A-Za-z0-9_-]{1,255}$');
    if (key is! String || binding is! String || key.trim() != key || binding.trim() != binding ||
        !pattern.hasMatch(key) || !pattern.hasMatch(binding)) {
      throw const FormatException('Invalid captcha binding');
    }
    return SandIamCaptchaConfiguration._(true, true,
        SandIamCaptchaWidget(siteKey: key, action: action, applicationBinding: binding));
  }
}

final class SandIamMfaFactor {
  SandIamMfaFactor.fromJson(SandIamJson json)
      : id = _integer(json, 'id'), type = _string(json, 'type'),
        name = _string(json, 'name'), status = _integer(json, 'status'),
        createTime = _nullableString(json, 'create_time'),
        lastUsedTime = _nullableString(json, 'last_used_time') {
    if (id <= 0 || !['totp', 'passkey'].contains(type)) throw const FormatException('Invalid MFA factor');
  }
  final int id;
  final String type;
  final String name;
  final int status;
  final String? createTime;
  final String? lastUsedTime;
}

final class SandIamTotpSetup {
  SandIamTotpSetup.fromJson(SandIamJson json)
      : factorId = _integer(json, 'factor_id'),
        secretAvailable = _nullableBoolean(json, 'secret_available'),
        secret = json['secret_available'] == false ? null : _string(json, 'secret'),
        otpauthUri = json['secret_available'] == false ? null : _string(json, 'otpauth_uri') {
    if (factorId <= 0 || (secretAvailable != false && (secret!.isEmpty || otpauthUri!.isEmpty))) throw const FormatException('Invalid TOTP setup');
  }
  final int factorId;
  final String? secret;
  final String? otpauthUri;
  final bool? secretAvailable;
}

final class SandIamRecoveryCodes {
  SandIamRecoveryCodes.fromJson(SandIamJson json, {bool confirmation = false})
      : recoveryCodes = json['secret_available'] == false ? null : _stringList(json, 'recovery_codes'),
        secretAvailable = _nullableBoolean(json, 'secret_available'),
        enabled = _nullableBoolean(json, 'enabled') {
    if ((secretAvailable != false && (recoveryCodes!.isEmpty || recoveryCodes!.any((code) => code.isEmpty))) ||
        (confirmation && enabled != true)) {
      throw const FormatException('Invalid recovery codes');
    }
  }
  final List<String>? recoveryCodes;
  final bool? enabled;
  final bool? secretAvailable;
}

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

  factory SandIamWorkloadContext.issue(SandIamJson json) =>
      SandIamWorkloadContext(
        context: _string(json, 'context'),
        contextId: _string(json, 'context_id'),
        expireTime: _string(json, 'expire_time'),
        claims: json,
      );

  factory SandIamWorkloadContext.verify(SandIamJson json) =>
      SandIamWorkloadContext(
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
    this.methods,
    this.expiresIn,
    this.publicKey,
    this.stepUp,
  });

  factory SandIamAuthResult.fromJson(SandIamJson json) {
    final identityValue = json['identity'];
    final expiresIn = _nullableInteger(json, 'expires_in');
    if (expiresIn != null && expiresIn <= 0) {
      throw const FormatException('expires_in must be positive');
    }
    final methods =
        json['methods'] == null ? null : _stringList(json, 'methods');
    if (methods != null && methods.any((method) => method.isEmpty)) {
      throw const FormatException('methods must contain nonempty strings');
    }
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
      methods: methods,
      stepUp: _nullableBoolean(json, 'step_up'),
      expiresIn: expiresIn,
      publicKey: json['public_key'] == null
          ? null
          : _asMap(json['public_key'], 'public_key'),
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
  final List<String>? methods;
  final int? expiresIn;
  final SandIamJson? publicKey;
  final bool? stepUp;
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

/// Serialized WebAuthn options; platform code owns the ceremony and encoding.
final class SandIamPasskeyOptions {
  const SandIamPasskeyOptions({required this.challengeToken, required this.publicKey});
  final String challengeToken;
  final SandIamJson publicKey;

  factory SandIamPasskeyOptions.fromJson(SandIamJson json) {
    final challenge = json['challenge_token'];
    final publicKey = json['public_key'];
    if (challenge is! String || challenge.trim().isEmpty || publicKey is! SandIamJson) {
      throw const FormatException('Invalid passkey options');
    }
    return SandIamPasskeyOptions(challengeToken: challenge, publicKey: publicKey);
  }
}
