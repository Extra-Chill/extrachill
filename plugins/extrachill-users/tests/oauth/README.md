# OAuth service unit tests

Run `php tests/oauth/run.php` with PHP 8+ and OpenSSL. No WordPress installation or external network access is needed.

The isolated runner supplies in-memory WordPress doubles, generates a fresh local RSA key, and stubs the HTTP JWKS response. It exercises the real JWT verifier, Apple identity resolution, and both providers' login functions through the shared token completion helper. Expected verification failures write generic operational reasons to stderr.
