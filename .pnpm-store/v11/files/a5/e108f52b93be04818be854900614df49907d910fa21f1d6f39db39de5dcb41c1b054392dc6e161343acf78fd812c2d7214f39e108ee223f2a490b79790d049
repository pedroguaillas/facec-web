const { parse, parseSync } = require('@dotenvx/primitives')
const isNetworkError = require('./isNetworkError')

const SERVER_SIDE_DECRYPTION_REQUIRED = 'SERVER_SIDE_DECRYPTION_REQUIRED'

function decryptOptions (error) {
  const meta = error.meta || {}

  return {
    publicKey: meta.public_key,
    grantToken: meta.grant_token,
    error
  }
}

function parseOptionsWithoutProvider (options) {
  return {
    ...options,
    provider: null,
    decryptor: null
  }
}

async function parseWithDecryptor (src, options = {}) {
  try {
    return await parse(src, options)
  } catch (error) {
    if (error.code !== SERVER_SIDE_DECRYPTION_REQUIRED || typeof options.decryptor !== 'function') {
      throw error
    }

    try {
      const result = await options.decryptor(src, decryptOptions(error))
      return await parse(result.src, parseOptionsWithoutProvider(options))
    } catch (decryptorError) {
      if (isNetworkError(decryptorError)) {
        return await parse(src, parseOptionsWithoutProvider(options))
      }

      throw decryptorError
    }
  }
}

parseWithDecryptor.sync = function parseWithDecryptorSync (src, options = {}) {
  try {
    return parseSync(src, options)
  } catch (error) {
    if (error.code !== SERVER_SIDE_DECRYPTION_REQUIRED || typeof options.decryptor !== 'function') {
      throw error
    }

    try {
      const result = options.decryptor(src, decryptOptions(error))
      return parseSync(result.src, parseOptionsWithoutProvider(options))
    } catch (decryptorError) {
      if (isNetworkError(decryptorError)) {
        return parseSync(src, parseOptionsWithoutProvider(options))
      }

      throw decryptorError
    }
  }
}

module.exports = parseWithDecryptor
