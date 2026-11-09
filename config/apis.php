<?php
/**
 * External API keys & settings.
 *
 * Keys are resolved in this order:
 *   1) config/apis.local.php         <- written by the in-app Integrations
 *       (Administrator > Menu > Integrations) screen. NOT committed; secret.
 *   2) server environment variables  GOOGLE_MAPS_API_KEY / GEMINI_API_KEY
 *                                     / GEMINI_MODEL / GROQ_API_KEY /
 *                                     GROQ_MODEL / CHAT_PROVIDER
 *
 * GOOGLE_MAPS_API_KEY - Google Maps JavaScript API key for the Map screen
 *                       (all districts). Empty => built-in keyless Leaflet map.
 * CHAT_PROVIDER       - 'groq' or 'gemini' (which AI provider the chat
 *                       assistant prefers). Defaults to whichever is enabled.
 * GROQ_API_KEY        - Groq key for the chat assistant. Empty => unused; the
 *                       app falls back to Gemini, then the built-in assistant.
 * GROQ_MODEL          - Groq model used by the chat assistant.
 * GEMINI_API_KEY      - Google AI (Gemini) key for the chat assistant so it
 *                       can answer anything. Empty => built-in offline
 *                       assistant is used automatically.
 * GEMINI_MODEL        - Gemini model name used by the chat assistant.
 */
$__apisLocalFile = __DIR__ . '/apis.local.php';
$__apisCfg = [];
if (is_file($__apisLocalFile)) {
    $__loaded = require $__apisLocalFile;
    if (is_array($__loaded)) $__apisCfg = $__loaded;
}
$__apisVal = function (string $name) use ($__apisCfg): string {
    if (isset($__apisCfg[$name]) && $__apisCfg[$name] !== '') return (string)$__apisCfg[$name];
    $v = getenv($name);
    return ($v !== false && $v !== '') ? (string)$v : '';
};
if (!defined('GOOGLE_MAPS_API_KEY')) define('GOOGLE_MAPS_API_KEY', $__apisVal('GOOGLE_MAPS_API_KEY'));
if (!defined('GROQ_API_KEY'))         define('GROQ_API_KEY', $__apisVal('GROQ_API_KEY'));
if (!defined('GEMINI_API_KEY'))       define('GEMINI_API_KEY', $__apisVal('GEMINI_API_KEY'));
$__apisModel = $__apisVal('GROQ_MODEL');
if (!defined('GROQ_MODEL'))           define('GROQ_MODEL', $__apisModel !== '' ? $__apisModel : 'qwen/qwen3.8-27b');
$__apisModel = $__apisVal('GEMINI_MODEL');
if (!defined('GEMINI_MODEL'))         define('GEMINI_MODEL', $__apisModel !== '' ? $__apisModel : 'gemini-2.5-flash');
$__apisPref = $__apisVal('CHAT_PROVIDER');
if (!defined('CHAT_PROVIDER'))        define('CHAT_PROVIDER', $__apisPref !== '' ? $__apisPref : '');
unset($__apisLocalFile, $__apisCfg, $__apisVal, $__apisModel, $__apisPref);