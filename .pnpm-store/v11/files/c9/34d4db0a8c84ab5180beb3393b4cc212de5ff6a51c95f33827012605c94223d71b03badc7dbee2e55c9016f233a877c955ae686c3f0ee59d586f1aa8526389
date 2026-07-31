const { execFileSync } = require('child_process')

const SECURITY_BIN = '/usr/bin/security'
const SERVICE = 'dotenvx'

module.exports = {
  get (key) {
    return execFileSync(SECURITY_BIN, ['find-generic-password', '-s', SERVICE, '-a', key, '-w'], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim()
  },

  set (key, value, label) {
    try {
      execFileSync(SECURITY_BIN, ['add-generic-password', '-U', '-s', SERVICE, '-a', key, '-l', label, '-w', value], { stdio: 'ignore' })
    } catch {
      throw new Error('failed to save private key to macOS Keychain')
    }
  },

  delete (key) {
    try {
      execFileSync(SECURITY_BIN, ['delete-generic-password', '-s', SERVICE, '-a', key], { stdio: 'ignore' })
    } catch {
      throw new Error('failed to delete private key from macOS Keychain')
    }
  }
}
