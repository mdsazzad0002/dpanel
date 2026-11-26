/*
 * Every bn namespace as one module, so the build gives this language a chunk
 * of its own instead of putting all five languages in the entry bundle
 * (see resources/js/i18n/index.js).
 */
const files = import.meta.glob('./*.json', { eager: true });

const messages = {};
Object.entries(files).forEach(([path, module]) => {
    const namespace = path.match(/\.\/([^/]+)\.json$/)[1];
    messages[namespace] = module.default ?? module;
});

export default messages;
