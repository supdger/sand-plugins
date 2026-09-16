<?php

declare(strict_types=1);

namespace Sand\Iam\Sdk;

/** @phpstan-type ManagementPayload array<string,mixed> */
final class SandIamOnboardingOperation
{
    /** @param array<string,mixed> $manifest */
    public function __construct(
        public readonly array $manifest,
        public readonly string $requestId,
        public readonly ?string $previewHash = null,
    ) {
    }
}

final class SandIamRouteSyncOperation
{
    /** @param array<string,mixed> $manifest */
    public function __construct(
        public readonly array $manifest,
        public readonly string $previewHash,
        public readonly string $requestId,
        public readonly bool $disableMissing = false,
    ) {
    }
}

final class SandIamCredentialIssueInput
{
    public function __construct(
        public readonly int $workloadClientId,
        public readonly string $name,
        public readonly ?string $expireTime,
        public readonly string $requestId,
    ) {
    }
}

final class SandIamCredentialRotateInput
{
    public function __construct(
        public readonly int $credentialId,
        public readonly string $name,
        public readonly ?string $expireTime,
        public readonly string $requestId,
    ) {
    }
}

final class SandIamCredentialRevokeInput
{
    public function __construct(
        public readonly int $credentialId,
        public readonly string $requestId,
    ) {
    }
}

final class SandIamProviderPresetDraftInput
{
    /** @param list<string> $handoffReturnUris */
    public function __construct(
        public readonly string $code,
        public readonly string $clientId,
        public readonly string $redirectUri,
        public readonly array $handoffReturnUris,
        public readonly ?string $tenantId = null,
        public readonly ?string $requestId = null,
    ) {
    }
}

/**
 * A one-time credential value. It cannot be coerced to text or JSON; call
 * revealOnce() only at the handoff boundary and place it in a secret store.
 */
final class SandIamOneTimeSecret implements \JsonSerializable
{
    private ?string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function isAvailable(): bool
    {
        return $this->value !== null;
    }

    public function revealOnce(): string
    {
        if ($this->value === null) throw new SandIamException('SAND_IAM_SDK_SECRET_UNAVAILABLE', '一次性密钥已读取或当前响应不含密钥', 0);
        $value = $this->value;
        $this->value = null;
        return $value;
    }

    public function __toString(): string
    {
        throw new \LogicException('SandIAM 一次性密钥禁止转换为字符串或写入日志');
    }

    /** @return array{secret_available:bool} */
    public function jsonSerialize(): array
    {
        return ['secret_available' => $this->isAvailable()];
    }
}

final class SandIamCredentialResult implements \JsonSerializable
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public readonly array $metadata,
        public readonly bool $secretAvailable,
        private readonly ?SandIamOneTimeSecret $secret,
        public readonly bool $replayed,
    ) {
    }

    public function revealSecretOnce(): string
    {
        if ($this->secret === null) throw new SandIamException('SAND_IAM_SDK_SECRET_UNAVAILABLE', '本次响应不含一次性调用凭证', 0);
        return $this->secret->revealOnce();
    }

    public function __toString(): string
    {
        throw new \LogicException('SandIAM 一次性凭证结果禁止转换为字符串或写入日志');
    }

    /** @return array{metadata:array<string,mixed>,secret_available:bool,replayed:bool} */
    public function jsonSerialize(): array
    {
        return ['metadata' => $this->metadata, 'secret_available' => $this->secretAvailable, 'replayed' => $this->replayed];
    }
}
