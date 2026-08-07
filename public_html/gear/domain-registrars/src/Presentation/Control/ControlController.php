<?php

declare(strict_types=1);

namespace App\Presentation\Control;

use App\Application;
use App\Domain\Provider\Contracts\DomainMutationInterface;
use App\Domain\Provider\Contracts\DomainPortfolioSyncInterface;
use App\Domain\Sync\SyncContext;
use Throwable;

final class ControlController
{
    public function __construct(
        private readonly Application $app,
    ) {
    }

    public function handle(string $path, string $method, array $query, array $post): string
    {
        if ($this->requiresAuth()) {
            return $this->renderAuthRequired();
        }

        $path = $this->normalizePath($path);

        return match ([$path, strtoupper($method)]) {
            ['/', 'GET'] => $this->renderDashboard($query),
            ['/orders', 'GET'] => $this->renderOrders(),
            ['/domains', 'GET'] => $this->renderDomains($query),
            ['/domains/', 'GET'] => $this->renderDomains($query),
            ['/domains/manage', 'GET'] => $this->renderDomains($query),
            ['/domains/manage/', 'GET'] => $this->renderDomains($query),
            ['/domains/sync/portfolio', 'GET'] => $this->renderDomains([...$query, 'focus' => 'bulk-sync']),
            ['/domains', 'POST'] => $this->handleDomainBulkAction($post, $query),
            ['/domains/sync', 'POST'] => $this->handleDomainSync($post),
            ['/domains/renew', 'POST'] => $this->handleDomainRenew($post),
            ['/domains/assign', 'GET'] => $this->renderDomainAssignPage($query),
            ['/domains/assign', 'POST'] => $this->handleDomainAssign($post),
            ['/domains/sync/portfolio', 'POST'] => $this->handleDomainPortfolioSync($post),
            ['/tasks', 'GET'] => $this->renderTasks(),
            ['/providers', 'GET'] => $this->renderProvidersIndex($query),
            ['/providers/', 'GET'] => $this->renderProvidersIndex($query),
            ['/providers/coza', 'GET'] => $this->renderCozaSettings($query),
            ['/providers/coza', 'POST'] => $this->handleCozaSettingsSave($post),
            ['/providers/netearthone', 'GET'] => $this->renderNetEarthOneSettings($query),
            ['/providers/netearthone', 'POST'] => $this->handleNetEarthOneSettingsSave($post),
            ['/tasks/enqueue', 'GET'] => $this->renderTaskEnqueuePage(),
            ['/tasks/enqueue', 'POST'] => $this->handleTaskEnqueue($post),
            default => $this->renderNotFound(),
        };
    }

    private function requiresAuth(): bool
    {
        $role = isset($_SESSION['mh_auth_role']) ? strtolower(trim((string) $_SESSION['mh_auth_role'])) : '';
        if ($role !== '' && stripos($role, 'kripzmaster') !== false) {
            return false;
        }

        $username = $this->app->config()->nullableString('CONTROL_USERNAME');
        $password = $this->app->config()->nullableString('CONTROL_PASSWORD');

        if ($username === null || $password === null) {
            return false;
        }

        $actualUser = $_SERVER['PHP_AUTH_USER'] ?? null;
        $actualPassword = $_SERVER['PHP_AUTH_PW'] ?? null;

        return $actualUser !== $username || $actualPassword !== $password;
    }

    private function renderAuthRequired(): string
    {
        header('WWW-Authenticate: Basic realm="Registrar Control"');
        http_response_code(401);

        return $this->layout(
            'Authentication Required',
            '<section class="panel"><h1>Authentication Required</h1><p class="muted">Control authentication is required.</p></section>'
        );
    }

    private function renderDashboard(array $query): string
    {
        $stats = $this->app->dashboardRepository()->stats();
        $recentOrders = $this->app->orderRepository()->listRecent(8);
        $recentDomains = $this->app->domainRepository()->listRecent(8);
        $recentTasks = $this->app->taskQueueRepository()->listRecent(8);
        $flash = trim((string) ($query['flash'] ?? ''));
        $basePath = $this->basePath();
        $ordersPath = $this->ordersPath();
        $domainsPath = $this->domainsPath();
        $tasksPath = $this->tasksPath();
        $cozaSettingsPath = $this->providersCozaPath();

        $ordersMarkup = $this->renderTable(
            ['Order', 'Provider', 'Domain', 'Status', 'Mode', 'Created'],
            array_map(
                fn (array $order): array => [
                    (string) $order['order_number'],
                    $this->providerDisplayName((string) ($order['provider_code'] ?? '')),
                    (string) $order['domain_name'],
                    (string) $order['status'],
                    (string) $order['submission_mode'],
                    (string) $order['created_at'],
                ],
                $recentOrders,
            ),
        );

        $domainsMarkup = $this->renderTable(
            ['Provider', 'Domain', 'Status', 'Expires', 'Updated'],
            array_map(
                fn (array $domain): array => [
                    $this->providerDisplayName((string) ($domain['provider_code'] ?? '')),
                    (string) $domain['domain_name'],
                    (string) $domain['registrar_status'],
                    (string) ($domain['expires_at'] ?? '-'),
                    (string) $domain['updated_at'],
                ],
                $recentDomains,
            ),
        );

        $tasksMarkup = $this->renderTable(
            ['Task', 'Queue', 'Status', 'Attempts', 'Created'],
            array_map(
                static fn (array $task): array => [
                    (string) $task['task_type'],
                    (string) $task['queue_name'],
                    (string) $task['status'],
                    (string) $task['attempts'],
                    (string) $task['created_at'],
                ],
                $recentTasks,
            ),
        );

        $flashMarkup = $flash === '' ? '' : '<div class="notice">' . $this->escape($flash) . '</div>';
        $providersIndexPath = $this->providersIndexPath();

        $body = <<<HTML
{$flashMarkup}
<section class="dash-grid">
  <article class="metric-card"><span>Domains</span><strong>{$stats['domains']}</strong></article>
  <article class="metric-card"><span>Orders</span><strong>{$stats['orders']}</strong></article>
  <article class="metric-card"><span>Queued Tasks</span><strong>{$stats['queued_tasks']}</strong></article>
  <article class="metric-card"><span>Failed Tasks</span><strong>{$stats['failed_tasks']}</strong></article>
</section>

<section class="panel">
  <div class="panel-head">
    <div>
      <p class="eyebrow">Worker Control</p>
      <h1>Operational dashboard</h1>
      <p class="muted">Queue provider work, review recent orders, and keep the registrar service moving.</p>
    </div>
    <a href="{$this->escape($providersIndexPath)}">Provider settings</a>
  </div>
  <div class="action-grid">
    {$this->taskForm('sync_pricing', 'pricing', 'Queue pricing sync')}
    {$this->taskForm('sync_domain_dates', 'dates', 'Queue date sync')}
    {$this->taskForm('sync_domain_portfolio', 'sync', 'Queue portfolio sync')}
    {$this->taskForm('retry_failed_sync_runs', 'retries', 'Retry failed tasks')}
  </div>
</section>

<section class="panel">
  <div class="panel-head"><h2>Recent Orders</h2><a href="{$this->escape($ordersPath)}">View all</a></div>
  {$ordersMarkup}
</section>

<section class="panel">
  <div class="panel-head"><h2>Recent Domains</h2><a href="{$this->escape($domainsPath)}">View all</a></div>
  {$domainsMarkup}
</section>

<section class="panel">
  <div class="panel-head"><h2>Recent Tasks</h2><a href="{$this->escape($tasksPath)}">View all</a></div>
  {$tasksMarkup}
</section>
HTML;

        return $this->layout('Registrar Control', $body);
    }

    private function renderOrders(): string
    {
        $rows = $this->renderTable(
            ['Order', 'Provider', 'Domain', 'Status', 'Mode', 'Email', 'Created'],
            array_map(
                fn (array $order): array => [
                    (string) $order['order_number'],
                    $this->providerDisplayName((string) ($order['provider_code'] ?? '')),
                    (string) $order['domain_name'],
                    (string) $order['status'],
                    (string) $order['submission_mode'],
                    (string) $order['customer_email'],
                    (string) $order['created_at'],
                ],
                $this->app->orderRepository()->listRecent(50),
            ),
        );

        return $this->layout('Orders', '<section class="panel"><div class="panel-head"><h1>Orders</h1><a href="' . $this->escape($this->basePath()) . '">Back to dashboard</a></div>' . $rows . '</section>');
    }

    private function renderDomains(array $query = []): string
    {
        $flash = trim((string) ($query['flash'] ?? ''));
        $filters = [
            'keyword' => trim((string) ($query['keyword'] ?? ($query['q'] ?? ''))),
            'tld' => trim((string) ($query['tld'] ?? ($query['extension'] ?? ''))),
            'provider_code' => trim((string) ($query['provider'] ?? ($query['provider_code'] ?? ''))),
            'owner_type' => trim((string) ($query['owner_type'] ?? '')),
            'registrar_status' => trim((string) ($query['status'] ?? ($query['registrar_status'] ?? ''))),
            'renewal_from' => trim((string) ($query['renewal_from'] ?? ($query['renew'] ?? ''))),
            'renewal_to' => trim((string) ($query['renewal_to'] ?? '')),
            'registered_from' => trim((string) ($query['registered_from'] ?? '')),
            'registered_to' => trim((string) ($query['registered_to'] ?? '')),
        ];
        $page = (int) ($query['page'] ?? 1);
        $perPageRaw = (string) ($query['per_page'] ?? ($query['show'] ?? '50'));
        $validSizes = [5, 10, 50, 100];
        $perPage = 50;
        if (strtolower(trim($perPageRaw)) === 'all') {
            $perPage = 0;
        } elseif (ctype_digit(trim($perPageRaw))) {
            $perPage = (int) trim($perPageRaw);
            if (! in_array($perPage, $validSizes, true)) {
                $perPage = 50;
            }
        }
        [$domains, $total, $page, $totalPages] = [[], 0, 1, 1];
        $tlds = [];
        $domainLoadError = null;
        try {
            [$domains, $total, $page, $totalPages] = $this->app->domainRepository()->search($filters, $page, $perPage);
        } catch (Throwable $t) {
            $domainLoadError = $t->getMessage();
            error_log('[control/domain-registrars][renderDomains:search] ' . $t->getMessage());
        }
        try {
            $tlds = $this->app->domainRepository()->listTlds(200);
        } catch (Throwable $t) {
            error_log('[control/domain-registrars][renderDomains:listTlds] ' . $t->getMessage());
        }
        $flashMarkup = $flash === '' ? '' : '<div class="notice">' . $this->escape($flash) . '</div>';
        if ($domainLoadError !== null) {
            $flashMarkup = ($flashMarkup !== '' ? ($flashMarkup . "\n") : '')
                . '<div class="notice" style="border-color:#f59e0b;background:#1f2937;color:#fde68a;">'
                . '<strong>Portfolio not ready yet.</strong> '
                . 'If this is the first load, open <a style="color:#60a5fa;" href="' . $this->escape($this->providersNetEarthOnePath()) . '">NetEarthOne provider settings</a> once to create the default provider account, then return here and run Sync All. '
                . '<span class="muted" style="font-size:12px;">(' . $this->escape($domainLoadError) . ')</span>'
                . '</div>';
        }
        $domainCards = $domains === []
            ? '<p class="muted">No records yet. Run Sync All below to import your registrar portfolio.</p>'
            : implode('', array_map(fn (array $domain): string => $this->renderDomainManagementCard($domain), $domains));
        $rows = $this->renderRawTable(
            ['Provider', 'Domain', 'TLD', 'Status', 'Owner Type', 'Owner ID', 'Tenant', 'Registered', 'Expires', 'Updated', 'Actions'],
            array_map(
                fn (array $domain): array => [
                    $this->providerDisplayName((string) ($domain['provider_code'] ?? '')),
                    (string) $domain['domain_name'],
                    (string) ($domain['tld'] ?? '-'),
                    (string) ($domain['registrar_status'] ?? '-'),
                    (string) ($domain['owner_type'] ?? '-'),
                    (string) ($domain['owner_id'] ?? '-'),
                    (string) ($domain['tenant_id'] ?? '-'),
                    (string) ($domain['registered_at'] ?? '-'),
                    (string) ($domain['expires_at'] ?? '-'),
                    (string) $domain['updated_at'],
                    $this->renderDomainRowActions($domain),
                ],
                $domains,
            ),
            [10],
        );
        $tlds = $this->app->domainRepository()->listTlds(200);
        $tldOptions = '<option value="">All Extensions</option>';
        foreach ($tlds as $tld) {
            $selected = $filters['tld'] === $tld ? ' selected' : '';
            $tldOptions .= '<option value="' . $this->escape($tld) . '"' . $selected . '>.' . $this->escape($tld) . '</option>';
        }
        $providerOptions = '<option value="">All Providers</option>';
        foreach (['coza' => '.co.za Registry', 'netearthone' => 'NetEarthOne'] as $code => $name) {
            $selected = $filters['provider_code'] === $code ? ' selected' : '';
            $providerOptions .= '<option value="' . $this->escape($code) . '"' . $selected . '>' . $this->escape($name) . '</option>';
        }
        $sizeOptions = '';
        foreach ([5, 10, 50, 100] as $size) {
            $selected = (string) $perPage === (string) $size ? ' selected' : '';
            $sizeOptions .= '<option value="' . $size . '"' . $selected . '>' . $size . '</option>';
        }
        $selectedAll = $perPage === 0 ? ' selected' : '';
        $sizeOptions .= '<option value="all"' . $selectedAll . '>All</option>';
        $ownerOptions = '<option value="">All Owners</option>';
        foreach (['registrar' => 'Registrar Pool', 'tenant' => 'Tenant Pool', 'reseller' => 'Reseller Pool', 'system' => 'System Pool', 'user' => 'User Owned', 'company' => 'Company Owned', 'persona' => 'Persona Owned'] as $code => $name) {
            $selected = $filters['owner_type'] === $code ? ' selected' : '';
            $ownerOptions .= '<option value="' . $this->escape($code) . '"' . $selected . '>' . $this->escape($name) . '</option>';
        }
        $searchQueryPrefix = [];
        foreach ($filters as $k => $v) {
            if (is_string($v) && $v !== '') {
                $searchQueryPrefix[] = rawurlencode($k) . '=' . rawurlencode($v);
            }
        }
        if ($perPage !== 50) {
            $searchQueryPrefix[] = 'per_page=' . rawurlencode((string) ($perPage === 0 ? 'all' : $perPage));
        }
        $queryPrefixNoPage = implode('&', $searchQueryPrefix);
        $pagination = '';
        if ($perPage !== 0 && $totalPages > 1) {
            $pagination = '<nav class="pagination" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:20px 0;justify-content:space-between;"><div>Page ' . $page . ' of ' . $totalPages . ', showing ' . count($domains) . ' of ' . $total . ' total</div><div style="display:flex;gap:4px;flex-wrap:wrap;">';
            for ($p = 1; $p <= $totalPages; ++$p) {
                $sep = $queryPrefixNoPage !== '' ? '&' : '';
                $href = $this->escape($this->domainsPath()) . ($queryPrefixNoPage !== '' ? '?' . $queryPrefixNoPage . $sep . 'page=' . $p : '?page=' . $p);
                $cls = $p === $page ? 'button button-primary' : 'button button-secondary';
                $pagination .= '<a class="' . $cls . '" href="' . $href . '">' . $p . '</a>';
            }
            $pagination .= '</div></nav>';
        } else {
            $pagination = '<div class="muted" style="margin:12px 0;">' . ($total === 0 ? '0 records' : ('Showing ' . count($domains) . ' of ' . $total . ' total records.')) . '</div>';
        }
        $scopeNote = '<article class="info-card">'
            . '<h2>Portfolio Scope</h2>'
            . '<p class="muted"><strong>Registrar / Control view:</strong> This page lists <strong>every domain record in the system</strong> (total rows: '
            . (int) $total
            . '). The reseller/registrar owns the pool of domains; allocate them to specific users or companies via the <em>Assign / Move</em> action in each row.'
            . ' The <em>Hub portfolio (/hub/companies/domains/manage)</em> only shows domains that have been registered through the user/company or explicitly transferred to that user or company (owner_type user, company, persona with matching owner_id; pool rows tenant/reseller/registrar/system are intentionally hidden from tenant hub views).</p>'
            . '</article>';
        $syncAllPath = $this->escape($this->domainsPath() . '/sync/portfolio');
        $searchFormAction = $this->escape($this->domainsPath());
        $searchBlock = <<<HTML
<article class="info-card">
  <h2>Search Registered Domains</h2>
  <form method="get" action="{$searchFormAction}" class="checkout-form">
    <div class="field-grid">
      <label>
        <span>Search (domain, order, customer, registrant)</span>
        <input type="text" name="keyword" value="{$this->escape($filters['keyword'])}" placeholder="example.com, customer_123, order_456">
      </label>
      <label>
        <span>Extension (TLD)</span>
        <select name="tld">{$tldOptions}</select>
      </label>
      <label>
        <span>Provider</span>
        <select name="provider">{$providerOptions}</select>
      </label>
      <label>
        <span>Owner</span>
        <select name="owner_type">{$ownerOptions}</select>
      </label>
      <label>
        <span>Renewal Date From</span>
        <input type="date" name="renewal_from" value="{$this->escape($filters['renewal_from'])}">
      </label>
      <label>
        <span>Renewal Date To</span>
        <input type="date" name="renewal_to" value="{$this->escape($filters['renewal_to'])}">
      </label>
      <label>
        <span>Registration From</span>
        <input type="date" name="registered_from" value="{$this->escape($filters['registered_from'])}">
      </label>
      <label>
        <span>Registration To</span>
        <input type="date" name="registered_to" value="{$this->escape($filters['registered_to'])}">
      </label>
      <label>
        <span>Show per page</span>
        <select name="per_page">{$sizeOptions}</select>
      </label>
    </div>
    <div class="form-actions">
      <button type="submit" class="button-primary">Search</button>
      <a class="button button-secondary" href="{$this->escape($this->domainsPath())}">Reset</a>
    </div>
  </form>
</article>
HTML;
        $bulkSyncBlock = <<<HTML
<article class="info-card">
  <h2>Registrar Portfolio Sync</h2>
  <p class="muted">NetEarthOne domains and .co.za domains both support live upstream sync. Press a provider button below to pull every domain from that registrar account, then search or assign them. Dates, pricing, nameservers, and statuses are written into the domains table directly. Prices are updated every day at 03:00 UTC by the sync_pricing worker; domain dates refresh every day at 02:15 UTC by sync_domain_dates; portfolio list refreshes every 6 hours.</p>
  <div class="action-grid" style="grid-template-columns:repeat(3, minmax(0, 1fr));">
    <form method="post" action="{$syncAllPath}" class="action-card">
      <input type="hidden" name="provider_code" value="netearthone">
      <input type="hidden" name="task" value="portfolio">
      <p class="eyebrow">Global TLDs / Reseller</p>
      <h2>NetEarthOne</h2>
      <p class="muted">Pulls domain registration orders (Active/Suspended/Restorable/Archived) from the reseller account via orders/index.json, imports them into the registrar pool, and refreshes registration/expiry dates.</p>
      <div class="form-actions"><button type="submit" class="button button-primary">Sync All NetEarthOne</button></div>
    </form>
    <form method="post" action="{$syncAllPath}" class="action-card">
      <input type="hidden" name="provider_code" value="coza">
      <input type="hidden" name="task" value="portfolio">
      <p class="eyebrow">Africa / .za ccTLD</p>
      <h2>.co.za Registry</h2>
      <p class="muted">Runs the EPP-based portfolio sync for .co.za / .org.za / .net.za and other ZACR zones. Requires client certificates and EPP credentials to be configured.</p>
      <div class="form-actions"><button type="submit" class="button button-primary">Sync All .co.za</button></div>
    </form>
    <form method="post" action="{$syncAllPath}" class="action-card">
      <input type="hidden" name="provider_code" value="all">
      <input type="hidden" name="task" value="portfolio">
      <p class="eyebrow">All Providers</p>
      <h2>Sync All</h2>
      <p class="muted">Run both provider portfolio syncs back-to-back. Use this after a deploy to bring the registrar pool completely up to date. Also enqueues domain-dates and pricing workers if configured.</p>
      <div class="form-actions"><button type="submit" class="button button-primary">Sync All Providers</button></div>
    </form>
  </div>
</article>
HTML;

        return $this->layout(
            'Domains',
            $flashMarkup
            . '<section class="panel"><div class="panel-head"><div><h1>Domains</h1><p class="muted">Registrar pool of all domains in the system ('
            . (int) $total
            . ' total). Live-sync the provider portfolios below, search / filter / paginate across the pool, and allocate domains to users or companies via Assign / Move.</p></div><a href="'
            . $this->escape($this->basePath())
            . '">Back to dashboard</a></div>'
            . $bulkSyncBlock
            . $scopeNote
            . $searchBlock
            . $pagination
            . $rows
            . $pagination
            . '<div class="action-grid" style="margin-top:20px;">'
            . $domainCards
            . '</div>'
            . '</section>'
        );
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $query
     */
    private function handleDomainBulkAction(array $post, array $query): string
    {
        unset($query);
        $action = trim((string) ($post['bulk_action'] ?? ''));
        if ($action === '') {
            return $this->redirectToDomains('');
        }
        return $this->redirectToDomains('No bulk action matched.');
    }

    /**
     * @param array<string, mixed> $post
     */
    private function handleDomainPortfolioSync(array $post): string
    {
        $providerCode = trim((string) ($post['provider_code'] ?? 'all'));
        $providerCodes = [];
        if ($providerCode === 'all' || $providerCode === '') {
            $providerCodes = ['coza', 'netearthone'];
        } else {
            $providerCodes = [$providerCode];
        }
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        foreach ($providerCodes as $code) {
            try {
                $providerAccount = $this->app->providerAccount($code);
                $providerAccountId = (string) ($providerAccount['id'] ?? '');
                if ($providerAccountId === '') {
                    $errors[] = sprintf('No provider_accounts row found for %s. Create one or visit /control/domain-registrars/providers/%s first.', $code, $code);
                    continue;
                }
                $provider = $this->app->provider($code);
                if (! $provider instanceof \App\Domain\Provider\Contracts\DomainPortfolioSyncInterface) {
                    $errors[] = sprintf('Provider %s does not implement DomainPortfolioSyncInterface.', $code);
                    continue;
                }
                $ctx = new \App\Domain\Sync\SyncContext($code, 'control-portfolio-sync');
                $idx = 0;
                foreach ($provider->listDomains($ctx) as $row) {
                    if (! is_array($row) || ! isset($row['domain_name'])) {
                        ++$skipped;
                        continue;
                    }
                    ++$idx;
                    $domainName = strtolower(trim((string) $row['domain_name']));
                    if ($domainName === '') {
                        ++$skipped;
                        continue;
                    }
                    try {
                        $existing = $this->app->domainRepository()->findByName($domainName);
                        $syncPayload = [
                            'tenant_id' => (string) ($row['tenant_id'] ?? ('registrar:' . $code)),
                            'owner_type' => (string) ($row['owner_type'] ?? 'registrar'),
                            'owner_id' => (string) ($row['owner_id'] ?? ('pool:' . $code)),
                            'billing_mode' => (string) ($row['billing_mode'] ?? 'registrar'),
                            'billing_tenant_id' => (string) ($row['billing_tenant_id'] ?? ('registrar:' . $code)),
                            'provider_code' => $code,
                            'registrar_status' => (string) ($row['registrar_status'] ?? 'active'),
                            'tld' => (string) ($row['tld'] ?? (str_contains($domainName, '.') ? substr($domainName, strpos($domainName, '.') + 1) : $domainName)),
                            'customer_id' => (string) ($row['customer_id'] ?? ''),
                            'registered_at' => $row['registered_at'] ?? null,
                            'expires_at' => $row['expires_at'] ?? null,
                            'renewal_due_at' => $row['renewal_due_at'] ?? ($row['expires_at'] ?? null),
                            'grace_period_ends_at' => $row['grace_period_ends_at'] ?? null,
                            'redemption_period_ends_at' => $row['redemption_period_ends_at'] ?? null,
                            'auto_renew_enabled' => $row['auto_renew_enabled'] ?? null,
                            'registrant' => $row['registrant'] ?? null,
                            'autorenew' => $row['auto_renew_enabled'] ?? null,
                            'contacts' => $row['contacts'] ?? null,
                        ];
                        $saved = $this->app->domainRepository()->upsertImportedDomain(
                            $providerAccountId,
                            $code,
                            $domainName,
                            $syncPayload,
                        );
                        if (isset($row['upstream_domain_id']) || isset($row['upstream_order_id']) || isset($row['raw'])) {
                            $updates = [];
                            $params = ['id' => (string) ($saved['id'] ?? '')];
                            if (isset($row['upstream_domain_id']) && trim((string) $row['upstream_domain_id']) !== '') {
                                $updates[] = 'upstream_domain_id = :upstream_domain_id';
                                $params['upstream_domain_id'] = trim((string) $row['upstream_domain_id']);
                            }
                            if (isset($row['upstream_order_id']) && trim((string) $row['upstream_order_id']) !== '') {
                                $updates[] = 'upstream_order_id = :upstream_order_id';
                                $params['upstream_order_id'] = trim((string) $row['upstream_order_id']);
                            }
                            if (isset($row['raw']) || $existing === null) {
                                $rowMeta = $existing ?? ['id' => $params['id']];
                                $merged = $this->mergeMetadata($rowMeta, ['portfolio_sync' => [
                                    'provider' => $code,
                                    'sync_time' => date('c'),
                                    'raw' => $row['raw'] ?? null,
                                ]]);
                                $updates[] = 'metadata_json = :metadata_json';
                                $params['metadata_json'] = is_string($merged) ? $merged : json_encode($merged, JSON_UNESCAPED_SLASHES);
                            }
                            if ($updates !== [] && $params['id'] !== '') {
                                $this->app->domainRepository()->updateFields($params['id'], $updates, $params);
                            }
                        }
                        if ($existing === null) {
                            ++$inserted;
                        } else {
                            ++$updated;
                        }
                    } catch (\Throwable $t) {
                        ++$skipped;
                        $errors[] = sprintf('%s: %s', $domainName, $t->getMessage());
                    }
                    if ($idx >= 10000) {
                        break;
                    }
                }
            } catch (\Throwable $t) {
                $errors[] = sprintf('Provider %s sync failed: %s', $code, $t->getMessage());
            }
        }
        $parts = [];
        if ($inserted > 0) {
            $parts[] = sprintf('%d domain(s) imported', $inserted);
        }
        if ($updated > 0) {
            $parts[] = sprintf('%d domain(s) updated', $updated);
        }
        if ($skipped > 0) {
            $parts[] = sprintf('%d skipped', $skipped);
        }
        $message = $parts === [] ? 'Sync complete.' : implode(', ', $parts) . '.';
        if ($errors !== []) {
            $message .= ' Errors: ' . implode(' | ', array_slice($errors, 0, 10));
            if (count($errors) > 10) {
                $message .= ' (and ' . (count($errors) - 10) . ' more)';
            }
        }
        return $this->redirectToDomains($message);
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $patch
     */
    private function mergeMetadata(array $existing, array $patch): string
    {
        $previous = [];
        if (isset($existing['metadata_json'])) {
            if (is_string($existing['metadata_json']) && trim($existing['metadata_json']) !== '') {
                $decoded = json_decode($existing['metadata_json'], true);
                if (is_array($decoded)) {
                    $previous = $decoded;
                }
            } elseif (is_array($existing['metadata_json'])) {
                $previous = $existing['metadata_json'];
            }
        }
        return json_encode(array_replace_recursive($previous, $patch), JSON_UNESCAPED_SLASHES);
    }

    private function renderTasks(): string
    {
        $rows = $this->renderTable(
            ['Task', 'Queue', 'Status', 'Attempts', 'Available', 'Error'],
            array_map(
                static fn (array $task): array => [
                    (string) $task['task_type'],
                    (string) $task['queue_name'],
                    (string) $task['status'],
                    (string) $task['attempts'],
                    (string) $task['available_at'],
                    (string) ($task['last_error'] ?? '-'),
                ],
                $this->app->taskQueueRepository()->listRecent(50),
            ),
        );

        return $this->layout('Tasks', '<section class="panel"><div class="panel-head"><h1>Tasks</h1><a href="' . $this->escape($this->basePath()) . '">Back to dashboard</a></div>' . $rows . '</section>');
    }

    private function renderProvidersIndex(array $query): string
    {
        $flash = trim((string) ($query['flash'] ?? ''));
        $flashMarkup = $flash === '' ? '' : '<div class="notice">' . $this->escape($flash) . '</div>';
        $cozaPath = $this->providersCozaPath();
        $neoPath = $this->providersNetEarthOnePath();

        $body = <<<HTML
{$flashMarkup}
<section class="panel">
  <div class="panel-head">
    <div>
      <p class="eyebrow">Providers</p>
      <h1>Connected Registrar Providers</h1>
      <p class="muted">Each provider stores non-sensitive runtime settings in this control. Sensitive credentials are kept in mounted secrets.</p>
    </div>
    <a href="{$this->escape($this->basePath())}">Back to dashboard</a>
  </div>
  <div class="action-grid">
    <article class="action-card">
      <p class="eyebrow">Africa / .za ccTLD</p>
      <h2>.co.za EPP Provider</h2>
      <p class="muted">Direct EPP connection for .co.za, .org.za, .net.za and other ZACR zones. Requires TLS client certificates plus EPP login credentials.</p>
      <p><strong>Provider code:</strong> <code>coza</code></p>
      <div class="form-actions">
        <a class="button button-primary" href="{$this->escape($cozaPath)}">Open .co.za Settings</a>
      </div>
    </article>
    <article class="action-card">
      <p class="eyebrow">Global TLDs / LogicBoxes Platform</p>
      <h2>NetEarthOne Provider</h2>
      <p class="muted">HTTP API for gTLDs via the NetEarthOne reseller platform (LogicBoxes / ResellerClub). Supports registration, renew, nameserver updates, and contact management.</p>
      <p><strong>Provider code:</strong> <code>netearthone</code></p>
      <div class="form-actions">
        <a class="button button-primary" href="{$this->escape($neoPath)}">Open NetEarthOne Settings</a>
      </div>
    </article>
  </div>
</section>
HTML;

        return $this->layout('Providers', $body);
    }

    private function renderNetEarthOneSettings(array $query): string
    {
        $flash = trim((string) ($query['flash'] ?? ''));
        $providerAccount = $this->app->providerAccount('netearthone');
        $stored = $this->app->providerStoredConfig('netearthone');
        $effective = $this->app->providerEffectiveConfig('netearthone');
        $environment = trim((string) ($providerAccount['environment'] ?? 'production'));
        $isActiveDb = (bool) ($providerAccount['is_active'] ?? true);

        $apiBaseUrl = (string) ($effective['api_base_url'] ?? '');
        $resellerId = (string) ($effective['auth_user_id'] ?? '');
        $ipAddress = (string) ($effective['ip_address'] ?? '');
        if ($ipAddress === '') {
            $detectedIps = $this->detectPublicOutboundIps();
            if ($detectedIps !== []) {
                $joined = implode(', ', $detectedIps);
                $ipAddress = $joined;
                try {
                    $currentStored = $this->app->providerStoredConfig('netearthone');
                    $basePreserve = array_replace($currentStored, [
                        'ip_address' => $joined,
                        'timeout' => (int) (($currentStored['timeout'] ?? $effective['timeout']) ?? 30) ?: 30,
                        'api_base_url' => (string) ($currentStored['api_base_url'] ?? $apiBaseUrl),
                        'pricing_json' => (string) ($currentStored['pricing_json'] ?? ($effective['pricing_json'] ?? '')),
                        'default_customer_id' => (string) ($currentStored['default_customer_id'] ?? ($effective['default_customer_id'] ?? '')),
                        'default_invoice_option' => (string) ($currentStored['default_invoice_option'] ?? ($effective['default_invoice_option'] ?? 'NoInvoice')),
                    ]);
                    if (! isset($basePreserve['auth_user_id']) || trim((string) $basePreserve['auth_user_id']) === '') {
                        $basePreserve['auth_user_id'] = (string) ($resellerId !== '' ? $resellerId : ($effective['auth_user_id'] ?? ''));
                    }
                    if (! isset($basePreserve['api_key']) || trim((string) $basePreserve['api_key']) === '') {
                        $candidate = (string) ($effective['api_key'] ?? '');
                        if ($candidate !== '') {
                            $basePreserve['api_key'] = $candidate;
                        }
                    }
                    $mergedConfig = $basePreserve;
                    $this->app->providerAccountRepository()->updateSettings(
                        (string) $providerAccount['id'],
                        [
                            'is_active' => ! empty($providerAccount['is_active']),
                            'environment' => $environment,
                        ],
                        $mergedConfig,
                    );
                } catch (\Throwable) {
                }
            }
        } else {
            $detectedIps = $this->detectPublicOutboundIps();
        }
        $apiKeyMasked = $this->maskedSecret((string) ($effective['api_key'] ?? ''));
        $apiKeyPlaceholder = $apiKeyMasked === 'Not configured' ? '' : '•••••••••••• (leave blank to keep current)';
        $timeout = (string) (($effective['timeout'] ?? null) ?: '30');
        $pricingJson = (string) ($effective['pricing_json'] ?? '');
        $defaultCustomerId = (string) ($effective['default_customer_id'] ?? '');
        $defaultInvoiceOption = (string) ($effective['default_invoice_option'] ?? '');
        if ($defaultInvoiceOption === '') {
            $defaultInvoiceOption = 'NoInvoice';
        }

        $liveStatusLabel = 'Provider Account Inactive';
        $liveStatusNote = '';
        if ($isActiveDb) {
            try {
                $provider = $this->app->provider('netearthone');
                if (method_exists($provider, 'healthCheck')) {
                    $health = $provider->healthCheck();
                    $ok = (bool) ($health['ok'] ?? true);
                    $statusText = is_string($health['status'] ?? null) ? trim((string) $health['status']) : '';
                    if ($ok) {
                        $liveStatusLabel = 'Provider Account Active';
                        if ($statusText !== '') {
                            $liveStatusNote = $statusText;
                        }
                    } else {
                        $liveStatusLabel = 'Provider Account Inactive';
                        $liveStatusNote = is_string($health['error'] ?? null) && trim((string) $health['error']) !== ''
                            ? trim((string) $health['error'])
                            : ($statusText !== '' ? $statusText : 'Live health check returned a failure status.');
                    }
                } else {
                    $liveStatusLabel = 'Provider Account Active';
                }
            } catch (Throwable $exception) {
                $liveStatusLabel = 'Provider Account Inactive';
                $liveStatusNote = $exception->getMessage();
            }
        } else {
            $liveStatusNote = 'Provider account is marked inactive in provider_accounts.is_active.';
        }
        $liveStatusPillClass = $liveStatusLabel === 'Provider Account Active' ? 'status-pill status-ok' : 'status-pill status-warn';
        $liveStatusNoteMarkup = $liveStatusNote === '' ? '' : '<p class="muted">' . $this->escape($liveStatusNote) . '</p>';

        $flashMarkup = $flash === '' ? '' : '<div class="notice">' . $this->escape($flash) . '</div>';
        $probeMarkup = '';
        $probeDiagnosticsMarkup = '';
        if (($query['probe'] ?? null) === 'health') {
            $envBase = $this->nullableString($_ENV['NETEARTHONE_API_BASE_URL'] ?? getenv('NETEARTHONE_API_BASE_URL'));
            $envAuthId = $this->nullableString($_ENV['NETEARTHONE_AUTH_USER_ID'] ?? getenv('NETEARTHONE_AUTH_USER_ID'));
            $envApiKey = $this->nullableString($_ENV['NETEARTHONE_API_KEY'] ?? getenv('NETEARTHONE_API_KEY'));
            $envIp = $this->nullableString($_ENV['NETEARTHONE_IP_ADDRESS'] ?? getenv('NETEARTHONE_IP_ADDRESS'));
            $storedAuthId = $this->nullableString($stored['auth_user_id'] ?? null);
            $storedApiKey = $this->nullableString($stored['api_key'] ?? null);
            $storedIp = $this->nullableString($stored['ip_address'] ?? null);
            $effAuthId = $this->nullableString($effective['auth_user_id'] ?? null);
            $effApiKey = $this->nullableString($effective['api_key'] ?? null);

            $prefixSuffix = function (?string $s): string {
                if ($s === null) {
                    return '<em style="color:#94a3b8;">Not set</em>';
                }
                $n = strlen($s);
                if ($n <= 8) {
                    return str_repeat('•', $n);
                }
                return '<span style="font-family:ui-monospace,monospace;">'
                    . htmlspecialchars(substr($s, 0, 4), ENT_QUOTES)
                    . '<span style="color:#64748b;">' . str_repeat('•', max(1, $n - 8)) . '</span>'
                    . htmlspecialchars(substr($s, -4), ENT_QUOTES)
                    . '</span>';
            };

            $chooseSource = function (?string $env, ?string $storedV, ?string $eff, string $name) use ($prefixSuffix): string {
                $mark = match (true) {
                    $eff === null => '<span style="color:#f87171;font-weight:600;">MISSING (no value)</span>',
                    $storedV !== null && $eff === $storedV => '<span style="color:#34d399;font-weight:600;">STORED (provider_accounts.config_json)</span>',
                    $env !== null && $eff === $env => '<span style="color:#60a5fa;font-weight:600;">ENV (Northflank secrets / mounted .env)</span>',
                    default => '<span style="color:#fbbf24;font-weight:600;">MERGED (check effective)</span>',
                };
                return '<tr><td style="padding:6px 10px;border-bottom:1px solid #1e293b;"><code style="font-size:12px;">' . htmlspecialchars($name, ENT_QUOTES) . '</code></td>'
                    . '<td style="padding:6px 10px;border-bottom:1px solid #1e293b;">' . $mark . '</td>'
                    . '<td style="padding:6px 10px;border-bottom:1px solid #1e293b;">' . $prefixSuffix($env) . '</td>'
                    . '<td style="padding:6px 10px;border-bottom:1px solid #1e293b;">' . $prefixSuffix($storedV) . '</td>'
                    . '<td style="padding:6px 10px;border-bottom:1px solid #1e293b;font-weight:600;">' . $prefixSuffix($eff) . '</td></tr>';
            };

            $rawProviderRow = $this->app->providerAccount('netearthone');
            $rawConfigJson = is_array($rawProviderRow) && isset($rawProviderRow['config_json']) && is_string($rawProviderRow['config_json']) ? $rawProviderRow['config_json'] : '';
            $rawRowId = is_array($rawProviderRow) && isset($rawProviderRow['id']) && is_string($rawProviderRow['id']) ? $rawProviderRow['id'] : '';
            $rawRowUpdatedAt = is_array($rawProviderRow) && isset($rawProviderRow['updated_at']) ? (string) $rawProviderRow['updated_at'] : '';
            $rawRowIsActive = is_array($rawProviderRow) && isset($rawProviderRow['is_active']) ? (int) $rawProviderRow['is_active'] : 0;
            $jsonDecodeAttempt = json_decode($rawConfigJson, true);
            $jsonError = json_last_error();
            $jsonErrorMsg = $jsonError !== JSON_ERROR_NONE ? json_last_error_msg() : '';
            $configJsonKeys = is_array($jsonDecodeAttempt) ? array_values(array_keys($jsonDecodeAttempt)) : [];
            $configJsonHasAuthKey = in_array('auth_user_id', $configJsonKeys, true);
            $configJsonHasApiKey = in_array('api_key', $configJsonKeys, true);

            $displayRowId = $this->escape($rawRowId !== '' ? $rawRowId : 'N/A');
            $displayUpdatedAt = $this->escape($rawRowUpdatedAt !== '' ? $rawRowUpdatedAt : 'N/A');
            $displayConfigJsonLength = $this->escape((string) strlen($rawConfigJson));
            $jsonDecodeColor = $jsonError === JSON_ERROR_NONE ? '#34d399' : '#f87171';
            $jsonDecodeText = $this->escape($jsonError === JSON_ERROR_NONE ? 'OK' : 'ERROR: ' . $jsonErrorMsg);
            $hasAuthColor = $configJsonHasAuthKey ? '#34d399' : '#f87171';
            $hasAuthText = $configJsonHasAuthKey ? 'YES' : 'NO';
            $hasApiKeyColor = $configJsonHasApiKey ? '#34d399' : '#f87171';
            $hasApiKeyText = $configJsonHasApiKey ? 'YES' : 'NO';
            $displayKeysPresent = $this->escape($configJsonKeys === [] ? '(empty)' : implode(', ', $configJsonKeys));

            $envLookup = function (array $candidates): array {
                $readOne = function (string $key): ?string {
                    $v = getenv($key);
                    if (is_string($v) && $v !== '') {
                        return $v;
                    }
                    if (isset($_ENV[$key]) && is_string($_ENV[$key]) && $_ENV[$key] !== '') {
                        return $_ENV[$key];
                    }
                    if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') {
                        return $_SERVER[$key];
                    }
                    return null;
                };
                foreach ($candidates as $c) {
                    if (! is_string($c) || trim($c) === '') {
                        continue;
                    }
                    $v = $readOne($c);
                    if ($v !== null) {
                        return ['key' => $c, 'value' => $v];
                    }
                }
                return ['key' => null, 'value' => null];
            };

            $envAuthCandidates = ['NETEARTHONE_AUTH_USER_ID','NETEARTHONE_USER_ID','NETEARTHONE_RESELLER_ID','NETEARTHONE_RESELLERID','NETEARTHONE_RESSELLER_ID','NETEARTHONE_RESSELLERID','NEO_RESELLER_ID','NEO_AUTH_USER_ID','NEO_USER_ID','RESELLER_ID','RESELLERID','RESSELLER_ID','RESSELLERID','AUTH_USER_ID','USER_ID'];
            $envKeyCandidates = ['NETEARTHONE_API_KEY','NETEARTHONE_APIKEY','NETEARTHONE_PASSWORD','NETEARTHONE_AUTH_KEY','NEO_API_KEY','NEO_APIKEY','NEO_PASSWORD','API_KEY','APIKEY','PASSWORD','AUTH_KEY','SECRET'];
            $envBaseCandidates = ['NETEARTHONE_API_BASE_URL','NETEARTHONE_BASE_URL','NETEARTHONE_ENDPOINT','NEO_API_BASE_URL','NEO_BASE_URL','HTTPAPI_BASE_URL','API_BASE_URL','BASE_URL','ENDPOINT'];
            $envIpCandidates = ['NETEARTHONE_IP_ADDRESS','NETEARTHONE_IP','NETEARTHONE_WHITELIST_IP','NETEARTHONE_WHITELIST_IPS','NETEARTHONE_ALLOWED_IPS','NETEARTHONE_ALLOWED_IP','NETEARTHONE_ACL_IP','NETEARTHONE_ACL_IPS','NETEARTHONE_OUTBOUND_IP','NETEARTHONE_OUTBOUND_IPS','NETEARTHONE_EGRESS_IP','NETEARTHONE_EGRESS_IPS','NETEARTHONE_CLIENT_IPS','NEO_IP_ADDRESS','NEO_IP','NEO_WHITELIST_IP','NEO_WHITELIST_IPS','NEO_ALLOWED_IPS','NEO_ALLOWED_IP','NEO_ACL_IP','NEO_ACL_IPS','NEO_OUTBOUND_IP','NEO_OUTBOUND_IPS','NEO_EGRESS_IP','NEO_EGRESS_IPS','IP_ADDRESS','IP','IP_ADDRESSES','WHITELIST_IP','WHITELIST_IPS','ALLOWED_IP','ALLOWED_IPS','ACL_IP','ACL_IPS','CLIENT_IP','CLIENT_IPS','OUTBOUND_IP','OUTBOUND_IPS','EGRESS_IP','EGRESS_IPS'];

            $envAuthResolved = $envLookup($envAuthCandidates);
            $envKeyResolved = $envLookup($envKeyCandidates);
            $envBaseResolved = $envLookup($envBaseCandidates);
            $envIpResolved = $envLookup($envIpCandidates);

            $maskedEnvFn = static function (?string $s): string {
                if ($s === null) {
                    return '<em style="color:#94a3b8;">Not set</em>';
                }
                $n = strlen($s);
                if ($n <= 8) {
                    return '<span style="font-family:ui-monospace,monospace;">' . str_repeat('•', $n) . '</span>';
                }
                return '<span style="font-family:ui-monospace,monospace;">'
                    . htmlspecialchars(substr($s, 0, 4), ENT_QUOTES)
                    . '<span style="color:#64748b;">' . str_repeat('•', max(1, $n - 8)) . '</span>'
                    . htmlspecialchars(substr($s, -4), ENT_QUOTES)
                    . '</span>';
            };

            $envAuthKeyBadge = $envAuthResolved['key'] !== null
                ? '<span style="color:#34d399;font-weight:600;">✅ via getenv(' . htmlspecialchars($envAuthResolved['key'], ENT_QUOTES) . ')</span> → ' . $maskedEnvFn($envAuthResolved['value'])
                : '<span style="color:#f87171;font-weight:600;">❌ No env var found (checked: NETEARTHONE_AUTH_USER_ID, NEO_AUTH_USER_ID, RESELLER_ID, AUTH_USER_ID + 11 aliases)</span>';
            $envKeyKeyBadge = $envKeyResolved['key'] !== null
                ? '<span style="color:#34d399;font-weight:600;">✅ via getenv(' . htmlspecialchars($envKeyResolved['key'], ENT_QUOTES) . ')</span> → ' . $maskedEnvFn($envKeyResolved['value'])
                : '<span style="color:#f87171;font-weight:600;">❌ No env var found (checked: NETEARTHONE_API_KEY, NEO_API_KEY, API_KEY + 9 aliases)</span>';
            $envBaseKeyBadge = $envBaseResolved['key'] !== null
                ? '<span style="color:#34d399;font-weight:600;">✅ via getenv(' . htmlspecialchars($envBaseResolved['key'], ENT_QUOTES) . ')</span> → ' . $maskedEnvFn($envBaseResolved['value'])
                : '<span style="color:#fbbf24;font-weight:600;">⚠️ No env var found (will use default https://httpapi.com/api)</span>';
            $envIpKeyBadge = $envIpResolved['key'] !== null
                ? '<span style="color:#34d399;font-weight:600;">✅ via getenv(' . htmlspecialchars($envIpResolved['key'], ENT_QUOTES) . ')</span> → ' . $maskedEnvFn($envIpResolved['value'])
                : '<em style="color:#94a3b8;">No env var set (IP will be auto-detected and/or saved via form)</em>';

            $probeResult = null;
            try {
                $provider = $this->app->provider('netearthone');
                if (method_exists($provider, 'healthCheck')) {
                    $probeResult = $provider->healthCheck();
                }
            } catch (Throwable $exception) {
                $probeResult = ['ok' => false, 'error' => $exception->getMessage(), 'status' => 'API probe failed.', 'exception_class' => $exception::class];
            }
            $probeMarkup = '<article class="info-card"><h2>Live API Health Probe</h2><pre class="code-block">' . $this->escape(
                json_encode($probeResult ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            ) . '</pre></article>';

            $probeUsedPath = is_array($probeResult) && isset($probeResult['probe_path']) && is_string($probeResult['probe_path']) ? $probeResult['probe_path'] : '(see error above)';
            $neoClientForDiag = null;
            try {
                $provider2 = $this->app->provider('netearthone');
                if (property_exists($provider2, 'client')) {
                    $r = new \ReflectionProperty($provider2, 'client');
                    $r->setAccessible(true);
                    $v = $r->getValue($provider2);
                    if (is_object($v) && $v instanceof \App\Infrastructure\Providers\NetEarthOneApiClient) {
                        $neoClientForDiag = $v;
                    }
                }
            } catch (Throwable) {
                $neoClientForDiag = null;
            }
            $effBaseUrl = (string) ($effective['api_base_url'] ?? '');
            $effBaseUrlDisplay = $effBaseUrl !== '' ? '<code style="font-size:12px;">' . htmlspecialchars($effBaseUrl, ENT_QUOTES) . '</code>' : '<em style="color:#94a3b8;">(empty)</em>';
            $clientBaseUrlDisplay = $neoClientForDiag !== null
                ? '<code style="font-size:12px;">' . htmlspecialchars($neoClientForDiag->getBaseUrl(), ENT_QUOTES) . '</code>'
                : '<em style="color:#94a3b8;">(could not read client)</em>';
            $baseUrlMatchBadge = $neoClientForDiag !== null && $neoClientForDiag->getBaseUrl() !== '' && $neoClientForDiag->getBaseUrl() === rtrim((string) $effBaseUrl, '/')
                ? '<span style="color:#34d399;font-weight:600;">✅ effective base_url matches client-injected base_url after normalization</span>'
                : '<span style="color:#fbbf24;font-weight:600;">⚠️ base_url mismatch (or client not readable) — effective=' . $effBaseUrlDisplay . ' vs client=' . $clientBaseUrlDisplay . '</span>';
            $fullUrlCustomerDetails = '(could not build)';
            $fullUrlDomainsAvailable = '(could not build)';
            $clientMaskedAuth = '';
            $clientMaskedKey = '';
            if ($neoClientForDiag !== null) {
                $authForDiag = (string) ($effective['auth_user_id'] ?? '');
                if ($authForDiag === '' || ! ctype_digit($authForDiag)) {
                    $authForDiag = (string) $neoClientForDiag->getAuthUserId();
                }
                $customerExtra = $authForDiag !== '' && ctype_digit($authForDiag) ? ['customer-id' => $authForDiag] : [];
                try {
                    $fullUrlCustomerDetails = htmlspecialchars($neoClientForDiag->buildFullUrlForDiagnostics('customers/details-by-id.json', $customerExtra), ENT_QUOTES);
                } catch (Throwable) {
                    $fullUrlCustomerDetails = '(build error)';
                }
                try {
                    $fullUrlDomainsAvailable = htmlspecialchars($neoClientForDiag->buildFullUrlForDiagnostics('domains/available.json', ['domain-name' => ['healthcheck-example'], 'tlds' => ['com']]), ENT_QUOTES);
                } catch (Throwable) {
                    $fullUrlDomainsAvailable = '(build error)';
                }
                $clientMaskedAuth = htmlspecialchars($neoClientForDiag->maskedAuthUserId(), ENT_QUOTES);
                $clientMaskedKey = htmlspecialchars($neoClientForDiag->maskedApiKey(), ENT_QUOTES);
            }
            $envAuthMaskedCompare = $maskedEnvFn($envAuthResolved['value'] ?? null);
            $envKeyMaskedCompare = $maskedEnvFn($envKeyResolved['value'] ?? null);

            $upstreamResponseBody = '';
            if (is_array($probeResult) && isset($probeResult['raw_response']) && is_string($probeResult['raw_response']) && trim($probeResult['raw_response']) !== '') {
                $truncated = strlen($probeResult['raw_response']) > 4000
                    ? substr($probeResult['raw_response'], 0, 4000) . "\n\n[truncated at 4000 chars]"
                    : $probeResult['raw_response'];
                $upstreamResponseBody = '<p style="margin-top:14px;"><strong style="color:#93c5fd;">Raw LogicBoxes (NEO upstream) response body (truncated):</strong></p>'
                    . '<pre class="code-block" style="max-height:320px;overflow:auto;">' . $this->escape($truncated) . '</pre>';
            }

            $wireAuth = is_array($probeResult) && isset($probeResult['used_auth_id_prefix_suffix']) && is_array($probeResult['used_auth_id_prefix_suffix']) ? $probeResult['used_auth_id_prefix_suffix'] : ['prefix' => null, 'suffix' => null];
            $wireKey  = is_array($probeResult) && isset($probeResult['used_api_key_prefix_suffix'])  && is_array($probeResult['used_api_key_prefix_suffix'])  ? $probeResult['used_api_key_prefix_suffix']  : ['prefix' => null, 'suffix' => null];
            $wireAuthStr = is_string($wireAuth['prefix'] ?? null) && is_string($wireAuth['suffix'] ?? null)
                ? htmlspecialchars($wireAuth['prefix'], ENT_QUOTES) . '<span style="color:#64748b;">••••</span>' . htmlspecialchars($wireAuth['suffix'], ENT_QUOTES)
                : '<em style="color:#94a3b8;">N/A</em>';
            $wireKeyStr  = is_string($wireKey['prefix']  ?? null) && is_string($wireKey['suffix']  ?? null)
                ? htmlspecialchars($wireKey['prefix'],  ENT_QUOTES) . '<span style="color:#64748b;">••••</span>' . htmlspecialchars($wireKey['suffix'],  ENT_QUOTES)
                : '<em style="color:#94a3b8;">N/A</em>';
            $wireAuthMaskedPlain = is_string($wireAuth['prefix'] ?? null) && is_string($wireAuth['suffix'] ?? null)
                ? htmlspecialchars($wireAuth['prefix'], ENT_QUOTES) . '••••' . htmlspecialchars($wireAuth['suffix'], ENT_QUOTES)
                : 'N/A';
            $wireKeyMaskedPlain = is_string($wireKey['prefix'] ?? null) && is_string($wireKey['suffix'] ?? null)
                ? htmlspecialchars($wireKey['prefix'], ENT_QUOTES) . '••••' . htmlspecialchars($wireKey['suffix'], ENT_QUOTES)
                : 'N/A';
            $authWireVsEnvBadge = $neoClientForDiag !== null
                ? '<span style="color:#34d399;font-weight:600;">✅ credentials consistent (client instantiated this request)</span>'
                : '<span style="color:#94a3b8;">(credentials check skipped — no client handle)</span>';

            $sourceTable = $chooseSource($envBase, $this->nullableString($stored['api_base_url'] ?? null), $this->nullableString($effective['api_base_url'] ?? null), 'api_base_url')
                . $chooseSource($envAuthId, $storedAuthId, $effAuthId, 'auth_user_id (Reseller ID)')
                . $chooseSource($envApiKey, $storedApiKey, $effApiKey, 'api_key')
                . $chooseSource($envIp, $storedIp, $this->nullableString($effective['ip_address'] ?? null), 'ip_address');

            $neoStackIps = $this->detectApiOutboundIps();
            $neoStackIpHtml = '<em style="color:#94a3b8;">None detected (check cURL / outbound network)</em>';
            if ($neoStackIps !== []) {
                $neoStackIpHtml = implode('<br>', array_map(
                    fn (string $ip): string => '<code style="font-size:12px;">' . htmlspecialchars($ip, ENT_QUOTES) . '</code>',
                    array_values(array_unique($neoStackIps)),
                ));
            }
            $normalDetectIps = $this->detectPublicOutboundIps();
            $normalDiff = [];
            foreach ($neoStackIps as $ip) {
                if (! in_array($ip, $normalDetectIps, true)) {
                    $normalDiff[] = $ip;
                }
            }
            $ipMatchBadge = ($neoStackIps === [] || $normalDetectIps === [])
                ? '<span style="color:#94a3b8;">(no comparison data)</span>'
                : (($normalDiff === [])
                    ? '<span style="color:#34d399;font-weight:600;">✅ MATCH — Neo stack outbound IP equals detected public IP. NEO whitelist of this IP should work.</span>'
                    : '<span style="color:#f87171;font-weight:600;">⚠️ MISMATCH — Neo cURL stack sees different IPs (above) vs generic detection: <code>' . htmlspecialchars(implode(',', $normalDetectIps), ENT_QUOTES) . '</code>. Add ALL listed IPs to NEO Allowed IPs.</span>');

            $displayClientMaskedAuth = $clientMaskedAuth !== ''
                ? '<code style="font-size:12px;">' . $clientMaskedAuth . '</code>'
                : '<em style="color:#94a3b8;">N/A</em>';
            $displayClientMaskedKey = $clientMaskedKey !== ''
                ? '<code style="font-size:12px;">' . $clientMaskedKey . '</code>'
                : '<em style="color:#94a3b8;">N/A</em>';
            $displayProbeUsedPath = $this->escape($probeUsedPath);

            $probeDiagnosticsMarkup = <<<HTML
  <article class="info-card" style="border-color:#3b82f6;background-color:rgba(59,130,246,0.08);">
    <h2 style="color:#93c5fd;">Secrets Resolution Diagnostics (probe=health)</h2>
    <p class="muted">This table shows <em>which source</em> each NetEarthOne credential is currently read from at runtime — ENV (Northflank secret sets / mounted secrets), or STORED (provider_accounts.config_json). If both are set, <strong>STORED wins</strong>. Values are masked: only first 4 + last 4 chars exposed for correlation against your NF secrets / NEO console.</p>

    <div style="padding:10px 14px;margin:10px 0 14px;border:1px solid #1e293b;border-radius:8px;background:rgba(15,23,42,0.5);">
      <p style="margin:4px 0;font-size:13px;"><strong style="color:#a78bfa;">provider_accounts DB row (absolute truth from shared DB):</strong></p>
      <p style="margin:4px 0;font-size:12px;">id: <code>{$displayRowId}</code></p>
      <p style="margin:4px 0;font-size:12px;">is_active: <code>{$rawRowIsActive}</code> &nbsp;·&nbsp; updated_at: <code>{$displayUpdatedAt}</code></p>
      <p style="margin:4px 0;font-size:12px;">config_json length: <code>{$displayConfigJsonLength} bytes</code>
         &nbsp;·&nbsp; json_decode: <code style="color:{$jsonDecodeColor};">{$jsonDecodeText}</code></p>
      <p style="margin:4px 0;font-size:12px;">config_json has auth_user_id key: <code style="color:{$hasAuthColor};">{$hasAuthText}</code>
         &nbsp;·&nbsp; has api_key key: <code style="color:{$hasApiKeyColor};">{$hasApiKeyText}</code></p>
      <p style="margin:4px 0;font-size:12px;">keys present in config_json: <code style="word-break:break-all;">{$displayKeysPresent}</code></p>
      <p class="muted" style="margin:8px 0 4px 0;font-size:11px;">If has auth_user_id / has api_key above show ❌NO, click <strong>Save Settings</strong> ONCE with the API Key password field populated (paste DS8D..mltf) and Reseller ID field showing 400454 — the post-save flash will show either <code>KEYS OK 8/8</code> or list missing keys (before/after counts). This confirms save handler actually wrote credentials into DB.</p>
    </div>

    <div style="padding:10px 14px;margin:10px 0 14px;border:1px solid #1e293b;border-radius:8px;background:rgba(15,23,42,0.5);">
      <p style="margin:4px 0;font-size:13px;"><strong style="color:#f59e0b;">ENV VAR KEY NAME resolution (exactly which Northflank secret key populated each field):</strong></p>
      <p class="muted" style="margin:2px 0 6px 0;font-size:11px;">If a Northflank secret group uses a KEY NAME not in the alias list below, the value will be silently missed and credentials will appear to be "missing" even though NF shows the secret is set. Green badges show the EXACT key name that was matched by the getenv() / $_ENV reader.</p>
      <p style="margin:4px 0;font-size:12px;"><strong>auth_user_id (Reseller ID):</strong> {$envAuthKeyBadge}</p>
      <p style="margin:4px 0;font-size:12px;"><strong>api_key:</strong> {$envKeyKeyBadge}</p>
      <p style="margin:4px 0;font-size:12px;"><strong>api_base_url:</strong> {$envBaseKeyBadge}</p>
      <p style="margin:4px 0;font-size:12px;"><strong>ip_address:</strong> {$envIpKeyBadge}</p>
      <p class="muted" style="margin:6px 0 0 0;font-size:11px;">Full alias list checked: auth_user_id → NETEARTHONE_AUTH_USER_ID, NETEARTHONE_USER_ID, NETEARTHONE_RESELLER_ID, NETEARTHONE_RESELLERID, NETEARTHONE_RESSELLER_ID, NETEARTHONE_RESSELLERID, NEO_RESELLER_ID, NEO_AUTH_USER_ID, NEO_USER_ID, RESELLER_ID, RESELLERID, RESSELLER_ID, RESSELLERID, AUTH_USER_ID, USER_ID. api_key → NETEARTHONE_API_KEY, NETEARTHONE_APIKEY, NETEARTHONE_PASSWORD, NETEARTHONE_AUTH_KEY, NEO_API_KEY, NEO_APIKEY, NEO_PASSWORD, API_KEY, APIKEY, PASSWORD, AUTH_KEY, SECRET. If the badge shows ❌NO, add the secret under one of these key names in NF or use the form + Save to write a STORED override.</p>
    </div>

    <div style="padding:10px 14px;margin:10px 0 14px;border:1px solid #1e293b;border-radius:8px;background:rgba(15,23,42,0.5);">
      <p style="margin:4px 0;font-size:13px;"><strong style="color:#c084fc;">Outbound SOURCE IP used for NetEarthOne cURL calls (CURLOPT_IPRESOLVE forced to IPv4):</strong></p>
      <p style="margin:4px 0;font-size:12px;">These are the EXACT IPs LogicBoxes/NEO will see when <code>customers/details-by-id.json</code> is called:<br>{$neoStackIpHtml}</p>
      <p style="margin:6px 0 2px 0;font-size:12px;">{$ipMatchBadge}</p>
      <p class="muted" style="margin:6px 0 0 0;font-size:11px;">Force-IPv4 is now <strong>always enabled</strong> for NetEarthOne API client cURL requests (since this commit). Previously the cURL could prefer AAAA (IPv6) and egress from a different IP that wasn't whitelisted → "You are not allowed to perform this action" even though 35.225.15.93 was in the whitelist.</p>
    </div>

    <div style="padding:10px 14px;margin:10px 0 14px;border:1px solid #1e293b;border-radius:8px;background:rgba(15,23,42,0.5);">
      <p style="margin:4px 0;font-size:13px;"><strong style="color:#c084fc;">What was actually SENT ON THE WIRE to LogicBoxes/NEO in this ?probe=health call:</strong></p>
      <p style="margin:4px 0;font-size:12px;">auth-userid (prefix4 •••• suffix4): <code>{$wireAuthStr}</code></p>
      <p style="margin:4px 0;font-size:12px;">api-key    (prefix4 •••• suffix4): <code>{$wireKeyStr}</code></p>
      <p class="muted" style="margin:6px 0 0 0;font-size:11px;">If these don't match the EFFECTIVE column above, a provider instance cached earlier with different credentials is being used (requires deploy restart or page cache clear).</p>
    </div>

    <div style="padding:10px 14px;margin:10px 0 14px;border:1px solid #1e293b;border-radius:8px;background:rgba(15,23,42,0.5);">
      <p style="margin:4px 0;font-size:13px;"><strong style="color:#22d3ee;">API Base URL + Credential consistency + Request URL blueprint:</strong></p>
      <p style="margin:6px 0 2px 0;font-size:12px;">{$baseUrlMatchBadge}</p>
      <p style="margin:4px 0;font-size:12px;">Health check path used: <code style="font-size:12px;">{$displayProbeUsedPath}</code></p>
      <p style="margin:4px 0;font-size:12px;"><strong>Actual client-injected auth_user_id (masked):</strong> {$displayClientMaskedAuth}</p>
      <p style="margin:4px 0;font-size:12px;"><strong>Actual client-injected api_key (masked):</strong> {$displayClientMaskedKey}</p>
      <p style="margin:4px 0;font-size:12px;">{$authWireVsEnvBadge}</p>
      <p style="margin:8px 0 2px 0;font-size:12px;"><strong>Full blueprint URL #1 (customers/details-by-id):</strong></p>
      <p style="margin:2px 0;font-size:11px;word-break:break-all;"><code style="font-size:11px;">{$fullUrlCustomerDetails}</code></p>
      <p style="margin:8px 0 2px 0;font-size:12px;"><strong>Full blueprint URL #2 (domains/available fallback):</strong></p>
      <p style="margin:2px 0;font-size:11px;word-break:break-all;"><code style="font-size:11px;">{$fullUrlDomainsAvailable}</code></p>
      <p class="muted" style="margin:6px 0 0 0;font-size:11px;">If the "Full blueprint URLs" show auth-userid=•••• and api-key=•••• with WRONG prefixes (not 40••54 / DS8D••mltf), then a different value was injected into the client constructor vs what the ENV/STORED effective shows. If base_url is NOT https://httpapi.com/api (ends with /api, no trailing slash, no anacreon/XML legacy paths), click Save Settings once after correcting. NetEarthOne/LogicBoxes expects base URL with /api suffix, no /anacreon/servlet/ApiCall.xml legacy paths.</p>
    </div>

    <div style="overflow:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
          <tr style="text-align:left;color:#93c5fd;">
            <th style="padding:8px 10px;border-bottom:2px solid #1e293b;">Setting</th>
            <th style="padding:8px 10px;border-bottom:2px solid #1e293b;">Source used</th>
            <th style="padding:8px 10px;border-bottom:2px solid #1e293b;">ENV (first 4 •••• last 4)</th>
            <th style="padding:8px 10px;border-bottom:2px solid #1e293b;">STORED (prefix4 •••• suffix4)</th>
            <th style="padding:8px 10px;border-bottom:2px solid #1e293b;">EFFECTIVE (runtime)</th>
          </tr>
        </thead>
        <tbody>
          {$sourceTable}
        </tbody>
      </table>
    </div>

    {$upstreamResponseBody}

    <p style="margin-top:14px;" class="muted"><strong>How to fix mismatches / "not allowed" / "invalid credentials":</strong></p>
    <ol class="muted" style="margin-top:4px;">
      <li><strong>IP WHITELIST FIRST:</strong> Copy every IP from <em>"Outbound SOURCE IP used for NetEarthOne cURL calls"</em> panel above → go to NEO Console → Settings → API → Security → Allowed IPs → paste each IP (NEO only allows single IPs, not CIDR) → <strong>Save whitelisted IP addresses</strong>. If the panel shows ✅MATCH, whitelisting 35.225.15.93 alone is sufficient.</li>
      <li>If the <strong>Wire values</strong> above show <code>KGt8••••WpFP</code> (OLD) but you saved/pasted a new key like <code>DS8D••••mltf</code>, then either: (a) save handler didn't persist (check DB row panel: <em>config_json has api_key key: YES/NO</em> must show YES after save, with correct prefix), or (b) provider was cached with old credentials → after a confirmed saved, <strong>reload the page (hard refresh)</strong> or redeploy to clear cached instances.</li>
      <li>If ENV column shows OLD stale credentials but you want the new key: update <code>NETEARTHONE_API_KEY</code> + <code>NETEARTHONE_AUTH_USER_ID</code> in Northflank <code>metahumans-netearthone-provider</code> secret group to match NEO popup, then redeploy the NF service (env only changes on new container instances).</li>
      <li>If STORED column should contain the newly-saved key but shows Not set: click Save Settings <em>once</em> and look for the green flash banner, which now explicitly lists <code>"KEYS OK 8/8 [...] Stored: auth_user_id=… api_key=…"</code> masked strings as confirmation. If flash shows the correct masked key, the DB write succeeded; if it still shows old values, inspect config_json keys section above.</li>
      <li>After you confirm <em>Outbound SOURCE IP</em> panel has ✅MATCH, Wire === EFFECTIVE === the new key from your NEO popup, re-run health probe. If it still says "Invalid credentials", NEO key propagation may still be pending (wait up to 2 minutes and retry), or the auth_user_id and api_key belong to different accounts — double-check the Reseller ID shown at the very top of the NEO Console header.</li>
      <li>To rotate without touching NF: paste the new key into the API Key password field → <strong>Save Settings</strong> (STORED override wins — no redeploy needed). Post-save flash confirms stored masked key matches paste.</li>
    </ol>
  </article>
HTML;
        }

        $ipWhitelistMarkup = '';
        if (($detectedIps ?? []) !== []) {
            $ipItems = implode('', array_map(
                fn (string $ip): string => '<li><code>' . $this->escape($ip) . '</code></li>',
                array_values(array_unique($detectedIps)),
            ));
            $ipWhitelistMarkup = <<<HTML
  <article class="info-card">
    <h2>Detected Public Outbound IPs (Add These To NetEarthOne Whitelist)</h2>
    <p class="muted">NetEarthOne's LogicBoxes platform rejects API requests unless the <strong>originating server IP</strong> is in your reseller account's allowed IP list. Copy the IP(s) below and paste them into NetEarthOne Reseller Console → <strong>Settings → API → Security → Allowed IPs</strong>.</p>
    <ul class="info-list">
      {$ipItems}
    </ul>
    <p class="muted">Some cloud providers (including Northflank) use a dynamic egress pool. If the IP changes or you continue to see permission errors, add the full egress CIDR range or contact your host to request a static outbound IP.</p>
  </article>
HTML;
        }

        $authErrorMarkup = '';
        $errorLower = is_string($liveStatusNote) ? strtolower($liveStatusNote) : '';
        if ($liveStatusLabel === 'Provider Account Inactive' && (stripos($errorLower, 'not allowed') !== false || stripos($errorLower, 'permission') !== false || stripos($errorLower, 'unauthorized') !== false)) {
            $authErrorMarkup = <<<HTML
  <article class="info-card" style="border-color:#f59e0b;background-color:rgba(245,158,11,0.08);">
    <h2 style="color:#fbbf24;">API Permission Denied</h2>
    <p><strong>"You are not allowed to perform this action"</strong> from NetEarthOne almost always means one of two settings issues in your NetEarthOne / LogicBoxes reseller account:</p>
    <ol>
      <li><strong>Outbound IP not whitelisted.</strong> In the NetEarthOne reseller console go to <strong>Settings → API → Security → Allowed IPs</strong> and add the <em>Detected Public Outbound IPs</em> listed above. LB/NEO silently drops (returns "not allowed") any API request from a non-whitelisted IP even if credentials are correct.</li>
      <li><strong>Reseller ID and API key don't match.</strong> Double-check that the Reseller ID (<code>{$this->escape($resellerId !== '' ? $resellerId : 'Not set')}</code>) and the currently stored API key belong to the <em>same</em> reseller account. Regenerate the API key from NetEarthOne Reseller Console → Settings → API if you suspect a mismatch.</li>
    </ol>
    <p class="muted">After you save changes to the whitelist or credentials, re-run <a href="{$this->escape($this->providersNetEarthOnePath())}?probe=health">Live API health probe</a> to confirm.</p>
  </article>
HTML;
        }

        $body = <<<HTML
{$flashMarkup}
<section class="panel">
  <div class="panel-head">
    <div>
      <p class="eyebrow">Provider Settings</p>
      <h1>NetEarthOne API</h1>
      <p class="muted">Reseller credentials (reseller ID, API key, IP) plus defaults for creating registrations under an existing customer account.</p>
    </div>
    <a href="{$this->escape($this->basePath())}">Back to dashboard</a>
  </div>
  <article class="info-card">
    <h2>Current Status</h2>
    <p class="{$this->escape($liveStatusPillClass)}">{$this->escape($liveStatusLabel)}</p>
    {$liveStatusNoteMarkup}
    <p class="muted">Provider account: {$this->escape((string) ($providerAccount['display_name'] ?? 'NetEarthOne'))}</p>
    <p class="muted">Environment: {$this->escape($environment)}</p>
    <p class="muted">API base URL: <code>{$this->escape($apiBaseUrl !== '' ? $apiBaseUrl : 'Not configured')}</code></p>
    <p class="muted">Reseller ID: <code>{$this->escape($resellerId !== '' ? $resellerId : 'Not configured')}</code></p>
    <p class="muted">IP Address (ACL): <code>{$this->escape($ipAddress !== '' ? $ipAddress : 'Not configured')}</code></p>
    <p class="muted">API key: <code>{$this->escape($apiKeyMasked)}</code></p>
    <p class="muted">Default customer ID: <code>{$this->escape($defaultCustomerId !== '' ? $defaultCustomerId : 'Not configured')}</code></p>
    <p class="muted">Default invoice option: <code>{$this->escape($defaultInvoiceOption)}</code></p>
    <p><a href="{$this->escape($this->providersNetEarthOnePath())}?probe=health">Run live API health probe</a></p>
  </article>
  {$authErrorMarkup}
  {$ipWhitelistMarkup}
  <form method="post" action="{$this->escape($this->providersNetEarthOnePath())}" class="settings-form">
    <div class="form-grid">
      <label>
        <span>Timeout (seconds)</span>
        <input type="number" name="timeout" min="5" max="300" value="{$this->escape($timeout)}" required>
      </label>
      <label>
        <span>API Base URL</span>
        <input type="text" name="api_base_url" value="{$this->escape($apiBaseUrl)}" placeholder="https://api.netearthone.com/anacreon/servlet/ApiCall.xml">
      </label>
      <label>
        <span>Reseller ID</span>
        <input type="text" name="auth_user_id" value="{$this->escape($resellerId)}" placeholder="Reseller ID (also known as auth_user_id)">
        <small class="muted">Numeric reseller / account owner ID issued by NetEarthOne.</small>
      </label>
      <label>
        <span>IP Address (Whitelist)</span>
        <input type="text" name="ip_address" value="{$this->escape($ipAddress)}" placeholder="203.0.113.10 or comma-separated list">
        <small class="muted">Reference only. Add these IPs to the allowed outgoing IP list in your NetEarthOne reseller console so API calls are not blocked.</small>
      </label>
      <label>
        <span>API Key</span>
        <input type="password" name="api_key" autocomplete="off" value="" placeholder="{$this->escape($apiKeyPlaceholder)}">
        <small class="muted">Leave blank to keep the current value. Paste a new value here to rotate or set a missing API key.</small>
      </label>
      <label>
        <span>Pricing JSON Path</span>
        <input type="text" name="pricing_json" value="{$this->escape($pricingJson)}" placeholder="config/pricing/netearthone.custom.json">
      </label>
      <label>
        <span>Default Customer ID</span>
        <input type="text" name="default_customer_id" value="{$this->escape($defaultCustomerId)}" placeholder="NetEarthOne sub-customer ID (numeric)">
        <small class="muted">When a registration, renew or transfer order is created without an explicit customer, it will be placed under this existing NetEarthOne customer account. Leave blank to rely on provider defaults or to create new customers via orders.</small>
      </label>
      <label>
        <span>Default Invoice Option</span>
        <select name="default_invoice_option">
          <option value="NoInvoice"{$this->selected($defaultInvoiceOption, 'NoInvoice')}>NoInvoice</option>
          <option value="PayInvoice"{$this->selected($defaultInvoiceOption, 'PayInvoice')}>PayInvoice</option>
          <option value="KeepInvoice"{$this->selected($defaultInvoiceOption, 'KeepInvoice')}>KeepInvoice</option>
          <option value="OnlyAdd"{$this->selected($defaultInvoiceOption, 'OnlyAdd')}>OnlyAdd</option>
        </select>
      </label>
      <label>
        <span>Environment</span>
        <select name="environment">
          <option value="production"{$this->selected($environment, 'production')}>Production</option>
          <option value="sandbox"{$this->selected($environment, 'sandbox')}>Sandbox</option>
          <option value="staging"{$this->selected($environment, 'staging')}>Staging</option>
        </select>
      </label>
      <label class="checkbox-line">
        <input type="checkbox" name="is_active" value="1"{$this->checked($isActiveDb)}>
        <span>Provider account is active</span>
      </label>
    </div>
    <div class="form-actions">
      <button type="submit">Save Settings</button>
    </div>
  </form>
</section>
{$probeMarkup}
{$probeDiagnosticsMarkup}
HTML;

        return $this->layout('NetEarthOne Settings', $body);
    }

    private function handleNetEarthOneSettingsSave(array $post): string
    {
        $providerAccount = $this->app->providerAccount('netearthone');
        $currentStored = $this->app->providerStoredConfig('netearthone');
        $currentEffective = $this->app->providerEffectiveConfig('netearthone');

        $fields = [
            'is_active' => isset($post['is_active']) && (string) $post['is_active'] === '1',
            'environment' => $this->normalizeNullableString($post['environment'] ?? null) ?? 'production',
        ];

        $timeoutInput = (int) ($post['timeout'] ?? 0);
        $apiBaseUrlInput = $this->normalizeProjectPathInput($post['api_base_url'] ?? null);
        $authUserIdInput = $this->normalizeNumericString($post['auth_user_id'] ?? null);
        $ipAddressInput = $this->normalizeWhitespaceSeparated($post['ip_address'] ?? null);
        $pricingJsonInput = $this->normalizeProjectPathInput($post['pricing_json'] ?? null);
        $defaultCustomerIdInput = $this->normalizeNumericString($post['default_customer_id'] ?? null);
        $defaultInvoiceOptionInput = $this->normalizeInvoiceOption($post['default_invoice_option'] ?? null);
        $apiKeyInputRaw = $this->normalizeNullableString($post['api_key'] ?? null);

        $base = $currentStored;
        if ($timeoutInput > 0) {
            $base['timeout'] = max(5, min(300, $timeoutInput));
        }
        if ($apiBaseUrlInput !== null) {
            $base['api_base_url'] = $apiBaseUrlInput;
        }
        if ($authUserIdInput !== null) {
            $base['auth_user_id'] = $authUserIdInput;
        }
        if ($ipAddressInput !== null) {
            $base['ip_address'] = $ipAddressInput;
        }
        if ($pricingJsonInput !== null) {
            $base['pricing_json'] = $pricingJsonInput;
        }
        if ($defaultCustomerIdInput !== null) {
            $base['default_customer_id'] = $defaultCustomerIdInput;
        }
        if ($defaultInvoiceOptionInput !== null) {
            $base['default_invoice_option'] = $defaultInvoiceOptionInput;
        }
        if ($apiKeyInputRaw !== null) {
            $base['api_key'] = $apiKeyInputRaw;
        }

        $expectedKeys = ['timeout', 'api_base_url', 'auth_user_id', 'api_key', 'ip_address', 'pricing_json', 'default_customer_id', 'default_invoice_option'];
        $defaults = [
            'timeout' => 30,
            'api_base_url' => $this->nullableConfigString($currentEffective['api_base_url'] ?? null) ?? 'https://httpapi.com/api',
            'auth_user_id' => $this->nullableConfigString($currentEffective['auth_user_id'] ?? null),
            'api_key' => $this->nullableConfigString($currentEffective['api_key'] ?? null),
            'ip_address' => $this->nullableConfigString($currentEffective['ip_address'] ?? null),
            'pricing_json' => $this->nullableConfigString($currentEffective['pricing_json'] ?? null) ?? 'config/pricing/netearthone.custom.json',
            'default_customer_id' => $this->nullableConfigString($currentEffective['default_customer_id'] ?? null),
            'default_invoice_option' => $this->nullableConfigString($currentEffective['default_invoice_option'] ?? null) ?? 'NoInvoice',
        ];
        foreach ($defaults as $key => $fallback) {
            $hasAlready = isset($base[$key]) && (is_int($base[$key]) ? $base[$key] > 0 : $this->nullableConfigString($base[$key]) !== null);
            if (! $hasAlready && $fallback !== null) {
                $base[$key] = $fallback;
            }
        }

        $config = $base;

        $beforeKeys = array_values(array_keys($currentStored));
        $beforeMissing = array_values(array_diff($expectedKeys, $beforeKeys));
        $beforeCount = count($beforeKeys);

        $this->app->providerAccountRepository()->updateSettings(
            (string) $providerAccount['id'],
            $fields,
            $config,
        );

        $reload = $this->app->providerAccountRepository()->findByCode('netearthone');
        $reloadStored = $this->app->providerAccountRepository()->decodeConfig($reload);
        $afterKeys = array_values(array_keys($reloadStored));
        $afterMissing = array_values(array_diff($expectedKeys, $afterKeys));
        $afterCount = count($afterKeys);
        $savedAuthIdMasked = $this->maskedSecret((string) ($reloadStored['auth_user_id'] ?? ''));
        $savedApiKeyMasked = $this->maskedSecret((string) ($reloadStored['api_key'] ?? ''));
        $savedIpMasked = $this->escape((string) ($reloadStored['ip_address'] ?? 'Not configured'));
        $rowUpdatedAt = '';
        if (is_array($reload) && isset($reload['updated_at'])) {
            $rowUpdatedAt = ' (updated_at=' . $this->escape((string) $reload['updated_at']) . ')';
        }

        $keyStatus = count($afterMissing) === 0
            ? " KEYS OK {$afterCount}/8:[" . implode(',', $afterKeys) . ']'
            : " KEYS {$afterCount}/8 — MISSING:[" . implode(',', $afterMissing) . '] (before save had ' . $beforeCount . ' missing:[' . implode(',', $beforeMissing) . '])';

        $flash = 'NetEarthOne settings saved.' . $rowUpdatedAt . $keyStatus
            . ' Stored: auth_user_id=' . $savedAuthIdMasked
            . ', api_key=' . $savedApiKeyMasked
            . ', ip_address=' . $savedIpMasked . '.';

        return $this->safeRedirect($this->providersNetEarthOnePath() . '?flash=' . rawurlencode($flash));
    }

    private function renderCozaSettings(array $query): string
    {
        $flash = trim((string) ($query['flash'] ?? ''));
        $providerAccount = $this->app->providerAccount('coza');
        $saved = $this->app->providerStoredConfig('coza');
        $effective = $this->app->providerEffectiveConfig('coza');
        $diagnostics = $this->app->providerRuntimeDiagnostics('coza');
        $certOptions = $this->certFileOptions();
        $certPath = $this->displayConfigValue($saved, $effective, 'cert_path');
        $caFile = $this->displayConfigValue($saved, $effective, 'ca_file');
        $pricingJson = $this->displayConfigValue($saved, $effective, 'pricing_json');
        $timeout = (string) ($this->displayConfigValue($saved, $effective, 'timeout') ?: '30');
        $environment = trim((string) ($providerAccount['environment'] ?? 'production'));
        $isActiveDb = (bool) ($providerAccount['is_active'] ?? true);
        $verifyPeer = array_key_exists('verify_peer', $saved)
            ? (bool) $saved['verify_peer']
            : (bool) ($effective['verify_peer'] ?? true);
        $resolvedCertPath = $this->resolvedPathPreview($certPath);
        $resolvedCaPath = $this->resolvedPathPreview($caFile);
        $readiness = $this->cozaReadinessSummary($effective);

        $liveStatusLabel = 'Provider Account Inactive';
        $liveStatusNote = '';
        if ($isActiveDb) {
            try {
                $provider = $this->app->provider('coza');
                if (method_exists($provider, 'healthCheck')) {
                    $health = $provider->healthCheck();
                    $ok = (bool) ($health['ok'] ?? true);
                    $statusText = is_string($health['status'] ?? null) ? trim((string) $health['status']) : '';
                    if ($ok) {
                        $liveStatusLabel = 'Provider Account Active';
                        if ($statusText !== '') {
                            $liveStatusNote = $statusText;
                        }
                    } else {
                        $liveStatusLabel = 'Provider Account Inactive';
                        $liveStatusNote = is_string($health['error'] ?? null) && trim((string) $health['error']) !== ''
                            ? trim((string) $health['error'])
                            : ($statusText !== '' ? $statusText : 'Live EPP hello returned a failure status.');
                    }
                } else {
                    $liveStatusLabel = 'Provider Account Active';
                }
            } catch (Throwable $exception) {
                $liveStatusLabel = 'Provider Account Inactive';
                $liveStatusNote = $exception->getMessage();
            }
        } else {
            $liveStatusNote = 'Provider account is marked inactive in provider_accounts.is_active.';
        }
        $liveStatusPillClass = $liveStatusLabel === 'Provider Account Active' ? 'status-pill status-ok' : 'status-pill status-warn';
        $liveStatusNoteMarkup = $liveStatusNote === '' ? '' : '<p class="muted">' . $this->escape($liveStatusNote) . '</p>';

        $flashMarkup = $flash === '' ? '' : '<div class="notice">' . $this->escape($flash) . '</div>';
        $readinessItems = implode('', array_map(
            fn (string $item): string => '<li>' . $this->escape($item) . '</li>',
            $readiness['items'],
        ));
        $runtimeDiagnosticsMarkup = '<pre class="code-block">' . $this->escape(
            json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
        ) . '</pre>';
        $probeMarkup = '';
        if (($query['probe'] ?? null) === 'hello') {
            $probeMarkup = '<article class="info-card"><h2>Live EPP Hello Probe</h2>' . $runtimeDiagnosticsMarkup . '</article>';

            try {
                $provider = $this->app->provider('coza');
                if (method_exists($provider, 'healthCheck')) {
                    $probeResult = $provider->healthCheck();
                    $probeMarkup = '<article class="info-card"><h2>Live EPP Hello Probe</h2><pre class="code-block">' . $this->escape(
                        json_encode($probeResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
                    ) . '</pre>' . $runtimeDiagnosticsMarkup . '</article>';
                }
            } catch (Throwable $exception) {
                $probeMarkup = '<article class="info-card"><h2>Live EPP Hello Probe</h2><pre class="code-block">' . $this->escape($exception->getMessage()) . '</pre>' . $runtimeDiagnosticsMarkup . '</article>';
            }
        }

        $body = <<<HTML
{$flashMarkup}
<section class="panel">
  <div class="panel-head">
    <div>
      <p class="eyebrow">Provider Settings</p>
      <h1>.co.za EPP connection</h1>
      <p class="muted">This screen stores non-sensitive runtime settings for the .co.za EPP connection. Sensitive credentials are kept in mounted secrets.</p>
    </div>
    <a href="{$this->escape($this->basePath())}">Back to dashboard</a>
  </div>
  <article class="info-card">
    <h2>Current Status</h2>
    <p class="{$this->escape($liveStatusPillClass)}">{$this->escape($liveStatusLabel)}</p>
    {$liveStatusNoteMarkup}
    <ul class="info-list">{$readinessItems}</ul>
    <p class="muted">Provider account: {$this->escape((string) ($providerAccount['display_name'] ?? '.co.za'))}</p>
    <p class="muted">Environment: {$this->escape($environment)}</p>
    <p class="muted">Repo cert folder: <code>cert/</code></p>
    <p class="muted">Resolved cert path: <code>{$this->escape($resolvedCertPath ?? 'Not set')}</code></p>
    <p class="muted">Resolved CA file: <code>{$this->escape($resolvedCaPath ?? 'Not set')}</code></p>
    <p><a href="{$this->escape($this->providersCozaPath())}?probe=hello">Run live EPP hello probe</a></p>
  </article>
  <form method="post" action="{$this->escape($this->providersCozaPath())}" class="settings-form">
    <div class="form-grid">
      <label>
        <span>Timeout (seconds)</span>
        <input type="number" name="timeout" min="5" max="300" value="{$this->escape($timeout)}" required>
      </label>
      <label>
        <span>Certificate Path</span>
        <input type="text" name="cert_path" value="{$this->escape($certPath)}" placeholder="cert/client.pem or mounted absolute path">
      </label>
      <label>
        <span>CA File</span>
        <input type="text" name="ca_file" value="{$this->escape($caFile)}" placeholder="cert/ca.pem or mounted absolute path">
      </label>
      <label>
        <span>Pricing JSON Path</span>
        <input type="text" name="pricing_json" value="{$this->escape($pricingJson)}" placeholder="config/pricing/coza.custom.json">
      </label>
      <label>
        <span>Environment</span>
        <select name="environment">
          <option value="production"{$this->selected($environment, 'production')}>Production</option>
          <option value="sandbox"{$this->selected($environment, 'sandbox')}>Sandbox</option>
          <option value="staging"{$this->selected($environment, 'staging')}>Staging</option>
        </select>
      </label>
      <label class="checkbox-line">
        <input type="checkbox" name="verify_peer" value="1"{$this->checked($verifyPeer)}>
        <span>Verify TLS peer certificate</span>
      </label>
      <label class="checkbox-line">
        <input type="checkbox" name="is_active" value="1"{$this->checked($isActiveDb)}>
        <span>Provider account is active</span>
      </label>
    </div>
    <div class="form-actions">
      <button type="submit">Save Non-Sensitive Settings</button>
    </div>
  </form>
</section>
{$probeMarkup}
HTML;

        return $this->layout('.co.za Settings', $body);
    }

    private function handleCozaSettingsSave(array $post): string
    {
        $providerAccount = $this->app->providerAccount('coza');

        $config = [
            'cert_path' => $this->normalizeProjectPathInput($post['cert_path'] ?? null),
            'ca_file' => $this->normalizeProjectPathInput($post['ca_file'] ?? null),
            'verify_peer' => isset($post['verify_peer']) && (string) $post['verify_peer'] === '1',
            'timeout' => max(5, min(300, (int) ($post['timeout'] ?? 30))),
            'pricing_json' => $this->normalizeProjectPathInput($post['pricing_json'] ?? null),
        ];

        $this->app->providerAccountRepository()->updateSettings(
            (string) $providerAccount['id'],
            [
                'is_active' => isset($post['is_active']) && (string) $post['is_active'] === '1',
                'environment' => $post['environment'] ?? 'production',
            ],
            $config,
        );

        return $this->safeRedirect($this->providersCozaPath() . '?flash=' . rawurlencode('.co.za settings saved.'));
    }

    private function handleTaskEnqueue(array $post): string
    {
        $taskType = trim((string) ($post['task_type'] ?? ''));
        $queueName = trim((string) ($post['queue_name'] ?? 'default'));
        $providerCode = trim((string) ($post['provider_code'] ?? 'coza'));

        try {
            $this->app->taskQueueRepository()->enqueue(
                $taskType,
                $queueName,
                $this->app->tenantContext() + ['provider_code' => $providerCode],
                priority: 10,
            );

            return $this->safeRedirect($this->basePath() . '?flash=' . rawurlencode(sprintf('%s queued for %s.', $taskType, $providerCode)));
        } catch (Throwable $exception) {
            return $this->safeRedirect($this->basePath() . '?flash=' . rawurlencode($exception->getMessage()));
        }
    }

    private function renderTaskEnqueuePage(): string
    {
        $body = <<<HTML
<section class="panel">
  <div class="panel-head">
    <div>
      <p class="eyebrow">Worker Control</p>
      <h1>Queue Registrar Tasks</h1>
      <p class="muted">This route accepts task submissions and also provides a direct manual queueing screen when opened in a browser.</p>
    </div>
    <a href="{$this->escape($this->basePath())}">Back to dashboard</a>
  </div>
  <div class="action-grid">
    {$this->taskForm('sync_pricing', 'pricing', 'Queue pricing sync')}
    {$this->taskForm('sync_domain_dates', 'dates', 'Queue date sync')}
    {$this->taskForm('sync_domain_portfolio', 'sync', 'Queue portfolio sync')}
    {$this->taskForm('retry_failed_sync_runs', 'retries', 'Retry failed tasks')}
  </div>
</section>
HTML;

        return $this->layout('Queue Tasks', $body);
    }

    private function handleDomainsImport(array $post): string
    {
        $entries = $this->parseDomainImportEntries($post, $_FILES['import_file'] ?? null);
        if ($entries === []) {
            return $this->renderDomains(['flash' => 'No domains were detected in the pasted text or uploaded file.']);
        }

        $defaultProvider = trim((string) ($post['provider_code'] ?? 'coza'));
        $providerAccount = $this->app->providerAccount($defaultProvider);
        $providerAccountId = trim((string) ($providerAccount['id'] ?? ''));
        if ($providerAccountId === '') {
            return $this->renderDomains(['flash' => 'The selected provider account is not available for imports yet.']);
        }

        $imported = 0;
        $skipped = [];
        foreach ($entries as $entry) {
            $domainName = strtolower(trim((string) ($entry['domain_name'] ?? $entry['domain'] ?? '')));
            if ($domainName === '' || ! str_contains($domainName, '.')) {
                $skipped[] = $domainName === '' ? '[blank]' : $domainName;
                continue;
            }

            $providerCode = trim((string) ($entry['provider_code'] ?? $defaultProvider));
            $accountId = $providerCode === $defaultProvider
                ? $providerAccountId
                : trim((string) (($this->app->providerAccount($providerCode)['id'] ?? '')));

            if ($accountId === '') {
                $skipped[] = $domainName;
                continue;
            }

            $tenantId = trim((string) ($entry['tenant_id'] ?? ''));
            $ownerType = trim((string) ($entry['owner_type'] ?? 'user'));
            $ownerId = trim((string) ($entry['owner_id'] ?? ''));
            $billingTenantId = trim((string) ($entry['billing_tenant_id'] ?? $tenantId));

            $this->app->domainRepository()->upsertImportedDomain(
                $accountId,
                $providerCode,
                $domainName,
                [
                    'tenant_id' => $tenantId,
                    'owner_type' => $ownerType,
                    'owner_id' => $ownerId,
                    'billing_mode' => trim((string) ($entry['billing_mode'] ?? 'user')),
                    'billing_tenant_id' => $billingTenantId,
                    'registered_at' => $entry['registered_at'] ?? $entry['cdate'] ?? null,
                    'expires_at' => $entry['expires_at'] ?? $entry['expiry'] ?? null,
                    'autorenew' => $entry['autorenew'] ?? $entry['auto_renew_enabled'] ?? null,
                    'registrant' => $entry['registrant'] ?? null,
                    'billing' => $entry['billing'] ?? null,
                    'admin' => $entry['admin'] ?? null,
                    'tech' => $entry['tech'] ?? null,
                ],
            );
            $imported++;
        }

        $message = sprintf('Imported %d domain%s.', $imported, $imported === 1 ? '' : 's');
        if ($skipped !== []) {
            $message .= ' Skipped: ' . implode(', ', array_slice($skipped, 0, 10));
            if (count($skipped) > 10) {
                $message .= ' and more';
            }
        }

        return $this->renderDomains(['flash' => $message]);
    }

    private function handleDomainSync(array $post): string
    {
        $domain = $this->findControlDomain($post);
        if ($domain === null) {
            return $this->redirectToDomains('The selected domain could not be found.');
        }

        $providerCode = trim((string) ($domain['provider_code'] ?? ''));
        $provider = $this->app->provider($providerCode);
        if (! $provider instanceof DomainPortfolioSyncInterface) {
            return $this->redirectToDomains(sprintf('%s does not support live registry sync.', $this->providerDisplayName($providerCode)));
        }

        $sync = $provider->syncDomain(
            (string) ($domain['domain_name'] ?? ''),
            new SyncContext($providerCode, 'control-domain-sync', true),
        );

        if (($sync['ok'] ?? true) === false) {
            return $this->redirectToDomains((string) ($sync['message'] ?? 'The registry sync failed.'));
        }

        $this->app->domainRepository()->updateFromSync((string) ($domain['id'] ?? ''), $sync);
        $nameserverCount = count($this->app->domainRepository()->listNameservers((string) ($domain['id'] ?? '')));

        return $this->redirectToDomains(sprintf(
            'Synced %s from the registry. %d nameserver%s stored locally.',
            (string) ($domain['domain_name'] ?? ''),
            $nameserverCount,
            $nameserverCount === 1 ? '' : 's',
        ));
    }

    private function handleDomainRenew(array $post): string
    {
        $domain = $this->findControlDomain($post);
        if ($domain === null) {
            return $this->redirectToDomains('The selected domain could not be found.');
        }

        $providerCode = trim((string) ($domain['provider_code'] ?? ''));
        $provider = $this->app->provider($providerCode);
        if (! $provider instanceof DomainMutationInterface) {
            return $this->redirectToDomains(sprintf('%s does not support live renewals.', $this->providerDisplayName($providerCode)));
        }

        $periodYears = max(1, (int) ($post['period_years'] ?? 1));
        $currentExpiryDate = $this->normalizeDateOnly((string) ($domain['expires_at'] ?? $domain['renewal_due_at'] ?? ''));

        if ($currentExpiryDate === null && $provider instanceof DomainPortfolioSyncInterface) {
            $sync = $provider->syncDomain(
                (string) ($domain['domain_name'] ?? ''),
                new SyncContext($providerCode, 'control-pre-renew-sync', true),
            );
            if (($sync['ok'] ?? true) !== false) {
                $this->app->domainRepository()->updateFromSync((string) ($domain['id'] ?? ''), $sync);
                $domain = $this->app->domainRepository()->findById((string) ($domain['id'] ?? '')) ?? $domain;
                $currentExpiryDate = $this->normalizeDateOnly((string) ($domain['expires_at'] ?? $domain['renewal_due_at'] ?? ''));
            }
        }

        if ($currentExpiryDate === null) {
            return $this->redirectToDomains(sprintf(
                'Cannot renew %s yet because no current expiry date is stored. Sync the domain first.',
                (string) ($domain['domain_name'] ?? ''),
            ));
        }

        $result = $provider->renewDomain(
            (string) ($domain['domain_name'] ?? ''),
            $periodYears,
            ['current_expiry_date' => $currentExpiryDate],
        );

        if (! ($result['ok'] ?? false)) {
            return $this->redirectToDomains((string) ($result['message'] ?? 'The live renewal failed.'));
        }

        if ($provider instanceof DomainPortfolioSyncInterface) {
            $sync = $provider->syncDomain(
                (string) ($domain['domain_name'] ?? ''),
                new SyncContext($providerCode, 'control-post-renew-sync', true),
            );
            if (($sync['ok'] ?? true) !== false) {
                $this->app->domainRepository()->updateFromSync((string) ($domain['id'] ?? ''), $sync);
                $domain = $this->app->domainRepository()->findById((string) ($domain['id'] ?? '')) ?? $domain;
            }
        }

        return $this->redirectToDomains(sprintf(
            'Renewed %s for %d year%s without billing. Current expiry: %s.',
            (string) ($domain['domain_name'] ?? ''),
            $periodYears,
            $periodYears === 1 ? '' : 's',
            $this->formatDateDisplay((string) ($domain['expires_at'] ?? $domain['renewal_due_at'] ?? 'Not yet synced')),
        ));
    }

    private function handleDomainAssign(array $post): string
    {
        $domain = $this->findControlDomain($post);
        if ($domain === null) {
            return $this->redirectToDomains('The selected domain could not be found.');
        }

        $tenantId = trim((string) ($post['tenant_id'] ?? (string) ($domain['tenant_id'] ?? '')));
        $ownerType = trim((string) ($post['owner_type'] ?? (string) ($domain['owner_type'] ?? 'user')));
        $ownerId = trim((string) ($post['owner_id'] ?? (string) ($domain['owner_id'] ?? '')));
        $billingTenantId = trim((string) ($post['billing_tenant_id'] ?? (string) ($domain['billing_tenant_id'] ?? $tenantId)));

        if ($tenantId === '') {
            return $this->redirectToDomains('A tenant ID is required before assigning this domain.');
        }

        if ($ownerId === '') {
            $ownerId = $tenantId;
        }

        $this->app->domainRepository()->assignOwnership(
            (string) ($domain['id'] ?? ''),
            [
                'tenant_id' => $tenantId,
                'owner_type' => $ownerType === '' ? 'user' : $ownerType,
                'owner_id' => $ownerId,
                'billing_mode' => trim((string) ($post['billing_mode'] ?? (string) ($domain['billing_mode'] ?? 'user'))),
                'billing_tenant_id' => $billingTenantId === '' ? $tenantId : $billingTenantId,
                'customer_id' => trim((string) ($post['customer_id'] ?? '')),
            ],
        );

        return $this->redirectToDomains(sprintf(
            'Assigned %s to %s %s.',
            (string) ($domain['domain_name'] ?? ''),
            $ownerType === '' ? 'user' : $ownerType,
            $ownerId,
        ));
    }

    /**
     * @param array<string, mixed> $query
     */
    private function renderDomainAssignPage(array $query): string
    {
        $flash = trim((string) ($query['flash'] ?? ''));
        $flashMarkup = $flash === '' ? '' : '<div class="notice">' . $this->escape($flash) . '</div>';
        $domain = $this->findControlDomain($query);
        if ($domain === null) {
            return $this->redirectToDomains('Select a domain from the list below to assign it.');
        }
        $domainName = (string) ($domain['domain_name'] ?? '');
        $card = $this->renderDomainManagementCard($domain);
        $back = $this->escape($this->domainsPath());

        $body = <<<HTML
{$flashMarkup}
<section class="panel">
  <div class="panel-head">
    <div>
      <p class="eyebrow">Domains / Assign</p>
      <h1>Assign {$this->escape($domainName)}</h1>
      <p class="muted">Move the selected domain to another tenant, owner, or billing mode. All Sync, Renew, and Assign actions for this domain are on this card.</p>
    </div>
    <a href="{$back}">Back to domains list</a>
  </div>
  <div class="action-grid">{$card}</div>
</section>
HTML;

        return $this->layout('Assign ' . $domainName, $body);
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    private function renderTable(array $headers, array $rows): string
    {
        $headCells = implode('', array_map(fn (string $header): string => '<th>' . $this->escape($header) . '</th>', $headers));

        if ($rows === []) {
            return '<p class="muted">No records yet.</p>';
        }

        $bodyRows = [];
        foreach ($rows as $row) {
            $cells = implode('', array_map(fn (string $cell): string => '<td>' . $this->escape($cell) . '</td>', $row));
            $bodyRows[] = '<tr>' . $cells . '</tr>';
        }

        return '<div class="table-wrap"><table><thead><tr>' . $headCells . '</tr></thead><tbody>' . implode('', $bodyRows) . '</tbody></table></div>';
    }

    /**
     * Like renderTable but skips escaping for specific zero-indexed column numbers (htmlColumns), trusting each is already safe HTML.
     *
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @param list<int> $htmlColumns
     */
    private function renderRawTable(array $headers, array $rows, array $htmlColumns = []): string
    {
        $htmlColumnSet = [];
        foreach ($htmlColumns as $index) {
            $htmlColumnSet[(int) $index] = true;
        }
        $headCells = implode('', array_map(fn (string $header): string => '<th>' . $this->escape($header) . '</th>', $headers));

        if ($rows === []) {
            return '<p class="muted">No records yet.</p>';
        }

        $bodyRows = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach (array_values($row) as $index => $cell) {
                if (isset($htmlColumnSet[$index])) {
                    $cells[] = '<td>' . $cell . '</td>';
                } else {
                    $cells[] = '<td>' . $this->escape((string) $cell) . '</td>';
                }
            }
            $bodyRows[] = '<tr>' . implode('', $cells) . '</tr>';
        }

        return '<div class="table-wrap"><table><thead><tr>' . $headCells . '</tr></thead><tbody>' . implode('', $bodyRows) . '</tbody></table></div>';
    }

    /**
     * @param array<string, mixed> $domain
     */
    private function renderDomainRowActions(array $domain): string
    {
        $domainId = (string) ($domain['id'] ?? '');
        $domainName = (string) ($domain['domain_name'] ?? '');
        $syncForm = '<form method="post" action="' . $this->escape($this->domainsSyncPath()) . '" class="inline-form">'
            . '<input type="hidden" name="domain_id" value="' . $this->escape($domainId) . '">'
            . '<input type="hidden" name="domain_name" value="' . $this->escape($domainName) . '">'
            . '<button type="submit" class="button button-secondary">Sync Registry</button>'
            . '</form>';
        $renewForm = '<form method="post" action="' . $this->escape($this->domainsRenewPath()) . '" class="inline-form">'
            . '<input type="hidden" name="domain_id" value="' . $this->escape($domainId) . '">'
            . '<input type="hidden" name="domain_name" value="' . $this->escape($domainName) . '">'
            . '<label style="display:none;"><input type="number" name="years" min="1" max="10" value="1"></label>'
            . '<button type="submit" class="button button-secondary">Renew 1y</button>'
            . '</form>';
        $assignHref = $this->domainsAssignPath() . '?domain_id=' . urlencode($domainId) . '&domain=' . urlencode($domainName);
        $assignLink = '<a class="button button-primary" href="' . $this->escape($assignHref) . '">Assign / Move</a>';

        return '<div class="inline-actions">' . $syncForm . $renewForm . $assignLink . '</div>';
    }

    private function renderDomainImportForm(): string
    {
        return <<<HTML
<form method="post" action="{$this->escape($this->domainsPath())}" enctype="multipart/form-data" class="checkout-form">
  <div class="panel panel-subtle">
    <h2>Bulk Import Domains</h2>
    <p class="muted">Paste one domain per line, or upload a CSV with columns like <code>domain_name</code>, <code>provider_code</code>, <code>owner_type</code>, <code>owner_id</code>, and <code>billing_tenant_id</code>. The `.co.za` export format with <code>name</code>, <code>cdate</code>, <code>expiry</code>, <code>autorenew</code>, <code>registrant</code>, <code>billing</code>, <code>admin</code>, and <code>tech</code> is supported too.</p>
    <div class="field-grid">
      <label>
        <span>Default Provider</span>
        <select name="provider_code">
          <option value="coza">.co.za</option>
          <option value="netearthone">NetEarthOne</option>
        </select>
      </label>
      <label>
        <span>CSV Upload</span>
        <input type="file" name="import_file" accept=".csv,.txt,text/csv,text/plain">
      </label>
      <label>
        <span>Default Owner Type</span>
        <select name="owner_type">
          <option value="user">User</option>
          <option value="company">Company</option>
          <option value="persona">Persona</option>
        </select>
      </label>
      <label>
        <span>Default Owner ID</span>
        <input type="text" name="owner_id" placeholder="Optional">
      </label>
      <label>
        <span>Default Tenant ID</span>
        <input type="text" name="tenant_id" placeholder="Optional">
      </label>
      <label>
        <span>Default Billing Tenant ID</span>
        <input type="text" name="billing_tenant_id" placeholder="Optional">
      </label>
    </div>
    <label>
      <span>Paste Domains or CSV Rows</span>
      <textarea name="import_text" rows="8" placeholder="ttestthis.co.za&#10;example.org.za&#10;domain_name,owner_type,owner_id,tenant_id&#10;sample.co.za,company,company_123,tenant_123"></textarea>
    </label>
    <div class="form-actions">
      <button type="submit">Import Domains</button>
    </div>
  </div>
</form>
HTML;
    }

    private function taskForm(string $taskType, string $queueName, string $label): string
    {
        return <<<HTML
<form method="post" action="{$this->escape($this->taskEnqueuePath())}" class="action-card">
  <input type="hidden" name="task_type" value="{$this->escape($taskType)}">
  <input type="hidden" name="queue_name" value="{$this->escape($queueName)}">
  <p>{$this->escape($label)}</p>
  <label>
    <span>Provider</span>
    <select name="provider_code">
      <option value="coza">.co.za</option>
      <option value="netearthone">NetEarthOne</option>
    </select>
  </label>
  <button type="submit">Run</button>
</form>
HTML;
    }

    /**
     * @param array<string, mixed> $domain
     */
    private function renderDomainManagementCard(array $domain): string
    {
        $domainId = (string) ($domain['id'] ?? '');
        $domainName = (string) ($domain['domain_name'] ?? '');
        $nameservers = $this->app->domainRepository()->listNameservers($domainId);
        $metadata = $this->decodeDomainMetadata($domain);
        $contacts = is_array($metadata['contacts'] ?? null) ? $metadata['contacts'] : [];
        $importMetadata = is_array($metadata['import'] ?? null) ? $metadata['import'] : [];
        $registrant = trim((string) ($metadata['registrant'] ?? ($importMetadata['registrant'] ?? '')));
        $billingContact = trim((string) ($contacts['billing'] ?? ($importMetadata['billing'] ?? '')));
        $adminContact = trim((string) ($contacts['admin'] ?? ($importMetadata['admin'] ?? '')));
        $techContact = trim((string) ($contacts['tech'] ?? ($importMetadata['tech'] ?? '')));
        $nameserverItems = $nameservers === []
            ? '<li>No nameservers stored locally yet. Run a registry sync.</li>'
            : implode('', array_map(fn (array $nameserver): string => '<li>' . $this->escape($this->nameserverDisplay($nameserver)) . '</li>', $nameservers));
        $expiresAt = $this->formatDateDisplay((string) ($domain['expires_at'] ?? $domain['renewal_due_at'] ?? ''));
        $registeredAt = $this->formatDateDisplay((string) ($domain['registered_at'] ?? ''));
        $lastSync = $this->formatDateDisplay((string) ($domain['last_synced_at'] ?? ''));
        $ownerType = (string) ($domain['owner_type'] ?? 'user');
        $ownerId = (string) ($domain['owner_id'] ?? '');
        $tenantId = (string) ($domain['tenant_id'] ?? '');
        $billingMode = (string) ($domain['billing_mode'] ?? 'user');
        $billingTenantId = (string) ($domain['billing_tenant_id'] ?? '');

        return <<<HTML
<article class="action-card">
  <p class="eyebrow">{$this->escape($this->providerDisplayName((string) ($domain['provider_code'] ?? '')))}</p>
  <h3>{$this->escape($domainName)}</h3>
  <p class="muted">Status: {$this->escape((string) ($domain['registrar_status'] ?? 'active'))} | Registered: {$this->escape($registeredAt)} | Expires: {$this->escape($expiresAt)} | Last Sync: {$this->escape($lastSync)}</p>
  <div class="summary-row"><span>Registrant</span><strong>{$this->escape($registrant !== '' ? $registrant : 'Not synced yet')}</strong></div>
  <div class="summary-row"><span>Admin</span><strong>{$this->escape($adminContact !== '' ? $adminContact : 'Not synced yet')}</strong></div>
  <div class="summary-row"><span>Tech</span><strong>{$this->escape($techContact !== '' ? $techContact : 'Not synced yet')}</strong></div>
  <div class="summary-row"><span>Billing</span><strong>{$this->escape($billingContact !== '' ? $billingContact : 'Not synced yet')}</strong></div>
  <div class="panel panel-subtle" style="margin-top:12px;">
    <h2 style="margin-bottom:8px;">Nameservers</h2>
    <ul class="info-list">{$nameserverItems}</ul>
  </div>
  <form method="post" action="{$this->escape($this->domainsSyncPath())}" class="settings-form">
    <input type="hidden" name="domain_id" value="{$this->escape($domainId)}">
    <div class="form-actions">
      <button type="submit">Sync Registry Details</button>
    </div>
  </form>
  <form method="post" action="{$this->escape($this->domainsRenewPath())}" class="settings-form">
    <input type="hidden" name="domain_id" value="{$this->escape($domainId)}">
    <div class="form-grid">
      <label>
        <span>Renewal Period</span>
        <select name="period_years">
          <option value="1">1 year</option>
          <option value="2">2 years</option>
          <option value="3">3 years</option>
        </select>
      </label>
    </div>
    <div class="form-actions">
      <button type="submit">Renew Now Without Charging</button>
    </div>
  </form>
  <form method="post" action="{$this->escape($this->domainsAssignPath())}" class="settings-form">
    <input type="hidden" name="domain_id" value="{$this->escape($domainId)}">
    <div class="form-grid">
      <label>
        <span>Tenant ID</span>
        <input type="text" name="tenant_id" value="{$this->escape($tenantId)}" placeholder="user:example">
      </label>
      <label>
        <span>Owner Type</span>
        <select name="owner_type">
          <option value="user"{$this->selected($ownerType, 'user')}>User</option>
          <option value="company"{$this->selected($ownerType, 'company')}>Company</option>
          <option value="persona"{$this->selected($ownerType, 'persona')}>Persona</option>
        </select>
      </label>
      <label>
        <span>Owner ID</span>
        <input type="text" name="owner_id" value="{$this->escape($ownerId)}" placeholder="user or company id">
      </label>
      <label>
        <span>Billing Mode</span>
        <select name="billing_mode">
          <option value="user"{$this->selected($billingMode, 'user')}>User</option>
          <option value="company"{$this->selected($billingMode, 'company')}>Company</option>
          <option value="persona"{$this->selected($billingMode, 'persona')}>Persona</option>
        </select>
      </label>
      <label>
        <span>Billing Tenant ID</span>
        <input type="text" name="billing_tenant_id" value="{$this->escape($billingTenantId)}" placeholder="billing tenant id">
      </label>
      <label>
        <span>Customer ID</span>
        <input type="text" name="customer_id" value="{$this->escape((string) ($domain['customer_id'] ?? ''))}" placeholder="Optional existing customer id">
      </label>
    </div>
    <div class="form-actions">
      <button type="submit">Assign Domain</button>
    </div>
  </form>
</article>
HTML;
    }

    /**
     * @param array<string, mixed> $post
     * @param mixed $uploadedFile
     * @return list<array<string, string>>
     */
    private function parseDomainImportEntries(array $post, mixed $uploadedFile): array
    {
        $defaultProvider = trim((string) ($post['provider_code'] ?? 'coza'));
        $defaultOwnerType = trim((string) ($post['owner_type'] ?? 'user'));
        $defaultOwnerId = trim((string) ($post['owner_id'] ?? ''));
        $defaultTenantId = trim((string) ($post['tenant_id'] ?? ''));
        $defaultBillingTenantId = trim((string) ($post['billing_tenant_id'] ?? $defaultTenantId));

        $chunks = [];
        $pasted = trim((string) ($post['import_text'] ?? ''));
        if ($pasted !== '') {
            $chunks[] = $pasted;
        }

        if (is_array($uploadedFile) && (int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmpPath = (string) ($uploadedFile['tmp_name'] ?? '');
            if ($tmpPath !== '' && is_file($tmpPath)) {
                $fileContents = file_get_contents($tmpPath);
                if (is_string($fileContents) && trim($fileContents) !== '') {
                    $chunks[] = $fileContents;
                }
            }
        }

        if ($chunks === []) {
            return [];
        }

        $entries = [];
        foreach ($chunks as $chunk) {
            $lines = preg_split('/\r\n|\r|\n/', $chunk) ?: [];
            $headerMap = null;

            foreach ($lines as $line) {
                $trimmedLine = trim((string) $line);
                if ($trimmedLine === '') {
                    continue;
                }

                $columns = array_map('trim', str_getcsv($trimmedLine));
                if ($columns === []) {
                    continue;
                }

                if ($headerMap === null && $this->isDomainImportHeader($columns)) {
                    $headerMap = array_map(
                        static fn (string $column): string => strtolower(trim($column)),
                        $columns,
                    );
                    continue;
                }

                $entry = [
                    'provider_code' => $defaultProvider,
                    'owner_type' => $defaultOwnerType,
                    'owner_id' => $defaultOwnerId,
                    'tenant_id' => $defaultTenantId,
                    'billing_tenant_id' => $defaultBillingTenantId,
                ];

                if ($headerMap !== null) {
                    foreach ($headerMap as $index => $name) {
                        $entry[$name] = trim((string) ($columns[$index] ?? ''));
                    }
                    if (($entry['domain_name'] ?? '') === '' && ($entry['domain'] ?? '') !== '') {
                        $entry['domain_name'] = (string) $entry['domain'];
                    }
                    if (($entry['domain_name'] ?? '') === '' && ($entry['name'] ?? '') !== '') {
                        $entry['domain_name'] = (string) $entry['name'];
                    }
                } else {
                    $entry['domain_name'] = trim((string) ($columns[0] ?? ''));
                    if (isset($columns[1]) && trim((string) $columns[1]) !== '') {
                        $entry['owner_type'] = trim((string) $columns[1]);
                    }
                    if (isset($columns[2]) && trim((string) $columns[2]) !== '') {
                        $entry['owner_id'] = trim((string) $columns[2]);
                    }
                    if (isset($columns[3]) && trim((string) $columns[3]) !== '') {
                        $entry['tenant_id'] = trim((string) $columns[3]);
                    }
                    if (isset($columns[4]) && trim((string) $columns[4]) !== '') {
                        $entry['billing_tenant_id'] = trim((string) $columns[4]);
                    }
                    if (isset($columns[5]) && trim((string) $columns[5]) !== '') {
                        $entry['provider_code'] = trim((string) $columns[5]);
                    }
                }

                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param list<string> $columns
     */
    private function isDomainImportHeader(array $columns): bool
    {
        $normalized = array_map(
            static fn (string $column): string => strtolower(trim($column)),
            $columns,
        );

        return in_array('domain_name', $normalized, true)
            || in_array('domain', $normalized, true)
            || in_array('name', $normalized, true);
    }

    private function renderNotFound(): string
    {
        http_response_code(404);

        return $this->layout('Not Found', '<section class="panel"><h1>Page Not Found</h1><p class="muted">This admin route does not exist.</p><a href="' . $this->escape($this->basePath()) . '">Back to dashboard</a></section>');
    }

    private function layout(string $title, string $body): string
    {
        if ($this->usesPlatformLayout()) {
            return $this->platformLayout($title, $body);
        }

        $assetBase = $this->assetBasePath();
        $shell = $this->shellMarkup($body);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$this->escape($title)}</title>
  <link rel="stylesheet" href="{$this->escape($assetBase)}/assets/control.css">
</head>
<body>
  {$shell}
</body>
</html>
HTML;
    }

    private function platformLayout(string $title, string $body): string
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['current_realm'] = 'control';
        }

        $head = $this->captureInclude($this->platformIncludePath('complete-head.php'));
        $bodyStart = $this->captureInclude($this->platformIncludePath('complete-body-start.php'));
        $bodyEnd = $this->captureInclude($this->platformIncludePath('complete-body-end.php'));
        $assetBase = $this->assetBasePath();
        $shell = $this->shellMarkup($body);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$this->escape($title)}</title>
  {$head}
  <link rel="stylesheet" href="{$this->escape($assetBase)}/assets/control.css">
</head>
<body>
  {$bodyStart}
  <main class="main-content">
    {$shell}
  </main>
  {$bodyEnd}
</body>
</html>
HTML;
    }

    private function shellMarkup(string $body): string
    {
        return <<<HTML
<div class="shell">
  <header class="topbar">
    <div>
      <p class="eyebrow">Administrative Control</p>
      <strong>Registrar Control</strong>
    </div>
    <nav>
      <a href="{$this->escape($this->basePath())}">Dashboard</a>
      <a href="{$this->escape($this->providersIndexPath())}">Providers</a>
      <a href="{$this->escape($this->providersCozaPath())}">.co.za Settings</a>
      <a href="{$this->escape($this->providersNetEarthOnePath())}">NetEarthOne Settings</a>
      <a href="{$this->escape($this->ordersPath())}">Orders</a>
      <a href="{$this->escape($this->domainsPath())}">Domains</a>
      <a href="{$this->escape($this->tasksPath())}">Tasks</a>
    </nav>
  </header>
  <div class="page">{$body}</div>
</div>
HTML;
    }

    private function usesPlatformLayout(): bool
    {
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

    private function assetBasePath(): string
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        if (str_starts_with($path, '/control/')) {
            return '/gear/domain-registrars/public';
        }

        return '';
    }

    private function basePath(): string
    {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        return str_starts_with($path, '/control/domain-registrars') ? '/control/domain-registrars' : '/';
    }

    private function ordersPath(): string
    {
        return $this->basePath() === '/control/domain-registrars' ? '/control/domain-registrars/orders' : '/orders';
    }

    private function domainsPath(): string
    {
        return $this->basePath() === '/control/domain-registrars' ? '/control/domain-registrars/domains' : '/domains';
    }

    private function domainsSyncPath(): string
    {
        return $this->basePath() === '/control/domain-registrars' ? '/control/domain-registrars/domains/sync' : '/domains/sync';
    }

    private function domainsRenewPath(): string
    {
        return $this->basePath() === '/control/domain-registrars' ? '/control/domain-registrars/domains/renew' : '/domains/renew';
    }

    private function domainsAssignPath(): string
    {
        return $this->basePath() === '/control/domain-registrars' ? '/control/domain-registrars/domains/assign' : '/domains/assign';
    }

    private function tasksPath(): string
    {
        return $this->basePath() === '/control/domain-registrars' ? '/control/domain-registrars/tasks' : '/tasks';
    }

    private function providersCozaPath(): string
    {
        return $this->basePath() === '/control/domain-registrars' ? '/control/domain-registrars/providers/coza' : '/providers/coza';
    }

    private function providersIndexPath(): string
    {
        return $this->basePath() === '/control/domain-registrars' ? '/control/domain-registrars/providers' : '/providers';
    }

    private function providersNetEarthOnePath(): string
    {
        return $this->basePath() === '/control/domain-registrars' ? '/control/domain-registrars/providers/netearthone' : '/providers/netearthone';
    }

    private function taskEnqueuePath(): string
    {
        return $this->basePath() === '/control/domain-registrars' ? '/control/domain-registrars/tasks/enqueue' : '/tasks/enqueue';
    }

    private function normalizePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '' || $trimmed === '/') {
            return '/';
        }

        $normalized = '/' . ltrim($trimmed, '/');
        $normalized = rtrim($normalized, '/');

        return $normalized === '' ? '/' : $normalized;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function providerDisplayName(string $providerCode): string
    {
        return match ($providerCode) {
            'coza' => '.co.za',
            'netearthone' => 'NetEarthOne',
            default => $providerCode === '' ? '-' : ucfirst($providerCode),
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function findControlDomain(array $payload): ?array
    {
        $domainId = trim((string) ($payload['domain_id'] ?? ''));
        if ($domainId !== '') {
            return $this->app->domainRepository()->findById($domainId);
        }

        $domainName = trim((string) ($payload['domain_name'] ?? ''));
        if ($domainName !== '') {
            return $this->app->domainRepository()->findByName($domainName);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $domain
     * @return array<string, mixed>
     */
    private function decodeDomainMetadata(array $domain): array
    {
        $metadata = $domain['metadata_json'] ?? null;
        if (! is_string($metadata) || trim($metadata) === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $nameserver
     */
    private function nameserverDisplay(array $nameserver): string
    {
        $parts = [trim((string) ($nameserver['hostname'] ?? ''))];

        $ipv4 = trim((string) ($nameserver['ipv4_address'] ?? $nameserver['ipv4'] ?? ''));
        if ($ipv4 !== '') {
            $parts[] = 'IPv4 ' . $ipv4;
        }

        $ipv6 = trim((string) ($nameserver['ipv6_address'] ?? $nameserver['ipv6'] ?? ''));
        if ($ipv6 !== '') {
            $parts[] = 'IPv6 ' . $ipv6;
        }

        return implode(' | ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private function formatDateDisplay(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return 'Not set';
        }

        return str_contains($trimmed, ' ') ? substr($trimmed, 0, 19) : $trimmed;
    }

    private function normalizeDateOnly(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $trimmed, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    private function redirectToDomains(string $message): string
    {
        return $this->safeRedirect($this->domainsPath() . '?flash=' . rawurlencode($message));
    }

    private function safeRedirect(string $url): string
    {
        $escaped = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        if (! headers_sent()) {
            header('Location: ' . $url, true, 302);
            header('Content-Type: text/html; charset=UTF-8', true);
        }
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta http-equiv="refresh" content="0; url=' . $escaped . '"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Redirecting…</title></head><body style="font-family:system-ui,sans-serif;background:#020617;color:#e2e8f0;margin:0;padding:32px;"><p>Redirecting to <a style="color:#60a5fa;" href="' . $escaped . '">' . $escaped . '</a>…</p></body></html>';
    }

    /**
     * @param array<string, mixed> $saved
     * @param array<string, mixed> $effective
     */
    private function displayConfigValue(array $saved, array $effective, string $key): string
    {
        $value = array_key_exists($key, $saved) ? $saved[$key] : ($effective[$key] ?? '');

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<string, mixed> $saved
     * @param array<string, mixed> $effective
     */
    private function maskedConfigValue(array $saved, array $effective, string $key): string
    {
        $value = array_key_exists($key, $saved) ? $saved[$key] : ($effective[$key] ?? '');
        if (! is_scalar($value)) {
            return 'Not configured';
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return 'Not configured';
        }
        $length = strlen($trimmed);
        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return substr($trimmed, 0, 2) . str_repeat('•', max(1, $length - 4)) . substr($trimmed, -2);
    }

    private function maskedSecret(string $secret): string
    {
        $trimmed = trim($secret);
        if ($trimmed === '') {
            return 'Not configured';
        }
        $length = strlen($trimmed);
        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return substr($trimmed, 0, 2) . str_repeat('•', max(1, $length - 4)) . substr($trimmed, -2);
    }

    /**
     * @return list<string>
     */
    private function certFileOptions(): array
    {
        $certRoot = $this->app->projectRootPath() . '/cert';
        if (! is_dir($certRoot)) {
            return [];
        }

        $items = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($certRoot, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $absolutePath = str_replace('\\', '/', $file->getPathname());
            $projectRoot = str_replace('\\', '/', $this->app->projectRootPath()) . '/';
            if (str_starts_with($absolutePath, $projectRoot)) {
                $items[] = substr($absolutePath, strlen($projectRoot));
            }
        }

        sort($items);

        return array_values(array_unique($items));
    }

    /**
     * @return array{label: string, items: list<string>}
     */
    private function cozaReadinessSummary(array $config): array
    {
        $items = [];
        $requiredKeys = [
            'host' => 'Host configured',
            'username' => 'Username configured',
            'password' => 'Password configured',
            'client_id' => 'Client ID configured',
            'cert_path' => 'Certificate path configured',
        ];

        foreach ($requiredKeys as $key => $label) {
            if ($this->nullableString($config[$key] ?? null) !== null) {
                $items[] = $label;
            }
        }

        $status = count($items) >= count($requiredKeys) ? 'Ready for EPP login' : 'Needs more connection details';

        if ($this->nullableString($config['ca_file'] ?? null) !== null) {
            $items[] = 'CA file configured';
        }
        $items[] = ! empty($config['verify_peer']) ? 'TLS peer verification is enabled' : 'TLS peer verification is disabled';

        return [
            'label' => $status,
            'items' => $items,
        ];
    }

    private function renderTokenList(array $items): string
    {
        if ($items === []) {
            return '<p class="muted">No certificate files were found in <code>cert/</code> yet.</p>';
        }

        return implode('', array_map(
            fn (string $item): string => '<span class="token-item">' . $this->escape($item) . '</span>',
            $items,
        ));
    }

    private function normalizeProjectPathInput(mixed $value): ?string
    {
        $path = $this->nullableString($value);
        if ($path === null) {
            return null;
        }

        $normalized = str_replace('\\', '/', $path);
        $projectRoot = str_replace('\\', '/', $this->app->projectRootPath());

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($normalized, '/')) {
            if (str_starts_with($normalized, $projectRoot . '/')) {
                return ltrim(substr($normalized, strlen($projectRoot)), '/');
            }

            return $normalized;
        }

        if (str_starts_with($normalized, $projectRoot . '/')) {
            return ltrim(substr($normalized, strlen($projectRoot)), '/');
        }

        return ltrim($normalized, '/');
    }

    private function resolvedPathPreview(?string $path): ?string
    {
        $value = $this->nullableString($path);
        if ($value === null) {
            return null;
        }

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $value) === 1 || str_starts_with($value, '/')) {
            return $value;
        }

        return str_replace('\\', '/', $this->app->projectRootPath()) . '/' . ltrim(str_replace('\\', '/', $value), '/');
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        return $this->nullableString($value);
    }

    private function nullableConfigString(mixed $value): ?string
    {
        return $this->nullableString($value);
    }

    private function checked(bool $value): string
    {
        return $value ? ' checked' : '';
    }

    private function selected(string $value, string $option): string
    {
        return $value === $option ? ' selected' : '';
    }

    private function normalizeWhitespaceSeparated(mixed $value): ?string
    {
        $raw = $this->nullableString($value);
        if ($raw === null) {
            return null;
        }
        $parts = preg_split('/[\s,;]+/', $raw);
        if (! is_array($parts)) {
            return $raw;
        }
        $cleaned = array_values(array_filter(array_map('trim', $parts), static fn ($v) => $v !== ''));
        if ($cleaned === []) {
            return null;
        }
        return implode(', ', $cleaned);
    }

    private function normalizeNumericString(mixed $value): ?string
    {
        $raw = $this->nullableString($value);
        if ($raw === null) {
            return null;
        }
        $digits = preg_replace('/[^0-9]/', '', $raw);
        if (! is_string($digits) || trim($digits) === '') {
            return null;
        }
        return $digits;
    }

    private function normalizeInvoiceOption(mixed $value): ?string
    {
        $raw = $this->nullableString($value);
        if ($raw === null) {
            return null;
        }
        $allowed = ['NoInvoice', 'PayInvoice', 'KeepInvoice', 'OnlyAdd'];
        if (in_array($raw, $allowed, true)) {
            return $raw;
        }
        return 'NoInvoice';
    }

    /**
     * @return list<string>
     */
    private function detectPublicOutboundIps(): array
    {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        $ips = [];
        $urls = [
            'https://api.ipify.org' => 3,
            'https://checkip.amazonaws.com' => 3,
            'https://ifconfig.me/ip' => 3,
            'https://icanhazip.com' => 3,
            'https://ipinfo.io/ip' => 3,
            'https://ident.me' => 3,
        ];
        foreach ($urls as $url => $timeout) {
            $candidate = $this->fetchExternalIpWithNeoCurlStack($url, $timeout + 2);
            if ($candidate === null) {
                continue;
            }
            $ip = @inet_pton($candidate);
            if ($ip === false || $ip === '') {
                continue;
            }
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                continue;
            }
            $ips[] = $candidate;
        }

        $unique = array_values(array_unique(array_filter($ips, static fn (string $i): bool => $i !== '')));
        $cached = $unique;

        return $unique;
    }

    /**
     * Fetches an external IP check URL using EXACTLY the same cURL options (including
     * CURLOPT_IPRESOLVE_V4) that NetEarthOneApiClient uses for outbound API calls.
     * This ensures the returned IP is what LogicBoxes/NetEarthOne actually sees as the
     * source IP of our request (so we can detect IPv6-vs-IPv4 routing issues).
     */
    private function fetchExternalIpWithNeoCurlStack(string $url, int $timeoutSeconds): ?string
    {
        $curl = curl_init();
        if ($curl === false) {
            return null;
        }

        curl_setopt_array(
            $curl,
            [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => max(2, $timeoutSeconds),
                CURLOPT_CONNECTTIMEOUT => min(3, max(1, $timeoutSeconds)),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'metahumans-registrar/1.0',
                CURLOPT_HTTPHEADER => ['Accept: text/plain'],
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ],
        );

        $body = curl_exec($curl);
        $httpCode = 0;
        if (is_string($body)) {
            $info = curl_getinfo($curl);
            $httpCode = (int) (is_array($info) && isset($info['http_code']) ? $info['http_code'] : 0);
        }
        curl_close($curl);

        if (! is_string($body)) {
            return null;
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return null;
        }

        $trimmed = trim($body);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Detects the server's outbound SOURCE IP as seen by external check services — but
     * FORCES the same cURL stack and IPv4-only resolution used for NetEarthOne API calls.
     * If all NEO-stack checks fail, falls back to detectPublicOutboundIps().
     *
     * @return list<string>
     */
    private function detectApiOutboundIps(): array
    {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        $ips = [];
        $urls = [
            'https://api.ipify.org' => 3,
            'https://checkip.amazonaws.com' => 3,
            'https://ifconfig.me/ip' => 3,
            'https://icanhazip.com' => 3,
        ];
        foreach ($urls as $url => $timeout) {
            $candidate = $this->fetchExternalIpWithNeoCurlStack($url, $timeout);
            if ($candidate === null) {
                continue;
            }
            if (@inet_pton($candidate) === false || @inet_pton($candidate) === '') {
                continue;
            }
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                continue;
            }
            $ips[] = $candidate;
        }

        $unique = array_values(array_unique(array_filter($ips, static fn (string $i): bool => $i !== '')));
        $cached = $unique === [] ? $this->detectPublicOutboundIps() : $unique;

        return $cached;
    }
}
