const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {runInNewContext} = require('node:vm');
const source = readFileSync(require('node:path').join(__dirname, '../Assets/js/tech-provider.js'), 'utf8');

function harness() {
    const handlers = {}; let submits = 0, callback;
    const form = {dataset: {app: '12345', version: 'v26.0', config: '123456'}, elements: {code: {}, waba_id: {}, phone_id: {}}, reportValidity: () => true, submit: () => submits++};
    const button = {addEventListener: (_, handler) => { handlers.click = handler; }};
    const result = {};
    const FB = {init: () => {}, login: cb => { callback = cb; }};
    runInNewContext(source, {window: {FB, addEventListener: (event, fn) => { handlers[event] = fn; }}, FB, document: {getElementById: id => ({'meta-provider-signup': form, 'meta-provider-launch': button, 'meta-provider-result': result})[id]}});
    return {form, handlers, submits: () => submits, code: () => callback({authResponse: {code: 'single-use-code'}}), event: (origin = 'https://www.facebook.com', event = 'FINISH') => handlers.message({origin, data: JSON.stringify({type: 'WA_EMBEDDED_SIGNUP', event, data: {waba_id: '123456', phone_number_id: '987654'}})})};
}
test('joins SDK callback and session event, submits once', () => { const h = harness(); h.handlers.click(); h.code(); assert.equal(h.submits(), 0); h.event(); h.event(); h.code(); assert.equal(h.submits(), 1); assert.equal(h.form.elements.phone_id.value, '987654'); });
test('accepts reverse arrival order', () => { const h = harness(); h.handlers.click(); h.event(); assert.equal(h.submits(), 0); h.code(); assert.equal(h.submits(), 1); });
test('rejects lookalike domains and unsolicited events', () => { const h = harness(); h.event(); h.handlers.click(); h.code(); h.event('https://evilfacebook.com'); h.event('https://www.facebook.com.evil.test'); assert.equal(h.submits(), 0); });
test('cancellation prevents a late callback submitting', () => { const h = harness(); h.handlers.click(); h.event('https://www.facebook.com', 'CANCEL'); h.code(); h.event(); assert.equal(h.submits(), 0); });
