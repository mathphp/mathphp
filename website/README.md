# MathPHP website

This is the public-facing companion site for MathPHP: a landing page, a concise reference, and an interactive evaluator demo.

It intentionally uses the existing PHP package directly instead of duplicating evaluator logic in a separate service.

## Run locally

From the repository root:

```sh
php -S 127.0.0.1:8080 -t website/public
```

Then open <http://127.0.0.1:8080/>.

The site has no additional runtime dependency beyond the repository's Composer install.
