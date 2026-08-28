<?php

declare(strict_types=1);

// Deployment marker: private visuals package integration (2026-08-27).

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$explainingAutoload = dirname(__DIR__, 2) . '/private/mathphp-explaining/vendor/autoload.php';
if (is_file($explainingAutoload)) {
    require_once $explainingAutoload;
}

use MathPHP\Math;
use MathPHP\Exception\MathException;

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatResult(int|float $result): string
{
    if (is_int($result)) {
        return (string) $result;
    }

    $formatted = sprintf('%.14g', $result);

    return str_contains($formatted, '.')
        ? rtrim(rtrim($formatted, '0'), '.')
        : $formatted;
}

function renderLayout(string $title, string $content, string $active): string
{
    $nav = ['home' => 'Overview', 'packages' => 'Packages', 'docs' => 'Docs', 'playground' => 'Playground', 'pricing' => 'Pricing'];
    $links = '';
    foreach ($nav as $key => $label) {
        $class = $active === $key ? ' class="active"' : '';
        $links .= '<a' . $class . ' href="?page=' . $key . '">' . e($label) . '</a>';
    }

    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="description" content="MathPHP: a bounded scalar expression evaluator for PHP.">'
        . '<title>' . e($title) . ' · MathPHP</title>'
        . '<link rel="stylesheet" href="assets/site.css"></head><body>'
        . '<header class="site-header"><a class="brand" href="?page=home"><span class="brand-mark">∑</span><span>MathPHP</span></a>'
        . '<nav aria-label="Primary">' . $links . '</nav><a class="header-cta" href="?page=playground">Try it <span>↗</span></a></header>'
        . '<main>' . $content . '</main>'
        . '<footer class="site-footer"><span>MathPHP · deterministic math for PHP</span><span>Built with boundaries in mind.</span></footer>'
        . '<script src="assets/site.js" defer></script></body></html>';
}

function renderHome(): string
{
    return '<section class="hero wrap"><div class="eyebrow"><span class="eyebrow-dot"></span>Scalar expressions for PHP</div>'
        . '<div class="hero-grid"><div><h1>Math, with <em>boundaries.</em></h1><p class="hero-copy">A small, predictable expression evaluator for the moments when a calculator needs to live inside your application.</p>'
        . '<div class="hero-actions"><a class="button button-primary" href="?page=playground">Open evaluator <span>↗</span></a><a class="text-link" href="?page=docs">Read the docs <span>→</span></a></div>'
        . '<div class="hero-note"><span class="note-line"></span><span>PHP 8.2+ · no runtime dependencies · explicit errors</span></div></div>'
        . '<div class="console-card"><div class="console-top"><span class="console-label">mathphp / playground</span><span class="console-status"><i></i> ready</span></div><div class="console-body"><div><span class="prompt">&gt;</span><span> (subtotal + tax) * 1.05</span></div><div class="console-muted">subtotal = 42.50 &nbsp; tax = 0.20</div><div class="console-result"><span class="result-arrow">→</span><strong>53.55</strong><span class="result-type">float</span></div></div><div class="console-foot"><span>deterministic</span><span>bounded</span><span>typed</span></div></div></div></section>'
        . '<section class="signal-band"><div class="wrap signal-grid"><div><strong>Small surface area.</strong><span>Clear contracts at every edge.</span></div><div><strong>Safe by default.</strong><span>No eval(), no hidden state.</span></div><div><strong>Useful immediately.</strong><span>Operators, functions, variables.</span></div></div></section>'
        . '<section class="feature-section wrap"><div class="section-kicker">Why MathPHP</div><div class="feature-grid"><article><div class="feature-number">01</div><h2>Expressions that stay readable.</h2><p>Write the calculation your users already understand. Variables, familiar operators, and a focused set of math functions.</p><a href="?page=docs#grammar">See the grammar <span>→</span></a></article><article><div class="feature-number">02</div><h2>Errors that tell the truth.</h2><p>Every failure has a stable code and source span, so validation messages can be useful instead of mysterious.</p><a href="?page=docs#errors">Explore error codes <span>→</span></a></article><article><div class="feature-number">03</div><h2>Boundaries you can trust.</h2><p>Overflow, non-finite values, malformed input, and resource limits are handled explicitly and consistently.</p><a href="?page=docs#limits">View the limits <span>→</span></a></article></div></section>'
        . '<section class="extensions-section wrap" id="extensions"><div class="section-kicker">Optional extensions</div><div class="extensions-heading"><div><h2>Keep the engine free.<br><em>Add the understanding.</em></h2></div><p>MathPHP stays a small, open core. Private add-ons plug into the same contracts when your product needs to teach a calculation or present it beautifully.</p></div><div class="extension-grid"><article class="extension-card extension-card-warm"><div class="extension-top"><span class="extension-index">01</span><span class="extension-badge">Private package</span></div><h3>mathphp-explaining</h3><p>Turn an expression into a clear, ordered lesson: substitutions, operation rules, partial results, source spans, and translated messages.</p><ul><li>Step-by-step evaluation</li><li>English and Danish catalogs</li><li>Observer-based, deterministic output</li></ul><a class="button button-secondary" href="?page=explaining">Explore package <span>→</span></a></article><article class="extension-card extension-card-cool"><div class="extension-top"><span class="extension-index">02</span><span class="extension-badge">Private package</span></div><h3>mathphp-visuals</h3><p>Turn formulas and analysis into renderer-neutral data with accessible SVG fallbacks for charts, graphs, matrices, and calculus.</p><ul><li>Plots, areas, roots, and histograms</li><li>SVG and image-ready data URIs</li><li>Bring your own frontend renderer</li></ul><a class="button button-secondary" href="?page=visuals">Explore package <span>→</span></a></article></div><div class="extension-foot"><span>Licensed for your team · private GitHub access · updates while active</span><a href="?page=pricing">See sponsor access <span>→</span></a></div></section>'
        . '<section class="api-callout wrap"><div><div class="section-kicker">A tiny API</div><h2>One call between input and result.</h2><p>Keep the evaluator behind your own form, API, or rule editor. MathPHP does the careful part.</p></div><pre><code><span class="code-keyword">$result</span> = Math::evaluate(<span class="code-string">\'2 * (3 + 4)\'</span>);</code></pre></section>';
}

function renderPackages(): string
{
    return '<section class="page-intro wrap"><div class="eyebrow"><span class="eyebrow-dot"></span>Package catalogue</div><h1>One core.<br><em>Two ways to extend it.</em></h1><p>The evaluator stays free and focused. Add the private package that gives your users a lesson, a picture, or both.</p></section>'
        . '<section class="package-overview wrap"><div class="package-overview-grid"><article class="package-feature package-feature-warm"><span class="package-label">01 · Private add-on</span><h2>Explain every move.</h2><p><code>mathphp/mathphp-explaining</code> turns an AST evaluation into ordered, translated steps with substitutions, partial results, and source spans.</p><a class="button button-secondary" href="?page=explaining">Explore Explaining <span>→</span></a></article><article class="package-feature package-feature-cool"><span class="package-label">02 · Private add-on</span><h2>Make the result visible.</h2><p><code>mathphp/mathphp-visuals</code> produces portable chart data and accessible SVG fallbacks for plots, matrices, calculus, and statistics.</p><a class="button button-secondary" href="?page=visuals">Explore Visuals <span>→</span></a></article></div><div class="package-note"><strong>Built as additions.</strong><span>Install the free core alone, or load either private package when your product needs it.</span><a href="?page=pricing">See sponsor access and pricing <span>→</span></a></div></section>';
}

function renderPackage(string $package): string
{
    $explaining = $package === 'explaining';
    $name = $explaining ? 'mathphp-explaining' : 'mathphp-visuals';
    $title = $explaining ? 'Teach the calculation.' : 'Show the calculation.';
    $description = $explaining
        ? 'A private extension that observes the same deterministic evaluator and turns each completed node into a useful, translatable lesson.'
        : 'A private extension that keeps visual output structured first, with accessible SVG and image-ready fallbacks when a frontend renderer is not available.';
    $features = $explaining
        ? '<li>Post-order steps with dependencies</li><li>Substitutions, partial results, and exact spans</li><li>English and Danish translations</li><li>Custom observers without changing core semantics</li>'
        : '<li>Line plots, areas, roots, and histograms</li><li>Equation, matrix, system, and calculus models</li><li>Renderer-neutral data for your own frontend</li><li>Accessible SVG and image-ready data URIs</li>';
    $example = $explaining
        ? '<div class="package-code"><span class="code-comment">// explain the same expression your app evaluates</span><br><span class="code-keyword">$result</span> = (<span class="code-keyword">new</span> Explainer(Translations::create(<span class="code-string">\'en\'</span>)))-&gt;explain(<span class="code-string">\'(5*2)*2\'</span>);</div>'
        : '<div class="package-code"><span class="code-comment">// keep data and presentation separate</span><br><span class="code-keyword">$plot</span> = (<span class="code-keyword">new</span> Plotter())-&gt;plot(<span class="code-string">\'sin(x)\'</span>, <span class="code-string">\'x\'</span>, 0, 6.28);</div>';
    $showcase = $explaining
        ? '<div class="showcase-card showcase-card-dark"><div class="showcase-top"><span>Expression</span><code>(5 × 2) × 2</code></div><ol class="step-list light"><li><span class="step-index">1</span><span><strong>Multiply 5 by 2</strong><small>5 × 2 = 10</small></span><b>10</b></li><li><span class="step-index">2</span><span><strong>Multiply the partial result by 2</strong><small>10 × 2 = 20</small></span><b>20</b></li></ol><div class="showcase-result"><span>Final result</span><strong>20</strong></div></div>'
        : '<div class="showcase-card showcase-card-mint"><div class="showcase-top"><span>Plot model</span><code>sin(x)</code></div><div class="mini-chart"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div><div class="showcase-metrics"><span><b>128</b> samples</span><span><b>SVG</b> fallback</span><span><b>0 → 6.28</b> domain</span></div></div>';

    $whatItAdds = $explaining
        ? '<article><span class="feature-number">01</span><h3>A teaching layer</h3><p>Turn a single result into a sequence a learner can follow, with the operation, operands, translation key, and resulting value.</p></article><article><span class="feature-number">02</span><h3>Translations included</h3><p>Ship English or Danish copy from the same step model. Add your own catalogue without rewriting evaluation logic.</p></article><article><span class="feature-number">03</span><h3>Stable for products</h3><p>Attach observers to your UI, API, or audit log while the free core remains the source of truth.</p></article>'
        : '<article><span class="feature-number">01</span><h3>Data before pixels</h3><p>Get samples, domains, labels, and metadata as plain models before choosing SVG, Canvas, or a chart library.</p></article><article><span class="feature-number">02</span><h3>More than plots</h3><p>Use the same contracts for matrices, equations, systems, calculus, roots, areas, and statistical summaries.</p></article><article><span class="feature-number">03</span><h3>Accessible fallback</h3><p>Render a useful SVG or image-ready data URI when a full frontend renderer is unavailable.</p></article>';

    return '<section class="page-intro wrap"><div class="eyebrow"><span class="eyebrow-dot"></span>Private extension</div><h1>' . $title . '<br><em>' . e($name) . '</em></h1><p>' . $description . '</p></section>'
        . '<section class="package-detail wrap"><div class="package-detail-grid"><div><span class="package-label">' . e($name) . '</span><h2>Designed to load separately.</h2><p>Keep <code>mathphp/mathphp</code> small and stable. This package adds its own contracts, translations, and presentation models without changing the public evaluator.</p><ul class="package-list">' . $features . '</ul><a class="button button-primary" href="?page=pricing">Get access <span>↗</span></a></div><div>' . $showcase . $example . '<div class="package-callout"><strong>Private by distribution.</strong><span>Licensed customers receive read-only GitHub access and updates while their sponsorship or license is active.</span></div></div></div></section>'
        . '<section class="package-adds wrap"><div class="section-kicker">What it adds</div><div class="package-adds-grid">' . $whatItAdds . '</div></section>'
        . '<section class="package-flow wrap"><div><div class="section-kicker">How it fits</div><h2>One core call.<br><em>One richer response.</em></h2></div><div class="flow-steps"><div><span>01</span><strong>Evaluate</strong><small>Core parses and validates the expression.</small></div><div><span>02</span><strong>Enrich</strong><small>' . ($explaining ? 'Explaining records each completed operation.' : 'Visuals turns the result into portable models.') . '</small></div><div><span>03</span><strong>Present</strong><small>Your application chooses the UI, language, and renderer.</small></div></div></section>';
}

function renderPricing(): string
{
    return '<section class="page-intro wrap"><div class="eyebrow"><span class="eyebrow-dot"></span>Sponsor access</div><h1>Support the core.<br><em>Unlock the extras.</em></h1><p>MathPHP Core remains free. Sponsors receive private repository access to the add-ons, with existing licensed versions remaining usable after access ends.</p></section>'
        . '<section class="pricing-section wrap"><div class="pricing-grid"><article class="pricing-card"><span class="pricing-kicker">Core</span><h2>Free</h2><div class="pricing-price">€0 <small>forever</small></div><p>The bounded evaluator for every PHP project.</p><ul><li>Public Composer package</li><li>Stable errors and limits</li><li>Community issues and pull requests</li></ul><a class="button button-secondary" href="?page=docs">Read the docs <span>→</span></a></article><article class="pricing-card pricing-card-featured"><span class="pricing-kicker">Individual</span><h2>Plus</h2><div class="pricing-price">€12 <small>/ month</small></div><p>Step-by-step explanations and visual output for one developer.</p><ul><li>Both private add-ons</li><li>Private GitHub read access</li><li>Updates while active</li><li>Perpetual use of obtained versions</li></ul><a class="button button-primary" href="https://github.com/sponsors/mathphp">Sponsor on GitHub <span>↗</span></a></article><article class="pricing-card"><span class="pricing-kicker">Team</span><h2>Team</h2><div class="pricing-price">€39 <small>/ month</small></div><p>Shared access for a small product team.</p><ul><li>Both private add-ons</li><li>Up to five developers</li><li>Commercial internal use</li><li>Priority issue handling</li></ul><a class="button button-secondary" href="https://github.com/sponsors/mathphp">Sponsor on GitHub <span>↗</span></a></article></div><div class="pricing-terms"><strong>How access works</strong><span>GitHub Sponsors automatically syncs private-repository access for eligible tiers. No shared tokens. No forced runtime check-ins.</span><a href="?page=docs#api">Read license and API notes <span>→</span></a></div></section>';
}

function renderDocs(): string
{
    return '<section class="page-intro wrap"><div class="eyebrow"><span class="eyebrow-dot"></span>Reference</div><h1>The useful parts,<br><em>in one place.</em></h1><p>MathPHP keeps the language intentionally small. That makes expressions easy to review and failures easy to explain.</p></section>'
        . '<section class="docs-layout wrap"><aside class="docs-nav"><a class="active" href="#grammar">Grammar</a><a href="#functions">Functions</a><a href="#errors">Errors</a><a href="#limits">Limits</a><a href="#api">PHP API</a></aside><div class="docs-content">'
        . '<section id="grammar"><div class="section-kicker">01 · Grammar</div><h2>Familiar operators, explicit behavior.</h2><p>Use numbers, variables, parentheses, unary signs, and the operators below. Exponentiation is right-associative.</p><div class="table-wrap"><table><thead><tr><th>Operator</th><th>Meaning</th><th>Example</th></tr></thead><tbody><tr><td><code>+</code> <code>-</code></td><td>Addition / subtraction</td><td><code>18 - 4</code></td></tr><tr><td><code>*</code> <code>/</code> <code>%</code></td><td>Product / quotient / remainder</td><td><code>9 % 4</code></td></tr><tr><td><code>^</code></td><td>Exponentiation</td><td><code>2^3^2</code></td></tr><tr><td><code>( )</code></td><td>Grouping</td><td><code>(subtotal + tax)</code></td></tr></tbody></table></div></section>'
        . '<section id="functions"><div class="section-kicker">02 · Functions</div><h2>Ten carefully chosen building blocks.</h2><p>Functions are deterministic and validated before they run.</p><div class="function-list"><code>abs(x)</code><code>sqrt(x)</code><code>sin(x)</code><code>cos(x)</code><code>tan(x)</code><code>log(x)</code><code>log10(x)</code><code>exp(x)</code><code>min(a, b, ...)</code><code>max(a, b, ...)</code></div></section>'
        . '<section id="errors"><div class="section-kicker">03 · Errors</div><h2>Stable codes. Useful spans.</h2><p>Catch <code>MathException</code> and expose the machine-readable code and exact source span to your UI.</p><div class="error-grid"><div><code>lex.malformed_number</code><span>Invalid numeric token</span></div><div><code>parse.unexpected_token</code><span>Expression structure is invalid</span></div><div><code>eval.division_by_zero</code><span>Division or modulo by zero</span></div><div><code>eval.integer_overflow</code><span>Exact integer result cannot fit</span></div></div></section>'
        . '<section id="limits"><div class="section-kicker">04 · Limits</div><h2>Bounded on purpose.</h2><p>Configure resource limits for expression length, nesting depth, function arguments, and factorial inputs.</p><div class="limit-note"><span>↳</span><div><strong>No surprises in production.</strong><br>Limits fail closed with explicit error codes before work can grow without bound.</div></div></section>'
        . '<section id="api"><div class="section-kicker">05 · PHP API</div><h2>Install, evaluate, inspect.</h2><pre class="code-block"><code><span class="code-comment">// composer require mathphp/mathphp</span>
<span class="code-keyword">use</span> MathPHP\Math;

<span class="code-keyword">try</span> {
    <span class="code-keyword">$result</span> = Math::evaluate(
        <span class="code-string">\'(subtotal + tax) * 1.05\'</span>,
        [<span class="code-string">\'subtotal\'</span> =&gt; 42.5, <span class="code-string">\'tax\'</span> =&gt; 0.2],
    );
} <span class="code-keyword">catch</span> (MathException <span class="code-keyword">$error</span>) {
    <span class="code-keyword">echo</span> <span class="code-keyword">$error</span>-&gt;getErrorCode();
}</code></pre></section>'
        . '</div></section>';
}

function renderPlayground(): string
{
    return '<section class="page-intro playground-intro wrap"><div class="eyebrow"><span class="eyebrow-dot"></span>Interactive evaluator</div><h1>Make a calculation.<br><em>See its edges.</em></h1><p>Try the same engine your PHP application calls. Change the expression, add variables, and inspect the typed result.</p></section>'
        . '<section class="playground wrap" data-playground><div class="editor-panel"><div class="panel-heading"><span>Expression</span><span class="panel-hint">⌘ ↵ to evaluate</span></div><textarea id="expression" spellcheck="false">(5*2)*2</textarea><div class="panel-heading variables-heading"><span>Variables <small>JSON object</small></span><label class="locale-label" for="locale">Language <select id="locale"><option value="en">English</option><option value="da">Dansk</option></select></label></div><textarea id="variables" spellcheck="false">{}</textarea><div class="action-row"><button class="button button-primary evaluate-button" id="evaluate">Evaluate <span>↗</span></button><button class="button button-secondary" id="explain">Explain steps <span>✦</span></button><button class="button button-secondary" id="plot">Plot function <span>⌁</span></button></div><div class="action-row calculus-actions"><button class="button button-secondary" id="derivative">Derivative <span>′</span></button><button class="button button-secondary" id="integral">Antiderivative <span>∫</span></button><button class="button button-secondary" id="area">Area 0→1 <span>∫</span></button><button class="button button-secondary" id="root-find">Find root 0→2 <span>≈</span></button></div><div class="equation-tool"><div class="panel-heading"><span>Equation analysis</span><span class="panel-hint">partial solving is honest</span></div><input id="equation" value="x^y = z" aria-label="Equation"><button class="button button-secondary" id="analyze">Analyze equation <span>∑</span></button></div><div class="equation-tool"><div class="panel-heading"><span>Linear system</span><span class="panel-hint">two equations, two unknowns</span></div><textarea id="system" aria-label="Linear system">2*x + 3*y = 8; 1*x - 1*y = 1</textarea><button class="button button-secondary" id="analyze-system">Analyze system <span>▦</span></button></div><div class="equation-tool"><div class="panel-heading"><span>Matrix</span><span class="panel-hint">JSON 2×2</span></div><textarea id="matrix" aria-label="Matrix">[[1,2],[3,4]]</textarea><button class="button button-secondary" id="analyze-matrix">Analyze matrix <span>▦</span></button></div><div class="examples"><span>Try an example</span><button type="button" data-example="(5*2)*2">(5*2)*2</button><button type="button" data-example="sqrt(144) + abs(-3)">sqrt(144) + abs(-3)</button><button type="button" data-example="2^3^2">2^3^2</button><button type="button" data-example="10 / 0">10 / 0</button></div></div><div class="result-panel"><div class="panel-heading"><span id="result-heading">Result</span><span class="result-live"><i></i> live</span></div><div class="result-empty" id="result"><span class="result-symbol">∑</span><strong>Your result will appear here.</strong><span>Evaluate normally, or reveal each operation one step at a time.</span></div><div class="result-meta"><span>MathPHP evaluator</span><span>PHP 8.2+</span></div></div></section>';
}

/**
 * @param array<string|int, mixed> $rawVariables
 * @return array<string, int|float>
 */
function normalizeVariables(array $rawVariables): array
{
    $variables = [];
    foreach ($rawVariables as $name => $value) {
        if (!is_string($name) || (!is_int($value) && !is_float($value))) {
            throw new InvalidArgumentException('Variables must be a JSON object with numeric values.');
        }
        $variables[$name] = $value;
    }

    return $variables;
}

function handleEvaluationRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $expression = is_array($payload) && is_string($payload['expression'] ?? null) ? $payload['expression'] : '';
    $rawVariables = is_array($payload) && is_array($payload['variables'] ?? null) ? $payload['variables'] : [];
    try {
        $variables = normalizeVariables($rawVariables);
    } catch (InvalidArgumentException $error) {
        echo json_encode(['ok' => false, 'code' => 'input.invalid_variables', 'message' => $error->getMessage(), 'span' => [0, 0]], JSON_THROW_ON_ERROR);
        exit;
    }

    try {
        $result = Math::evaluate($expression, $variables);
        echo json_encode(['ok' => true, 'result' => $result, 'type' => get_debug_type($result), 'display' => formatResult($result)], JSON_THROW_ON_ERROR);
    } catch (MathException $error) {
        $span = $error->span();
        echo json_encode(['ok' => false, 'code' => $error->errorCode(), 'message' => $error->getMessage(), 'span' => [$span->start, $span->end]], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handleExplanationRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Explaining\\Explainer')) {
        echo json_encode(['ok' => false, 'code' => 'explain.unavailable', 'message' => 'The private mathphp-explaining package is not installed on this deployment.'], JSON_THROW_ON_ERROR);
        exit;
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    $expression = is_array($payload) && is_string($payload['expression'] ?? null) ? $payload['expression'] : '';
    $rawVariables = is_array($payload) && is_array($payload['variables'] ?? null) ? $payload['variables'] : [];
    $locale = is_array($payload) && is_string($payload['locale'] ?? null) ? $payload['locale'] : 'en';

    try {
        $variables = normalizeVariables($rawVariables);
        $translator = \MathPHP\Explaining\Translation\Translations::create($locale);
        $explanation = (new \MathPHP\Explaining\Explainer($translator))->explain($expression, $variables);
        echo json_encode(['ok' => true, 'explanation' => $explanation->toArray()], JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException $error) {
        echo json_encode(['ok' => false, 'code' => 'input.invalid_variables', 'message' => $error->getMessage(), 'span' => [0, 0]], JSON_THROW_ON_ERROR);
    } catch (MathException $error) {
        $span = $error->span();
        echo json_encode(['ok' => false, 'code' => $error->errorCode(), 'message' => $error->getMessage(), 'span' => [$span->start, $span->end]], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handleEquationRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Explaining\\EquationAnalyzer')) {
        echo json_encode(['ok' => false, 'code' => 'visuals.unavailable', 'message' => 'The private mathphp-visuals package is not installed.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $equation = is_array($payload) && is_string($payload['equation'] ?? null) ? $payload['equation'] : '';
    $rawKnown = is_array($payload) && is_array($payload['known'] ?? null) ? $payload['known'] : [];
    try {
        $known = normalizeVariables($rawKnown);
        $analysis = (new \MathPHP\Explaining\EquationAnalyzer())->analyze($equation, $known);
        echo json_encode(['ok' => true, 'analysis' => $analysis->toArray()], JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException $error) {
        echo json_encode(['ok' => false, 'code' => 'input.invalid_variables', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handlePlotRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Visuals\\Plotter')) {
        echo json_encode(['ok' => false, 'code' => 'visuals.unavailable', 'message' => 'The private mathphp-visuals package is not installed.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $expression = is_array($payload) && is_string($payload['expression'] ?? null) ? $payload['expression'] : '';
    $variable = is_array($payload) && is_string($payload['variable'] ?? null) ? $payload['variable'] : 'x';
    $minimum = is_array($payload) && is_numeric($payload['minimum'] ?? null) ? (float) $payload['minimum'] : -10.0;
    $maximum = is_array($payload) && is_numeric($payload['maximum'] ?? null) ? (float) $payload['maximum'] : 10.0;
    $samples = is_array($payload) && is_numeric($payload['samples'] ?? null) ? (int) $payload['samples'] : 101;
    $rawVariables = is_array($payload) && is_array($payload['variables'] ?? null) ? $payload['variables'] : [];
    try {
        $visual = (new \MathPHP\Visuals\Plotter())->plot($expression, $variable, $minimum, $maximum, $samples, normalizeVariables($rawVariables));
        echo json_encode(['ok' => true, 'visual' => $visual->toArray()], JSON_THROW_ON_ERROR);
    } catch (\InvalidArgumentException $error) {
        echo json_encode(['ok' => false, 'code' => 'input.invalid_plot', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
    } catch (MathException $error) {
        $span = $error->span();
        echo json_encode(['ok' => false, 'code' => $error->errorCode(), 'message' => $error->getMessage(), 'span' => [$span->start, $span->end]], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handleSystemRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Explaining\\SystemAnalyzer')) {
        echo json_encode(['ok' => false, 'code' => 'explain.unavailable', 'message' => 'The private explanation package is not installed.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $system = is_array($payload) && is_string($payload['system'] ?? null) ? $payload['system'] : '';
    $analysis = (new \MathPHP\Explaining\SystemAnalyzer())->analyze($system);
    echo json_encode(['ok' => true, 'analysis' => $analysis->toArray()], JSON_THROW_ON_ERROR);
    exit;
}

function handleCalculusRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Explaining\\CalculusAnalyzer')) {
        echo json_encode(['ok' => false, 'code' => 'explain.unavailable', 'message' => 'The private explanation package is not installed.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $expression = is_array($payload) && is_string($payload['expression'] ?? null) ? $payload['expression'] : '';
    $variable = is_array($payload) && is_string($payload['variable'] ?? null) ? $payload['variable'] : 'x';
    $operation = is_array($payload) && in_array($payload['operation'] ?? 'derivative', ['integral', 'derivative-order'], true) ? $payload['operation'] : 'derivative';
    $order = is_array($payload) && is_numeric($payload['order'] ?? null) ? (int) $payload['order'] : 1;
    $analyzer = new \MathPHP\Explaining\CalculusAnalyzer();
    $analysis = $operation === 'integral' ? $analyzer->integral($expression, $variable) : ($operation === 'derivative-order' ? $analyzer->derivativeOrder($expression, $variable, $order) : $analyzer->derivative($expression, $variable));
    echo json_encode(['ok' => true, 'analysis' => $analysis->toArray()], JSON_THROW_ON_ERROR);
    exit;
}

function handleAreaRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Explaining\\AreaAnalyzer')) {
        echo json_encode(['ok' => false, 'code' => 'explain.unavailable', 'message' => 'The private explanation package is not installed.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $expression = is_array($payload) && is_string($payload['expression'] ?? null) ? $payload['expression'] : '';
    $variable = is_array($payload) && is_string($payload['variable'] ?? null) ? $payload['variable'] : 'x';
    $minimum = is_array($payload) && is_numeric($payload['minimum'] ?? null) ? (float) $payload['minimum'] : 0.0;
    $maximum = is_array($payload) && is_numeric($payload['maximum'] ?? null) ? (float) $payload['maximum'] : 1.0;
    $samples = is_array($payload) && is_numeric($payload['samples'] ?? null) ? (int) $payload['samples'] : 101;
    try {
        $analysis = (new \MathPHP\Explaining\AreaAnalyzer())->analyze($expression, $variable, $minimum, $maximum, $samples);
        echo json_encode(['ok' => true, 'analysis' => $analysis->toArray()], JSON_THROW_ON_ERROR);
    } catch (\InvalidArgumentException $error) {
        echo json_encode(['ok' => false, 'code' => 'input.invalid_area', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handleRootRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Explaining\\RootAnalyzer')) {
        echo json_encode(['ok' => false, 'code' => 'explain.unavailable', 'message' => 'The private explanation package is not installed.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $expression = is_array($payload) && is_string($payload['expression'] ?? null) ? $payload['expression'] : '';
    $variable = is_array($payload) && is_string($payload['variable'] ?? null) ? $payload['variable'] : 'x';
    $minimum = is_array($payload) && is_numeric($payload['minimum'] ?? null) ? (float) $payload['minimum'] : 0.0;
    $maximum = is_array($payload) && is_numeric($payload['maximum'] ?? null) ? (float) $payload['maximum'] : 1.0;
    $iterations = is_array($payload) && is_numeric($payload['iterations'] ?? null) ? (int) $payload['iterations'] : 40;
    try {
        $analysis = (new \MathPHP\Explaining\RootAnalyzer())->analyze($expression, $variable, $minimum, $maximum, $iterations);
        echo json_encode(['ok' => true, 'analysis' => $analysis->toArray()], JSON_THROW_ON_ERROR);
    } catch (\InvalidArgumentException $error) {
        echo json_encode(['ok' => false, 'code' => 'input.invalid_root', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handleStatisticsRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Explaining\\StatisticsAnalyzer')) {
        echo json_encode(['ok' => false, 'code' => 'explain.unavailable', 'message' => 'The private explanation package is not installed.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $values = is_array($payload) && is_array($payload['values'] ?? null) ? $payload['values'] : [];
    $bins = is_array($payload) && is_numeric($payload['bins'] ?? null) ? (int) $payload['bins'] : 5;
    try {
        $analysis = (new \MathPHP\Explaining\StatisticsAnalyzer())->analyze($values, $bins);
        echo json_encode(['ok' => true, 'analysis' => $analysis->toArray()], JSON_THROW_ON_ERROR);
    } catch (\InvalidArgumentException $error) {
        echo json_encode(['ok' => false, 'code' => 'input.invalid_statistics', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handleMatrixRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    if (!class_exists('MathPHP\\Explaining\\MatrixAnalyzer')) {
        echo json_encode(['ok' => false, 'code' => 'explain.unavailable', 'message' => 'The private explanation package is not installed.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $matrix = is_array($payload) && is_array($payload['matrix'] ?? null) ? $payload['matrix'] : [];
    try {
        $analysis = (new \MathPHP\Explaining\MatrixAnalyzer())->analyze($matrix);
        echo json_encode(['ok' => true, 'analysis' => $analysis->toArray()], JSON_THROW_ON_ERROR);
    } catch (\InvalidArgumentException $error) {
        echo json_encode(['ok' => false, 'code' => 'input.invalid_matrix', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}

function handleCapabilitiesRequest(): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'version' => '0.1', 'capabilities' => [
        ['id' => 'explain', 'endpoint' => '?api=explain', 'input' => 'expression, variables, locale', 'visualKinds' => ['dependency-graph']],
        ['id' => 'equation', 'endpoint' => '?api=analyze', 'input' => 'equation, known', 'visualKinds' => ['equation-flow']],
        ['id' => 'system', 'endpoint' => '?api=system', 'input' => '2×2 system', 'visualKinds' => ['linear-system']],
        ['id' => 'matrix', 'endpoint' => '?api=matrix', 'input' => '2×2 matrix', 'visualKinds' => ['matrix-heatmap']],
        ['id' => 'calculus', 'endpoint' => '?api=calculus', 'input' => 'expression, operation, variable', 'visualKinds' => ['calculus-derivative', 'calculus-integral']],
        ['id' => 'plot', 'endpoint' => '?api=plot', 'input' => 'expression, variable, domain, samples', 'visualKinds' => ['line-plot']],
        ['id' => 'area', 'endpoint' => '?api=area', 'input' => 'expression, variable, interval, samples', 'visualKinds' => ['area-under-curve']],
        ['id' => 'root', 'endpoint' => '?api=root', 'input' => 'expression, variable, bracket', 'visualKinds' => ['root-convergence']],
        ['id' => 'statistics', 'endpoint' => '?api=statistics', 'input' => 'values, bins', 'visualKinds' => ['histogram']],
    ]], JSON_THROW_ON_ERROR);
    exit;
}

if (($_GET['api'] ?? '') === 'evaluate') {
    handleEvaluationRequest();
}
if (($_GET['api'] ?? '') === 'explain') {
    handleExplanationRequest();
}
if (($_GET['api'] ?? '') === 'analyze') {
    handleEquationRequest();
}
if (($_GET['api'] ?? '') === 'plot') {
    handlePlotRequest();
}
if (($_GET['api'] ?? '') === 'system') {
    handleSystemRequest();
}
if (($_GET['api'] ?? '') === 'calculus') {
    handleCalculusRequest();
}
if (($_GET['api'] ?? '') === 'area') {
    handleAreaRequest();
}
if (($_GET['api'] ?? '') === 'root') {
    handleRootRequest();
}
if (($_GET['api'] ?? '') === 'statistics') {
    handleStatisticsRequest();
}
if (($_GET['api'] ?? '') === 'matrix') {
    handleMatrixRequest();
}
if (($_GET['api'] ?? '') === 'capabilities') {
    handleCapabilitiesRequest();
}

$page = $_GET['page'] ?? 'home';
$page = in_array($page, ['home', 'packages', 'explaining', 'visuals', 'docs', 'playground', 'pricing'], true) ? $page : 'home';
$content = match ($page) {
    'packages' => renderPackages(),
    'explaining', 'visuals' => renderPackage($page),
    'docs' => renderDocs(),
    'playground' => renderPlayground(),
    'pricing' => renderPricing(),
    default => renderHome(),
};

$activePage = in_array($page, ['explaining', 'visuals'], true) ? 'packages' : $page;
echo renderLayout(ucfirst($page), $content, $activePage);
