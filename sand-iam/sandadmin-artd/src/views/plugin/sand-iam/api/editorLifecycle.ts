/** Monotonically invalidates stale editor initialization work. */
export function createSandIamEditorSessionTracker(): {
  next(): number
  isCurrent(session: number): boolean
} {
  let current = 0

  return {
    next(): number {
      current += 1
      return current
    },
    isCurrent(session: number): boolean {
      return session === current
    }
  }
}

/**
 * Determines which hydration cleanup effects still belong to the completed
 * editor request. A stale request must not clear the loading state of the
 * request that replaced it.
 */
export function planSandIamEditorHydrationFinish(
  hydratingSession: number | null,
  completedSession: number,
  completedSessionIsActive: boolean
): {
  clearHydration: boolean
  captureDependencyValues: boolean
} {
  const ownsHydration = hydratingSession === completedSession

  return {
    clearHydration: ownsHydration,
    captureDependencyValues: ownsHydration && completedSessionIsActive
  }
}
