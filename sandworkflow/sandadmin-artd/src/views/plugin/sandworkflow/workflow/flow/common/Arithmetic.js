const tokens = /\d+(?:\.\d+)?|[()+\-*/]/g
const precedence = { '+': 1, '-': 1, '*': 2, '/': 2 }

export function evaluateArithmetic(expression) {
  const normalized = String(expression).replace(/\s+/g, '')
  const values = normalized.match(tokens)
  if (!values || values.join('') !== normalized) throw new Error('Invalid expression')
  const output = [], operators = []
  for (const token of values) {
    if (/^\d/.test(token)) output.push(Number(token))
    else if (token === '(') operators.push(token)
    else if (token === ')') { while (operators.at(-1) && operators.at(-1) !== '(') output.push(operators.pop()); if (operators.pop() !== '(') throw new Error('Unbalanced parentheses') }
    else { while (precedence[operators.at(-1)] >= precedence[token]) output.push(operators.pop()); operators.push(token) }
  }
  while (operators.length) { const token = operators.pop(); if (token === '(') throw new Error('Unbalanced parentheses'); output.push(token) }
  const stack = []
  for (const token of output) { if (typeof token === 'number') stack.push(token); else { const right = stack.pop(), left = stack.pop(); if (!Number.isFinite(left) || !Number.isFinite(right)) throw new Error('Invalid operands'); stack.push(token === '+' ? left + right : token === '-' ? left - right : token === '*' ? left * right : left / right) } }
  if (stack.length !== 1 || !Number.isFinite(stack[0])) throw new Error('Invalid result')
  return stack[0]
}
