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
            ['/domains/sync', 'POST'] => $this->handleDomainSync($post),
            ['/domains/renew', 'POST'] => $this->handleDomainRenew($post),
            ['/domains/assign', 'GET'] => $this->renderDomainAssignPage($query),
            ['/domains/assign', 'POST'] => $this->handleDomainAssign($post),
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
        $domains = $this->app->domainRepository()->listRecent(200);
        $flashMarkup = $flash === '' ? '' : '<div class="notice">' . $this->escape($flash) . '</div>';
        $importForm = $this->renderDomainImportForm();
        $domainCards = $domains === []
            ? '<p class="muted">No records yet.</p>'
            : implode('', array_map(fn (array $domain): string => $this->renderDomainManagementCard($domain), $domains));
        $rows = $this->renderRawTable(
            ['Provider', 'Domain', 'Status', 'Owner Type', 'Owner ID', 'Tenant', 'Registered', 'Expires', 'Updated', 'Actions'],
            array_map(
                fn (array $domain): array => [
                    $this->providerDisplayName((string) ($domain['provider_code'] ?? '')),
                    (string) $domain['domain_name'],
                    (string) $domain['registrar_status'],
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
            [9],
        );

        return $this->layout(
            'Domains',
            $flashMarkup
            . '<section class="panel"><div class="panel-head"><div><h1>Domains</h1><p class="muted">Bulk import registry domains into the local portfolio and allocate them to users or companies. This page shows locally imported records, not an automatic registry-wide portfolio feed.</p></div><a href="'
            . $this->escape($this->basePath())
            . '">Back to dashboard</a></div>'
            . $importForm
            . $rows
                . '<div class="action-grid" style="margin-top:20px;">'
                . $domainCards
                . '</div>'
            . '</section>'
        );
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
      <p class="muted">Each provider stores non-sensitive runtime settings in this control. Sensitive credentials live in mounted Northflank secret sets.</p>
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
        $saved = $this->app->providerStoredConfig('netearthone');
        $effective = $this->app->providerEffectiveConfig('netearthone');
        $secretRef = trim((string) ($providerAccount['credentials_secret_ref'] ?? ''));
        $environment = trim((string) ($providerAccount['environment'] ?? 'production'));
        $isActive = (bool) ($providerAccount['is_active'] ?? true);

        $apiBaseUrl = (string) $this->displayConfigValue($saved, $effective, 'api_base_url');
        $authUserId = (string) $this->displayConfigValue($saved, $effective, 'auth_user_id');
        $apiKeyMasked = $this->maskedConfigValue($saved, $effective, 'api_key');
        $timeout = (string) ($this->displayConfigValue($saved, $effective, 'timeout') ?: '30');
        $pricingJson = (string) $this->displayConfigValue($saved, $effective, 'pricing_json');
        $defaultCustomerId = (string) $this->displayConfigValue($saved, $effective, 'default_customer_id');
        $defaultInvoiceOption = (string) $this->displayConfigValue($saved, $effective, 'default_invoice_option');
        if ($defaultInvoiceOption === '') {
            $defaultInvoiceOption = 'NoInvoice';
        }

        $neoSecretSet = $secretRef === '' ? 'metahumans-netearthone-provider' : $secretRef;
        $neoSecretKeys = [
            'NETEARTHONE_API_BASE_URL',
            'NETEARTHONE_AUTH_USER_ID',
            'NETEARTHONE_API_KEY',
            'NETEARTHONE_TIMEOUT',
            'NETEARTHONE_PRICING_JSON',
            'NETEARTHONE_DEFAULT_CUSTOMER_ID',
            'NETEARTHONE_DEFAULT_INVOICE_OPTION',
        ];
        $neoSecretKeyMarkup = $this->renderTokenList($neoSecretKeys);
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
      <p class="muted">Non-sensitive runtime settings only. Store API keys and credentials in the Northflank secret set reference below.</p>
    </div>
    <a href="{$this->escape($this->basePath())}">Back to dashboard</a>
  </div>
  <div class="settings-grid">
    <article class="info-card">
      <h2>Current Status</h2>
      <p class="status-pill">{$this->escape($isActive ? 'Provider Account Active' : 'Provider Account Inactive')}</p>
      <p class="muted">Provider account: {$this->escape((string) ($providerAccount['display_name'] ?? 'NetEarthOne'))}</p>
      <p class="muted">Environment: {$this->escape($environment)}</p>
      <p class="muted">Provider secret set: <code>{$this->escape($neoSecretSet)}</code></p>
      <p class="muted">API base URL: <code>{$this->escape($apiBaseUrl !== '' ? $apiBaseUrl : 'Not configured')}</code></p>
      <p class="muted">Auth user ID: <code>{$this->escape($authUserId !== '' ? $authUserId : 'Not configured')}</code></p>
      <p class="muted">API key: <code>{$this->escape($apiKeyMasked)}</code></p>
      <p class="muted">Default customer ID: <code>{$this->escape($defaultCustomerId !== '' ? $defaultCustomerId : 'Not configured')}</code></p>
      <p class="muted">Default invoice option: <code>{$this->escape($defaultInvoiceOption)}</code></p>
      <p><a href="{$this->escape($this->providersNetEarthOnePath())}?probe=health">Run live API health probe</a></p>
    </article>
    <article class="info-card">
      <h2>Northflank Secret Set</h2>
      <p class="muted">Mount this secret set in the control + worker services. The non-sensitive settings below are merged with the secret values at runtime.</p>
      <p class="muted"><strong>Secret set:</strong> <code>{$this->escape($neoSecretSet)}</code></p>
      <div class="token-list">{$neoSecretKeyMarkup}</div>
    </article>
  </div>
  <form method="post" action="{$this->escape($this->providersNetEarthOnePath())}" class="settings-form">
    <div class="form-grid">
      <label>
        <span>Timeout (seconds)</span>
        <input type="number" name="timeout" min="5" max="300" value="{$this->escape($timeout)}" required>
      </label>
      <label>
        <span>API Base URL (non-sensitive)</span>
        <input type="text" name="api_base_url" value="{$this->escape($apiBaseUrl)}" placeholder="https://api.netearthone.com/anacreon/servlet/ApiCall.xml">
      </label>
      <label>
        <span>Auth User ID (optional non-sensitive override)</span>
        <input type="text" name="auth_user_id" value="{$this->escape($authUserId)}" placeholder="Reseller ID / User ID">
      </label>
      <label>
        <span>Pricing JSON Path</span>
        <input type="text" name="pricing_json" value="{$this->escape($pricingJson)}" placeholder="config/pricing/netearthone.custom.json">
      </label>
      <label>
        <span>Default Customer ID</span>
        <input type="text" name="default_customer_id" value="{$this->escape($defaultCustomerId)}" placeholder="Required for registrations without an explicit customer_id">
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
      <label>
        <span>Provider Secret Set Ref</span>
        <input type="text" name="credentials_secret_ref" value="{$this->escape($neoSecretSet)}" placeholder="metahumans-netearthone-provider">
      </label>
      <label class="checkbox-line">
        <input type="checkbox" name="is_active" value="1"{$this->checked($isActive)}>
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

        return $this->layout('NetEarthOne Settings', $body);
    }

    private function handleNetEarthOneSettingsSave(array $post): string
    {
        $providerAccount = $this->app->providerAccount('netearthone');

        $config = [
            'api_base_url' => $this->nullableString($post['api_base_url'] ?? null),
            'auth_user_id' => $this->nullableString($post['auth_user_id'] ?? null),
            'timeout' => max(5, min(300, (int) ($post['timeout'] ?? 30))),
            'pricing_json' => $this->normalizeProjectPathInput($post['pricing_json'] ?? null),
            'default_customer_id' => $this->nullableString($post['default_customer_id'] ?? null),
            'default_invoice_option' => $this->nullableString($post['default_invoice_option'] ?? null),
        ];

        $this->app->providerAccountRepository()->updateSettings(
            (string) $providerAccount['id'],
            [
                'is_active' => isset($post['is_active']) && (string) $post['is_active'] === '1',
                'environment' => $post['environment'] ?? 'production',
                'credentials_secret_ref' => $post['credentials_secret_ref'] ?? null,
            ],
            $config,
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
        $secretRef = trim((string) ($providerAccount['credentials_secret_ref'] ?? ''));
        $environment = trim((string) ($providerAccount['environment'] ?? 'production'));
        $isActive = (bool) ($providerAccount['is_active'] ?? true);
        $verifyPeer = array_key_exists('verify_peer', $saved)
            ? (bool) $saved['verify_peer']
            : (bool) ($effective['verify_peer'] ?? true);
        $resolvedCertPath = $this->resolvedPathPreview($certPath);
        $resolvedCaPath = $this->resolvedPathPreview($caFile);
        $readiness = $this->cozaReadinessSummary($effective);
        $cozaSecretSet = $secretRef === '' ? 'metahumans-coza-provider' : $secretRef;
        $certificateSecretSet = 'metahumans-coza-certificates';
        $cozaSecretKeys = [
            'COZA_HOST',
            'COZA_PORT',
            'COZA_USERNAME',
            'COZA_PASSWORD',
            'COZA_CLIENT_ID',
            'COZA_LOGIN_OBJECT_URIS',
            'COZA_LOGIN_EXTENSION_URIS',
        ];
        $netearthoneSecretSet = 'metahumans-netearthone-provider';
        $netearthoneSecretKeys = [
            'NETEARTHONE_API_BASE_URL',
            'NETEARTHONE_AUTH_USER_ID',
            'NETEARTHONE_API_KEY',
            'NETEARTHONE_TIMEOUT',
            'NETEARTHONE_PRICING_JSON',
            'NETEARTHONE_DEFAULT_CUSTOMER_ID',
            'NETEARTHONE_DEFAULT_INVOICE_OPTION',
        ];
        $cozaSecretKeyMarkup = $this->renderTokenList($cozaSecretKeys);
        $netearthoneSecretKeyMarkup = $this->renderTokenList($netearthoneSecretKeys);
        $certificateFileMarkup = $this->renderTokenList($certOptions);

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
            $probeMarkup = '<article class="info-card"><h2>Live EPP Hello Probe</h2>' . $runtimeDiagnosticsMarkup . '<p class="muted">The diagnostics above are the exact non-sensitive values currently being merged into the .co.za provider in this web runtime.</p></article>';

            try {
                $provider = $this->app->provider('coza');
                if (method_exists($provider, 'healthCheck')) {
                    $probeResult = $provider->healthCheck();
                    $probeMarkup = '<article class="info-card"><h2>Live EPP Hello Probe</h2><pre class="code-block">' . $this->escape(
                        json_encode($probeResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
                    ) . '</pre><p class="muted">The diagnostics below show the exact non-sensitive values currently being merged into the .co.za provider in this web runtime.</p>' . $runtimeDiagnosticsMarkup . '</article>';
                }
            } catch (Throwable $exception) {
                $probeMarkup = '<article class="info-card"><h2>Live EPP Hello Probe</h2><pre class="code-block">' . $this->escape($exception->getMessage()) . '</pre><p class="muted">The diagnostics below show the exact non-sensitive values currently being merged into the .co.za provider in this web runtime.</p>' . $runtimeDiagnosticsMarkup . '</article>';
            }
        }

        $body = <<<HTML
{$flashMarkup}
<section class="panel">
  <div class="panel-head">
    <div>
      <p class="eyebrow">Provider Settings</p>
      <h1>.co.za EPP connection</h1>
      <p class="muted">Complete provider credentials in Northflank secret sets under <code>metahumans</code>. This screen stores only non-sensitive runtime settings and secret references.</p>
    </div>
    <a href="{$this->escape($this->basePath())}">Back to dashboard</a>
  </div>
  <div class="settings-grid">
    <article class="info-card">
      <h2>Current Status</h2>
      <p class="status-pill">{$this->escape($readiness['label'])}</p>
      <ul class="info-list">{$readinessItems}</ul>
      <p class="muted">Provider account: {$this->escape((string) ($providerAccount['display_name'] ?? '.co.za'))}</p>
      <p class="muted">Environment: {$this->escape($environment)}</p>
      <p class="muted">Provider secret set: <code>{$this->escape($cozaSecretSet)}</code></p>
      <p class="muted">Certificate secret set: <code>{$this->escape($certificateSecretSet)}</code></p>
      <p class="muted">Repo cert folder: <code>cert/</code></p>
      <p class="muted">Resolved cert path: <code>{$this->escape($resolvedCertPath ?? 'Not set')}</code></p>
      <p class="muted">Resolved CA file: <code>{$this->escape($resolvedCaPath ?? 'Not set')}</code></p>
      <p><a href="{$this->escape($this->providersCozaPath())}?probe=hello">Run live EPP hello probe</a></p>
    </article>
    <article class="info-card">
      <h2>Northflank Secret Sets</h2>
      <p class="muted">Create these secret sets in the <code>metahumans</code> project and mount them on control and worker.</p>
      <p class="muted"><strong>.co.za provider:</strong> <code>{$this->escape($cozaSecretSet)}</code></p>
      <div class="token-list">{$cozaSecretKeyMarkup}</div>
      <p class="muted" style="margin-top: 14px;"><strong>.co.za certificates:</strong> <code>{$this->escape($certificateSecretSet)}</code></p>
      <div class="token-list">{$certificateFileMarkup}</div>
      <p class="muted" style="margin-top: 14px;"><strong>NetEarthOne provider:</strong> <code>{$this->escape($netearthoneSecretSet)}</code></p>
      <div class="token-list">{$netearthoneSecretKeyMarkup}</div>
    </article>
  </div>
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
      <label>
        <span>Provider Secret Set Ref</span>
        <input type="text" name="credentials_secret_ref" value="{$this->escape($cozaSecretSet)}" placeholder="metahumans-coza-provider">
      </label>
      <label class="checkbox-line">
        <input type="checkbox" name="verify_peer" value="1"{$this->checked($verifyPeer)}>
        <span>Verify TLS peer certificate</span>
      </label>
      <label class="checkbox-line">
        <input type="checkbox" name="is_active" value="1"{$this->checked($isActive)}>
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
                'credentials_secret_ref' => $post['credentials_secret_ref'] ?? null,
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
