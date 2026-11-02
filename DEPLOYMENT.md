# Deployment

## Website

The website's `main` branch is configured for automatic deployment to
`https://mathphp-web.2nmpax.easypanel.host/`. A merge to `main` should trigger
the EasyPanel service deployment; do not deploy manually unless that automation
is unavailable.

The deployment was confirmed working on 2026-11-02: the change titled
`Add accent rule to site header` appeared in the EasyPanel service deployment,
and the deployed website returned HTTP 200. The live pricing page also showed
the GitHub Sponsors checkout and access information.

## Sponsorship service

The sponsorship Worker health endpoint at
`https://mathphp-sponsors.ulties.workers.dev/health` returned HTTP 200 during
the same verification. The GitHub OAuth login route redirected to GitHub, and
the webhook route rejected an invalid signature with HTTP 401.

These checks verify the deployed service routes, but do not verify that the
GitHub organization webhook is configured. Confirm the webhook in the GitHub
organization settings before treating sponsor event delivery as end-to-end
verified.
