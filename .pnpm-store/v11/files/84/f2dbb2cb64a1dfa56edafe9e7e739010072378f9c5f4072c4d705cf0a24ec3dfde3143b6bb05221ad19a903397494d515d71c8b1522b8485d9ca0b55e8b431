const { execFileSync } = require('child_process')

const SECRET_TOOL_BIN = 'secret-tool'
const SERVICE = 'dotenvx'

function attributes (publicKey) {
  return ['service', SERVICE, 'public-key', publicKey]
}

function get (publicKey) {
  try {
    return execFileSync(SECRET_TOOL_BIN, ['lookup', ...attributes(publicKey)], {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe']
    }).trim() || null
  } catch {
    throw new Error('failed to read private key from Linux Secret Service')
  }
}

function set (publicKey, privateKey, label) {
  try {
    execFileSync(SECRET_TOOL_BIN, ['store', `--label=${label}`, ...attributes(publicKey)], {
      input: privateKey,
      encoding: 'utf8',
      stdio: ['pipe', 'ignore', 'pipe']
    })
  } catch {
    throw new Error('failed to save private key to Linux Secret Service')
  }
}

module.exports = {
  get,
  set,
  delete (publicKey) {
    try {
      execFileSync(SECRET_TOOL_BIN, ['clear', ...attributes(publicKey)], {
        stdio: ['ignore', 'ignore', 'pipe']
      })
    } catch {
      throw new Error('failed to delete private key from Linux Secret Service')
    }
  }
}
