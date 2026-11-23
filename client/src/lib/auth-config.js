// Works out how to log in to the API under test from its parsed README (OpenAPI) document.
// Pure functions only, so they can be unit tested without React.

export const DEFAULT_HEADER_NAME = "Authorization";
export const DEFAULT_HEADER_FORMAT = "Bearer {token}";

const TOKEN_KEY = /^(access_?token|token|jwt|id_?token|auth_?token|bearer_?token)$/i;
const EXPIRES_KEY = /^(expires_?in|expires_?at|expires|exp)$/i;
const LOGIN_PATH = /log-?in|sign-?in|authenticate|auth\/token|oauth\/token|(^|\/)token(\.\w+)?$|(^|\/)sessions?(\.\w+)?$/i;
const NOT_LOGIN_PATH = /log-?out|sign-?out|register|sign-?up|refresh|reset|forgot|verify|password/i;
const LOGIN_SUMMARY = /log ?in|sign ?in|token|authenticat/i;
const SECRET_FIELD = /pass(word|wd|phrase)?|pwd|secret|^pin$|otp/i;

const isObject = (value) => Boolean(value) && typeof value === "object" && !Array.isArray(value);

function resolveRef(spec, schema, seen = new Set()) {
  if (!isObject(schema) || typeof schema.$ref !== "string") return schema;
  const ref = schema.$ref;
  if (!ref.startsWith("#/") || seen.has(ref)) return {};
  const target = ref
    .slice(2)
    .split("/")
    .reduce((value, key) => value?.[key.replace(/~1/g, "/").replace(/~0/g, "~")], spec);
  return resolveRef(spec, target, new Set([...seen, ref]));
}

/** Breadth-first search for a key; returns its dotted path (e.g. "data.token") or null. */
function findKeyInExample(value, pattern) {
  const queue = [{ value, path: [] }];
  while (queue.length) {
    const { value: node, path } = queue.shift();
    if (!isObject(node) || path.length > 6) continue;
    for (const [key, child] of Object.entries(node)) {
      if (pattern.test(key) && (typeof child === "string" || typeof child === "number")) return [...path, key].join(".");
    }
    for (const [key, child] of Object.entries(node)) queue.push({ value: child, path: [...path, key] });
  }
  return null;
}

function findKeyInSchema(spec, schema, pattern) {
  const queue = [{ schema, path: [] }];
  while (queue.length) {
    const { schema: raw, path } = queue.shift();
    const node = resolveRef(spec, raw);
    if (!isObject(node) || path.length > 6) continue;
    const properties = isObject(node.properties) ? node.properties : {};
    for (const [key, child] of Object.entries(properties)) {
      const type = resolveRef(spec, child)?.type;
      if (pattern.test(key) && (!type || type === "string" || type === "integer" || type === "number")) return [...path, key].join(".");
    }
    for (const [key, child] of Object.entries(properties)) queue.push({ schema: child, path: [...path, key] });
    for (const part of [...(node.allOf ?? []), ...(node.oneOf ?? []), ...(node.anyOf ?? [])]) queue.push({ schema: part, path });
  }
  return null;
}

const successResponses = (endpoint) =>
  (endpoint?.responses ?? []).filter((response) => /^2/.test(String(response.status)) || response.status === "default");

/** Path of `pattern` in the endpoint's documented success response (examples first, then schemas). */
function responseKeyPath(spec, endpoint, pattern) {
  for (const response of successResponses(endpoint)) {
    const found = findKeyInExample(response.example, pattern);
    if (found) return found;
  }
  for (const response of successResponses(endpoint)) {
    const found = findKeyInSchema(spec, response.schema, pattern);
    if (found) return found;
  }
  return null;
}

const pathOnly = (value) => {
  const text = String(value ?? "").trim();
  if (!/^https?:\/\//i.test(text)) return text;
  try {
    return new URL(text).pathname;
  } catch {
    return text;
  }
};
const normalizePath = (value) => `/${pathOnly(value).replace(/^\/+|\/+$/g, "")}`.toLowerCase();

/** The endpoint whose path matches `loginUrl` (relative or absolute), comparing path suffixes. */
function endpointForUrl(endpoints, loginUrl, method) {
  const wanted = normalizePath(loginUrl);
  const candidates = endpoints.filter((endpoint) => {
    const path = normalizePath(endpoint.path);
    return path === wanted || wanted.endsWith(path) || path.endsWith(wanted);
  });
  return candidates.find((endpoint) => !method || endpoint.method === method) ?? candidates[0] ?? null;
}

/**
 * Scores the endpoints and returns the one that most looks like a login call, or null.
 *
 * A login never needs a token, but a README often leaves it unmarked (a strict document treats every endpoint as
 * protected unless it says `Auth: false`). So a login-looking path whose body takes a password still counts, and a
 * public POST that takes a password counts even when its path does not say "login".
 */
export function findLoginEndpoint(spec, endpoints) {
  let best = null;
  let bestScore = 0;
  for (const endpoint of endpoints ?? []) {
    if (NOT_LOGIN_PATH.test(endpoint.path)) continue;
    const loginPath = LOGIN_PATH.test(endpoint.path);
    const takesPassword = endpoint.method === "POST" && loginFieldsOf(endpoint).some((field) => field.secret);
    if (endpoint.requiresAuth && !(loginPath && takesPassword)) continue;
    let score = 0;
    if (loginPath) score += 10;
    if (takesPassword) score += 10;
    if (LOGIN_SUMMARY.test(`${endpoint.summary ?? ""} ${endpoint.operationId ?? ""}`)) score += 3;
    if (endpoint.method === "POST") score += 3;
    if (responseKeyPath(spec, endpoint, TOKEN_KEY)) score += 6;
    if (score > bestScore) {
      best = endpoint;
      bestScore = score;
    }
  }
  return bestScore >= 12 ? best : null;
}

/** Header name and format from the security scheme the protected operations use. */
export function headerFromSpec(spec) {
  const schemes = isObject(spec?.components?.securitySchemes) ? spec.components.securitySchemes : {};
  let used = null;
  for (const pathItem of Object.values(spec?.paths ?? {})) {
    for (const operation of Object.values(pathItem ?? {})) {
      const requirement = (operation?.security ?? spec?.security ?? [])[0];
      if (isObject(requirement) && Object.keys(requirement).length) {
        used = Object.keys(requirement)[0];
        break;
      }
    }
    if (used) break;
  }
  const scheme = resolveRef(spec, schemes[used] ?? Object.values(schemes)[0]);
  if (isObject(scheme) && scheme.type === "apiKey" && scheme.in === "header" && scheme.name) {
    return { headerName: String(scheme.name), headerFormat: "{token}" };
  }
  return { headerName: DEFAULT_HEADER_NAME, headerFormat: DEFAULT_HEADER_FORMAT };
}

/** Form fields for the login request, taken from its documented body. */
function loginFieldsOf(endpoint) {
  const body = endpoint?.requestBody;
  if (!body) return [];
  const sample = isObject(body.sample) ? body.sample : {};
  const documented = body.fields?.length ? body.fields : Object.keys(sample).map((name) => ({ name, type: typeof sample[name] }));
  return documented
    .filter((field) => !field.file && field.type !== "object" && field.type !== "array")
    .map((field) => ({
      name: field.name,
      secret: SECRET_FIELD.test(field.name),
      required: Boolean(field.required),
      description: field.description ?? "",
      placeholder: SECRET_FIELD.test(field.name) || sample[field.name] == null ? "" : String(sample[field.name]),
      type: field.type === "integer" || field.type === "number" ? "number" : field.type === "boolean" ? "boolean" : "string",
    }));
}

/**
 * Everything the tester needs to log in, as far as the spec documents it.
 * `server` is the spec server matching the active environment (it may carry x-login-* settings).
 */
export function detectAuthConfig(spec, endpoints = [], server = null) {
  const authRequired = endpoints.some((endpoint) => endpoint.requiresAuth);
  const serverLogin = typeof server?.["x-login-url"] === "string" ? server["x-login-url"] : "";
  const serverMethod = typeof server?.["x-login-method"] === "string" ? server["x-login-method"].toUpperCase() : "";
  const endpoint = serverLogin ? endpointForUrl(endpoints, serverLogin, serverMethod) : findLoginEndpoint(spec, endpoints);
  const loginPath = serverLogin || endpoint?.path || "";
  const tokenPath = (typeof server?.["x-token-path"] === "string" && server["x-token-path"]) || responseKeyPath(spec, endpoint, TOKEN_KEY) || (loginPath ? "data.token" : "");
  const tokenParent = tokenPath.includes(".") ? tokenPath.slice(0, tokenPath.lastIndexOf(".")) : "";
  const expiresPath = endpoint ? responseKeyPath(spec, endpoint, EXPIRES_KEY) : null;
  return {
    authRequired,
    loginMethod: serverMethod || endpoint?.method || "POST",
    loginPath,
    tokenPath,
    // Only trust an expiry that sits next to the token, so unrelated fields are not picked up.
    expiresPath: expiresPath && (tokenParent ? expiresPath.startsWith(`${tokenParent}.`) : !expiresPath.includes(".")) ? expiresPath : "",
    ...headerFromSpec(spec),
    loginFields: loginFieldsOf(endpoint),
    loginSummary: endpoint?.summary ?? "",
    source: loginPath ? "readme" : "none",
  };
}

export function normalizeHeaderFormat(value) {
  const text = String(value ?? "").trim();
  if (!text || /^\*+$/.test(text)) return DEFAULT_HEADER_FORMAT;
  return text.includes("{token}") ? text : `${text} {token}`;
}

/** Applies the user's saved overrides (null when the README settings are used) on top of the detected ones. */
export function resolveAuthConfig(detected, overrides) {
  if (!overrides) return { ...detected, headerFormat: normalizeHeaderFormat(detected.headerFormat) };
  const pick = (key) => (typeof overrides[key] === "string" && overrides[key].trim() ? overrides[key].trim() : detected[key]);
  return {
    ...detected,
    authRequired: typeof overrides.authRequired === "boolean" ? overrides.authRequired : detected.authRequired,
    loginMethod: pick("loginMethod").toUpperCase(),
    loginPath: pick("loginPath"),
    tokenPath: pick("tokenPath"),
    headerName: pick("headerName") || DEFAULT_HEADER_NAME,
    headerFormat: normalizeHeaderFormat(pick("headerFormat")),
    source: "custom",
  };
}

/** The value at a dotted path such as "data.token" or "items[0].id" (undefined when any step is missing). */
export function valueAtPath(value, path) {
  return String(path ?? "")
    .replace(/\[(?:'([^']+)'|"([^"]+)"|(\d+))\]/g, ".$1$2$3")
    .split(".")
    .filter(Boolean)
    .reduce((current, key) => current?.[key], value);
}

/**
 * A value in a login response. A README's `Response data:` block documents only what is inside the
 * `{statusCode, message, data}` envelope, so the detected path ("token") is tried on the body first and then inside `data`.
 */
export function valueAtLoginPath(body, path) {
  const found = valueAtPath(body, path);
  return found != null ? found : valueAtPath(body?.data, path);
}

/** Reads the `exp` claim of a JWT as an ISO date, or null. */
export function jwtExpiry(token) {
  const part = String(token ?? "").split(".")[1];
  if (!part) return null;
  try {
    const json = JSON.parse(atob(part.replace(/-/g, "+").replace(/_/g, "/").padEnd(Math.ceil(part.length / 4) * 4, "=")));
    return typeof json.exp === "number" ? new Date(json.exp * 1000).toISOString() : null;
  } catch {
    return null;
  }
}

/** Turns an `expires_in`-style value (seconds, epoch seconds/ms, or a date string) into an ISO date. */
export function expiryFrom(value, now = Date.now()) {
  if (value == null || value === "") return null;
  if (typeof value === "number" || /^\d+$/.test(String(value))) {
    const number = Number(value);
    if (number > 1e12) return new Date(number).toISOString();
    if (number > 1e9) return new Date(number * 1000).toISOString();
    return new Date(now + number * 1000).toISOString();
  }
  const parsed = Date.parse(String(value));
  return Number.isNaN(parsed) ? null : new Date(parsed).toISOString();
}

export function tokenStatus(apiToken, now = Date.now()) {
  if (!apiToken?.token) return "none";
  if (apiToken.expiresAt && Date.parse(apiToken.expiresAt) <= now) return "expired";
  return "active";
}
