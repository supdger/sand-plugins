// Host dependency boundary for the pure SandIAM error-recovery contract test.
exports.isHttpError = (value) => Boolean(value && value.name === 'HttpError')
