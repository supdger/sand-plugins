/**
 * 向导行为/视口入口在模块边界替换 `@/hooks/core/useAuth`。
 * 默认放行全部权限；测试按权限码名单拒绝，不经过 Pinia 用户信息。
 */
const deniedPermissions = new Set<string>()

/**
 * 设置本轮拒绝的权限码。传入空列表表示当前账号具备向导所需权限。
 */
export function configureWizardAuth(denied: readonly string[]): void {
  deniedPermissions.clear()
  for (const item of denied) deniedPermissions.add(item)
}

export function useAuth(): { hasAuth: (auth: string) => boolean } {
  return {
    hasAuth(auth: string): boolean {
      return !deniedPermissions.has(auth)
    }
  }
}
