// Thin client for the PHP API. Every function returns normalized API data and throws ApiError on failure.

import { API_BASE } from "./base.js";
import { diffSpecs } from "./lib/openapi_diff.js";
import { validateOpenApiDocument } from "./lib/openapi_import.js";
import { baseUrlError } from "./lib/base-url.js";
import { expiryFrom, jwtExpiry, normalizeHeaderFormat } from "./lib/auth-config.js";
import { operationsForSpec } from "./lib/openapi-endpoints.js";
import { suggestNextVersion } from "./lib/version-number.js";

const PROJECT_SETTINGS_KEY = "apvp.project-settings.";
const AUTH_SETTING_KEYS = ["authRequired", "loginMethod", "loginPath", "tokenPath", "headerName", "headerFormat"];

export class ApiError extends Error {
  constructor(status, message, code, details) {
    super(message);
    this.status = status;
    this.code = code;
    this.details = details;
  }
}

function toQueryString(query) {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value === undefined || value === null || value === "") continue;
    for (const item of Array.isArray(value) ? value : [value]) params.append(key, String(item));
  }
  const text = params.toString();
  return text ? `?${text}` : "";
}

async function request(method, path, { query, json, form } = {}) {
  const headers = {};
  let body;
  if (form) {
    body = form;
  } else if (json !== undefined) {
    headers["content-type"] = "application/json";
    body = JSON.stringify(json);
  }

  let response;
  try {
    // no-store: version data changes with every save, so a cached GET could show an old version list.
    response = await fetch(`${API_BASE}${path}${toQueryString(query)}`, { method, headers, body, cache: "no-store" });
  } catch {
    throw new ApiError(0, "Cannot reach the API server. Check the API URL and network connection.", "NETWORK_ERROR");
  }

  if (response.status === 204) return null;
  const text = await response.text();
  let payload = null;
  try {
    payload = text ? JSON.parse(text) : null;
  } catch {
    throw new ApiError(response.status, `The API returned a non-JSON response (${response.status}).`, "INVALID_RESPONSE");
  }

  const responseCode = Number(payload?.statusCode ?? response.status);
  const responseData = payload?.data ?? null;
  if (!response.ok || responseCode >= 400) {
    throw new ApiError(responseCode, payload?.message || `Request failed (${responseCode})`, undefined, responseData);
  }
  return responseData;
}

const get = (path, query) => request("GET", path, { query });
const post = (path, json) => request("POST", path, { json });
const asArray = (value) => (Array.isArray(value) ? value : []);
const numberOrNull = (value) => (value == null || value === "" ? null : Number(value));

function normalizeEnvironment(environment, index = 0) {
  if (!environment) throw new ApiError(502, "The API returned an invalid environment.", "INVALID_RESPONSE");
  return {
    ...environment,
    id: String(environment.id ?? `environment-${index + 1}`),
    name: String(environment.name ?? ""),
    baseUrl: String(environment.baseUrl ?? environment.base_url ?? environment.url ?? ""),
  };
}

function normalizeVersion(version) {
  if (!version) return null;
  const latest = version.is_latest ?? version.isLatest;
  return {
    ...version,
    id: numberOrNull(version.id),
    projectId: numberOrNull(version.project_id ?? version.projectId),
    versionNumber: version.version ?? version.versionNumber,
    openapiJson: version.openapi ?? version.openapiJson ?? null,
    isLatest: latest === true || latest === 1 || latest === "1",
    createdAt: version.created_at ?? version.createdAt,
  };
}

function parseJsonValue(value, fallback) {
  if (typeof value !== "string") return value ?? fallback;
  try {
    return JSON.parse(value);
  } catch {
    return fallback;
  }
}

function readProjectSettings(projectId) {
  try {
    const raw = localStorage.getItem(`${PROJECT_SETTINGS_KEY}${projectId}`);
    return raw ? JSON.parse(raw) : {};
  } catch {
    return {};
  }
}

function saveProjectSettings(projectId, settings) {
  localStorage.setItem(`${PROJECT_SETTINGS_KEY}${projectId}`, JSON.stringify(settings));
}

function normalizeProject(project) {
  if (!project) return null;
  const testerSettings = readProjectSettings(project.id ?? project.project_id);
  const versions = asArray(project.versions).map(normalizeVersion);
  const environments = asArray(parseJsonValue(project.environments, [])).map(normalizeEnvironment);
  const headerFormat = normalizeHeaderFormat(testerSettings.headerFormat);
  // Older saved setups have no authSource; a saved login path means the user configured it by hand.
  const custom = testerSettings.authSource === "custom" || (testerSettings.authSource == null && Boolean(testerSettings.loginPath));
  const authOverrides = custom
    ? Object.fromEntries(AUTH_SETTING_KEYS.filter((key) => testerSettings[key] != null).map((key) => [key, testerSettings[key]]))
    : null;
  return {
    ...project,
    id: numberOrNull(project.id ?? project.project_id),
    versions,
    _count: {
      versions: numberOrNull(project.versions_count ?? project._count?.versions) ?? versions.length,
    },
    latestVersionNumber: project.latest_version ?? versions.find((version) => version.isLatest)?.versionNumber ?? null,
    baseUrl: environments[0]?.baseUrl ?? project.baseUrl ?? testerSettings.baseUrl ?? "",
    environmentName: "Default",
    environments,
    authConfig: {},
    authRequired: Boolean(testerSettings.authRequired),
    loginMethod: String(testerSettings.loginMethod ?? "POST").toUpperCase(),
    loginPath: testerSettings.loginPath ?? "",
    tokenPath: testerSettings.tokenPath ?? "data.token",
    headerName: testerSettings.headerName ?? "Authorization",
    headerFormat,
    authOverrides,
    createdAt: project.created_at ?? project.createdAt,
    updatedAt: project.updated_at ?? project.updatedAt,
  };
}

function normalizeHistory(entry) {
  return {
    ...entry,
    id: numberOrNull(entry.id),
    status: numberOrNull(entry.response_status ?? entry.status),
    durationMs: numberOrNull(entry.duration_ms ?? entry.durationMs),
    createdAt: entry.created_at ?? entry.createdAt,
  };
}

const operationsFor = (version) => operationsForSpec(version?.openapiJson ?? {});

function versionSummary(version) {
  return {
    id: version.id,
    projectId: version.projectId,
    versionNumber: version.versionNumber,
    createdAt: version.createdAt,
    isLatest: version.isLatest,
  };
}

/** Same naming rule everywhere (form suggestion, CLIs, "Save as new version"): first version's format, latest's numbers + 1. */
function nextVersionNumber(versions) {
  const oldestFirst = [...versions]
    .sort((a, b) => (a.id ?? 0) - (b.id ?? 0))
    .map((version) => version.versionNumber);
  const taken = (name) => versions.some((version) => version.versionNumber === name);
  let candidate = suggestNextVersion(oldestFirst) ?? "v1.0.0";
  // The suggestion bumps the latest numbers; step on if that name is already used by an older version.
  for (let guard = 0; taken(candidate) && guard < 1000; guard++) candidate = suggestNextVersion([...oldestFirst, candidate]);
  return candidate;
}

function projectUrl(project, path, query) {
  const text = String(path ?? "");
  const base = String(project?.baseUrl ?? "").replace(/\/+$/, "");
  const error = baseUrlError(base);
  if (error) throw new ApiError(400, error, "INVALID_BASE_URL");
  const url = /^https?:\/\//i.test(text) ? text : `${base}/${text.replace(/^\/+/, "")}`;
  return `${url}${toQueryString(query).replace(/^\?/, url.includes("?") ? "&" : "?")}`;
}

function authHeader(project, token) {
  if (!token) return null;
  const format = String(project.headerFormat ?? "Bearer {token}");
  return format.includes("{token}") ? format.replaceAll("{token}", token) : `${format} ${token}`.trim();
}

async function runRequest(project, method, path, query, headers, body, token, saveHistory) {
  const requestHeaders = {};
  for (const [key, value] of Object.entries(headers ?? {})) {
    const existing = Object.keys(requestHeaders).find((name) => name.toLowerCase() === key.toLowerCase());
    if (existing) delete requestHeaders[existing];
    requestHeaders[key] = value;
  }
  if (token && project.headerName && !Object.keys(requestHeaders).some((key) => key.toLowerCase() === project.headerName.toLowerCase())) {
    requestHeaders[project.headerName] = authHeader(project, token);
  }
  const url = projectUrl(project, path, query);
  // Every API call is recorded (signed-in ones too); the server redacts secrets before storing it.
  // Only the sign-in request itself (saveHistory false) is left out.
  const historySaved = Boolean(saveHistory);
  const result = await post("/runner/execute.php", {
    project_id: project.id,
    environment: project.environmentName ?? "Default",
    method,
    url,
    headers: requestHeaders,
    body: body ?? null,
    save_history: Boolean(historySaved),
  });
  const response = result ?? {};
  return {
    request: { method, url },
    response: {
      status: numberOrNull(response.status),
      headers: response.headers ?? {},
      body: response.body ?? null,
      durationMs: numberOrNull(response.duration_ms) ?? 0,
      bodySize: new Blob([typeof response.body === "string" ? response.body : JSON.stringify(response.body ?? "")]).size,
    },
    specVersion: { versionNumber: project.latestVersionNumber ?? "latest" },
    historySaved: Boolean(historySaved),
  };
}

function valueAtPath(value, path) {
  return String(path ?? "")
    .replace(/\[(?:'([^']+)'|"([^"]+)"|(\d+))\]/g, ".$1$2$3")
    .split(".")
    .filter(Boolean)
    .reduce((current, key) => current?.[key], value);
}

const postVersion = (payload) => post("/versions/modify/insertUpdateVersion.php", payload);

export const api = {
  // README working copy
  readmeStatus: () => get("/local/readme"),
  // README Markdown is always parsed by the server, so the UI and the CLI turn a README into the same OpenAPI.
  parseReadme: (markdown) => post("/local/readme/parse", { markdown }),
  saveReadme: ({ label, note } = {}) => post("/local/readme/save", { label, note }),

  // projects
  getProjects: async () => asArray(await get("/projects/fetch/getProjects.php")).map(normalizeProject),
  getProject: async (id) => normalizeProject(await get("/projects/fetch/getProject.php", { id })),
  updateProject: async (id, payload) => {
    const result = await post("/projects/modify/insertUpdateProject.php", {
      id,
      name: payload.name,
      description: payload.description,
      ...(payload.environments !== undefined ? { environments: payload.environments } : {}),
    });
    return { ...result, id: numberOrNull(result?.project_id ?? id) };
  },
  /**
   * Saves only the project's environments (base URLs used to test APIs).
   * Environments are testing data, so every project member may save them — unlike
   * updateProject, which edits project details and requires owner/editor.
   */
  updateEnvironments: async (id, environments) => {
    const result = await post("/projects/modify/updateEnvironments.php", { id, environments });
    return { ...result, id: numberOrNull(result?.project_id ?? id) };
  },
  /** Login settings for the API under test. They are kept in this browser only. */
  saveAuthSettings: (projectId, settings) => {
    const current = readProjectSettings(projectId);
    if (settings.authSource !== "custom") {
      saveProjectSettings(projectId, { ...Object.fromEntries(Object.entries(current).filter(([key]) => !AUTH_SETTING_KEYS.includes(key))), authSource: "readme" });
      return;
    }
    saveProjectSettings(projectId, {
      ...current,
      authSource: "custom",
      authRequired: Boolean(settings.authRequired),
      loginMethod: String(settings.loginMethod || "POST").toUpperCase(),
      loginPath: String(settings.loginPath ?? "").trim(),
      tokenPath: String(settings.tokenPath ?? "").trim(),
      headerName: String(settings.headerName ?? "").trim() || "Authorization",
      headerFormat: normalizeHeaderFormat(settings.headerFormat),
    });
  },
  // cloud (the hosted platform; the local server makes the calls and keeps the sign-in)
  cloudStatus: () => get("/cloud/status"),
  cloudLogin: ({ email, password }) => post("/cloud/login", { email, password }),
  cloudLogout: () => post("/cloud/logout", {}),
  /** Publishes a local version to the cloud project named after this project. */
  cloudPublish: ({ versionId, version, note }) => post("/cloud/publish", { version_id: versionId, version, note }),

  // versions
  getVersions: async (projectId) =>
    asArray(await get("/versions/fetch/getVersions.php", { project_id: projectId })).map(normalizeVersion),
  getVersion: async (id) => normalizeVersion(await get("/versions/fetch/getVersion.php", { id })),
  getLatestVersion: async (projectId) => {
    try {
      return normalizeVersion(await get("/versions/fetch/getLatestVersion.php", { project_id: projectId }));
    } catch (error) {
      if (error.status === 404) throw new ApiError(404, "Add an OpenAPI version before testing this project.", "NO_VERSIONS");
      throw error;
    }
  },
  importVersion: async (payload) => api.createVersion(payload),
  compareOperation: async (fromId, toId, method, path) => {
    const [from, to] = await Promise.all([api.getVersion(fromId), api.getVersion(toId)]);
    return {
      from: { version: versionSummary(from), operation: operationsFor(from).get(`${method} ${path}`) ?? null },
      to: { version: versionSummary(to), operation: operationsFor(to).get(`${method} ${path}`) ?? null },
    };
  },
  compareVersions: async (toId, fromId) => {
    const [to, from] = await Promise.all([api.getVersion(toId), api.getVersion(fromId)]);
    return {
      from: versionSummary(from),
      to: versionSummary(to),
      diff: diffSpecs(from?.openapiJson ?? {}, to?.openapiJson ?? {}),
    };
  },
  updateVersionSpec: async (id, openapiJson, projectId) => {
    const validationErrors = validateOpenApiDocument(openapiJson);
    if (validationErrors.length) throw new ApiError(400, validationErrors.join("\n"), "INVALID_OPENAPI", validationErrors);
    const version = await api.getVersion(id);
    return normalizeVersion(await postVersion({
      id,
      project_id: projectId ?? version.projectId,
      version: version.versionNumber,
      openapi: openapiJson,
      note: version.note ?? "",
      is_latest: version.isLatest,
    }));
  },
  updateVersionNote: async (id, note) => {
    const version = await api.getVersion(id);
    return normalizeVersion(await postVersion({
      id,
      project_id: version.projectId,
      version: version.versionNumber,
      openapi: version.openapiJson,
      note,
      is_latest: version.isLatest,
    }));
  },
  createVersion: async (payload) => {
    const openapiJson = payload.openapiJson ?? payload.openapi;
    const validationErrors = validateOpenApiDocument(openapiJson);
    if (validationErrors.length) throw new ApiError(400, validationErrors.join("\n"), "INVALID_OPENAPI", validationErrors);
    const projectId = numberOrNull(payload.projectId ?? payload.project_id);
    const versions = await api.getVersions(projectId);
    const versionNumber = payload.versionNumber ?? payload.version ?? nextVersionNumber(versions);
    const versionPayload = {
      project_id: projectId,
      version: versionNumber,
      note: payload.note ?? "",
      is_latest: true,
    };
    versionPayload.openapi = openapiJson;
    const result = await postVersion(versionPayload);
    const saved = result?.id ? await api.getVersion(result.id) : null;
    return normalizeVersion({
      ...(saved ?? {}),
      ...result,
      project_id: projectId,
      version: result?.version ?? versionNumber,
      openapi: saved?.openapiJson ?? payload.openapiJson ?? payload.openapi,
      is_latest: result?.is_latest ?? true,
    });
  },
  deleteVersion: (id) => post("/versions/remove/removeVersion.php", { id }),

  // API testing
  getTestableEndpoints: async (projectId) => {
    const version = await api.getLatestVersion(projectId);
    return {
      version,
      endpoints: [...operationsFor(version).values()],
    };
  },
  loginProject: async (project, body) => {
    const response = await runRequest(
      project,
      project.loginMethod ?? "POST",
      project.loginPath,
      undefined,
      { "Content-Type": "application/json" },
      body,
      null,
      false,
    );
    if (response.response.status == null || response.response.status < 200 || response.response.status >= 300) {
      throw new ApiError(response.response.status ?? 502, `API login failed (${response.response.status ?? "no response"}).`, "LOGIN_FAILED", response.response.body);
    }
    const tokenPath = project.tokenPath || "data.token";
    const token = valueAtPath(response.response.body, tokenPath);
    if (typeof token !== "string" || !token) {
      throw new ApiError(502, `No token was found at "${tokenPath}" in the login response.`, "TOKEN_NOT_FOUND", response.response.body);
    }
    const expiresAt = (project.expiresPath && expiryFrom(valueAtPath(response.response.body, project.expiresPath))) || jwtExpiry(token);
    return { token, expiresAt };
  },
  executeRequest: (project, payload, apiToken) => {
    const headers = {};
    for (const [key, value] of Object.entries({
      ...(payload.contentType && payload.body != null ? { "Content-Type": payload.contentType } : {}),
      ...(payload.headers ?? {}),
    })) {
      const existing = Object.keys(headers).find((name) => name.toLowerCase() === key.toLowerCase());
      if (existing) delete headers[existing];
      headers[key] = value;
    }
    const contentType = Object.entries(headers).find(([key]) => key.toLowerCase() === "content-type")?.[1] ?? "";
    const body = contentType.includes("application/x-www-form-urlencoded") && payload.body && typeof payload.body === "object"
      ? new URLSearchParams(Object.entries(payload.body).flatMap(([key, value]) =>
          (Array.isArray(value) ? value : [value]).map((item) => [key, String(item)]),
        )).toString()
      : payload.body;
    return runRequest(project, payload.method, payload.path, payload.query, headers, body, apiToken, true);
  },
  executeMultipartRequest: () => {
    throw new ApiError(501, "The PHP runner does not support multipart file uploads.", "UNSUPPORTED_MULTIPART");
  },
  getRequestHistory: async (projectId, limit = 100) =>
    asArray(await get("/request_history/fetch/getHistory.php", {
      project_id: projectId,
      limit: Math.min(Number(limit) || 100, 100),
    })).map(normalizeHistory),
  clearRequestHistory: (projectId) => post("/request_history/remove/clearHistory.php", { project_id: projectId }),
};
