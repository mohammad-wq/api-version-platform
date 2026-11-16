import test from "node:test";
import assert from "node:assert/strict";
import { prepareOpenApiImport, validateOpenApiDocument } from "./openapi_import.js";

const validSpec = {
  openapi: "3.0.3",
  info: { title: "Test API", version: "1.0.0" },
  paths: {
    "/items/{itemId}": {
      parameters: [{ name: "itemId", in: "path", required: true, schema: { type: "string" } }],
      get: { responses: { "200": { description: "Item returned" } } },
    },
  },
};

test("accepts valid OpenAPI JSON while rejecting malformed and non-API input", async () => {
  const prepared = await prepareOpenApiImport(JSON.stringify(validSpec), { filename: "api.json" });
  assert.equal(prepared.sourceType, "json");
  assert.deepEqual(prepared.warnings, []);
  assert.equal(prepared.statistics, null);
  assert.deepEqual(validateOpenApiDocument(prepared.openapi), []);
  await assert.rejects(() => prepareOpenApiImport('{"openapi":', { filename: "api.json" }), /Invalid JSON/);
  await assert.rejects(() => prepareOpenApiImport(""), /empty/);
});

test("markdown input goes through the injected server-side README parser", async () => {
  const parserOpenapi = validSpec;
  const warnings = [{ code: "W_NO_EXAMPLES", message: "No JSON examples found.", lines: [1, 1] }];
  const statistics = { endpoints: 1, methods: { get: 1 }, tags: 1 };
  const calls = [];
  const prepared = await prepareOpenApiImport("# Some README", {
    parseMarkdown: async (markdown) => {
      calls.push(markdown);
      return { openapi: parserOpenapi, warnings, statistics };
    },
  });
  assert.equal(prepared.sourceType, "markdown");
  assert.equal(prepared.openapi, parserOpenapi);
  assert.deepEqual(prepared.warnings, warnings);
  assert.deepEqual(prepared.statistics, statistics);
  assert.deepEqual(calls, ["# Some README"]);
});

test("README Markdown needs the injected parseMarkdown callback", async () => {
  await assert.rejects(() => prepareOpenApiImport("# Title\n\nNo endpoints."), /requires the platform server/);
});

test("a failing README parser reports why the parse failed", async () => {
  await assert.rejects(
    () => prepareOpenApiImport("# Title", { parseMarkdown: async () => ({ openapi: { openapi: "3.0.3", info: { title: "t", version: "1" }, paths: {} } }) }),
    /at least one HTTP operation/,
  );
  await assert.rejects(
    () => prepareOpenApiImport("# Title", { parseMarkdown: async () => ({ warnings: [] }) }),
    /did not return an OpenAPI document/,
  );
  await assert.rejects(
    () => prepareOpenApiImport("# Title", { parseMarkdown: async () => Promise.reject(new Error("server exploded")) }),
    /Could not parse README Markdown: server exploded/,
  );
});

test("preserves README parser warnings when generated OpenAPI fails validation", async () => {
  const warnings = [{ code: "NO_ENDPOINTS_FOUND", message: "No API endpoints were detected.", lines: [1, 3] }];
  const openapi = {
    openapi: "3.0.3",
    info: { title: "Empty API", version: "1.0.0" },
    paths: {},
  };
  await assert.rejects(
    () => prepareOpenApiImport("# Empty API", {
      parseMarkdown: async () => ({ openapi, warnings }),
    }),
    (error) => {
      assert.match(error.message, /at least one HTTP operation/);
      assert.deepEqual(error.warnings, warnings);
      return true;
    },
  );
});

test("requires path parameters and responses before a spec can be saved", () => {
  const invalid = structuredClone(validSpec);
  delete invalid.paths["/items/{itemId}"].parameters;
  delete invalid.paths["/items/{itemId}"].get.responses;
  const errors = validateOpenApiDocument(invalid);
  assert(errors.some((error) => error.includes('missing the required path parameter "itemId"')));
  assert(errors.some((error) => error.includes("must define at least one response")));
});

test("validates reusable parameters, responses, request bodies, schemas, and auth references", () => {
  const spec = {
    openapi: "3.0.3",
    info: { title: "Reusable components", version: "1.0.0" },
    components: {
      parameters: {
        ItemId: { name: "itemId", in: "path", required: true, schema: { type: "string" } },
      },
      responses: { Ok: { description: "Successful response" } },
      requestBodies: {
        CreateItem: {
          required: true,
          content: { "application/json": { schema: { $ref: "#/components/schemas/Item" } } },
        },
      },
      schemas: { Item: { type: "object", properties: { name: { type: "string" } } } },
      securitySchemes: { Bearer: { type: "http", scheme: "bearer" } },
    },
    paths: {
      "/items/{itemId}": {
        parameters: [{ $ref: "#/components/parameters/ItemId" }],
        get: {
          security: [{ Bearer: [] }],
          responses: { "200": { $ref: "#/components/responses/Ok" } },
        },
        post: {
          requestBody: { $ref: "#/components/requestBodies/CreateItem" },
          responses: { "201": { description: "Item created" } },
        },
      },
    },
  };
  assert.deepEqual(validateOpenApiDocument(spec), []);
});

test("rejects mismatched Markdown and JSON file extensions", async () => {
  await assert.rejects(() => prepareOpenApiImport("# API", { filename: "api.json" }), /\.json extension/);
  await assert.rejects(() => prepareOpenApiImport(JSON.stringify(validSpec), { filename: "api.md" }), /Markdown extension/);
  await assert.rejects(() => prepareOpenApiImport("gibberish", { filename: "api.txt" }), /\.json, \.md/);
});
