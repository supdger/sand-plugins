/**
 * 向导行为/视口入口在模块边界替换 `@/utils/http/error`。
 * 与真实 HttpError 同样用 class + instanceof，供 describeSandIamError 识别 HTTP 状态。
 */
export class HttpError extends Error {
  public readonly code: number
  public readonly data?: unknown
  public readonly url?: string
  public readonly method?: string

  constructor(
    message: string,
    code: number,
    options?: {
      data?: unknown
      url?: string
      method?: string
    }
  ) {
    super(message)
    this.name = 'HttpError'
    this.code = code
    this.data = options?.data
    this.url = options?.url
    this.method = options?.method
  }
}

/**
 * 仅识别本模块抛出的 HttpError，避免把普通 Error 当成已解释的后台拒绝。
 */
export function isHttpError(error: unknown): error is HttpError {
  return error instanceof HttpError
}
