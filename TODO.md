# MathPHP math-coverage TODO

The active “support all mathematical functions” objective is intentionally open-ended. This list is a resume queue, not a complete definition of mathematical coverage.

## Next implementation/reconnaissance tasks

- [ ] Audit continuous distribution families for missing direct survival quantiles (`ccinv`) and lower-/survival-quantile probability derivatives (`invprime`, `ccinvprime`). Inspect `FunctionRegistry` definitions first; do not duplicate functions already present.
- [ ] Candidate families to inspect next: uniform, triangular, arcsine, Laplace, log-logistic/Fisk, gamma, and any generalized distributions. Validate each family’s support, endpoint quantiles, and whether derivatives exist at the chosen probability endpoints.
- [ ] Check all aliases alongside canonical registrations, generated arity-coverage tests, behavior tests, and README descriptions for every new function.
- [ ] Prefer stable formulas: direct upper-tail inverses instead of subtracting probabilities from one; log-density or log-domain derivatives where reciprocal density could underflow/overflow; explicit finite-range errors consistent with neighboring functions.
- [ ] Continue broader mathematical coverage audits beyond distributions: special functions, number theory, linear algebra, calculus, statistics, and parser/facade exposure. Prioritize concrete standard functions that fit the project’s architecture and have testable definitions.
- [ ] Run `composer style`, `composer syntax`, `git diff --check`, focused tests, and `composer test` after implementation. PHPStan may be worth running if its local environment behaves, but previous sessions have had PHPStan sandbox/bind issues.

## Constraints and handoff notes

- Preserve the already dirty worktree; see `STATUS.md` for the known tracked and untracked project work.
- Do not interpret this TODO list or one passing full test run as proof that “all math” is supported.
- Do not commit, push, or deploy without an explicit request.
