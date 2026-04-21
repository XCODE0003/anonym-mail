<?php

declare(strict_types=1);

/**
 * Webmail routes
 */

use App\Domain\User\UserService;
use App\Webmail\HtmlSanitizer;
use App\Webmail\ImapClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Twig\Environment;

/** @var App $app */

function webmailPublicBaseUrl(): string
{
    $base = trim((string) ($_ENV['APP_URL'] ?? ''));
    if ($base !== '') {
        return rtrim($base, '/');
    }

    $host = trim((string) ($_ENV['PRIMARY_DOMAIN'] ?? ''), '/');
    if ($host === '') {
        return '';
    }

    return 'https://' . $host;
}

// Helper function
function webmailRender(Response $response, Environment $twig, string $template, array $data = []): Response
{
    $data['csrf_token'] = $_SESSION['csrf_token'] ?? '';
    $data['user'] = $_SESSION['webmail_user'] ?? null;
    
    $html = $twig->render('webmail/' . $template, $data);
    $response->getBody()->write($html);
    return $response;
}

// Auth middleware
function requireAuth(Request $request): bool
{
    return isset($_SESSION['webmail_user']);
}

// =============================================================================
// LOGIN
// =============================================================================

$app->get('/', function (Request $request, Response $response) {
    if (isset($_SESSION['webmail_user'])) {
        return $response->withHeader('Location', '/webmail/inbox')->withStatus(302);
    }
    
    /** @var Environment $twig */
    $twig = $this->get(Environment::class);
    
    return webmailRender($response, $twig, 'login.html.twig', [
        'page_title' => 'Login',
    ]);
});

$app->post('/login', function (Request $request, Response $response) {
    /** @var Environment $twig */
    $twig = $this->get(Environment::class);
    /** @var ImapClient $imap */
    $imap = $this->get(ImapClient::class);
    
    $body = $request->getParsedBody();
    $email = $body['email'] ?? '';
    $password = $body['password'] ?? '';
    
    // Try IMAP login
    if ($imap->login($email, $password)) {
        $_SESSION['webmail_user'] = [
            'email' => $email,
            'password' => $password, // Needed for IMAP operations
        ];
        $imap->logout();
        
        return $response->withHeader('Location', '/webmail/inbox')->withStatus(302);
    }
    
    return webmailRender($response, $twig, 'login.html.twig', [
        'page_title' => 'Login',
        'error' => 'Invalid email or password',
    ]);
});

$app->get('/logout', function (Request $request, Response $response) {
    unset($_SESSION['webmail_user']);
    session_destroy();
    
    return $response->withHeader('Location', '/webmail/')->withStatus(302);
});

// =============================================================================
// INBOX / FOLDERS
// =============================================================================

$app->get('/inbox', function (Request $request, Response $response) {
    if (!requireAuth($request)) {
        return $response->withHeader('Location', '/webmail/')->withStatus(302);
    }
    
    return $response->withHeader('Location', '/webmail/folder/INBOX')->withStatus(302);
});

$app->get('/folder/{folder}', function (Request $request, Response $response, array $args) {
    if (!requireAuth($request)) {
        return $response->withHeader('Location', '/webmail/')->withStatus(302);
    }
    
    /** @var Environment $twig */
    $twig = $this->get(Environment::class);
    /** @var ImapClient $imap */
    $imap = $this->get(ImapClient::class);
    
    $user = $_SESSION['webmail_user'];
    $folder = urldecode($args['folder']);
    $page = (int) ($request->getQueryParams()['page'] ?? 1);
    $perPage = 25;
    
    if (!$imap->login($user['email'], $user['password'])) {
        unset($_SESSION['webmail_user']);
        return $response->withHeader('Location', '/webmail/')->withStatus(302);
    }
    
    $folders = $imap->getFolders();
    $messages = $imap->getMessages($folder, $page, $perPage);
    $totalMessages = $imap->getMessageCount($folder);
    $totalPages = (int) ceil($totalMessages / $perPage);
    
    $imap->logout();
    
    return webmailRender($response, $twig, 'folder.html.twig', [
        'page_title' => $folder,
        'folders' => $folders,
        'current_folder' => $folder,
        'messages' => $messages,
        'page' => $page,
        'total_pages' => $totalPages,
        'total_messages' => $totalMessages,
    ]);
});

// =============================================================================
// READ MESSAGE
// =============================================================================

$app->get('/message/{folder}/{uid}', function (Request $request, Response $response, array $args) {
    if (!requireAuth($request)) {
        return $response->withHeader('Location', '/webmail/')->withStatus(302);
    }
    
    /** @var Environment $twig */
    $twig = $this->get(Environment::class);
    /** @var ImapClient $imap */
    $imap = $this->get(ImapClient::class);
    /** @var HtmlSanitizer $sanitizer */
    $sanitizer = $this->get(HtmlSanitizer::class);
    
    $user = $_SESSION['webmail_user'];
    $folder = urldecode($args['folder']);
    $uid = (int) $args['uid'];
    
    if (!$imap->login($user['email'], $user['password'])) {
        return $response->withHeader('Location', '/webmail/')->withStatus(302);
    }
    
    $folders = $imap->getFolders();
    $message = $imap->getMessage($folder, $uid);
    
    if ($message === null) {
        $imap->logout();
        return $response->withHeader('Location', '/webmail/folder/' . urlencode($folder))->withStatus(302);
    }
    
    // Mark as read
    $imap->markAsRead($folder, $uid);
    
    // Sanitize HTML content
    if ($message['html'] !== null) {
        $message['html'] = $sanitizer->sanitize($message['html']);
    }
    
    $imap->logout();
    
    return webmailRender($response, $twig, 'message.html.twig', [
        'page_title' => $message['subject'] ?? '(No Subject)',
        'folders' => $folders,
        'current_folder' => $folder,
        'message' => $message,
    ]);
});

// =============================================================================
// COMPOSE
// =============================================================================

$app->get('/compose', function (Request $request, Response $response) {
    if (!requireAuth($request)) {
        return $response->withHeader('Location', '/webmail/')->withStatus(302);
    }
    
    /** @var Environment $twig */
    $twig = $this->get(Environment::class);
    /** @var ImapClient $imap */
    $imap = $this->get(ImapClient::class);
    /** @var UserService $userService */
    $userService = $this->get(UserService::class);
    
    $user = $_SESSION['webmail_user'];
    $params = $request->getQueryParams();
    
    if (!$imap->login($user['email'], $user['password'])) {
        return $response->withHeader('Location', '/webmail/')->withStatus(302);
    }
    
    $folders = $imap->getFolders();
    $imap->logout();

    $smtpBlocked = $userService->isSmtpBlocked($user['email']);
    
    return webmailRender($response, $twig, 'compose.html.twig', [
        'page_title' => 'Compose',
        'folders' => $folders,
        'to' => $params['to'] ?? '',
        'subject' => $params['subject'] ?? '',
        'body' => $params['body'] ?? '',
        'smtp_blocked' => $smtpBlocked,
        'smtp_help_url' => webmailPublicBaseUrl() !== '' ? webmailPublicBaseUrl() . '/unblock' : '',
    ]);
});

$app->post('/compose', function (Request $request, Response $response) {
    if (!requireAuth($request)) {
        return $response->withHeader('Location', '/webmail/')->withStatus(302);
    }
    
    /** @var Environment $twig */
    $twig = $this->get(Environment::class);
    /** @var ImapClient $imap */
    $imap = $this->get(ImapClient::class);
    /** @var UserService $userService */
    $userService = $this->get(UserService::class);
    
    $user = $_SESSION['webmail_user'];
    $body = $request->getParsedBody();
    
    $to = $body['to'] ?? '';
    $subject = $body['subject'] ?? '';
    $messageBody = $body['body'] ?? '';
    
    if (!$imap->login($user['email'], $user['password'])) {
        return $response->withHeader('Location', '/webmail/')->withStatus(302);
    }

    $folders = $imap->getFolders();
    $smtpBlocked = $userService->isSmtpBlocked($user['email']);
    $helpUrl = webmailPublicBaseUrl() !== '' ? webmailPublicBaseUrl() . '/unblock' : '';

    if ($smtpBlocked) {
        $imap->logout();
        $msg = 'Outgoing mail is disabled for your account (SMTP blocked). New accounts often need to complete SMTP unblock on the main site.';
        if ($helpUrl !== '') {
            $msg .= ' Open: ' . $helpUrl;
        } else {
            $msg .= ' Ask the administrator to unblock SMTP or set ALLOW_SMTP_FOR_NEW_USERS=true for new registrations.';
        }

        return webmailRender($response, $twig, 'compose.html.twig', [
            'page_title' => 'Compose',
            'folders' => $folders,
            'error' => $msg,
            'to' => $to,
            'subject' => $subject,
            'body' => $messageBody,
            'smtp_help_url' => $helpUrl,
        ]);
    }

    $success = $imap->sendMessage($user['email'], $user['password'], $to, $subject, $messageBody);
    $imap->logout();

    if ($success) {
        return webmailRender($response, $twig, 'compose-success.html.twig', [
            'page_title' => 'Message Sent',
            'folders' => $folders,
        ]);
    }

    return webmailRender($response, $twig, 'compose.html.twig', [
        'page_title' => 'Compose',
        'folders' => $folders,
        'error' => 'The server could not send the message (mail transport failed). If SMTP is allowed for your account, the host may need PHP sendmail/Postfix configured.',
        'to' => $to,
        'subject' => $subject,
        'body' => $messageBody,
        'smtp_blocked' => false,
        'smtp_help_url' => $helpUrl,
    ]);
});

// =============================================================================
// REPLY / FORWARD
// =============================================================================

$app->get('/reply/{folder}/{uid}', function (Request $request, Response $response, array $args) {
    if (!requireAuth($request)) {
        return $response->withHeader('Location', '/webmail/')->withStatus(302);
    }
    
    /** @var Environment $twig */
    $twig = $this->get(Environment::class);
    /** @var ImapClient $imap */
    $imap = $this->get(ImapClient::class);
    
    $user = $_SESSION['webmail_user'];
    $folder = urldecode($args['folder']);
    $uid = (int) $args['uid'];
    
    if (!$imap->login($user['email'], $user['password'])) {
        return $response->withHeader('Location', '/webmail/')->withStatus(302);
    }
    
    $folders = $imap->getFolders();
    $original = $imap->getMessage($folder, $uid);
    $imap->logout();
    
    if ($original === null) {
        return $response->withHeader('Location', '/webmail/folder/' . urlencode($folder))->withStatus(302);
    }
    
    // Prepare reply
    $replyTo = $original['from'] ?? '';
    $subject = $original['subject'] ?? '';
    if (!str_starts_with(strtolower($subject), 're:')) {
        $subject = 'Re: ' . $subject;
    }
    
    $body = "\n\n--- Original Message ---\n";
    $body .= "From: " . $original['from'] . "\n";
    $body .= "Date: " . $original['date'] . "\n";
    $body .= "Subject: " . $original['subject'] . "\n\n";
    $body .= $original['text'] ?? strip_tags($original['html'] ?? '');
    
    return webmailRender($response, $twig, 'compose.html.twig', [
        'page_title' => 'Reply',
        'folders' => $folders,
        'to' => $replyTo,
        'subject' => $subject,
        'body' => $body,
    ]);
});

$app->get('/forward/{folder}/{uid}', function (Request $request, Response $response, array $args) {
    if (!requireAuth($request)) {
        return $response->withHeader('Location', '/webmail/')->withStatus(302);
    }
    
    /** @var Environment $twig */
    $twig = $this->get(Environment::class);
    /** @var ImapClient $imap */
    $imap = $this->get(ImapClient::class);
    
    $user = $_SESSION['webmail_user'];
    $folder = urldecode($args['folder']);
    $uid = (int) $args['uid'];
    
    if (!$imap->login($user['email'], $user['password'])) {
        return $response->withHeader('Location', '/webmail/')->withStatus(302);
    }
    
    $folders = $imap->getFolders();
    $original = $imap->getMessage($folder, $uid);
    $imap->logout();
    
    if ($original === null) {
        return $response->withHeader('Location', '/webmail/folder/' . urlencode($folder))->withStatus(302);
    }
    
    // Prepare forward
    $subject = $original['subject'] ?? '';
    if (!str_starts_with(strtolower($subject), 'fwd:')) {
        $subject = 'Fwd: ' . $subject;
    }
    
    $body = "\n\n--- Forwarded Message ---\n";
    $body .= "From: " . $original['from'] . "\n";
    $body .= "Date: " . $original['date'] . "\n";
    $body .= "Subject: " . $original['subject'] . "\n\n";
    $body .= $original['text'] ?? strip_tags($original['html'] ?? '');
    
    return webmailRender($response, $twig, 'compose.html.twig', [
        'page_title' => 'Forward',
        'folders' => $folders,
        'to' => '',
        'subject' => $subject,
        'body' => $body,
    ]);
});

// =============================================================================
// MESSAGE ACTIONS
// =============================================================================

$app->post('/message/delete', function (Request $request, Response $response) {
    if (!requireAuth($request)) {
        return $response->withHeader('Location', '/webmail/')->withStatus(302);
    }
    
    /** @var ImapClient $imap */
    $imap = $this->get(ImapClient::class);
    
    $user = $_SESSION['webmail_user'];
    $body = $request->getParsedBody();
    $folder = $body['folder'] ?? 'INBOX';
    $uid = (int) ($body['uid'] ?? 0);
    
    if ($imap->login($user['email'], $user['password'])) {
        $imap->deleteMessage($folder, $uid);
        $imap->logout();
    }
    
    return $response->withHeader('Location', '/webmail/folder/' . urlencode($folder))->withStatus(302);
});

$app->post('/message/move', function (Request $request, Response $response) {
    if (!requireAuth($request)) {
        return $response->withHeader('Location', '/webmail/')->withStatus(302);
    }
    
    /** @var ImapClient $imap */
    $imap = $this->get(ImapClient::class);
    
    $user = $_SESSION['webmail_user'];
    $body = $request->getParsedBody();
    $folder = $body['folder'] ?? 'INBOX';
    $uid = (int) ($body['uid'] ?? 0);
    $destination = $body['destination'] ?? 'Trash';
    
    if ($imap->login($user['email'], $user['password'])) {
        $imap->moveMessage($folder, $uid, $destination);
        $imap->logout();
    }
    
    return $response->withHeader('Location', '/webmail/folder/' . urlencode($folder))->withStatus(302);
});

// =============================================================================
// SEARCH
// =============================================================================

$app->get('/search', function (Request $request, Response $response) {
    if (!requireAuth($request)) {
        return $response->withHeader('Location', '/webmail/')->withStatus(302);
    }
    
    /** @var Environment $twig */
    $twig = $this->get(Environment::class);
    /** @var ImapClient $imap */
    $imap = $this->get(ImapClient::class);
    
    $user = $_SESSION['webmail_user'];
    $params = $request->getQueryParams();
    $query = $params['q'] ?? '';
    $folder = $params['folder'] ?? 'INBOX';
    
    if (!$imap->login($user['email'], $user['password'])) {
        return $response->withHeader('Location', '/webmail/')->withStatus(302);
    }
    
    $folders = $imap->getFolders();
    $results = [];
    
    if ($query !== '') {
        $results = $imap->search($folder, $query);
    }
    
    $imap->logout();
    
    return webmailRender($response, $twig, 'search.html.twig', [
        'page_title' => 'Search',
        'folders' => $folders,
        'query' => $query,
        'folder' => $folder,
        'results' => $results,
    ]);
});

// =============================================================================
// SETTINGS
// =============================================================================

$app->get('/settings', function (Request $request, Response $response) {
    if (!requireAuth($request)) {
        return $response->withHeader('Location', '/webmail/')->withStatus(302);
    }
    
    /** @var Environment $twig */
    $twig = $this->get(Environment::class);
    /** @var ImapClient $imap */
    $imap = $this->get(ImapClient::class);
    
    $user = $_SESSION['webmail_user'];
    /** @var UserService $userService */
    $userService = $this->get(UserService::class);
    
    if (!$imap->login($user['email'], $user['password'])) {
        return $response->withHeader('Location', '/webmail/')->withStatus(302);
    }
    
    $folders = $imap->getFolders();
    $quota = $imap->getQuota();
    $imap->logout();

    $smtpBlocked = $userService->isSmtpBlocked($user['email']);
    $smtpHelpUrl = webmailPublicBaseUrl() !== '' ? webmailPublicBaseUrl() . '/unblock' : '';
    
    return webmailRender($response, $twig, 'settings.html.twig', [
        'page_title' => 'Settings',
        'folders' => $folders,
        'quota' => $quota,
        'smtp_blocked' => $smtpBlocked,
        'smtp_help_url' => $smtpHelpUrl,
    ]);
});

// =============================================================================
// IMAGE PROXY
// =============================================================================

$app->get('/imgproxy', function (Request $request, Response $response) {
    if (!requireAuth($request)) {
        return $response->withStatus(403);
    }
    
    $params = $request->getQueryParams();
    $url = $params['url'] ?? '';
    $sig = $params['sig'] ?? '';
    
    // Verify signature
    $expectedSig = hash_hmac('sha256', $url, $_ENV['APP_KEY'] ?? 'secret');
    if (!hash_equals($expectedSig, $sig)) {
        return $response->withStatus(403);
    }
    
    // Fetch image
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'AnonymMail/1.0',
        ],
    ]);
    
    $data = @file_get_contents($url, false, $context);
    
    if ($data === false) {
        return $response->withStatus(404);
    }
    
    // Detect content type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $contentType = $finfo->buffer($data);
    
    // Only allow images
    if (!str_starts_with($contentType, 'image/')) {
        return $response->withStatus(403);
    }
    
    $response->getBody()->write($data);
    return $response
        ->withHeader('Content-Type', $contentType)
        ->withHeader('Cache-Control', 'public, max-age=86400');
});
