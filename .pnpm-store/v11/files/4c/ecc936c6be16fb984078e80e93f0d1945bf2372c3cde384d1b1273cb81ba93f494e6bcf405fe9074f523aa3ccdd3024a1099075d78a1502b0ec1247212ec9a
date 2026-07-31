const fs = require('fs')
const { scan } = require('@dotenvx/primitives')

const Errors = require('./errors')
const validate = require('./validate')

function validateEnvExample (env = process.env, options = {}) {
  const filepath = options.filepath || '.env.example'

  if (!fs.existsSync(filepath)) {
    return new Errors().missingEnvExample()
  }

  const exampleSrc = fs.readFileSync(filepath, 'utf8')
  const { parsed: example, comments } = scan(exampleSrc)
  const validation = validate(example, env, { comments })

  if (!validation.valid) {
    const message = validation.errors.map(error => error.message).join('; ')
    return new Errors({ message }).validationFailed()
  }
}

module.exports = validateEnvExample
