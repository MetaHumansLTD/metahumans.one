<?php

declare(strict_types=1);

namespace App\Presentation\Hub;

use App\Application;
use App\Domain\Provider\Contracts\AvailabilityCheckInterface;
use App\Domain\Sync\SyncContext;
use InvalidArgumentException;
use Throwable;

final class HubController
{
    /**
     * @var list<string>
     */
    private array $searchTlds = ['co.za', 'org.za', 'net.za', 'com'];

    public function __construct(
        private readonly Application $app,
    ) {
    }

    public function handle(string $path, string $method, array $query, array $post): string
    {
        return match ([$path, strtoupper($method)]) {
            ['/', 'GET'] => $this->renderSearchPage($query),
            ['/hub/domains', 'GET'] => $this->renderSearchPage($query),
            ['/hub/domains/', 'GET'] => $this->renderSearchPage($query),
            ['/register', 'GET'] => $this->renderRegistrationPage($query),
            ['/hub/domains/register', 'GET'] => $this->renderRegistrationPage($query),
            ['/register', 'POST'] => $this->handleRegistrationSubmit($post),
            ['/hub/domains/register', 'POST'] => $this->handleRegistrationSubmit($post),
            default => $this->renderNotFound(),
        };
    }

    private function renderSearchPage(array $query): string
    {
        $basePath = $this->basePath();
        $search = trim((string) ($query['q'] ?? ''));
        $results = $search === '' ? [] : $this->searchDomains($search);
        $recentOrders = $this->app->orderRepository()->listRecent(3);
        $tenantContext = $this->app->tenantContext();
        $contextMarkup = $this->renderTenantContextSummary($tenantContext);

        $resultsMarkup = '';
        if ($results !== []) {
            $items = [];
            foreach ($results as $result) {
                $badgeClass = match ($result['state']) {
                    'available' => 'status status-available',
                    'taken' => 'status status-taken',
                    'error' => 'status status-error',
                    default => 'status status-pending',
                };

                $action = '<span class="button button-muted">Unavailable</span>';
                if (($result['state'] ?? '') === 'available' && ($result['register_url'] ?? null) !== null) {
                    $action = sprintf('<a class="button button-primary" href="%s">Continue</a>', $this->escape((string) $result['register_url']));
                } elseif (($result['state'] ?? '') === 'pending') {
                    $action = '<span class="button button-muted">Coming Soon</span>';
                }

                $items[] = sprintf(
                    '<article class="domain-card"><div><p class="%s">%s</p><h3>%s</h3><p class="muted">%s</p></div><div class="domain-card__aside"><strong>%s</strong>%s</div></article>',
                    $badgeClass,
                    $this->escape((string) $result['label']),
                    $this->escape((string) $result['domain']),
                    $this->escape((string) $result['message']),
                    $this->escape((string) $result['price_label']),
                    $action,
                );
            }

            $resultsMarkup = '<section class="panel panel-results"><div class="panel__head"><h2>Search Results</h2><p class="muted">Availability checks now route through the matching registrar provider when credentials are configured.</p></div>' . implode('', $items) . '</section>';
        }

        $recentMarkup = '';
        if ($recentOrders !== []) {
            $rows = [];
            foreach ($recentOrders as $order) {
                $rows[] = sprintf(
                    '<div class="summary-row"><span>%s</span><strong>%s</strong><small>%s</small></div>',
                    $this->escape((string) $order['order_number']),
                    $this->escape((string) $order['domain_name']),
                    $this->escape(sprintf(
                        '%s via %s',
                        (string) $order['status'],
                        $this->providerDisplayName((string) ($order['provider_code'] ?? '')),
                    )),
                );
            }
            $recentMarkup = '<div class="panel panel-recent"><p class="eyebrow">Recent Orders</p><h2>Saved in the hub</h2>' . implode('', $rows) . '</div>';
        }

        $body = <<<HTML
<section class="hero">
  <div class="hero__content">
    <p class="eyebrow">Client Area / Hub</p>
    <h1>Search and register your next domain with a familiar registrar-style flow.</h1>
    <p class="lead">A clean client journey for discovery, configuration, and checkout, built for `.co.za` first and designed to expand to more TLDs.</p>
    <form method="get" action="{$this->escape($basePath)}" class="search-bar">
      <label for="q" class="sr-only">Search domain</label>
      <input id="q" name="q" type="text" value="{$this->escape($search)}" placeholder="Try studioalpha or metahumans" autocomplete="off">
      <button type="submit" class="button button-primary">Search Domains</button>
    </form>
    <div class="tld-pills">
      <span>.co.za</span>
      <span>.org.za</span>
      <span>.net.za</span>
      <span>.com</span>
    </div>
  </div>
  <aside class="hero__card">
    <p class="eyebrow">How It Works</p>
    <ol>
      <li>Search across your preferred TLDs.</li>
      <li>Review availability and pricing.</li>
      <li>Save the order and queue live submission if enabled.</li>
    </ol>
    <p class="muted">The hub now persists customer orders and domain drafts before any upstream work is attempted.</p>
    {$contextMarkup}
  </aside>
</section>

{$resultsMarkup}
{$recentMarkup}
HTML;

        return $this->layout('Hub Domain Search', $body);
    }

    private function renderRegistrationPage(array $query): string
    {
        $basePath = $this->basePath();
        $domain = trim((string) ($query['domain'] ?? ''));
        if ($domain === '') {
            return $this->layout('Domain Registration', '<section class="panel"><h2>Missing domain</h2><p class="muted">Search for a domain first, then continue to registration.</p><a class="button button-primary" href="' . $this->escape($basePath) . '">Back to Search</a></section>');
        }

        $providerCode = $this->resolveProviderCode($domain);
        $providerName = $this->providerDisplayName($providerCode);
        $isCoZa = $providerCode === 'coza';
        $allowLiveSubmit = $this->app->config()->bool('HUB_ALLOW_LIVE_REGISTRATION', false);
        $tenantContext = $this->app->tenantContext();
        $liveBadge = $allowLiveSubmit ? 'Live queue enabled' : 'Draft mode only';
        $checked = $allowLiveSubmit ? 'checked' : '';
        $disabled = $allowLiveSubmit ? '' : 'disabled';
        $authRequired = $isCoZa ? 'required' : '';
        $customerIdField = $isCoZa ? '' : '<label><span>Customer ID</span><input type="text" name="customer_id" placeholder="LogicBoxes customer ID" required></label>';
        $invoiceField = $isCoZa ? '' : '<label><span>Invoice Option</span><select name="invoice_option"><option value="NoInvoice">NoInvoice</option><option value="PayInvoice">PayInvoice</option><option value="KeepInvoice">KeepInvoice</option><option value="OnlyAdd">OnlyAdd</option></select></label>';
        $privacyField = $isCoZa ? '' : '<label class="checkbox checkbox-inline"><input type="checkbox" name="purchase_privacy" value="1"><span>Add privacy protection when supported</span></label>';
        $registrantLabel = $isCoZa ? 'Registrant Handle' : 'Registrant Contact ID';
        $adminLabel = $isCoZa ? 'Admin Contact Handle' : 'Admin Contact ID';
        $techLabel = $isCoZa ? 'Tech Contact Handle' : 'Tech Contact ID';
        $billingLabel = $isCoZa ? 'Billing Contact Handle' : 'Billing Contact ID';
        $authLabel = $isCoZa ? 'Auth Info' : 'Auth Info (optional)';
        $defaultCompanyName = $this->escape((string) ($tenantContext['company_name'] ?? ''));
        $helperText = $isCoZa
            ? 'This order will be sent to the direct `.co.za` EPP flow.'
            : 'This order will be sent to the NetEarthOne / LogicBoxes registration flow.';
        $contextMarkup = $this->renderTenantContextSummary($tenantContext);

        $body = <<<HTML
<section class="checkout">
  <div class="checkout__main panel">
    <p class="eyebrow">Step 2 / Configure</p>
    <h1>Register {$this->escape($domain)}</h1>
    <p class="lead">{$this->escape($helperText)} This order is saved first, then handed to the worker when live queueing is enabled.</p>
    <form method="post" action="{$this->escape($this->registerPath())}" class="checkout-form">
      <input type="hidden" name="domain_name" value="{$this->escape($domain)}">
      <input type="hidden" name="provider_code" value="{$this->escape($providerCode)}">

      <div class="panel panel-subtle">
        <h2>Client Details</h2>
        <div class="field-grid">
          <label><span>First Name</span><input type="text" name="customer_first_name" required></label>
          <label><span>Last Name</span><input type="text" name="customer_last_name" required></label>
          <label><span>Email</span><input type="email" name="customer_email" required></label>
          <label><span>Company Name</span><input type="text" name="customer_company_name" value="{$defaultCompanyName}"></label>
        </div>
      </div>

      <div class="field-grid">
        <label>
          <span>Period</span>
          <select name="period_years">
            <option value="1">1 year</option>
            <option value="2">2 years</option>
          </select>
        </label>
        <label>
          <span>{$this->escape($registrantLabel)}</span>
          <input type="text" name="registrant" placeholder="REG-123" required>
        </label>
        <label>
          <span>{$this->escape($adminLabel)}</span>
          <input type="text" name="contact_admin" placeholder="ADM-123" {$this->escape($isCoZa ? '' : 'required')}>
        </label>
        <label>
          <span>{$this->escape($techLabel)}</span>
          <input type="text" name="contact_tech" placeholder="TEC-123" {$this->escape($isCoZa ? '' : 'required')}>
        </label>
        <label>
          <span>{$this->escape($billingLabel)}</span>
          <input type="text" name="contact_billing" placeholder="BIL-123" {$this->escape($isCoZa ? '' : 'required')}>
        </label>
        {$customerIdField}
        {$invoiceField}
        <label>
          <span>{$this->escape($authLabel)}</span>
          <input type="text" name="auth_info" placeholder="Strong auth code" {$authRequired}>
        </label>
      </div>

      <div class="panel panel-subtle">
        <h2>Nameservers</h2>
        <div class="field-grid">
          <label><span>Primary NS</span><input type="text" name="ns1" placeholder="ns1.example.net"></label>
          <label><span>Secondary NS</span><input type="text" name="ns2" placeholder="ns2.example.net"></label>
          <label><span>Third NS</span><input type="text" name="ns3" placeholder="Optional"></label>
          <label><span>Fourth NS</span><input type="text" name="ns4" placeholder="Optional"></label>
        </div>
      </div>

      <div class="submit-row">
        <label class="checkbox">
          <input type="checkbox" name="live_submit" value="1" {$checked} {$disabled}>
          <span>{$this->escape($liveBadge)}</span>
        </label>
        {$privacyField}
        <button type="submit" class="button button-primary">Save Order</button>
      </div>
    </form>
  </div>

  <aside class="checkout__summary panel">
    <p class="eyebrow">Order Summary</p>
    <h2>{$this->escape($domain)}</h2>
    <div class="summary-row"><span>Registrar</span><strong>{$this->escape($providerName)}</strong></div>
    <div class="summary-row"><span>Flow</span><strong>Hub checkout</strong></div>
    <div class="summary-row"><span>Mode</span><strong>{$this->escape($liveBadge)}</strong></div>
    {$contextMarkup}
    <p class="muted">Live submission uses the worker queue. Leave it off when you only want to save the order and review it in control.</p>
    <a href="{$this->escape($basePath)}" class="button button-secondary">Back to Search</a>
  </aside>
</section>
HTML;

        return $this->layout('Register Domain', $body);
    }

    private function handleRegistrationSubmit(array $post): string
    {
        $basePath = $this->basePath();
        $registerPath = $this->registerPath();
        $domainName = trim((string) ($post['domain_name'] ?? ''));
        $providerCode = trim((string) ($post['provider_code'] ?? $this->resolveProviderCode($domainName)));
        $periodYears = max(1, (int) ($post['period_years'] ?? 1));
        $allowLiveSubmit = $this->app->config()->bool('HUB_ALLOW_LIVE_REGISTRATION', false);
        $queueLiveSubmission = $allowLiveSubmit && ($post['live_submit'] ?? null) === '1';
        $tenantContext = $this->app->tenantContext();

        $contacts = array_filter([
            'admin' => trim((string) ($post['contact_admin'] ?? '')),
            'tech' => trim((string) ($post['contact_tech'] ?? '')),
            'billing' => trim((string) ($post['contact_billing'] ?? '')),
        ], static fn (string $value): bool => $value !== '');

        $nameservers = [];
        foreach (['ns1', 'ns2', 'ns3', 'ns4'] as $field) {
            $hostname = trim((string) ($post[$field] ?? ''));
            if ($hostname !== '') {
                $nameservers[] = ['hostname' => $hostname];
            }
        }

        $payload = array_replace($tenantContext, [
            'domain_name' => $domainName,
            'provider_code' => $providerCode,
            'period_years' => $periodYears,
            'customer_first_name' => trim((string) ($post['customer_first_name'] ?? '')),
            'customer_last_name' => trim((string) ($post['customer_last_name'] ?? '')),
            'customer_email' => trim((string) ($post['customer_email'] ?? '')),
            'customer_company_name' => trim((string) ($post['customer_company_name'] ?? '')) !== ''
                ? trim((string) ($post['customer_company_name'] ?? ''))
                : (string) ($tenantContext['company_name'] ?? ''),
            'customer_id' => trim((string) ($post['customer_id'] ?? '')),
            'registrant' => trim((string) ($post['registrant'] ?? '')),
            'contacts' => $contacts,
            'auth_info' => trim((string) ($post['auth_info'] ?? '')),
            'invoice_option' => trim((string) ($post['invoice_option'] ?? 'NoInvoice')),
            'purchase_privacy' => ($post['purchase_privacy'] ?? null) === '1',
            'nameservers' => $nameservers,
        ]);

        try {
            $result = $this->app->orderService()->createRegistrationOrder($payload, $queueLiveSubmission);
            $order = $result['order'];
            $task = $result['task'];

            $body = <<<HTML
<section class="result-layout">
  <div class="panel">
    <p class="eyebrow">Step 3 / Saved</p>
    <h1>Order {$this->escape((string) $order['order_number'])} saved</h1>
    <p class="lead">The domain order for {$this->escape($domainName)} has been written to the database and is ready for control review.</p>
    <div class="summary-row"><span>Order Status</span><strong>{$this->escape((string) $order['status'])}</strong></div>
    <div class="summary-row"><span>Submission Mode</span><strong>{$this->escape((string) $order['submission_mode'])}</strong></div>
HTML;

            if (is_array($task) && isset($task['id'])) {
                $body .= '<div class="summary-row"><span>Queued Task</span><strong>' . $this->escape((string) $task['id']) . '</strong></div>';
            }

            $body .= <<<HTML
    <div class="result-actions">
      <a class="button button-primary" href="{$this->escape($basePath)}">Search Another Domain</a>
      <a class="button button-secondary" href="{$this->escape($registerPath)}?domain={$this->escape(rawurlencode($domainName))}">Create Another Order</a>
    </div>
  </div>
  <div class="panel">
    <h2>Persisted Payload</h2>
    <pre>{$this->escape(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}')}</pre>
  </div>
</section>
HTML;

            return $this->layout('Registration Result', $body);
        } catch (InvalidArgumentException $exception) {
            return $this->layout('Registration Error', '<section class="panel"><h1>Missing information</h1><p class="lead">' . $this->escape($exception->getMessage()) . '</p><a class="button button-primary" href="' . $this->escape($registerPath) . '?domain=' . $this->escape(rawurlencode($domainName)) . '">Go back</a></section>');
        } catch (Throwable $exception) {
            return $this->layout('Registration Error', '<section class="panel"><h1>Unable to save order</h1><p class="lead">' . $this->escape($exception->getMessage()) . '</p><a class="button button-primary" href="' . $this->escape($registerPath) . '?domain=' . $this->escape(rawurlencode($domainName)) . '">Go back</a></section>');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchDomains(string $term): array
    {
        $term = strtolower(trim($term));
        $baseLabel = preg_replace('/[^a-z0-9-]+/i', '', str_contains($term, '.') ? explode('.', $term)[0] : $term) ?: '';

        if ($baseLabel === '') {
            return [];
        }

        $results = [];

        foreach ($this->searchTlds as $tld) {
            $fqdn = $baseLabel . '.' . $tld;
            $providerCode = $this->resolveProviderCode($fqdn);
            $provider = null;
            try {
                $provider = $this->app->provider($providerCode);
            } catch (Throwable) {
                $provider = null;
            }

            if ($provider instanceof AvailabilityCheckInterface) {
                try {
                    $checked = $provider->checkAvailability($fqdn, new SyncContext($providerCode, 'hub-search'));
                    $available = (bool) ($checked['available'] ?? false);
                    $results[] = [
                        'domain' => $fqdn,
                        'state' => $available ? 'available' : 'taken',
                        'label' => $available ? 'Available Now' : 'Already Registered',
                        'message' => (string) ($checked['message'] ?? ($available ? 'Ready to continue to registration.' : ($checked['reason'] ?? 'This domain is not available.'))),
                        'price_label' => $providerCode === 'coza' ? 'From R95/yr' : 'Provider pricing',
                        'register_url' => $available ? $this->registerPath() . '?domain=' . rawurlencode($fqdn) : null,
                    ];
                } catch (Throwable $exception) {
                    $results[] = [
                        'domain' => $fqdn,
                        'state' => 'error',
                        'label' => 'Lookup Error',
                        'message' => $exception->getMessage(),
                        'price_label' => 'Check config',
                        'register_url' => null,
                    ];
                }

                continue;
            }

            $results[] = [
                'domain' => $fqdn,
                'state' => 'pending',
                'label' => 'Provider Pending',
                'message' => 'Search UI is ready, but this TLD will go live once its provider integration is connected.',
                'price_label' => 'Coming soon',
                'register_url' => null,
            ];
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $tenantContext
     */
    private function renderTenantContextSummary(array $tenantContext): string
    {
        $rows = [
            ['Tenant', (string) ($tenantContext['tenant_id'] ?? 'local')],
            ['Owner', sprintf(
                '%s (%s)',
                (string) ($tenantContext['owner_id'] ?? 'local'),
                (string) ($tenantContext['owner_type'] ?? 'user'),
            )],
            ['Acting User', (string) ($tenantContext['acting_user_id'] ?? 'guest')],
            ['Billing', sprintf(
                '%s -> %s',
                (string) ($tenantContext['billing_mode'] ?? 'user'),
                (string) ($tenantContext['billing_tenant_id'] ?? 'local'),
            )],
        ];

        if (!empty($tenantContext['acting_persona_id'])) {
            $rows[] = ['Persona', (string) $tenantContext['acting_persona_id']];
        }

        if (!empty($tenantContext['tenant_db_config_id'])) {
            $rows[] = ['Tenant DB', (string) $tenantContext['tenant_db_config_id']];
        }

        if (!empty($tenantContext['company_name'])) {
            $rows[] = ['Company', (string) $tenantContext['company_name']];
        }

        $items = [];
        foreach ($rows as [$label, $value]) {
            $items[] = sprintf(
                '<div class="summary-row"><span>%s</span><strong>%s</strong></div>',
                $this->escape($label),
                $this->escape($value),
            );
        }

        return '<div class="panel panel-subtle"><p class="eyebrow">Active Platform Context</p>' . implode('', $items) . '</div>';
    }

    private function renderNotFound(): string
    {
        http_response_code(404);

        return $this->layout('Not Found', '<section class="panel"><h1>Page Not Found</h1><p class="muted">The page you requested does not exist in the hub flow.</p><a class="button button-primary" href="' . $this->escape($this->basePath()) . '">Back to Hub</a></section>');
    }

    private function layout(string $title, string $body): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$this->escape($title)}</title>
  <link rel="stylesheet" href="/assets/hub.css">
</head>
<body>
  <div class="shell">
    <header class="topbar">
      <a href="/" class="brand">
        <span class="brand__mark">H</span>
        <span>
          <strong>Hub</strong>
          <small>Client Domain Control</small>
        </span>
      </a>
      <nav class="topbar__nav">
        <a href="/">Search</a>
        <a href="/register">Register</a>
      </nav>
    </header>
    <main class="page">{$body}</main>
  </div>
</body>
</html>
HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function resolveProviderCode(string $domainName): string
    {
        return str_ends_with(strtolower($domainName), '.za') ? 'coza' : 'netearthone';
    }

    private function providerDisplayName(string $providerCode): string
    {
        return match ($providerCode) {
            'coza' => '.co.za direct',
            'netearthone' => 'NetEarthOne',
            default => ucfirst($providerCode),
        };
    }

    private function basePath(): string
    {
        $requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        return str_starts_with($requestPath, '/hub/domains') ? '/hub/domains' : '/';
    }

    private function registerPath(): string
    {
        return $this->basePath() === '/hub/domains' ? '/hub/domains/register' : '/register';
    }
}
