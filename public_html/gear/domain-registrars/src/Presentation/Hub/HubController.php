<?php

declare(strict_types=1);

namespace App\Presentation\Hub;

use App\Application;
use App\Domain\Provider\Contracts\AvailabilityCheckInterface;
use App\Domain\Provider\Contracts\DomainMutationInterface;
use App\Domain\Provider\Contracts\DomainPortfolioSyncInterface;
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
            ['/hub/companies/domains', 'GET'] => $this->renderSearchPage($query),
            ['/hub/companies/domains/', 'GET'] => $this->renderSearchPage($query),
            ['/manage', 'GET'] => $this->renderPortfolioPage(),
            ['/manage/', 'GET'] => $this->renderPortfolioPage(),
            ['/hub/domains/manage', 'GET'] => $this->renderPortfolioPage(),
            ['/hub/domains/manage/', 'GET'] => $this->renderPortfolioPage(),
            ['/hub/companies/domains/manage', 'GET'] => $this->renderPortfolioPage(),
            ['/hub/companies/domains/manage/', 'GET'] => $this->renderPortfolioPage(),
            ['/register', 'GET'] => $this->renderRegistrationPage($query),
            ['/register/', 'GET'] => $this->renderRegistrationPage($query),
            ['/register/index.php', 'GET'] => $this->renderRegistrationPage($query),
            ['/hub/domains/register', 'GET'] => $this->renderRegistrationPage($query),
            ['/hub/domains/register/', 'GET'] => $this->renderRegistrationPage($query),
            ['/hub/domains/register/index.php', 'GET'] => $this->renderRegistrationPage($query),
            ['/hub/companies/domains/register', 'GET'] => $this->renderRegistrationPage($query),
            ['/hub/companies/domains/register/', 'GET'] => $this->renderRegistrationPage($query),
            ['/hub/companies/domains/register/index.php', 'GET'] => $this->renderRegistrationPage($query),
            ['/renew', 'GET'] => $this->renderRenewalPage($query),
            ['/renew/', 'GET'] => $this->renderRenewalPage($query),
            ['/hub/domains/renew', 'GET'] => $this->renderRenewalPage($query),
            ['/hub/domains/renew/', 'GET'] => $this->renderRenewalPage($query),
            ['/hub/companies/domains/renew', 'GET'] => $this->renderRenewalPage($query),
            ['/hub/companies/domains/renew/', 'GET'] => $this->renderRenewalPage($query),
            ['/cancel', 'GET'] => $this->renderCancellationPage($query),
            ['/cancel/', 'GET'] => $this->renderCancellationPage($query),
            ['/hub/domains/cancel', 'GET'] => $this->renderCancellationPage($query),
            ['/hub/domains/cancel/', 'GET'] => $this->renderCancellationPage($query),
            ['/hub/companies/domains/cancel', 'GET'] => $this->renderCancellationPage($query),
            ['/hub/companies/domains/cancel/', 'GET'] => $this->renderCancellationPage($query),
            ['/edit', 'GET'] => $this->renderUpdatePage($query),
            ['/edit/', 'GET'] => $this->renderUpdatePage($query),
            ['/hub/domains/edit', 'GET'] => $this->renderUpdatePage($query),
            ['/hub/domains/edit/', 'GET'] => $this->renderUpdatePage($query),
            ['/hub/companies/domains/edit', 'GET'] => $this->renderUpdatePage($query),
            ['/hub/companies/domains/edit/', 'GET'] => $this->renderUpdatePage($query),
            ['/register', 'POST'] => $this->handleRegistrationSubmit($post),
            ['/register/', 'POST'] => $this->handleRegistrationSubmit($post),
            ['/register/index.php', 'POST'] => $this->handleRegistrationSubmit($post),
            ['/hub/domains/register', 'POST'] => $this->handleRegistrationSubmit($post),
            ['/hub/domains/register/', 'POST'] => $this->handleRegistrationSubmit($post),
            ['/hub/domains/register/index.php', 'POST'] => $this->handleRegistrationSubmit($post),
            ['/hub/companies/domains/register', 'POST'] => $this->handleRegistrationSubmit($post),
            ['/hub/companies/domains/register/', 'POST'] => $this->handleRegistrationSubmit($post),
            ['/hub/companies/domains/register/index.php', 'POST'] => $this->handleRegistrationSubmit($post),
            ['/renew', 'POST'] => $this->handleRenewalSubmit($post),
            ['/renew/', 'POST'] => $this->handleRenewalSubmit($post),
            ['/hub/domains/renew', 'POST'] => $this->handleRenewalSubmit($post),
            ['/hub/domains/renew/', 'POST'] => $this->handleRenewalSubmit($post),
            ['/hub/companies/domains/renew', 'POST'] => $this->handleRenewalSubmit($post),
            ['/hub/companies/domains/renew/', 'POST'] => $this->handleRenewalSubmit($post),
            ['/cancel', 'POST'] => $this->handleCancellationSubmit($post),
            ['/cancel/', 'POST'] => $this->handleCancellationSubmit($post),
            ['/hub/domains/cancel', 'POST'] => $this->handleCancellationSubmit($post),
            ['/hub/domains/cancel/', 'POST'] => $this->handleCancellationSubmit($post),
            ['/hub/companies/domains/cancel', 'POST'] => $this->handleCancellationSubmit($post),
            ['/hub/companies/domains/cancel/', 'POST'] => $this->handleCancellationSubmit($post),
            ['/edit', 'POST'] => $this->handleUpdateSubmit($post),
            ['/edit/', 'POST'] => $this->handleUpdateSubmit($post),
            ['/hub/domains/edit', 'POST'] => $this->handleUpdateSubmit($post),
            ['/hub/domains/edit/', 'POST'] => $this->handleUpdateSubmit($post),
            ['/hub/companies/domains/edit', 'POST'] => $this->handleUpdateSubmit($post),
            ['/hub/companies/domains/edit/', 'POST'] => $this->handleUpdateSubmit($post),
            ['/orders/cancel', 'POST'] => $this->handleOrderCancel($post),
            ['/orders/cancel/', 'POST'] => $this->handleOrderCancel($post),
            ['/hub/domains/orders/cancel', 'POST'] => $this->handleOrderCancel($post),
            ['/hub/domains/orders/cancel/', 'POST'] => $this->handleOrderCancel($post),
            ['/hub/companies/domains/orders/cancel', 'POST'] => $this->handleOrderCancel($post),
            ['/hub/companies/domains/orders/cancel/', 'POST'] => $this->handleOrderCancel($post),
            default => $this->renderNotFound(),
        };
    }

    private function renderSearchPage(array $query): string
    {
        $basePath = $this->basePath();
        $managePath = $this->managePath();
        $search = trim((string) ($query['q'] ?? ''));
        $tenantContext = $this->app->tenantContext();
        $results = $search === '' ? [] : $this->searchDomains($search);
        $recentOrders = $this->app->orderRepository()->listRecent(3);
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
      <a href="{$this->escape($managePath)}" class="button button-secondary">My Domains</a>
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

    private function renderPortfolioPage(): string
    {
        $tenantContext = $this->app->tenantContext();
        $tenantId = (string) ($tenantContext['tenant_id'] ?? '');
        $ownerType = (string) ($tenantContext['owner_type'] ?? 'user');
        $ownerId = (string) ($tenantContext['owner_id'] ?? $tenantId);
        $domains = $this->app->domainRepository()->listForAccount($tenantId, $ownerType, $ownerId, 100);
        $orders = $this->app->orderRepository()->listForAccount($tenantId, $ownerType, $ownerId, 100);
        $contextMarkup = $this->renderTenantContextSummary($tenantContext);

        $domainCards = '';
        if ($domains === []) {
            $domainCards = '<article class="domain-card"><div><p class="status status-pending">No Domains Yet</p><h3>Your account has no saved domains yet</h3><p class="muted">Once a registration order is saved or a registrar domain is synchronized into this tenant, it will appear here.</p></div><div class="domain-card__aside"><a class="button button-primary" href="' . $this->escape($this->basePath()) . '">Search Domains</a></div></article>';
        } else {
            $items = [];
            foreach ($domains as $domain) {
                $domainName = (string) ($domain['domain_name'] ?? '');
                $domainId = (string) ($domain['id'] ?? '');
                $status = (string) ($domain['registrar_status'] ?? 'active');
                $expiresAt = trim((string) ($domain['expires_at'] ?? ''));
                $expiresLabel = $expiresAt !== '' ? substr($expiresAt, 0, 10) : 'Not yet synced';
                $items[] = sprintf(
                    '<article class="domain-card"><div><p class="%s">%s</p><h3>%s</h3><p class="muted">Provider: %s | Expires: %s</p></div><div class="domain-card__aside">%s%s%s</div></article>',
                    $this->escape($this->statusClassForDomain($status)),
                    $this->escape($this->domainStatusLabel($status)),
                    $this->escape($domainName),
                    $this->escape($this->providerDisplayName((string) ($domain['provider_code'] ?? ''))),
                    $this->escape($expiresLabel),
                    $this->manageActionForm($this->renewPath(), 'Renew', 'button button-primary', $domainId, $domainName),
                    $this->manageActionForm($this->editPath(), 'Edit Settings', 'button button-secondary', $domainId, $domainName),
                    $this->manageActionForm($this->cancelPath(), 'Cancel', 'button button-muted', $domainId, $domainName),
                );
            }
            $domainCards = implode('', $items);
        }

        $orderRows = '';
        if ($orders === []) {
            $orderRows = '<div class="summary-row"><span>No orders yet</span><strong>Start from search</strong></div>';
        } else {
            $rows = [];
            foreach ($orders as $order) {
                $cancelAction = '';
                if (in_array((string) ($order['status'] ?? ''), ['draft', 'queued', 'failed'], true)) {
                    $cancelAction = '<form method="post" action="' . $this->escape($this->cancelOrderPath()) . '" class="inline-form"><input type="hidden" name="order_id" value="' . $this->escape((string) ($order['id'] ?? '')) . '"><button type="submit" class="button button-muted">Cancel Order</button></form>';
                }
                $rows[] = '<article class="domain-card"><div><p class="' . $this->escape($this->statusClassForOrder((string) ($order['status'] ?? 'draft'))) . '">' . $this->escape(strtoupper((string) ($order['action_type'] ?? 'register'))) . ' / ' . $this->escape((string) ($order['status'] ?? 'draft')) . '</p><h3>' . $this->escape((string) ($order['domain_name'] ?? '')) . '</h3><p class="muted">' . $this->escape((string) ($order['order_number'] ?? '')) . ' via ' . $this->escape($this->providerDisplayName((string) ($order['provider_code'] ?? ''))) . '</p></div><div class="domain-card__aside"><strong>' . $this->escape((string) ($order['submission_mode'] ?? 'draft')) . '</strong>' . $cancelAction . '</div></article>';
            }
            $orderRows = implode('', $rows);
        }

        $body = <<<HTML
<section class="hero">
  <div class="hero__content">
    <p class="eyebrow">Client Area / Hub</p>
    <h1>Manage your domains in one account view.</h1>
    <p class="lead">This is the user-facing page where domains are listed, renewal requests are saved, and cancellation requests are tracked per tenant account.</p>
    <div class="search-bar">
      <a href="{$this->escape($this->basePath())}" class="button button-primary">Search Domains</a>
      <a href="{$this->escape($this->registerPath())}" class="button button-secondary">Register a Domain</a>
    </div>
  </div>
  <aside class="hero__card">
    <p class="eyebrow">Account View</p>
    <ol>
      <li>Domains listed below belong to the active tenant account.</li>
      <li>Renewal requests are saved from this page.</li>
      <li>Cancellation requests are saved here for control review.</li>
    </ol>
    {$contextMarkup}
  </aside>
</section>

<section class="panel panel-results">
  <div class="panel__head"><h2>My Domains</h2><p class="muted">User-facing portfolio page: <code>{$this->escape($this->managePath())}</code></p></div>
  {$domainCards}
</section>

<section class="panel panel-results">
  <div class="panel__head"><h2>Account Orders</h2><p class="muted">Saved registration, renewal, and cancellation requests for this tenant account.</p></div>
  {$orderRows}
</section>
HTML;

        return $this->layout('My Domains', $body);
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

    private function renderRenewalPage(array $query): string
    {
        $flash = $this->popFlashMessages();
        $domain = $this->resolveManagedDomain($query);
        if ($domain === null) {
            return $this->layout('Renew Domain', '<section class="panel"><h1>Domain not found</h1><p class="lead">Open <a href="' . $this->escape($this->managePath()) . '">My Domains</a> and choose a domain from your account list.</p></section>');
        }

        $this->rememberManagedDomain($domain);

        $domainName = (string) ($domain['domain_name'] ?? '');
        $domainId = (string) ($domain['id'] ?? '');
        $expiresAt = trim((string) ($domain['expires_at'] ?? ''));
        $flashMarkup = $this->renderFlashMessages($flash);
        $body = <<<HTML
<section class="checkout">
  <div class="checkout__main panel">
    <p class="eyebrow">Live Renewal</p>
    <h1>Renew {$this->escape($domainName)}</h1>
    <p class="lead">This submits a live registrar renewal from the website without charging. It uses the expiry date already stored for this domain.</p>
    {$flashMarkup}
    <form method="post" action="{$this->escape($this->renewPath())}" class="checkout-form">
      <input type="hidden" name="domain_id" value="{$this->escape($domainId)}">
      <input type="hidden" name="domain_name" value="{$this->escape($domainName)}">
      <div class="field-grid">
        <label><span>Current Expiry</span><input type="text" value="{$this->escape($expiresAt !== '' ? substr($expiresAt, 0, 10) : 'Not yet synced')}" disabled></label>
        <label><span>Renewal Period</span><select name="period_years"><option value="1">1 year</option><option value="2">2 years</option><option value="3">3 years</option></select></label>
      </div>
      <label><span>Notes</span><textarea name="notes" rows="4" placeholder="Optional renewal notes for control review"></textarea></label>
      <div class="submit-row">
        <button type="submit" class="button button-primary">Renew Domain Now</button>
        <a class="button button-secondary" href="{$this->escape($this->managePath())}">Back to My Domains</a>
      </div>
    </form>
  </div>
  <aside class="checkout__summary panel">
    <p class="eyebrow">Domain Summary</p>
    <h2>{$this->escape($domainName)}</h2>
    <div class="summary-row"><span>Provider</span><strong>{$this->escape($this->providerDisplayName((string) ($domain['provider_code'] ?? '')))}</strong></div>
    <div class="summary-row"><span>Status</span><strong>{$this->escape((string) ($domain['registrar_status'] ?? 'active'))}</strong></div>
  </aside>
</section>
HTML;

        return $this->layout('Renew Domain', $body);
    }

    private function renderCancellationPage(array $query): string
    {
        $flash = $this->popFlashMessages();
        $domain = $this->resolveManagedDomain($query);
        if ($domain === null) {
            return $this->layout('Cancel Domain', '<section class="panel"><h1>Domain not found</h1><p class="lead">Open <a href="' . $this->escape($this->managePath()) . '">My Domains</a> and choose a domain from your account list.</p></section>');
        }

        $this->rememberManagedDomain($domain);

        $domainName = (string) ($domain['domain_name'] ?? '');
        $domainId = (string) ($domain['id'] ?? '');
        $flashMarkup = $this->renderFlashMessages($flash);
        $body = <<<HTML
<section class="checkout">
  <div class="checkout__main panel">
    <p class="eyebrow">Cancellation Request</p>
    <h1>Cancel {$this->escape($domainName)}</h1>
    <p class="lead">This saves a cancellation or non-renewal request in the account so it can be reviewed and actioned safely.</p>
    {$flashMarkup}
    <form method="post" action="{$this->escape($this->cancelPath())}" class="checkout-form">
      <input type="hidden" name="domain_id" value="{$this->escape($domainId)}">
      <input type="hidden" name="domain_name" value="{$this->escape($domainName)}">
      <label><span>Reason</span><textarea name="reason" rows="5" placeholder="Explain whether this is a deletion request, a stop-renewal request, or another cancellation instruction"></textarea></label>
      <div class="submit-row">
        <button type="submit" class="button button-primary">Save Cancellation Request</button>
        <a class="button button-secondary" href="{$this->escape($this->managePath())}">Back to My Domains</a>
      </div>
    </form>
  </div>
  <aside class="checkout__summary panel">
    <p class="eyebrow">Domain Summary</p>
    <h2>{$this->escape($domainName)}</h2>
    <div class="summary-row"><span>Provider</span><strong>{$this->escape($this->providerDisplayName((string) ($domain['provider_code'] ?? '')))}</strong></div>
    <div class="summary-row"><span>Status</span><strong>{$this->escape((string) ($domain['registrar_status'] ?? 'active'))}</strong></div>
  </aside>
</section>
HTML;

        return $this->layout('Cancel Domain', $body);
    }

    private function renderUpdatePage(array $query): string
    {
        $flash = $this->popFlashMessages();
        $domain = $this->resolveManagedDomain($query);
        if ($domain === null) {
            return $this->layout('Edit Domain Settings', '<section class="panel"><h1>Domain not found</h1><p class="lead">Open <a href="' . $this->escape($this->managePath()) . '">My Domains</a> and choose a domain from your account list.</p></section>');
        }

        $this->rememberManagedDomain($domain);

        $domainName = (string) ($domain['domain_name'] ?? '');
        $domainId = (string) ($domain['id'] ?? '');
        $defaults = $this->domainUpdateDefaults($domain);
        $flashMarkup = $this->renderFlashMessages($flash);
        $body = <<<HTML
<section class="checkout">
  <div class="checkout__main panel">
    <p class="eyebrow">Live Nameserver Update</p>
    <h1>Edit {$this->escape($domainName)}</h1>
    <p class="lead">Nameserver changes from this page are submitted live to the registrar. The registrant, admin, tech, and billing values shown here are registry handle IDs for reference and are not full contact profiles.</p>
    {$flashMarkup}
    <form method="post" action="{$this->escape($this->editPath())}" class="checkout-form">
      <input type="hidden" name="domain_id" value="{$this->escape($domainId)}">
      <input type="hidden" name="domain_name" value="{$this->escape($domainName)}">

      <div class="panel panel-subtle">
        <h2>Registry Handle IDs</h2>
        <div class="field-grid">
          <label><span>Registrant Handle</span><input type="text" name="registrant" value="{$this->escape($defaults['registrant'])}" placeholder="REG-123"></label>
          <label><span>Admin Handle</span><input type="text" name="contact_admin" value="{$this->escape($defaults['contact_admin'])}" placeholder="ADM-123"></label>
          <label><span>Tech Handle</span><input type="text" name="contact_tech" value="{$this->escape($defaults['contact_tech'])}" placeholder="TEC-123"></label>
          <label><span>Billing Handle</span><input type="text" name="contact_billing" value="{$this->escape($defaults['contact_billing'])}" placeholder="BIL-123"></label>
        </div>
      </div>

      <div class="panel panel-subtle">
        <h2>Nameservers</h2>
        <div class="field-grid">
          <label><span>ns1</span><input type="text" name="ns1" value="{$this->escape($defaults['ns1'])}" placeholder="ns1.example.net"></label>
          <label><span>ns2</span><input type="text" name="ns2" value="{$this->escape($defaults['ns2'])}" placeholder="ns2.example.net"></label>
          <label><span>ns3</span><input type="text" name="ns3" value="{$this->escape($defaults['ns3'])}" placeholder="Optional"></label>
          <label><span>ns4</span><input type="text" name="ns4" value="{$this->escape($defaults['ns4'])}" placeholder="Optional"></label>
        </div>
      </div>

      <label><span>Notes</span><textarea name="notes" rows="4" placeholder="Optional notes for the registrar team">{$this->escape($defaults['notes'])}</textarea></label>
      <div class="submit-row">
        <button type="submit" class="button button-primary">Update Nameservers Now</button>
        <a class="button button-secondary" href="{$this->escape($this->managePath())}">Back to My Domains</a>
      </div>
    </form>
  </div>
  <aside class="checkout__summary panel">
    <p class="eyebrow">Domain Summary</p>
    <h2>{$this->escape($domainName)}</h2>
    <div class="summary-row"><span>Provider</span><strong>{$this->escape($this->providerDisplayName((string) ($domain['provider_code'] ?? '')))}</strong></div>
    <div class="summary-row"><span>Status</span><strong>{$this->escape((string) ($domain['registrar_status'] ?? 'active'))}</strong></div>
    <div class="summary-row"><span>What this covers</span><strong>Registrant, admin, tech, billing, ns1-ns4</strong></div>
  </aside>
</section>
HTML;

        return $this->layout('Edit Domain Settings', $body);
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

    private function handleRenewalSubmit(array $post): string
    {
        $domain = $this->resolveManagedDomain($post);
        if ($domain === null) {
            $this->flashError('The requested domain is not available in this account.');
            $this->redirectNow($this->managePath());
        }

        $domainId = (string) ($domain['id'] ?? '');
        $domainName = (string) ($domain['domain_name'] ?? '');
        $providerCode = trim((string) ($domain['provider_code'] ?? ''));
        $provider = $this->app->provider($providerCode);
        if (! $provider instanceof DomainMutationInterface) {
            $this->flashError($this->providerDisplayName($providerCode) . ' does not support website renewals yet.');
            $this->redirectNow($this->buildManagedUrl($this->renewPath(), $domainId, $domainName));
        }

        $periodYears = max(1, (int) ($post['period_years'] ?? 1));
        $currentExpiryDate = $this->normalizeDateOnly((string) ($domain['expires_at'] ?? $domain['renewal_due_at'] ?? ''));

        if ($currentExpiryDate === null && $provider instanceof DomainPortfolioSyncInterface) {
            $sync = $provider->syncDomain(
                (string) ($domain['domain_name'] ?? ''),
                new SyncContext($providerCode, 'hub-pre-renew-sync', true),
            );
            if (($sync['ok'] ?? true) !== false) {
                $this->app->domainRepository()->updateFromSync((string) ($domain['id'] ?? ''), $sync);
                $domain = $this->resolveManagedDomain([
                    'domain_id' => (string) ($domain['id'] ?? ''),
                    'domain_name' => (string) ($domain['domain_name'] ?? ''),
                ]) ?? $domain;
                $currentExpiryDate = $this->normalizeDateOnly((string) ($domain['expires_at'] ?? $domain['renewal_due_at'] ?? ''));
            }
        }

        if ($currentExpiryDate === null) {
            $this->flashError('The current expiry date is not stored yet for ' . $domainName . '. Sync the domain first in control, then try the website renewal again.');
            $this->redirectNow($this->buildManagedUrl($this->renewPath(), $domainId, $domainName));
        }

        $result = $provider->renewDomain(
            (string) ($domain['domain_name'] ?? ''),
            $periodYears,
            ['current_expiry_date' => $currentExpiryDate],
        );

        if (! ($result['ok'] ?? false)) {
            $this->flashError((string) ($result['message'] ?? 'The live renewal failed.'));
            $this->redirectNow($this->buildManagedUrl($this->renewPath(), $domainId, $domainName));
        }

        if ($provider instanceof DomainPortfolioSyncInterface) {
            $sync = $provider->syncDomain(
                (string) ($domain['domain_name'] ?? ''),
                new SyncContext($providerCode, 'hub-post-renew-sync', true),
            );
            if (($sync['ok'] ?? true) !== false) {
                $this->app->domainRepository()->updateFromSync((string) ($domain['id'] ?? ''), $sync);
                $domain = $this->resolveManagedDomain([
                    'domain_id' => (string) ($domain['id'] ?? ''),
                    'domain_name' => (string) ($domain['domain_name'] ?? ''),
                ]) ?? $domain;
            }
        }

        $this->flashSuccess($domainName . ' renewed successfully. Current expiry: ' . $this->formatDateDisplay((string) ($domain['expires_at'] ?? $domain['renewal_due_at'] ?? 'Not yet synced')) . '.');
        $this->redirectNow($this->buildManagedUrl($this->renewPath(), $domainId, $domainName));
    }

    private function handleCancellationSubmit(array $post): string
    {
        $domain = $this->resolveManagedDomain($post);
        if ($domain === null) {
            $this->flashError('The requested domain is not available in this account.');
            $this->redirectNow($this->managePath());
        }

        $domainId = (string) ($domain['id'] ?? '');
        $domainName = (string) ($domain['domain_name'] ?? '');
        $tenantContext = $this->app->tenantContext();
        $customer = $this->app->customerRepository()->findById((string) ($domain['customer_id'] ?? ''));
        $customerEmail = trim((string) ($customer['email'] ?? ($tenantContext['acting_user_id'] ?? '')));
        $payload = array_replace($tenantContext, [
            'domain_name' => (string) ($domain['domain_name'] ?? ''),
            'reason' => trim((string) ($post['reason'] ?? '')),
            'requested_action' => 'cancel',
        ]);

        $order = $this->app->orderRepository()->createDomainActionOrder(
            $domain,
            $customerEmail,
            'cancel',
            'draft',
            $payload,
            1,
        );

        $this->flashSuccess('Cancellation request created. Order ' . ((string) ($order['order_number'] ?? '')) . ' has been saved for ' . $domainName . '.');
        $this->redirectNow($this->buildManagedUrl($this->cancelPath(), $domainId, $domainName));
    }

    private function handleOrderCancel(array $post): string
    {
        $tenantContext = $this->app->tenantContext();
        $tenantId = (string) ($tenantContext['tenant_id'] ?? '');
        $orderId = trim((string) ($post['order_id'] ?? ''));
        if ($orderId !== '' && $tenantId !== '') {
            $this->app->orderRepository()->cancelOpenOrder($orderId, $tenantId);
            $this->flashSuccess('The selected open order has been cancelled for this tenant account.');
        } else {
            $this->flashError('Unable to cancel the requested order.');
        }

        $this->redirectNow($this->managePath());
    }

    private function handleUpdateSubmit(array $post): string
    {
        $domain = $this->resolveManagedDomain($post);
        if ($domain === null) {
            $this->flashError('The requested domain is not available in this account.');
            $this->redirectNow($this->managePath());
        }

        $domainId = (string) ($domain['id'] ?? '');
        $domainName = (string) ($domain['domain_name'] ?? '');

        $nameservers = [];
        foreach (['ns1', 'ns2', 'ns3', 'ns4'] as $field) {
            $hostname = trim((string) ($post[$field] ?? ''));
            if ($hostname !== '') {
                $nameservers[] = ['hostname' => $hostname];
            }
        }

        $contacts = array_filter([
            'admin' => trim((string) ($post['contact_admin'] ?? '')),
            'tech' => trim((string) ($post['contact_tech'] ?? '')),
            'billing' => trim((string) ($post['contact_billing'] ?? '')),
        ], static fn (string $value): bool => $value !== '');

        $providerCode = trim((string) ($domain['provider_code'] ?? ''));
        $provider = $this->app->provider($providerCode);
        if (! $provider instanceof DomainMutationInterface) {
            $this->flashError($this->providerDisplayName($providerCode) . ' does not support website nameserver changes yet.');
            $this->redirectNow($this->buildManagedUrl($this->editPath(), $domainId, $domainName));
        }

        if ($nameservers === []) {
            $this->flashError('Enter at least one nameserver before submitting the website update.');
            $this->redirectNow($this->buildManagedUrl($this->editPath(), $domainId, $domainName));
        }

        $result = $provider->updateNameservers((string) ($domain['domain_name'] ?? ''), $nameservers);
        if (! ($result['ok'] ?? false)) {
            $this->flashError((string) ($result['message'] ?? 'The live nameserver update failed.'));
            $this->redirectNow($this->buildManagedUrl($this->editPath(), $domainId, $domainName));
        }

        if ($provider instanceof DomainPortfolioSyncInterface) {
            $sync = $provider->syncDomain(
                (string) ($domain['domain_name'] ?? ''),
                new SyncContext($providerCode, 'hub-post-nameserver-sync', true),
            );
            if (($sync['ok'] ?? true) !== false) {
                $this->app->domainRepository()->updateFromSync((string) ($domain['id'] ?? ''), $sync);
                $domain = $this->resolveManagedDomain([
                    'domain_id' => (string) ($domain['id'] ?? ''),
                    'domain_name' => (string) ($domain['domain_name'] ?? ''),
                ]) ?? $domain;
            }
        }

        $contactNote = $this->buildHandleReferenceNotice(trim((string) ($post['registrant'] ?? '')), $contacts);
        $this->flashSuccess('Nameservers updated for ' . $domainName . '.' . $contactNote);
        $this->redirectNow($this->buildManagedUrl($this->editPath(), $domainId, $domainName));
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
                    if ($providerCode === 'coza') {
                        error_log('[hub/domains][coza] availability failed: ' . json_encode([
                            'domain' => $fqdn,
                            'message' => $exception->getMessage(),
                            'diagnostics' => $this->app->providerRuntimeDiagnostics('coza'),
                        ], JSON_UNESCAPED_SLASHES));
                    }

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
        if ($this->usesPlatformLayout()) {
            return $this->platformLayout($title, $body);
        }

        return $this->standaloneLayout($title, $body);
    }

    private function standaloneLayout(string $title, string $body): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$this->escape($title)}</title>
  <style>{$this->styles()}</style>
</head>
<body class="domains-platform-body">
  <div class="domains-platform-page">
    <header class="topbar">
      <a href="{$this->escape($this->basePath())}" class="brand">
        <span class="brand__mark">H</span>
        <span>
          <strong>Hub</strong>
          <small>Client Domain Control</small>
        </span>
      </a>
      <nav class="topbar__nav">
        <a href="{$this->escape($this->basePath())}">Search</a>
        <a href="{$this->escape($this->managePath())}">My Domains</a>
        <a href="{$this->escape($this->registerPath())}">Register</a>
      </nav>
    </header>
    <main class="main-content">
      <div class="domains-platform-shell">{$body}</div>
    </main>
  </div>
</body>
</html>
HTML;
    }

    private function platformLayout(string $title, string $body): string
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['current_realm'] = 'hub';
        }

        $head = $this->captureInclude($this->platformIncludePath('complete-head.php'));
        $bodyStart = $this->captureInclude($this->platformIncludePath('complete-body-start.php'));
        $bodyEnd = $this->captureInclude($this->platformIncludePath('complete-body-end.php'));

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$this->escape($title)}</title>
  {$head}
  <style>{$this->styles()}</style>
</head>
<body class="domains-platform-body">
  {$bodyStart}
  <main class="main-content">
    <div class="domains-platform-page">
      <div class="domains-platform-shell">{$body}</div>
    </div>
  </main>
  {$bodyEnd}
</body>
</html>
HTML;
    }

    private function usesPlatformLayout(): bool
    {
        if (! str_starts_with($this->basePath(), '/hub/')) {
            return false;
        }

        return $this->platformIncludePath('complete-head.php') !== null;
    }

    private function platformIncludePath(string $file): ?string
    {
        $publicRoot = $this->platformPublicRoot();
        if ($publicRoot === null) {
            return null;
        }

        $path = $publicRoot . '/templates/global-ui/includes/' . ltrim($file, '/');

        return is_file($path) ? $path : null;
    }

    private function platformPublicRoot(): ?string
    {
        $configured = $_ENV['MH_PUBLIC_ROOT'] ?? $_SERVER['MH_PUBLIC_ROOT'] ?? getenv('MH_PUBLIC_ROOT');
        if (is_string($configured) && $configured !== '' && is_dir($configured)) {
            return rtrim($configured, '/\\');
        }

        $cueBootstrapPath = $_ENV['CUE_BOOTSTRAP_PATH'] ?? $_SERVER['CUE_BOOTSTRAP_PATH'] ?? getenv('CUE_BOOTSTRAP_PATH');
        if (is_string($cueBootstrapPath) && $cueBootstrapPath !== '' && is_file($cueBootstrapPath)) {
            $publicRoot = dirname(dirname($cueBootstrapPath));
            if (is_dir($publicRoot)) {
                return rtrim($publicRoot, '/\\');
            }
        }

        return null;
    }

    private function captureInclude(?string $path): string
    {
        if ($path === null || ! is_file($path)) {
            return '';
        }

        ob_start();
        include $path;

        return (string) ob_get_clean();
    }

    private function styles(): string
    {
        return <<<'CSS'
:root {
  color-scheme: dark;
  --domains-bg: #020617;
  --domains-surface: rgba(6, 18, 41, 0.9);
  --domains-surface-soft: rgba(15, 23, 42, 0.78);
  --domains-border: rgba(125, 211, 252, 0.18);
  --domains-accent: #22d3ee;
  --domains-accent-strong: #06b6d4;
  --domains-text: #e2e8f0;
  --domains-muted: #94a3b8;
  --domains-success: #22c55e;
  --domains-danger: #f97316;
  --domains-warning: #facc15;
  --domains-shadow: 0 22px 60px rgba(2, 6, 23, 0.48);
}

* {
  box-sizing: border-box;
}

body.domains-platform-body {
  margin: 0;
  background:
    radial-gradient(circle at top, rgba(34, 211, 238, 0.10), transparent 32%),
    linear-gradient(180deg, #020617 0%, #030712 100%);
  color: var(--domains-text);
  font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}

.domains-platform-page {
  width: min(1180px, calc(100% - 32px));
  margin: 0 auto;
  padding: 28px 0 48px;
}

.domains-platform-shell {
  display: grid;
  gap: 24px;
}

.topbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 18px;
  padding: 18px 22px;
  border: 1px solid var(--domains-border);
  background: var(--domains-surface);
  border-radius: 24px;
  box-shadow: var(--domains-shadow);
}

.topbar__nav {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.topbar__nav a,
.brand {
  color: var(--domains-text);
  text-decoration: none;
}

.topbar__nav a {
  padding: 10px 16px;
  border-radius: 999px;
  border: 1px solid rgba(148, 163, 184, 0.18);
  background: rgba(15, 23, 42, 0.72);
}

.brand {
  display: inline-flex;
  align-items: center;
  gap: 12px;
}

.brand__mark {
  width: 42px;
  height: 42px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 14px;
  background: linear-gradient(135deg, var(--domains-accent), #38bdf8);
  color: #001018;
  font-weight: 800;
}

.brand small,
.muted,
.lead,
.panel__head p,
.summary-row span,
.hero__card li,
.hero__card p {
  color: var(--domains-muted);
}

.hero,
.checkout,
.result-layout {
  display: grid;
  gap: 20px;
}

.hero {
  grid-template-columns: minmax(0, 1.7fr) minmax(280px, 0.9fr);
}

.checkout,
.result-layout {
  grid-template-columns: minmax(0, 1.5fr) minmax(280px, 0.9fr);
}

.panel,
.hero__card {
  border: 1px solid var(--domains-border);
  background: var(--domains-surface);
  border-radius: 24px;
  box-shadow: var(--domains-shadow);
}

.panel,
.hero__content,
.hero__card,
.checkout__main,
.checkout__summary {
  padding: 24px;
}

.panel-subtle {
  background: var(--domains-surface-soft);
  border-radius: 20px;
  padding: 18px;
}

.eyebrow {
  margin: 0 0 8px;
  color: var(--domains-accent);
  text-transform: uppercase;
  letter-spacing: 0.14em;
  font-size: 0.76rem;
  font-weight: 700;
}

h1,
h2,
h3,
ol,
pre,
p {
  margin-top: 0;
}

h1 {
  margin-bottom: 12px;
  font-size: clamp(2rem, 5vw, 3rem);
  line-height: 1.05;
}

h2 {
  margin-bottom: 14px;
}

.search-bar,
.field-grid,
.submit-row,
.result-actions,
.tld-pills {
  display: flex;
  gap: 14px;
  flex-wrap: wrap;
}

.search-bar {
  align-items: stretch;
  margin: 24px 0 16px;
}

.search-bar input,
.field-grid input,
.field-grid select,
textarea {
  width: 100%;
  padding: 14px 16px;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.22);
  background: rgba(2, 6, 23, 0.72);
  color: var(--domains-text);
}

textarea {
  min-height: 120px;
  resize: vertical;
}

.search-bar input {
  min-width: 260px;
  flex: 1 1 320px;
}

.field-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.field-grid label,
.checkbox,
.checkbox-inline {
  display: grid;
  gap: 8px;
  font-size: 0.95rem;
}

.checkbox,
.checkbox-inline {
  grid-auto-flow: column;
  justify-content: start;
  align-items: center;
}

.button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 12px 18px;
  border-radius: 999px;
  border: 1px solid transparent;
  text-decoration: none;
  font-weight: 700;
}

.button-primary {
  background: linear-gradient(135deg, var(--domains-accent), #38bdf8);
  color: #001018;
}

.button-secondary,
.button-muted {
  background: rgba(15, 23, 42, 0.8);
  color: var(--domains-text);
  border-color: rgba(148, 163, 184, 0.22);
}

.inline-form {
  margin: 0;
}

.domain-card {
  display: flex;
  justify-content: space-between;
  gap: 18px;
  padding: 18px 0;
  border-top: 1px solid rgba(148, 163, 184, 0.12);
}

.domain-card:first-of-type {
  border-top: 0;
  padding-top: 0;
}

.domain-card__aside {
  min-width: 160px;
  display: grid;
  justify-items: end;
  gap: 10px;
}

.status {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  width: fit-content;
  padding: 6px 10px;
  border-radius: 999px;
  font-size: 0.82rem;
  font-weight: 700;
}

.status-available {
  background: rgba(34, 197, 94, 0.16);
  color: #bbf7d0;
}

.status-taken {
  background: rgba(249, 115, 22, 0.16);
  color: #fdba74;
}

.status-error {
  background: rgba(239, 68, 68, 0.16);
  color: #fecaca;
}

.status-pending {
  background: rgba(250, 204, 21, 0.16);
  color: #fde68a;
}

.summary-row {
  display: flex;
  justify-content: space-between;
  gap: 14px;
  padding: 10px 0;
  border-top: 1px solid rgba(148, 163, 184, 0.12);
}

.summary-row:first-child {
  border-top: 0;
  padding-top: 0;
}

.summary-row strong,
.domain-card h3 {
  color: #f8fafc;
}

.tld-pills span {
  padding: 10px 14px;
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.82);
  border: 1px solid rgba(148, 163, 184, 0.18);
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  border: 0;
}

pre {
  overflow: auto;
  padding: 18px;
  border-radius: 18px;
  background: rgba(2, 6, 23, 0.92);
  border: 1px solid rgba(148, 163, 184, 0.16);
  color: #cbd5e1;
}

@media (max-width: 920px) {
  .hero,
  .checkout,
  .result-layout {
    grid-template-columns: 1fr;
  }

  .field-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 640px) {
  .domains-platform-page {
    width: min(100% - 20px, 1180px);
    padding-top: 20px;
  }

  .topbar,
  .panel,
  .hero__content,
  .hero__card,
  .checkout__main,
  .checkout__summary {
    padding: 18px;
  }

  .domain-card,
  .summary-row {
    flex-direction: column;
  }

  .domain-card__aside {
    justify-items: start;
    min-width: 0;
  }
}
CSS;
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

        if (str_starts_with($requestPath, '/hub/companies/domains')) {
            return '/hub/companies/domains';
        }

        return str_starts_with($requestPath, '/hub/domains') ? '/hub/domains' : '/';
    }

    private function registerPath(): string
    {
        return match ($this->basePath()) {
            '/hub/domains' => '/hub/domains/register/',
            '/hub/companies/domains' => '/hub/companies/domains/register/',
            default => '/register/',
        };
    }

    private function managePath(): string
    {
        return match ($this->basePath()) {
            '/hub/domains' => '/hub/domains/manage/',
            '/hub/companies/domains' => '/hub/companies/domains/manage/',
            default => '/manage/',
        };
    }

    private function renewPath(): string
    {
        return match ($this->basePath()) {
            '/hub/domains' => '/hub/domains/renew/',
            '/hub/companies/domains' => '/hub/companies/domains/renew/',
            default => '/renew/',
        };
    }

    private function cancelPath(): string
    {
        return match ($this->basePath()) {
            '/hub/domains' => '/hub/domains/cancel/',
            '/hub/companies/domains' => '/hub/companies/domains/cancel/',
            default => '/cancel/',
        };
    }

    private function cancelOrderPath(): string
    {
        return match ($this->basePath()) {
            '/hub/domains' => '/hub/domains/orders/cancel/',
            '/hub/companies/domains' => '/hub/companies/domains/orders/cancel/',
            default => '/orders/cancel/',
        };
    }

    private function editPath(): string
    {
        return match ($this->basePath()) {
            '/hub/domains' => '/hub/domains/edit/',
            '/hub/companies/domains' => '/hub/companies/domains/edit/',
            default => '/edit/',
        };
    }

    private function resolveManagedDomain(array $payload): ?array
    {
        $tenantContext = $this->app->tenantContext();
        $tenantId = (string) ($tenantContext['tenant_id'] ?? '');
        $ownerType = (string) ($tenantContext['owner_type'] ?? 'user');
        $ownerId = (string) ($tenantContext['owner_id'] ?? $tenantId);

        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $queryString = (string) parse_url($requestUri, PHP_URL_QUERY);
        $queryParams = [];
        if ($queryString !== '') {
            parse_str($queryString, $queryParams);
        }
        $superGlobals = $_REQUEST ?? [];
        if (! is_array($superGlobals)) {
            $superGlobals = [];
        }
        $merged = array_replace($superGlobals, $queryParams, $payload);

        $domainId = trim((string) ($merged['domain_id'] ?? ''));
        if ($domainId !== '') {
            $domain = $this->app->domainRepository()->findById($domainId);
            if ($domain !== null && $this->domainBelongsToAccount($domain, $tenantId, $ownerType, $ownerId)) {
                return $domain;
            }
        }

        $domainName = strtolower(trim((string) ($merged['domain'] ?? $merged['domain_name'] ?? '')));
        if ($domainName !== '') {
            $domain = $this->app->domainRepository()->findForAccountByName($tenantId, $ownerType, $ownerId, $domainName);
            if ($domain !== null) {
                return $domain;
            }
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $rememberedDomainId = trim((string) ($_SESSION['mh_hub_selected_domain_id'] ?? ''));
            if ($rememberedDomainId !== '') {
                $domain = $this->app->domainRepository()->findById($rememberedDomainId);
                if ($domain !== null && $this->domainBelongsToAccount($domain, $tenantId, $ownerType, $ownerId)) {
                    return $domain;
                }
            }

            $rememberedDomainName = strtolower(trim((string) ($_SESSION['mh_hub_selected_domain_name'] ?? '')));
            if ($rememberedDomainName !== '') {
                return $this->app->domainRepository()->findForAccountByName($tenantId, $ownerType, $ownerId, $rememberedDomainName);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $domain
     */
    private function domainBelongsToAccount(array $domain, string $tenantId, string $ownerType, string $ownerId): bool
    {
        if ((string) ($domain['tenant_id'] ?? '') === $tenantId) {
            return true;
        }

        return (string) ($domain['owner_type'] ?? '') === $ownerType
            && (string) ($domain['owner_id'] ?? '') === $ownerId;
    }

    private function manageActionForm(string $action, string $label, string $buttonClass, string $domainId, string $domainName): string
    {
        return '<form method="get" action="' . $this->escape($action) . '" class="inline-form">'
            . '<input type="hidden" name="domain_id" value="' . $this->escape($domainId) . '">'
            . '<input type="hidden" name="domain" value="' . $this->escape($domainName) . '">'
            . '<button type="submit" class="' . $this->escape($buttonClass) . '">' . $this->escape($label) . '</button>'
            . '</form>';
    }

    /**
     * @param array<string, mixed> $domain
     */
    private function rememberManagedDomain(array $domain): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION['mh_hub_selected_domain_id'] = (string) ($domain['id'] ?? '');
        $_SESSION['mh_hub_selected_domain_name'] = (string) ($domain['domain_name'] ?? '');
    }

    private function buildManagedUrl(string $basePath, string $domainId, string $domainName): string
    {
        $separator = str_contains($basePath, '?') ? '&' : '?';
        $query = [];
        if ($domainId !== '') {
            $query['domain_id'] = $domainId;
        }
        if ($domainName !== '' && $query === []) {
            $query['domain'] = $domainName;
        }

        return $query === [] ? $basePath : $basePath . $separator . http_build_query($query);
    }

    private function redirectNow(string $url): never
    {
        header('Location: ' . $url, true, 303);
        exit;
    }

    /**
     * @return array{success: list<string>, error: list<string>}
     */
    private function popFlashMessages(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return ['success' => [], 'error' => []];
        }

        $success = $_SESSION['mh_hub_flash_success'] ?? [];
        $error = $_SESSION['mh_hub_flash_error'] ?? [];
        unset($_SESSION['mh_hub_flash_success'], $_SESSION['mh_hub_flash_error']);

        return [
            'success' => is_array($success) ? $success : [],
            'error' => is_array($error) ? $error : [],
        ];
    }

    private function flashSuccess(string $message): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        if (! isset($_SESSION['mh_hub_flash_success']) || ! is_array($_SESSION['mh_hub_flash_success'])) {
            $_SESSION['mh_hub_flash_success'] = [];
        }

        $_SESSION['mh_hub_flash_success'][] = $message;
    }

    private function flashError(string $message): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        if (! isset($_SESSION['mh_hub_flash_error']) || ! is_array($_SESSION['mh_hub_flash_error'])) {
            $_SESSION['mh_hub_flash_error'] = [];
        }

        $_SESSION['mh_hub_flash_error'][] = $message;
    }

    /**
     * @param array{success: list<string>, error: list<string>} $flashes
     */
    private function renderFlashMessages(array $flashes): string
    {
        $parts = [];
        foreach ($flashes['success'] as $message) {
            $parts[] = '<div class="panel panel-subtle" style="margin-bottom:18px;"><p class="status status-available" style="width:auto;">' . $this->escape($message) . '</p></div>';
        }
        foreach ($flashes['error'] as $message) {
            $parts[] = '<div class="panel panel-subtle" style="margin-bottom:18px;"><p class="status status-error" style="width:auto;">' . $this->escape($message) . '</p></div>';
        }

        return implode('', $parts);
    }

    /**
     * @param array<string, string> $contacts
     */
    private function buildHandleReferenceNotice(string $registrant, array $contacts): string
    {
        if ($registrant === '' && $contacts === []) {
            return '';
        }

        return ' Registry handle fields are currently reference-only in this website flow, so registrant/admin/tech/billing handle edits were not sent live.';
    }

    private function statusClassForDomain(string $status): string
    {
        return match (strtolower(trim($status))) {
            'active', 'completed' => 'status status-available',
            'pending_submission', 'queued', 'processing' => 'status status-pending',
            'submission_failed', 'failed' => 'status status-error',
            default => 'status status-taken',
        };
    }

    private function domainStatusLabel(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'pending_submission' => 'Pending Submission',
            'submission_failed' => 'Submission Failed',
            default => $status === '' ? 'Unknown Status' : ucwords(str_replace('_', ' ', $status)),
        };
    }

    private function statusClassForOrder(string $status): string
    {
        return match (strtolower(trim($status))) {
            'completed' => 'status status-available',
            'draft', 'queued', 'processing' => 'status status-pending',
            'failed' => 'status status-error',
            'cancelled' => 'status status-taken',
            default => 'status status-pending',
        };
    }

    /**
     * @param array<string, mixed> $domain
     * @return array{registrant: string, contact_admin: string, contact_tech: string, contact_billing: string, ns1: string, ns2: string, ns3: string, ns4: string, notes: string}
     */
    private function domainUpdateDefaults(array $domain): array
    {
        $defaults = [
            'registrant' => '',
            'contact_admin' => '',
            'contact_tech' => '',
            'contact_billing' => '',
            'ns1' => '',
            'ns2' => '',
            'ns3' => '',
            'ns4' => '',
            'notes' => '',
        ];

        $metadata = [];
        if (is_string($domain['metadata_json'] ?? null) && trim((string) $domain['metadata_json']) !== '') {
            $decoded = json_decode((string) $domain['metadata_json'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        $draftPayload = is_array($metadata['draft_payload'] ?? null) ? $metadata['draft_payload'] : [];
        $contacts = is_array($metadata['contacts'] ?? null) ? $metadata['contacts'] : [];
        $importMetadata = is_array($metadata['import'] ?? null) ? $metadata['import'] : [];
        $defaults['registrant'] = trim((string) ($draftPayload['registrant'] ?? ($metadata['registrant'] ?? ($importMetadata['registrant'] ?? ''))));
        $defaults['contact_admin'] = trim((string) ($draftPayload['contacts']['admin'] ?? ($contacts['admin'] ?? ($importMetadata['admin'] ?? ''))));
        $defaults['contact_tech'] = trim((string) ($draftPayload['contacts']['tech'] ?? ($contacts['tech'] ?? ($importMetadata['tech'] ?? ''))));
        $defaults['contact_billing'] = trim((string) ($draftPayload['contacts']['billing'] ?? ($contacts['billing'] ?? ($importMetadata['billing'] ?? ''))));

        $nameservers = $this->app->domainRepository()->listNameservers((string) ($domain['id'] ?? ''));
        foreach (array_slice($nameservers, 0, 4) as $index => $nameserver) {
            $defaults['ns' . ($index + 1)] = trim((string) ($nameserver['hostname'] ?? ''));
        }

        return $defaults;
    }
}
