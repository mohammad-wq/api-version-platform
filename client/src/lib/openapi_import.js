export const MAX_OPENAPI_IMPORT_BYTES = 5 * 1024 * 1024;
const METHODS = new Set(["get", "put", "post", "delete", "options", "head", "patch", "trace"]);
const PARAMETER_LOCATIONS = new Set(["query", "header", "path", "cookie"]);
const SCHEMA_TYPES = new Set(["array", "boolean", "integer", "number", "object", "string"]);
const RESPONSE_STATUS = /^(?:[1-5]\d\d|[1-5]XX|default)$/i;

function isObject(value) {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}

function isString(value) {
  return typeof value === "string" && value.trim() !== "";
}

function isJsonCandidate(text) {
  const trimmed = text.trim();
  if (trimmed.startsWith("{")) return true;
  if (!trimmed.startsWith("[")) {
    try {
      JSON.parse(trimmed);
      return true;
    } catch {
      return false;
    }
  }
  try {
    JSON.parse(trimmed);
    return true;
  } catch {
    return /^\[\s*(?:\]|[{"\-\d]|true\b|false\b|null\b)/i.test(trimmed);
  }
}

function contentTypeOf(filename) {
  const extension = filename?.split(".").pop()?.toLowerCase();
  if (extension === "json") return "json";
  if (extension === "md" || extension === "markdown") return "markdown";
  return null;
}

function decodeJson(text) {
  try {
    return JSON.parse(text);
  } catch (error) {
    throw new Error(`Invalid JSON: ${error.message}`);
  }
}

function formatLocation(location) {
  return location || "OpenAPI document";
}

function resolveLocalReference(ref, root) {
  if (typeof ref !== "string" || !ref.startsWith("#/")) return undefined;
  return ref
    .slice(2)
    .split("/")
    .map((part) => part.replace(/~1/g, "/").replace(/~0/g, "~"))
    .reduce((value, key) => (isObject(value) || Array.isArray(value) ? value[key] : undefined), root);
}

function validateReference(ref, root, location, errors) {
  if (typeof ref !== "string" || !ref.trim()) {
    errors.push(`${formatLocation(location)} has an empty $ref.`);
    return undefined;
  }
  if (!ref.startsWith("#/")) {
    return undefined;
  }
  const target = resolveLocalReference(ref, root);
  if (target === undefined) errors.push(`${formatLocation(location)} references missing component ${ref}.`);
  return target;
}

function validateSchema(schema, root, location, errors, ancestors = new WeakSet()) {
  if (!isObject(schema)) {
    errors.push(`${formatLocation(location)} must be a schema object.`);
    return;
  }
  if (ancestors.has(schema)) {
    errors.push(`${formatLocation(location)} contains a circular object; JSON schemas must be serializable.`);
    return;
  }
  ancestors.add(schema);
  if (schema.$ref !== undefined) validateReference(schema.$ref, root, `${location}.$ref`, errors);
  if (schema.type !== undefined) {
    const types = Array.isArray(schema.type) ? schema.type : [schema.type];
    if (types.some((type) => !SCHEMA_TYPES.has(type))) errors.push(`${formatLocation(location)} has an unsupported schema type.`);
  }
  if (schema.properties !== undefined) {
    if (!isObject(schema.properties)) errors.push(`${location}.properties must be an object.`);
    else {
      for (const [name, property] of Object.entries(schema.properties)) {
        validateSchema(property, root, `${location}.properties.${name}`, errors, ancestors);
      }
    }
  }
  if (schema.items !== undefined) validateSchema(schema.items, root, `${location}.items`, errors, ancestors);
  for (const keyword of ["additionalProperties", "not", "contains", "propertyNames"]) {
    if (isObject(schema[keyword])) validateSchema(schema[keyword], root, `${location}.${keyword}`, errors, ancestors);
  }
  for (const keyword of ["allOf", "anyOf", "oneOf", "prefixItems"]) {
    if (schema[keyword] !== undefined) {
      if (!Array.isArray(schema[keyword])) errors.push(`${location}.${keyword} must be an array.`);
      else schema[keyword].forEach((child, index) => validateSchema(child, root, `${location}.${keyword}[${index}]`, errors, ancestors));
    }
  }
  if (schema.required !== undefined && (!Array.isArray(schema.required) || schema.required.some((key) => !isString(key)))) {
    errors.push(`${location}.required must be an array of non-empty property names.`);
  }
  if (schema.enum !== undefined && !Array.isArray(schema.enum)) errors.push(`${location}.enum must be an array.`);
  ancestors.delete(schema);
}

function validateContent(content, root, location, errors) {
  if (!isObject(content) || Object.keys(content).length === 0) {
    errors.push(`${formatLocation(location)} must define at least one media type.`);
    return;
  }
  for (const [mediaType, media] of Object.entries(content)) {
    const mediaLocation = `${location}.${mediaType}`;
    if (!isObject(media)) {
      errors.push(`${mediaLocation} must be an object.`);
      continue;
    }
    if (media.schema !== undefined) validateSchema(media.schema, root, `${mediaLocation}.schema`, errors);
    if (media.examples !== undefined && !isObject(media.examples)) errors.push(`${mediaLocation}.examples must be an object.`);
  }
}

function validateServers(servers, location, errors) {
  if (servers === undefined) return;
  if (!Array.isArray(servers)) {
    errors.push(`${formatLocation(location)} must be an array.`);
    return;
  }
  for (const [index, server] of servers.entries()) {
    const serverLocation = `${location}[${index}]`;
    if (!isObject(server) || !isString(server.url)) {
      errors.push(`${serverLocation} must define a non-empty url.`);
      continue;
    }
    if (server.variables !== undefined && !isObject(server.variables)) {
      errors.push(`${serverLocation}.variables must be an object.`);
    } else {
      for (const [name, variable] of Object.entries(server.variables ?? {})) {
        if (!isObject(variable) || !isString(variable.default)) {
          errors.push(`${serverLocation}.variables.${name} must define a non-empty default.`);
        }
      }
    }
  }
}

function validateParameter(parameter, root, location, errors, refStack = new Set()) {
  if (!isObject(parameter)) {
    errors.push(`${formatLocation(location)} must be a parameter object.`);
    return;
  }
  if (parameter.$ref !== undefined) {
    const target = validateReference(parameter.$ref, root, `${location}.$ref`, errors);
    if (isObject(target)) {
      if (refStack.has(parameter.$ref)) errors.push(`${location} contains a circular parameter reference.`);
      else {
        refStack.add(parameter.$ref);
        validateParameter(target, root, location, errors, refStack);
        refStack.delete(parameter.$ref);
      }
    }
    return;
  }
  if (!isString(parameter.name)) errors.push(`${location}.name must be a non-empty string.`);
  if (!PARAMETER_LOCATIONS.has(parameter.in)) errors.push(`${location}.in must be query, header, path, or cookie.`);
  if (parameter.required !== undefined && typeof parameter.required !== "boolean") errors.push(`${location}.required must be a boolean.`);
  if (parameter.in === "path" && parameter.required !== true) errors.push(`${location} path parameters must set required: true.`);
  if (parameter.schema !== undefined && parameter.content !== undefined) errors.push(`${location} must define schema or content, not both.`);
  if (parameter.schema !== undefined) validateSchema(parameter.schema, root, `${location}.schema`, errors);
  else if (parameter.content !== undefined) validateContent(parameter.content, root, `${location}.content`, errors);
  else errors.push(`${location} must define schema or content.`);
}

function validateResponse(response, root, location, errors, refStack = new Set()) {
  if (!isObject(response)) {
    errors.push(`${formatLocation(location)} must be a response object.`);
    return;
  }
  if (response.$ref !== undefined) {
    const target = validateReference(response.$ref, root, `${location}.$ref`, errors);
    if (isObject(target)) {
      if (refStack.has(response.$ref)) errors.push(`${location} contains a circular response reference.`);
      else {
        refStack.add(response.$ref);
        validateResponse(target, root, location, errors, refStack);
        refStack.delete(response.$ref);
      }
    }
    return;
  }
  if (!isString(response.description)) errors.push(`${location}.description must be a non-empty string.`);
  if (response.content !== undefined) validateContent(response.content, root, `${location}.content`, errors);
  if (response.headers !== undefined && !isObject(response.headers)) errors.push(`${location}.headers must be an object.`);
}

function validateResponses(responses, root, location, errors) {
  if (!isObject(responses) || Object.keys(responses).length === 0) {
    errors.push(`${formatLocation(location)} must define at least one response.`);
    return;
  }
  for (const [status, response] of Object.entries(responses)) {
    const responseLocation = `${location}.${status}`;
    if (!RESPONSE_STATUS.test(status)) errors.push(`${responseLocation} is not a valid response status key.`);
    validateResponse(response, root, responseLocation, errors);
  }
}

function validateRequestBody(body, root, location, errors, refStack = new Set()) {
  if (!isObject(body)) {
    errors.push(`${formatLocation(location)} must be an object.`);
    return;
  }
  if (body.$ref !== undefined) {
    const target = validateReference(body.$ref, root, `${location}.$ref`, errors);
    if (isObject(target)) {
      if (refStack.has(body.$ref)) errors.push(`${location} contains a circular request body reference.`);
      else {
        refStack.add(body.$ref);
        validateRequestBody(target, root, location, errors, refStack);
        refStack.delete(body.$ref);
      }
    }
    return;
  }
  if (body.required !== undefined && typeof body.required !== "boolean") errors.push(`${location}.required must be a boolean.`);
  validateContent(body.content, root, `${location}.content`, errors);
}

function validateSecurity(security, schemes, location, errors) {
  if (security === undefined) return;
  if (!Array.isArray(security)) {
    errors.push(`${formatLocation(location)} must be an array.`);
    return;
  }
  for (const [index, requirement] of security.entries()) {
    if (!isObject(requirement)) {
      errors.push(`${location}[${index}] must be an object.`);
      continue;
    }
    for (const name of Object.keys(requirement)) {
      if (!schemes.has(name)) errors.push(`${location}[${index}] references undefined security scheme "${name}".`);
      if (!Array.isArray(requirement[name]) || requirement[name].some((scope) => typeof scope !== "string")) {
        errors.push(`${location}[${index}].${name} must be an array of scope strings.`);
      }
    }
  }
}

function parameterIdentity(parameter, root) {
  const seen = new Set();
  let candidate = parameter;
  while (isObject(candidate) && typeof candidate.$ref === "string") {
    if (seen.has(candidate.$ref)) return null;
    seen.add(candidate.$ref);
    candidate = resolveLocalReference(candidate.$ref, root);
  }
  return isObject(candidate) && isString(candidate.name) && isString(candidate.in)
    ? `${candidate.in}:${candidate.name}`
    : null;
}

function validateOperation(operation, path, method, pathParameters, inheritedParameters, root, schemes, operationIds, errors) {
  const location = `paths.${path}.${method}`;
  if (!isObject(operation)) {
    errors.push(`${location} must be an operation object.`);
    return;
  }
  if (operation.operationId !== undefined) {
    if (!isString(operation.operationId)) errors.push(`${location}.operationId must be a non-empty string.`);
    else if (operationIds.has(operation.operationId)) errors.push(`${location}.operationId "${operation.operationId}" is duplicated.`);
    else operationIds.add(operation.operationId);
  }
  for (const key of ["summary", "description"]) {
    if (operation[key] !== undefined && typeof operation[key] !== "string") errors.push(`${location}.${key} must be a string.`);
  }
  if (operation.tags !== undefined && (!Array.isArray(operation.tags) || operation.tags.some((tag) => !isString(tag)))) {
    errors.push(`${location}.tags must be an array of non-empty strings.`);
  }
  validateSecurity(operation.security, schemes, `${location}.security`, errors);
  validateServers(operation.servers, `${location}.servers`, errors);

  const operationParameters = operation.parameters === undefined ? [] : operation.parameters;
  if (!Array.isArray(operationParameters)) {
    errors.push(`${location}.parameters must be an array.`);
  } else {
    const seen = new Set();
    for (const [index, parameter] of operationParameters.entries()) {
      validateParameter(parameter, root, `${location}.parameters[${index}]`, errors);
      const key = parameterIdentity(parameter, root);
      if (!key) continue;
      if (seen.has(key)) errors.push(`${location} declares parameter ${key} more than once.`);
      seen.add(key);
    }
    for (const name of pathParameters) {
      if (!seen.has(`path:${name}`) && !inheritedParameters.has(`path:${name}`)) {
        errors.push(`${location} is missing the required path parameter "${name}".`);
      }
    }
  }

  const body = operation.requestBody;
  if (body !== undefined) validateRequestBody(body, root, `${location}.requestBody`, errors);
  validateResponses(operation.responses, root, `${location}.responses`, errors);
}

/** Validate the structural OpenAPI contract used by this app and the PHP save API. */
export function validateOpenApiDocument(spec) {
  const errors = [];
  if (!isObject(spec)) return ["OpenAPI document must be a JSON object."];
  if (typeof spec.openapi !== "string" || !/^3\.\d+\.\d+(?:[-+].*)?$/.test(spec.openapi)) {
    errors.push('The "openapi" field must specify an OpenAPI 3.x version, such as "3.0.3".');
  }
  if (!isObject(spec.info)) errors.push('The "info" field must be an object.');
  else {
    if (!isString(spec.info.title)) errors.push('info.title must be a non-empty string.');
    if (!isString(spec.info.version)) errors.push('info.version must be a non-empty string.');
    if (spec.info.description !== undefined && typeof spec.info.description !== "string") errors.push("info.description must be a string.");
  }
  if (!isObject(spec.paths)) {
    errors.push('The "paths" field must be an object.');
    return errors;
  }
  validateServers(spec.servers, "servers", errors);

  if (spec.components !== undefined && !isObject(spec.components)) errors.push("components must be an object.");
  const securitySchemes = spec.components?.securitySchemes;
  const schemes = new Set(isObject(securitySchemes) ? Object.keys(securitySchemes) : []);
  if (securitySchemes !== undefined && !isObject(securitySchemes)) errors.push("components.securitySchemes must be an object.");
  if (isObject(securitySchemes)) {
    for (const [name, scheme] of Object.entries(securitySchemes)) {
      if (!isObject(scheme) || !["apiKey", "http", "oauth2", "openIdConnect"].includes(scheme.type)) {
        errors.push(`components.securitySchemes.${name} must define a supported security scheme type.`);
        continue;
      }
      if (scheme.type === "apiKey" && (!isString(scheme.name) || !["query", "header", "cookie"].includes(scheme.in))) {
        errors.push(`components.securitySchemes.${name} apiKey schemes require a name and valid in location.`);
      }
      if (scheme.type === "http" && !isString(scheme.scheme)) errors.push(`components.securitySchemes.${name}.scheme is required for HTTP authentication.`);
      if (scheme.type === "openIdConnect" && !isString(scheme.openIdConnectUrl)) errors.push(`components.securitySchemes.${name}.openIdConnectUrl is required.`);
      if (scheme.type === "oauth2") {
        if (!isObject(scheme.flows) || Object.keys(scheme.flows).length === 0) {
          errors.push(`components.securitySchemes.${name}.flows must define at least one OAuth2 flow.`);
        } else {
          for (const [flowName, flow] of Object.entries(scheme.flows)) {
            const flowLocation = `components.securitySchemes.${name}.flows.${flowName}`;
            if (!["implicit", "password", "clientCredentials", "authorizationCode"].includes(flowName) || !isObject(flow)) {
              errors.push(`${flowLocation} is not a valid OAuth2 flow.`);
              continue;
            }
            if (["implicit", "authorizationCode"].includes(flowName) && !isString(flow.authorizationUrl)) {
              errors.push(`${flowLocation}.authorizationUrl is required.`);
            }
            if (["password", "clientCredentials", "authorizationCode"].includes(flowName) && !isString(flow.tokenUrl)) {
              errors.push(`${flowLocation}.tokenUrl is required.`);
            }
            if (!isObject(flow.scopes)) errors.push(`${flowLocation}.scopes must be an object.`);
          }
        }
      }
    }
  }
  if (spec.components?.schemas !== undefined) {
    if (!isObject(spec.components.schemas)) errors.push("components.schemas must be an object.");
    else for (const [name, schema] of Object.entries(spec.components.schemas)) {
      validateSchema(schema, spec, `components.schemas.${name}`, errors);
    }
  }
  if (spec.components?.parameters !== undefined) {
    if (!isObject(spec.components.parameters)) errors.push("components.parameters must be an object.");
    else for (const [name, parameter] of Object.entries(spec.components.parameters)) {
      validateParameter(parameter, spec, `components.parameters.${name}`, errors);
    }
  }
  if (spec.components?.responses !== undefined) {
    if (!isObject(spec.components.responses)) errors.push("components.responses must be an object.");
    else for (const [name, response] of Object.entries(spec.components.responses)) {
      validateResponse(response, spec, `components.responses.${name}`, errors);
    }
  }
  if (spec.components?.requestBodies !== undefined) {
    if (!isObject(spec.components.requestBodies)) errors.push("components.requestBodies must be an object.");
    else for (const [name, body] of Object.entries(spec.components.requestBodies)) {
      validateRequestBody(body, spec, `components.requestBodies.${name}`, errors);
    }
  }
  validateSecurity(spec.security, schemes, "security", errors);

  const operationIds = new Set();
  let operationCount = 0;
  for (const [path, pathItem] of Object.entries(spec.paths)) {
    const location = `paths.${path}`;
    if (!path.startsWith("/") || path.includes("?") || path.includes("#")) {
      errors.push(`${location} must be an absolute OpenAPI path without query or fragment.`);
    }
    if (!isObject(pathItem)) {
      errors.push(`${location} must be a path item object.`);
      continue;
    }
    if (pathItem.$ref !== undefined) validateReference(pathItem.$ref, spec, `${location}.$ref`, errors);
    const pathNames = [...path.matchAll(/\{([^{}]+)\}/g)].map((match) => match[1]);
    if ((path.match(/[{}]/g) ?? []).length !== pathNames.length * 2) errors.push(`${location} has an unbalanced path parameter.`);

    validateServers(pathItem.servers, `${location}.servers`, errors);
    const sharedParameters = pathItem.parameters === undefined ? [] : pathItem.parameters;
    const inherited = new Set();
    if (!Array.isArray(sharedParameters)) errors.push(`${location}.parameters must be an array.`);
    else {
      for (const [index, parameter] of sharedParameters.entries()) {
        validateParameter(parameter, spec, `${location}.parameters[${index}]`, errors);
        const identity = parameterIdentity(parameter, spec);
        if (identity) inherited.add(identity);
      }
    }
    for (const [method, operation] of Object.entries(pathItem)) {
      if (method.startsWith("x-") || method === "$ref" || method === "parameters" || method === "servers" || method === "summary" || method === "description") continue;
      if (!METHODS.has(method)) {
        errors.push(`${location}.${method} is not a supported OpenAPI path-item field or HTTP method.`);
        continue;
      }
      operationCount++;
      validateOperation(operation, path, method, [...new Set(pathNames)], inherited, spec, schemes, operationIds, errors);
    }
  }
  if (operationCount === 0) errors.push("OpenAPI document must contain at least one HTTP operation.");
  return errors;
}

/**
 * Parse file or pasted content as OpenAPI JSON, or as README Markdown via the server's parser.
 *
 * `parseMarkdown` must be provided for Markdown input — it calls the platform API (or anything
 * returning `{ openapi, warnings, statistics }`). There is deliberately no bundled JavaScript
 * parser: the CLI and the UI must parse a README with the same code, or the results drift.
 */
export async function prepareOpenApiImport(text, { filename = "", parseMarkdown } = {}) {
  if (typeof text !== "string" || text.trim() === "") throw new Error("The selected file or text is empty.");
  if (new TextEncoder().encode(text).byteLength > MAX_OPENAPI_IMPORT_BYTES) throw new Error("Input must not exceed 5 MB.");

  const declaredFormat = contentTypeOf(filename);
  if (filename && !declaredFormat) throw new Error("Choose a .json, .md, or .markdown file.");
  const jsonCandidate = isJsonCandidate(text);
  let sourceType;
  let openapi;
  let warnings = [];
  let statistics = null;

  if (jsonCandidate) {
    if (declaredFormat === "markdown") {
      throw new Error(`"${filename}" has a Markdown extension, but its contents look like JSON. Rename it to .json or paste the content instead.`);
    }
    sourceType = "json";
    openapi = decodeJson(text);
  } else {
    if (declaredFormat === "json") {
      throw new Error(`"${filename}" has a .json extension, but its contents are not JSON.`);
    }
    sourceType = "markdown";
    if (typeof parseMarkdown !== "function") {
      throw new Error(
        "Parsing README Markdown requires the platform server. Start the local server (`avp serve`) and open the UI through it, or import an OpenAPI JSON document instead.",
      );
    }
    let parsed;
    try {
      parsed = await parseMarkdown(text);
    } catch (error) {
      throw new Error(`Could not parse README Markdown: ${error instanceof Error ? error.message : String(error)}`);
    }
    if (!parsed || typeof parsed !== "object" || !parsed.openapi || typeof parsed.openapi !== "object") {
      throw new Error("The README parser did not return an OpenAPI document.");
    }
    openapi = parsed.openapi;
    warnings = Array.isArray(parsed.warnings) ? parsed.warnings : [];
    statistics = parsed.statistics ?? null;
  }

  const validationErrors = validateOpenApiDocument(openapi);
  if (validationErrors.length > 0) {
    const error = new Error(validationErrors.join("\n"));
    error.validationErrors = validationErrors;
    error.warnings = warnings;
    throw error;
  }
  return { openapi, sourceType, warnings, statistics };
}
