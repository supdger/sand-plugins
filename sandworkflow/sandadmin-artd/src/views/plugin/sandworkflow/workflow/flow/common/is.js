export function isNumber(value) {
  return Object.prototype.toString.call(value) === '[object Number]' && !Number.isNaN(value)
}
