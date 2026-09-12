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
The open-core Dockerfile follows the same rule: it builds from a clean public
checkout and leaves private add-ons out of the image. An authorized deployment
can place `private/mathphp-explaining` and `private/mathphp-visuals` beside the
website and load their Composer autoloaders; the site reports those optional
features as unavailable when they are not installed.
