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
        [$domains, $total, $page, $totalPages] = $this->app->domainRepository()->search($filters, $page, $perPage);
        $flashMarkup = $flash === '' ? '' : '<div class="notice">' . $this->escape($flash) . '</div>';
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
        $effective = $this->app->providerEffectiveConfig('netearthone');
        $environment = trim((string) ($providerAccount['environment'] ?? 'production'));
        $isActiveDb = (bool) ($providerAccount['is_active'] ?? true);

        $apiBaseUrl = (string) ($effective['api_base_url'] ?? '');
        $resellerId = (string) ($effective['auth_user_id'] ?? '');
        $ipAddress = (string) ($effective['ip_address'] ?? '');
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
        if (($query['probe'] ?? null) === 'health') {
            try {
                $provider = $this->app->provider('netearthone');
                $probeResult = $provider->healthCheck();
                $probeMarkup = '<article class="info-card"><h2>Live API Health Probe</h2><pre class="code-block">' . $this->escape(
                    json_encode($probeResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
                ) . '</pre></article>';
            } catch (Throwable $exception) {
                $probeMarkup = '<article class="info-card"><h2>Live API Health Probe</h2><pre class="code-block">' . $this->escape($exception->getMessage()) . '</pre></article>';
            }
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
HTML;

        return $this->layout('NetEarthOne Settings', $body);
    }

    private function handleNetEarthOneSettingsSave(array $post): string
    {
        $providerAccount = $this->app->providerAccount('netearthone');

        $fields = [
            'is_active' => isset($post['is_active']) && (string) $post['is_active'] === '1',
            'environment' => $post['environment'] ?? 'production',
        ];

        $this->app->providerAccountRepository()->updateSettings(
            (string) $providerAccount['id'],
            $fields,
            [],
        );

        header('Location: ' . $this->providersNetEarthOnePath() . '?flash=' . rawurlencode('NetEarthOne settings saved.'));

        return '';
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

        header('Location: ' . $this->providersCozaPath() . '?flash=' . rawurlencode('.co.za settings saved.'));

        return '';
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

            header('Location: ' . $this->basePath() . '?flash=' . rawurlencode(sprintf('%s queued for %s.', $taskType, $providerCode)));
            return '';
        } catch (Throwable $exception) {
            header('Location: ' . $this->basePath() . '?flash=' . rawurlencode($exception->getMessage()));
            return '';
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
        header('Location: ' . $this->domainsPath() . '?flash=' . rawurlencode($message));

        return '';
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

    private function checked(bool $value): string
    {
        return $value ? ' checked' : '';
    }

    private function selected(string $value, string $option): string
    {
        return $value === $option ? ' selected' : '';
    }
}
