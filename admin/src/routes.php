<?php

declare(strict_types=1);

/**
 * Admin panel routes
 */

use App\Admin\AdminService;
use App\Admin\AuditLog;
use App\Domain\Domain\DomainRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Twig\Environment;

/** @var App $app */

// Helper
function adminRender(Response $response, Environment $twig, string $template, array $data = []): Response
{
    $data['csrf_token'] = $_SESSION['csrf_token'] ?? '';
    $data['admin_user'] = $_SESSION['admin_user'] ?? null;
    
    $html = $twig->render('admin/' . $template, $data);
    $response->getBody()->write($html);
    return $response;
}

function requireAdminAuth(Request $request): bool
{
    return isset($_SESSION['admin_user']);
}

// =============================================================================
// LOGIN
// =============================================================================

$app->get('/', function (Request $request, Response $response) {
    if (isset($_SESSION['admin_user'])) {
        return $response->withHeader('Location', '/dashboard')->withStatus(302);
    }
    
    /** @var Environment $twig */
    $twig = $this->get(Environment::class);
    
    return adminRender($response, $twig, 'login.html.twig', [
        'page_title' => 'Admin Login',
    ]);
});

$app->post('/login', function (Request $request, Response $response) {
    /** @var Environment $twig */
    $twig = $this->get(Environment::class);
    /** @var AdminService $adminService */
    $adminService = $this->get(AdminService::class);
    /** @var AuditLog $audit */
    $audit = $this->get(AuditLog::class);
    
    $body = $request->getParsedBody();
    $username = $body['username'] ?? '';
    $password = $body['password'] ?? '';
    $totpCode = $body['totp_code'] ?? '';
    
    $admin = $adminService->authenticate($username, $password, $totpCode);
    
    if ($admin === null) {
        return adminRender($response, $twig, 'login.html.twig', [
            'page_title' => 'Admin Login',
            'error' => 'Invalid credentials or TOTP code',
        ]);
    }
    
    $_SESSION['admin_user'] = $admin;
    $audit->log($username, 'login', null);
    
    return $response->withHeader('Location', '/dashboard')->withStatus(302);
});

$app->get('/logout', function (Request $request, Response $response) {
    /** @var AuditLog $audit */
    $audit = $this->get(AuditLog::class);
    
    if (isset($_SESSION['admin_user'])) {
        $audit->log($_SESSION['admin_user']['username'], 'logout', null);
    }
    
    unset($_SESSION['admin_user']);
    session_destroy();
    
    return $response->withHeader('Location', '/')->withStatus(302);
});

// =============================================================================
// DASHBOARD
// =============================================================================

$app->get('/dashboard', function (Request $request, Response $response) {
    if (!requireAdminAuth($request)) {
        return $response->withHeader('Location', '/')->withStatus(302);
    }
    
    /** @var Environment $twig */
    $twig = $this->get(Environment::class);
    /** @var AdminService $adminService */
    $adminService = $this->get(AdminService::class);
    
    $stats = $adminService->getDashboardStats();
    
    return adminRender($response, $twig, 'dashboard.html.twig', [
        'page_title' => 'Dashboard',
        'stats' => $stats,
    ]);
});

// =============================================================================
// DOMAINS
// =============================================================================

$app->get('/domains', function (Request $request, Response $response) {
    if (!requireAdminAuth($request)) {
        return $response->withHeader('Location', '/')->withStatus(302);
    }
    
    /** @var Environment $twig */
    $twig = $this->get(Environment::class);
    /** @var AdminService $adminService */
    $adminService = $this->get(AdminService::class);
    
    $domains = $adminService->getAllDomains();
    
    return adminRender($response, $twig, 'domains.html.twig', [
        'page_title' => 'Domains',
        'domains' => $domains,
    ]);
});

$app->post('/domains/add', function (Request $request, Response $response) {
    if (!requireAdminAuth($request)) {
        return $response->withHeader('Location', '/')->withStatus(302);
    }
    
    /** @var AdminService $adminService */
    $adminService = $this->get(AdminService::class);
    /** @var AuditLog $audit */
    $audit = $this->get(AuditLog::class);
    
    $body = $request->getParsedBody();
    $name = $body['name'] ?? '';
    
    if ($adminService->addDomain($name)) {
        $audit->log($_SESSION['admin_user']['username'], 'domain_add', $name);
    }
    
    return $response->withHeader('Location', '/domains')->withStatus(302);
});

// =============================================================================
// USERS
// =============================================================================

$app->get('/users', function (Request $request, Response $response) {
    if (!requireAdminAuth($request)) {
        return $response->withHeader('Location', '/')->withStatus(302);
    }
    
    /** @var Environment $twig */
    $twig = $this->get(Environment::class);
    /** @var AdminService $adminService */
    $adminService = $this->get(AdminService::class);
    
    $params = $request->getQueryParams();
    $search = $params['q'] ?? '';
    $page = (int) ($params['page'] ?? 1);
    
    $result = $adminService->searchUsers($search, $page);
    
    return adminRender($response, $twig, 'users.html.twig', [
        'page_title' => 'Users',
        'users' => $result['users'],
        'total' => $result['total'],
        'page' => $page,
        'search' => $search,
    ]);
});

$app->post('/users/freeze', function (Request $request, Response $response) {
    if (!requireAdminAuth($request)) {
        return $response->withHeader('Location', '/')->withStatus(302);
    }
    
    /** @var AdminService $adminService */
    $adminService = $this->get(AdminService::class);
    /** @var AuditLog $audit */
    $audit = $this->get(AuditLog::class);
    
    $body = $request->getParsedBody();
    $userId = (int) ($body['user_id'] ?? 0);
    $freeze = ($body['action'] ?? '') === 'freeze';
    
    $user = $adminService->getUserById($userId);
    if ($user && $adminService->setUserFrozen($userId, $freeze)) {
        $action = $freeze ? 'user_freeze' : 'user_unfreeze';
        $audit->log($_SESSION['admin_user']['username'], $action, $user['email']);
    }
    
    return $response->withHeader('Location', '/users')->withStatus(302);
});

$app->post('/users/unblock-smtp', function (Request $request, Response $response) {
    if (!requireAdminAuth($request)) {
        return $response->withHeader('Location', '/')->withStatus(302);
    }
    
    /** @var AdminService $adminService */
    $adminService = $this->get(AdminService::class);
    /** @var AuditLog $audit */
    $audit = $this->get(AuditLog::class);
    
    $body = $request->getParsedBody();
    $userId = (int) ($body['user_id'] ?? 0);
    
    $user = $adminService->getUserById($userId);
    if ($user && $adminService->unblockSmtp($userId)) {
        $audit->log($_SESSION['admin_user']['username'], 'user_unblock_smtp', $user['email']);
    }
    
    return $response->withHeader('Location', '/users')->withStatus(302);
});

// =============================================================================
// ANNOUNCEMENTS
// =============================================================================

$app->get('/announcements', function (Request $request, Response $response) {
    if (!requireAdminAuth($request)) {
        return $response->withHeader('Location', '/')->withStatus(302);
    }
    
    /** @var Environment $twig */
    $twig = $this->get(Environment::class);
    /** @var AdminService $adminService */
    $adminService = $this->get(AdminService::class);
    
    return adminRender($response, $twig, 'announcements.html.twig', [
        'page_title' => 'Announcements',
        'announcements' => $adminService->getAnnouncements(),
    ]);
});

$app->post('/announcements/save', function (Request $request, Response $response) {
    if (!requireAdminAuth($request)) {
        return $response->withHeader('Location', '/')->withStatus(302);
    }
    
    /** @var AdminService $adminService */
    $adminService = $this->get(AdminService::class);
    /** @var AuditLog $audit */
    $audit = $this->get(AuditLog::class);
    
    $body = $request->getParsedBody();
    $adminService->saveAnnouncement($body['body'] ?? '', (bool) ($body['active'] ?? false));
    $audit->log($_SESSION['admin_user']['username'], 'announcement_update', null);
    
    return $response->withHeader('Location', '/announcements')->withStatus(302);
});

// =============================================================================
// CONTENT BLOCKS
// =============================================================================

$app->get('/content', function (Request $request, Response $response) {
    if (!requireAdminAuth($request)) {
        return $response->withHeader('Location', '/')->withStatus(302);
    }
    
    /** @var Environment $twig */
    $twig = $this->get(Environment::class);
    /** @var AdminService $adminService */
    $adminService = $this->get(AdminService::class);
    
    return adminRender($response, $twig, 'content.html.twig', [
        'page_title' => 'Content',
        'blocks' => $adminService->getContentBlocks(),
    ]);
});

$app->get('/content/{key}', function (Request $request, Response $response, array $args) {
    if (!requireAdminAuth($request)) {
        return $response->withHeader('Location', '/')->withStatus(302);
    }
    
    /** @var Environment $twig */
    $twig = $this->get(Environment::class);
    /** @var AdminService $adminService */
    $adminService = $this->get(AdminService::class);
    
    $block = $adminService->getContentBlock($args['key']);
    
    return adminRender($response, $twig, 'content-edit.html.twig', [
        'page_title' => 'Edit: ' . $args['key'],
        'block' => $block,
    ]);
});

$app->post('/content/{key}', function (Request $request, Response $response, array $args) {
    if (!requireAdminAuth($request)) {
        return $response->withHeader('Location', '/')->withStatus(302);
    }
    
    /** @var AdminService $adminService */
    $adminService = $this->get(AdminService::class);
    /** @var AuditLog $audit */
    $audit = $this->get(AuditLog::class);
    
    $body = $request->getParsedBody();
    $adminService->updateContentBlock($args['key'], $body['body_md'] ?? '');
    $audit->log($_SESSION['admin_user']['username'], 'content_update', $args['key']);
    
    return $response->withHeader('Location', '/content')->withStatus(302);
});

// =============================================================================
// AUDIT LOG
// =============================================================================

$app->get('/audit', function (Request $request, Response $response) {
    if (!requireAdminAuth($request)) {
        return $response->withHeader('Location', '/')->withStatus(302);
    }
    
    /** @var Environment $twig */
    $twig = $this->get(Environment::class);
    /** @var AuditLog $audit */
    $audit = $this->get(AuditLog::class);
    
    $params = $request->getQueryParams();
    $page = (int) ($params['page'] ?? 1);
    
    return adminRender($response, $twig, 'audit.html.twig', [
        'page_title' => 'Audit Log',
        'logs' => $audit->getRecent($page),
    ]);
});
