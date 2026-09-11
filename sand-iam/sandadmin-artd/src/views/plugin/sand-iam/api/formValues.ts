/** Text inputs must not reinterpret a normalized numeric reference as a code. */
export function sandIamTextFieldValue(value: unknown): string {
  return typeof value === 'string' ? value : ''
}
