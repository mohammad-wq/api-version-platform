import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { operationsForSpec } from "./openapi-endpoints.js";
import { detectAuthConfig, expiryFrom, jwtExpiry, normalizeHeaderFormat, resolveAuthConfig, tokenStatus, valueAtLoginPath } from "./auth-config.js";

// The bundled template, pre-parsed into OpenAPI by the PHP parser (tests/parser_test.php guards
// this fixture against drift, and PHP tests validate fresh template edits against it).
const templateSpec = JSON.parse(readFileSync(new URL("../../test-fixtures/API_README_TEMPLATE.openapi.json", import.meta.url), "utf8"));
const endpointsOf = (spec) => [...operationsForSpec(spec).values()];

test("detects the login endpoint, token path and header format from the README template", () => {
  const config = detectAuthConfig(templateSpec, endpointsOf(templateSpec));
  assert.equal(config.authRequired, true);
  assert.equal(config.loginMethod, "POST");
  assert.equal(config.loginPath, "/auth/login.php");
  assert.equal(config.tokenPath, "data.token");
  assert.equal(config.expiresPath, "data.expires_in");
  assert.equal(config.headerName, "Authorization");
  assert.equal(config.headerFormat, "Bearer {token}");
  assert.equal(config.source, "readme");
  assert.deepEqual(config.loginFields.map(({ name, secret }) => ({ name, secret })), [
    { name: "user_name", secret: false },
    { name: "login_password", secret: true },
  ]);
  assert.equal(config.loginFields[0].placeholder, "user@example.com");
  assert.equal(config.loginFields[1].placeholder, "");
});

test("a server's x-login-* settings win over the detected endpoint", () => {
  const server = { url: "https://qa.example.com", "x-login-url": "/auth/login.php", "x-token-path": "data.access" };
  const spec = {
    ...structuredClone(templateSpec),
    servers: [server],
  };
  const config = detectAuthConfig(spec, endpointsOf(spec), server);
  assert.equal(config.loginPath, "/auth/login.php");
  assert.equal(config.tokenPath, "data.access");
  assert.equal(config.loginFields.length, 2);
});

test("an API without a public login endpoint reports no login settings", () => {
  const spec = {
    openapi: "3.0.3",
    paths: { "/items": { get: { security: [{ BearerAuth: [] }], responses: { 200: { description: "ok" } } } } },
    components: { securitySchemes: { BearerAuth: { type: "http", scheme: "bearer" } } },
  };
  const config = detectAuthConfig(spec, endpointsOf(spec));
  assert.equal(config.authRequired, true);
  assert.equal(config.loginPath, "");
  assert.equal(config.source, "none");
});

const loginBody = { content: { "application/json": { schema: { type: "object", properties: { identifier: { type: "string" }, password: { type: "string" } } }, example: { identifier: "10002315", password: "x" } } } };
const tokenResponse = { 200: { description: "ok", content: { "application/json": { example: { data: { token: "abc", expires_in: 3600 } } } } } };
const specWith = (paths) => ({ openapi: "3.0.3", paths, components: { securitySchemes: { BearerAuth: { type: "http", scheme: "bearer" } } } });
const protectedLogin = { security: [{ BearerAuth: [] }], requestBody: loginBody, responses: tokenResponse };

test("a login the README did not mark public is still found (a strict document treats every endpoint as protected)", () => {
  const spec = specWith({ "/auth/login.php": { post: protectedLogin }, "/items": { get: { security: [{ BearerAuth: [] }], responses: { 200: { description: "ok" } } } } });
  const config = detectAuthConfig(spec, endpointsOf(spec));
  assert.equal(config.loginPath, "/auth/login.php");
  assert.deepEqual(config.loginFields.map((field) => field.name), ["identifier", "password"]);
});

test("a public POST that takes a password is a login even when its path does not say so", () => {
  const spec = specWith({ "/auth/identify.php": { post: { security: [], requestBody: loginBody, responses: tokenResponse } } });
  const config = detectAuthConfig(spec, endpointsOf(spec));
  assert.equal(config.loginPath, "/auth/identify.php");
  assert.deepEqual(config.loginFields.map((field) => field.name), ["identifier", "password"]);
  assert.equal(config.tokenPath, "data.token");
});

test("protected endpoints that merely take a password, and public ones without one, are not mistaken for a login", () => {
  const spec = specWith({
    "/users/create.php": { post: { security: [{ BearerAuth: [] }], requestBody: loginBody, responses: { 201: { description: "ok" } } } },
    "/feedback.php": { post: { security: [], requestBody: { content: { "application/json": { schema: { type: "object", properties: { message: { type: "string" } } } } } }, responses: { 200: { description: "ok" } } } },
    "/auth/change-password.php": { post: { security: [], requestBody: loginBody, responses: { 200: { description: "ok" } } } },
    "/status.php": { get: { security: [], responses: { 200: { description: "ok" } } } },
  });
  const config = detectAuthConfig(spec, endpointsOf(spec));
  assert.equal(config.loginPath, "");
  assert.equal(config.source, "none");
});

test("an API key scheme sends the raw token in its header", () => {
  const spec = {
    openapi: "3.0.3",
    paths: { "/items": { get: { security: [{ Key: [] }], responses: {} } } },
    components: { securitySchemes: { Key: { type: "apiKey", in: "header", name: "X-Api-Key" } } },
  };
  const config = detectAuthConfig(spec, endpointsOf(spec));
  assert.equal(config.headerName, "X-Api-Key");
  assert.equal(config.headerFormat, "{token}");
});

test("custom settings override detected ones; README mode keeps them", () => {
  const detected = { authRequired: true, loginMethod: "POST", loginPath: "/auth/login.php", tokenPath: "data.token", headerName: "Authorization", headerFormat: "Bearer {token}", source: "readme" };
  assert.deepEqual(resolveAuthConfig(detected, null), detected);
  const custom = resolveAuthConfig(detected, { loginPath: "/login", tokenPath: "", headerFormat: "******", authRequired: false });
  assert.equal(custom.loginPath, "/login");
  assert.equal(custom.tokenPath, "data.token");
  assert.equal(custom.headerFormat, "Bearer {token}");
  assert.equal(custom.authRequired, false);
  assert.equal(custom.source, "custom");
});

test("header formats always contain {token}", () => {
  assert.equal(normalizeHeaderFormat("Token"), "Token {token}");
  assert.equal(normalizeHeaderFormat(""), "Bearer {token}");
  assert.equal(normalizeHeaderFormat("{token}"), "{token}");
});

test("token expiry comes from expires_in, epoch values, dates or the JWT exp claim", () => {
  const now = Date.parse("2026-01-01T00:00:00Z");
  assert.equal(expiryFrom(3600, now), "2026-01-01T01:00:00.000Z");
  assert.equal(expiryFrom(1767229200, now), "2026-01-01T01:00:00.000Z");
  assert.equal(expiryFrom("2026-02-01T00:00:00Z", now), "2026-02-01T00:00:00.000Z");
  assert.equal(expiryFrom(null, now), null);
  const payload = Buffer.from(JSON.stringify({ exp: 1767229200 })).toString("base64url");
  assert.equal(jwtExpiry(`h.${payload}.s`), "2026-01-01T01:00:00.000Z");
  assert.equal(jwtExpiry("not-a-jwt"), null);
  assert.equal(tokenStatus({ token: "x", expiresAt: "2025-01-01T00:00:00Z" }, now), "expired");
  assert.equal(tokenStatus({ token: "x" }, now), "active");
  assert.equal(tokenStatus(null, now), "none");
});

test("a login token is found at its path, or inside the data envelope when the README documented only the payload", () => {
  assert.equal(valueAtLoginPath({ data: { token: "abc" } }, "data.token"), "abc");
  assert.equal(valueAtLoginPath({ token: "top", data: { token: "inner" } }, "token"), "top");
  assert.equal(valueAtLoginPath({ statusCode: 200, data: { token: "abc", expires_in: 3600 } }, "token"), "abc");
  assert.equal(valueAtLoginPath({ statusCode: 200, data: { expires_in: 3600 } }, "expires_in"), 3600);
  assert.equal(valueAtLoginPath({ data: {} }, "token"), undefined);
  assert.equal(valueAtLoginPath("plain text", "token"), undefined);
  assert.equal(valueAtLoginPath(null, "token"), undefined);
});
