<?php

declare(strict_types=1);

namespace App\Presentation\Control;

use App\Application;
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

        return match ([$path, strtoupper($method)]) {
            ['/', 'GET'] => $this->renderDashboard($query),
            ['/orders', 'GET'] => $this->renderOrders(),
            ['/domains', 'GET'] => $this->renderDomains(),
            ['/tasks', 'GET'] => $this->renderTasks(),
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
  </div>
  <div class="action-grid">
    {$this->taskForm('sync_pricing', 'pricing', 'Queue pricing sync')}
    {$this->taskForm('sync_domain_dates', 'dates', 'Queue date sync')}
    {$this->taskForm('sync_domain_portfolio', 'sync', 'Queue portfolio sync')}
    {$this->taskForm('retry_failed_sync_runs', 'retries', 'Retry failed tasks')}
  </div>
</section>

<section class="panel">
  <div class="panel-head"><h2>Recent Orders</h2><a href="/orders">View all</a></div>
  {$ordersMarkup}
</section>

<section class="panel">
  <div class="panel-head"><h2>Recent Domains</h2><a href="/domains">View all</a></div>
  {$domainsMarkup}
</section>

<section class="panel">
  <div class="panel-head"><h2>Recent Tasks</h2><a href="/tasks">View all</a></div>
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

        return $this->layout('Orders', '<section class="panel"><div class="panel-head"><h1>Orders</h1><a href="/">Back to dashboard</a></div>' . $rows . '</section>');
    }

    private function renderDomains(): string
    {
        $rows = $this->renderTable(
            ['Provider', 'Domain', 'Status', 'Registered', 'Expires', 'Updated'],
            array_map(
                fn (array $domain): array => [
                    $this->providerDisplayName((string) ($domain['provider_code'] ?? '')),
                    (string) $domain['domain_name'],
                    (string) $domain['registrar_status'],
                    (string) ($domain['registered_at'] ?? '-'),
                    (string) ($domain['expires_at'] ?? '-'),
                    (string) $domain['updated_at'],
                ],
                $this->app->domainRepository()->listRecent(50),
            ),
        );

        return $this->layout('Domains', '<section class="panel"><div class="panel-head"><h1>Domains</h1><a href="/">Back to dashboard</a></div>' . $rows . '</section>');
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

        return $this->layout('Tasks', '<section class="panel"><div class="panel-head"><h1>Tasks</h1><a href="/">Back to dashboard</a></div>' . $rows . '</section>');
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

            header('Location: /?flash=' . rawurlencode(sprintf('%s queued for %s.', $taskType, $providerCode)));
            return '';
        } catch (Throwable $exception) {
            header('Location: /?flash=' . rawurlencode($exception->getMessage()));
            return '';
        }
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

    private function taskForm(string $taskType, string $queueName, string $label): string
    {
        return <<<HTML
<form method="post" action="/tasks/enqueue" class="action-card">
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

    private function renderNotFound(): string
    {
        http_response_code(404);

        return $this->layout('Not Found', '<section class="panel"><h1>Page Not Found</h1><p class="muted">This admin route does not exist.</p><a href="/">Back to dashboard</a></section>');
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
      <a href="/">Dashboard</a>
      <a href="/orders">Orders</a>
      <a href="/domains">Domains</a>
      <a href="/tasks">Tasks</a>
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
}
