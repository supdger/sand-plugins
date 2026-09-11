import 'models.dart';

abstract final class SandIamWorkloadErrorCode {
  static const networkForbidden = 'SAND_IAM_SERVICE_NETWORK_FORBIDDEN';
  static const factsUnverified = 'SAND_IAM_INVOCATION_FACTS_UNVERIFIED';
  static const dataClassForbidden = 'SAND_IAM_DATA_CLASS_FORBIDDEN';
  static const quotaExceeded = 'SAND_IAM_SERVICE_QUOTA_EXCEEDED';
  static const idempotencyConflict = 'SAND_IAM_IDEMPOTENCY_CONFLICT';
}

class SandIamException implements Exception {
  const SandIamException(this.code, this.message, this.status);

  final String code;
  final String message;
  final int status;

  @override
  String toString() => '$code: $message';
}

final class SandIamDeniedException extends SandIamException {
  SandIamDeniedException(this.decision)
      : super(decision.code, '当前账号没有执行此操作的权限', 403);

  final SandIamDecision decision;
}
