/**
 * POST /api/sand-iam/v1/invitations/accept 只消费 token/username/display_name/password。
 * 成功响应只留 display_name，不把 identity.id 当成会话。
 */

export interface SandIamInvitationAcceptResult {
  readonly displayName: string;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

export function invitationTokenLooksValid(token: string): boolean {
  return /^siam_inv_[A-Za-z0-9_-]{43}$/.test(token);
}

export function parseInvitationAcceptResult(
  value: unknown,
): SandIamInvitationAcceptResult | null {
  const payload = isRecord(value) && "data" in value ? value.data : value;
  if (!isRecord(payload)) return null;
  const displayName = payload.display_name;
  if (typeof displayName !== "string" || displayName.trim() === "") return null;
  return { displayName: displayName.trim() };
}
