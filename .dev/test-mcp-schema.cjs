const { execFileSync } = require('node:child_process');
const path = require('node:path');
const Ajv = require('./schema-check/node_modules/ajv');
const Ajv2020 = require('./schema-check/node_modules/ajv/dist/2020');
const addFormats = require('./schema-check/node_modules/ajv-formats');

async function main() {
    const sources = {
        core: 'https://raw.githubusercontent.com/modelcontextprotocol/modelcontextprotocol/main/schema/2026-07-28/schema.json',
        tasks: 'https://raw.githubusercontent.com/modelcontextprotocol/ext-tasks/main/schema/2026-07-28/schema.json'
    };
    const validators = {};
    for (const [name, url] of Object.entries(sources)) {
        const response = await fetch(url);
        if (!response.ok) throw new Error(`${url}: ${response.status}`);
        const schema = await response.json();
        const Validator = (schema.$schema || '').includes('2020-12') ? Ajv2020 : Ajv;
        const validator = new Validator({ strict: false, allErrors: true, validateFormats: false });
        addFormats(validator);
        validator.addSchema(schema, name);
        validators[name] = { validator, prefix: schema.$defs ? '$defs' : 'definitions' };
    }
    const fixtures = JSON.parse(execFileSync('php', [path.join(__dirname, 'test-mcp-protocol.php'), '--schema'], { encoding: 'utf8' }));
    for (const fixture of fixtures) {
        const { validator, prefix } = validators[fixture.source];
        const validate = validator.compile({ $ref: `${fixture.source}#/${prefix}/${fixture.schema}` });
        if (!validate(fixture.value)) {
            throw new Error(`${fixture.schema}: ${JSON.stringify(validate.errors)}`);
        }
        console.log(`PASS official schema ${fixture.schema}`);
    }
}
main().catch(error => { console.error(error.message); process.exitCode = 1; });