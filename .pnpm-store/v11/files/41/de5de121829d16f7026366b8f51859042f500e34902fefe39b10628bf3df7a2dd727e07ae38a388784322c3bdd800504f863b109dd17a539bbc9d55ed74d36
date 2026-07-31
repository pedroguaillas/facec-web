function normalizeDotenvConfigIgnore (
  /** @type {import('../main').DotenvConfigOptions} */ options
) {
  const configIgnore = (process.env.DOTENV_CONFIG_IGNORE || '')
    .split(',')
    .map(code => code.trim())
    .filter(Boolean)

  if (configIgnore.length < 1) return options

  return {
    ...options,
    ignore: [...(options.ignore || []), ...configIgnore]
  }
}

module.exports = normalizeDotenvConfigIgnore
