# MathPHP session status

Updated: 2026-10-08 (Europe/Copenhagen)

## Active objective

Continue expanding MathPHP's mathematical function coverage toward the user's open-ended goal of supporting all useful mathematical functions. Do not mark this objective complete: “all mathematical functions” has no finite, verifiable boundary. Make concrete, evidence-led increments; document APIs and edge cases; add tests; preserve unrelated worktree changes.

## Most recent completed increment

Added direct survival-quantile functions and probability derivatives for Cauchy, Pareto Type I, and Lomax (Pareto Type II):

- Cauchy: `cauchyccinv`, `cauchyinvprime`, `cauchyccinvprime`.
- Pareto: `paretoccinv`, `paretoinvprime`, `paretoccinvprime`.
- Lomax: `lomaxccinv`, `lomaxinvprime`, `lomaxccinvprime`.
- Pareto II aliases: `pareto2ccinv`, `pareto2invprime`, `pareto2ccinvprime`.

The Cauchy survival inverse uses a direct symmetric-tail expression instead of calculating the lower-tail inverse at `1-q`. Pareto and Lomax survival inverses support `q=1` at their lower-support endpoint; derivative functions require probabilities strictly between zero and one. Survival-quantile derivatives are negative.

README documentation and `FunctionRegistryTest` coverage were updated. Verification passed:

- `composer style`
- `composer syntax` (48 PHP files)
- `git diff --check`
- `composer test`: 3,813 tests, 41,568 assertions

## Worktree cautions

The worktree is already substantially dirty from broader project work. Preserve all existing edits and untracked files; do not reset, clean, or overwrite unrelated changes. At the time of this handoff, tracked modifications include README, PHPStan configuration, parser/lexer, facade and registry tests, and the website. Untracked items include `1`, `plans/`, `tests/Website/`, and `website/ResultFormatter.php`. These were present before the latest handoff notes and should be treated as user/project work.

No commit, push, or deployment was performed for this math-coverage work.

## Resume point

Continue by auditing nearby distribution families for missing mathematical capabilities rather than adding arbitrary aliases. A productive next pass is inverse-tail and inverse-derivative coverage for continuous distributions (for example uniform, triangular, arcsine, Laplace, log-logistic/Fisk, and gamma), verifying each family’s support, probability endpoints, parameter validation, and numerical stability before implementation. See `TODO.md` and `GOAL-PROMPT.md`.
