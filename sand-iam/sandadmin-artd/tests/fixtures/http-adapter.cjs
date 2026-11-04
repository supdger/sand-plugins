// Host HTTP client boundary stub for pure SandIAM api contract tests.
exports.default = {
  get: async () => { throw new Error('network stubbed') },
  post: async () => { throw new Error('network stubbed') },
}
exports.get = exports.default.get
exports.post = exports.default.post
