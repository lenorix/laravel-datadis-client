# Security policy

This package handles the password of a Datadis account and the token Datadis answers with, and it decides which queries are sent. A flaw that exposes them, or that lets a query be sent twice when it must not, is a security issue.

## Reporting a vulnerability

Please do **not** open a public issue. Report it privately through GitHub: [Report a vulnerability](https://github.com/lenorix/laravel-datadis-client/security/advisories/new). Include:

- what you found and where (the file, the configuration or the call);
- the versions of this package, `lenorix/datadis-client`, Laravel and PHP;
- the smallest steps that reproduce it.

Never put a real Datadis password, token, NIF or CUPS in a report: use made-up values.

I answer as soon as I can, fix confirmed issues in a new release, and credit you in it if you want. Please give me time to fix it before you make it public.

## Supported versions

Only the latest release receives security fixes.

## What is in scope

- The Datadis password or the token reaching a log, an event, a recorder (Telescope, Nightwatch), an error message, a cache key or a file.
- The 24 hour guard failing open: a guarded query sent twice, by two workers or after a failure.
- A configuration value that silently weakens the guard or the HTTP stack.
- Anything in this package that lets one account read another account's data.

Problems in Datadis itself, in `lenorix/datadis-client` (report those in [its repository](https://github.com/lenorix/datadis-php-client/security/policy)) or in Laravel are out of scope here.
