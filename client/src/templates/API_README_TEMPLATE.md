---
avp: 1
title: Example Service API
version: 1.0.0
# Optional: description, auth (bearer | none), servers (list of base URLs)
---

<!--
HOW TO USE (this comment is ignored by the parser)
1. Replace the example title, text and endpoints with your own. Keep the header at the top.
2. One "## Section — `prefix/`" per module. The prefix (in backticks, after an em dash) is added to
   every relative endpoint in the section.
3. Document endpoints with a table (Method | Endpoint | Auth | Description) or a heading such as
   "### POST `path/file.php`" followed by "Request:" / "Response `data`:" JSON blocks.
4. Endpoint paths go in `backticks`. `{id}` in a path is a path parameter; a query example such as
   `list.php?status=a|b&page=1` makes those query parameters (a|b = enum, 1 = integer).
5. Set `Auth: true` or `Auth: false` on every endpoint. Use "POST/DELETE" in Method for one URL with
   several methods. In a table cell, write \| for a literal pipe.
6. A `//` comment after a JSON example value documents that field, e.g. // minimum 8 characters.
7. Run `avp check` to see warnings; each one points at a line to look at. Never put real secrets in examples.
-->

# Example Service API

A short description of your API. It becomes the description of the generated OpenAPI document.

## Conventions

**Auth.** Every endpoint except `auth/login.php` requires `Authorization: Bearer <jwt>`, issued by `auth/login.php`.

**Response envelope.** Every response has this shape:

```json
{
  "statusCode": 200,
  "message": "Success",
  "data": {}
}
```

**Paginated list envelope.** List endpoints return their rows in `data`:

```json
{
  "items": [],
  "pagination": { "page": 1, "page_size": 20, "total": 42, "total_pages": 3 }
}
```

Accepts `?page=` (default 1), `?page_size=` (default 20, max 100) and usually `?search=`.

**Errors.** Non-2xx responses use the same envelope. Common codes: `400` invalid input, `401` missing or expired token, `404` not found, `409` conflict, `500` server error.

## Authentication — `auth/`

### POST `auth/login.php`

Auth: false

Request:

```json
{
  "user_name": "user@example.com", // valid email format
  "login_password": "example-pass" // minimum 8 characters
}
```

Response `data`:

```json
{
  "token": "<jwt>",
  "expires_in": 86400,
  "user_id": 1,
  "full_name": "Jane Doe"
}
```

Errors: `401` invalid credentials.

## Customers — `customers/`

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| GET | `fetch/getCustomers.php?status=active\|inactive` | true | Paginated list of customers. `?search=` matches name. |
| GET | `fetch/getCustomer.php?customer_id=1` | true | One customer. **requires** `customer_id`. `404` customer not found |
| POST | `modify/insertUpdateCustomer.php` — `{name, email, phone?}` | true | Creates a customer, or updates it when `id` is sent. `409` duplicate email |
| POST | `remove/removeCustomer.php` — `{customer_id}` | true | Deletes a customer. |
