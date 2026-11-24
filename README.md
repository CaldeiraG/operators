# Operators

Standalone operator board and management app, split out of graphProd.

- **Board:** `operadores.php` — photos, names and areas for the shift in progress.
- **Management:** `operadores_admin.php` - add/remove operators and update photos (AD login required).
- **Login:** `login.php` — Windows AD / LDAP validation.
- **Entry point:** `index.php` redirects to the board.

## Requirements

- PHP 7.4 or newer.
- PHP **LDAP** extension enabled (`php_ldap`).
- PHP sessions and either cURL or `allow_url_fopen`.
- An intranet API exposing operators and areas.
- HTTPS recommended, especially because AD passwords are submitted to `login.php`.

## Quick start

```bash
cp config/config.local.example.php config/config.local.php
php -S localhost:8080
```

Open:

- `http://localhost:8080/operadores.php` — public board
- `http://localhost:8080/operadores_admin.php` — redirects to the login page
- `http://localhost:8080/login.php` — AD login

In `config/config.local.php`, set at least:

```php
'auth' => [
    'ldap' => [
        'host'        => 'ldap://dc01.domain.local',
        'port'        => 389,
        'domain'      => 'DOMAIN',
        'bind_format' => 'downlevel', // or upn
        'use_tls'     => true,
    ],
],
'operadores_api' => [
    'base_url' => 'http://localhost:4000/intranet',
    'path'     => 'operators',
],
'intranet_api' => [
    'base_url' => 'http://localhost:4000/intranet',
    'areas_path' => 'areas',
],
```

## Structure

```text
├── operadores.php              public shift board
├── operadores_admin.php        add/remove operators + photos (login required)
├── login.php / logout.php      AD session login
├── api/
│   ├── operadores.php          list + create + delete + photo update BFF
│   ├── areas.php               areas for the dropdown
│   └── _helpers.php            JSON/config/cache/clock helpers
├── assets/
│   ├── app.css                 shared visual base
│   ├── operadores.css / .js    board
│   ├── operadores-admin.css / .js
│   └── login.css
├── config/
│   ├── config.php              tracked defaults (no secrets)
│   ├── config.local.example.php
│   └── auth_users.php          static authorized AD accounts
├── lib/
│   ├── auth.php                LDAP/session/CSRF
│   ├── bootstrap.php
│   ├── Cache/FileCache.php
│   └── Http/HttpClient.php
├── libraries/bootstrap/        vendored Bootstrap CSS
├── operadores_response.json    board/API demo fallback
├── areas_response.json         areas demo fallback
└── cache/                      runtime cache (git-ignored)
```

## Operator API contract

Default routes:

| Action | Browser → BFF | BFF → operator API |
| --- | --- | --- |
| List | `GET api/operadores.php` | `GET {operadores_api.base_url}/{path}` |
| Create | `POST api/operadores.php` | `POST {operadores_api.base_url}/{path}` |
| Update photo | `PATCH api/operadores.php` | `PUT {operadores_api.base_url}/{path}/{id}` |
| Delete | `DELETE api/operadores.php?id={id}&area={area}` | `DELETE {operadores_api.base_url}/{path}/{id}` |

List rows may look like:

```json
[
  {
    "id": 101,
    "name": "Ana Silva",
    "image": "/photos/operators/ana.jpg",
    "shift": "1T",
    "area": 1
  }
]
```

Create body sent to the upstream API:

```json
{
  "name": "Ana Silva",
  "shift": "1T",
  "area": 1,
  "image": "data:image/jpeg;base64,..."
}
```

`area` is the numeric **area id** selected in the dropdown (the dropdown shows
the area name, but sends its id). `line` and `role` are not sent by the
management page. `image` is omitted when no photo is selected.

Deleting an operator sends the operator `id` **and** its `area` id. If the
delete route needs them in the URL, use `{id}` and/or `{area}` in `delete_path`.

Writes can be routed elsewhere in `config.local.php`:

```php
'operadores_api' => [
    'create_method' => 'POST',
    'create_path'   => 'operators/insert',
    'delete_method' => 'POST',
    'delete_path'   => 'operators/delete',
    'update_method' => 'PUT',
    'update_path'   => 'operators/{id}',
],
```

The delete/update ids are then sent in the JSON body when the path has no
placeholder. Photo updates send `{ "image": ... }`; `id` is added when
`update_path` has no `{id}` and `{area}` is substituted when present.

## Images

New operators and existing rows both accept images (up to 2 MB). In the table,
use **Alterar foto** or drag an image file onto the row. The browser sends the
image as a data URI. The operator API should:

1. Strip the `data:image/...;base64,` prefix.
2. Validate and resize the image.
3. Save it under a stable path/filename.
4. Store the web path/URL in the database, e.g. `ImagePath NVARCHAR(400)`.
5. Return that path/URL as `image` in `GET /operators`.

A database path column is preferred over storing base64. Store a web path/URL,
not an absolute disk path. If the image is relative, set
`operadores_api.image_base_url` so the BFF can turn it into a full URL.

## Login / authorization

Only accounts in `config/auth_users.php` can open the management page or call
write endpoints:

```php
return [
    'caldeirag',
    // 'DOMAIN\another.user',
];
```

`AUTH_USERS=user1,user2` can add accounts without editing the file. Matching is
case-insensitive and uses the bare username.

AD settings (`auth.ldap`):

| Setting | Default | Purpose |
| --- | --- | --- |
| `host` | *(empty)* | `ldap://dc01.domain.local` or `ldaps://dc01.domain.local` |
| `port` | `389` | `389` for LDAP/StartTLS, `636` for LDAPS |
| `domain` | *(empty)* | NetBIOS name (`DOMAIN`) or DNS suffix for UPN |
| `bind_format` | `downlevel` | `DOMAIN\user` or `user@domain` |
| `use_tls` | `false` | StartTLS on 389 |
| `timeout` | `5` | LDAP connection timeout |

`ldap_validate()` in `lib/auth.php` performs the bind. With
`bind_format=downlevel`, a bare username (or a typed `user@domain`) is sent as
`DOMAIN\username`; with `upn` it is sent as `username@domain`. If `domain` starts
with `@` (for example `@hanonsystems`), it is treated as a UPN suffix. A valid
AD account that is not in the static list is refused.

Sessions use an HttpOnly/SameSite cookie, CSRF tokens for login and writes, and
a default lifetime of 8 hours (`session_ttl`).

## Shifts

| Shift | Window | Length |
| --- | --- | --- |
| **4T** | 01:00 – 07:59 | 7 h |
| **1T** | 08:00 – 16:29 | 8.5 h |
| **2T** | 16:30 – D+1 00:59 | 8.5 h |

The board follows the plant clock, shows only the live shift, and refreshes at
01:00, 08:00 and 16:30. `?display=1` gives a signage layout; `?shift=4T` pins a
specific shift.

## Security notes

- Protect the site with HTTPS.
- Use StartTLS or LDAPS for AD.
- Do not store passwords or LDAP service credentials in the repo.
- Keep `config/config.local.php` out of version control.
- Validate uploaded image types/sizes server-side; never execute uploaded files.
