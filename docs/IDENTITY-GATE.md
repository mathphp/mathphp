# Public identity decision

Status: **DECIDED — ecosystem collision explicitly accepted**

Last verified: 2026-07-30
Decision recorded: 2026-07-30

## Finding

The exact proposed Composer coordinate `mathphp/mathphp` did not resolve on
Packagist when checked. That absence is not an assurance that the coordinate is
claimable or appropriate.

The proposed brand and namespace do have a confirmed collision:

- [`markrogoyski/math-php` on Packagist](https://packagist.org/packages/markrogoyski/math-php)
  is an established, actively maintained package with millions of installs.
- Its
  [official Composer manifest](https://raw.githubusercontent.com/markrogoyski/math-php/master/composer.json)
  maps the exact root namespace `MathPHP\` to its source.
- Its
  [official README](https://raw.githubusercontent.com/markrogoyski/math-php/master/README.md)
  brands the project as “MathPHP”.

This is sufficient to create autoload coexistence risk, user confusion, and a
material release-identity conflict. It is an ecosystem collision finding, not
legal or trademark clearance.

## Decision

The user explicitly chose to retain all three proposed identity elements:

```text
Product name: MathPHP
Composer coordinate: mathphp/mathphp
Root PHP namespace: MathPHP\
```

This choice knowingly accepts the documented coexistence, autoload, and user
confusion risks. It does not imply affiliation with
`markrogoyski/math-php`, and it is not legal or trademark clearance.

The identity decision is final for v0.1 unless the user explicitly reopens it.
Do not silently rename the product, Composer coordinate, or public namespace.

## Remaining release policy

The identity gate and local implementation checks are resolved, but this is not
publication approval. PHP 8.3 remains unavailable locally and requires remote
CI evidence before a release. Do not publish, tag, or advertise a package
release without that evidence and separate explicit approval.

This directory is not a Git repository. Git initialization, publication,
tagging, and pushing remain separate actions requiring explicit approval.
