<?php

declare(strict_types=1);

use App\Captcha\CaptchaGenerator;
use App\Domain\Domain\DomainRepository;
use App\Domain\User\UserService;
use App\Domain\User\UserValidationException;
use App\Pow\ChallengeService;
use App\Pow\RateLimitException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Twig\Environment;

/** @var App $app */

function render(Response $response, Environment $twig, string $template, array $data = []): Response
{
    $data['csrf_token'] = $_SESSION['csrf_token'] ?? '';
    $html = $twig->render($template, $data);
    $response->getBody()->write($html);
    return $response;
}

// Home
$app->get('/', function (Request $request, Response $response) {
    $twig = $this->get(Environment::class);
    
    $announcement = null;
    try {
        $pdo = $this->get(PDO::class);
        $stmt = $pdo->query('SELECT body FROM announcements WHERE active = 1 ORDER BY id DESC LIMIT 1');
        $row = $stmt->fetch();
        if ($row) {
            $announcement = $row['body'];
        }
    } catch (\Exception $e) {}
    
    return render($response, $twig, 'pages/index.html.twig', [
        'page_title' => 'Home',
        'announcement' => $announcement,
    ]);
});

// Register
$registerGet = function (Request $request, Response $response) {
    $twig = $this->get(Environment::class);
    $domainRepo = $this->get(DomainRepository::class);
    $captcha = $this->get(CaptchaGenerator::class);
    
    return render($response, $twig, 'pages/register.html.twig', [
        'page_title' => 'Register',
        'domains' => $domainRepo->getRegistrableDomains(),
        'captcha_key' => $captcha->generate(),
    ]);
};

$registerPost = function (Request $request, Response $response) {
    $twig = $this->get(Environment::class);
    $domainRepo = $this->get(DomainRepository::class);
    $userService = $this->get(UserService::class);
    $captcha = $this->get(CaptchaGenerator::class);
    
    $body = $request->getParsedBody();
    $errors = [];
    
    if (!$captcha->verify($body['captcha_key'] ?? '', $body['captcha_solution'] ?? '')) {
        $errors[] = 'Invalid CAPTCHA';
    }
    
    if (!isset($body['tos_agree'])) {
        $errors[] = 'You must agree to the Terms of Service';
    }
    
    if (!empty($errors)) {
        return render($response, $twig, 'pages/register.html.twig', [
            'page_title' => 'Register',
            'domains' => $domainRepo->getRegistrableDomains(),
            'captcha_key' => $captcha->generate(),
            'errors' => $errors,
            'old' => $body,
        ]);
    }
    
    try {
        $userService->register(
            $body['username'] ?? '',
            (int) ($body['domain'] ?? 0),
            $body['password'] ?? '',
            $body['password_confirm'] ?? '',
        );
        
        $domain = $domainRepo->findById((int) ($body['domain'] ?? 0));
        $email = ($body['username'] ?? '') . '@' . ($domain['name'] ?? '');
        
        return render($response, $twig, 'pages/register-success.html.twig', [
            'page_title' => 'Registration Successful',
            'email' => $email,
        ]);
    } catch (UserValidationException $e) {
        return render($response, $twig, 'pages/register.html.twig', [
            'page_title' => 'Register',
            'domains' => $domainRepo->getRegistrableDomains(),
            'captcha_key' => $captcha->generate(),
            'errors' => [$e->getMessage()],
            'old' => $body,
        ]);
    }
};

$app->get('/register', $registerGet);
$app->get('/register.php', $registerGet);
$app->post('/register', $registerPost);
$app->post('/register.php', $registerPost);

// Change Password
$changepassGet = function (Request $request, Response $response) {
    $twig = $this->get(Environment::class);
    $captcha = $this->get(CaptchaGenerator::class);
    
    return render($response, $twig, 'pages/changepass.html.twig', [
        'page_title' => 'Change Password',
        'captcha_key' => $captcha->generate(),
    ]);
};

$changepassPost = function (Request $request, Response $response) {
    $twig = $this->get(Environment::class);
    $userService = $this->get(UserService::class);
    $captcha = $this->get(CaptchaGenerator::class);
    
    $body = $request->getParsedBody();
    
    if (!$captcha->verify($body['captcha_key'] ?? '', $body['captcha_solution'] ?? '')) {
        return render($response, $twig, 'pages/changepass.html.twig', [
            'page_title' => 'Change Password',
            'captcha_key' => $captcha->generate(),
            'errors' => ['Invalid CAPTCHA'],
        ]);
    }
    
    $user = $userService->authenticate($body['email'] ?? '', $body['old_password'] ?? '');
    
    if ($user === null) {
        return render($response, $twig, 'pages/changepass.html.twig', [
            'page_title' => 'Change Password',
            'captcha_key' => $captcha->generate(),
            'errors' => ['Invalid email or password'],
        ]);
    }
    
    if (($body['password'] ?? '') !== ($body['password_again'] ?? '')) {
        return render($response, $twig, 'pages/changepass.html.twig', [
            'page_title' => 'Change Password',
            'captcha_key' => $captcha->generate(),
            'errors' => ['New passwords do not match'],
        ]);
    }
    
    if (!$userService->changePassword($user['id'], $body['old_password'], $body['password'])) {
        return render($response, $twig, 'pages/changepass.html.twig', [
            'page_title' => 'Change Password',
            'captcha_key' => $captcha->generate(),
            'errors' => ['Password must be at least 10 characters'],
        ]);
    }
    
    return render($response, $twig, 'pages/changepass-success.html.twig', [
        'page_title' => 'Password Changed',
    ]);
};

$app->get('/changepass', $changepassGet);
$app->get('/changepass.php', $changepassGet);
$app->post('/changepass', $changepassPost);
$app->post('/changepass.php', $changepassPost);

// SMTP unblock (proof-of-work)
$unblockGet = function (Request $request, Response $response) {
    $twig = $this->get(Environment::class);
    /** @var ChallengeService $challengeService */
    $challengeService = $this->get(ChallengeService::class);
    /** @var UserService $userService */
    $userService = $this->get(UserService::class);

    $params = $request->getQueryParams();
    $email = strtolower(trim((string) ($params['email'] ?? '')));
    $code = trim((string) ($params['unblock_code'] ?? ''));

    if ($email !== '' && $code !== '') {
        if ($challengeService->verifySolution($email, $code)) {
            $userId = $userService->getUserIdForEmail($email);
            if ($userId === null) {
                return render($response, $twig, 'pages/unblock.html.twig', [
                    'page_title' => 'Unblock SMTP',
                    'errors' => ['Account not found.'],
                ]);
            }
            $userService->unblockSmtp($userId);

            return render($response, $twig, 'pages/unblock-success.html.twig', [
                'page_title' => 'SMTP Unblocked',
                'email' => $email,
            ]);
        }

        $challenge = $challengeService->getChallenge($email);

        $payload = [
            'page_title' => 'Unblock SMTP',
            'errors' => ['Invalid solution code. Check the solver output and try again.'],
            'email' => $email,
        ];
        if ($challenge !== null) {
            $payload['challenge'] = [
                'seed' => $challenge->seed,
                'salt' => $challenge->salt,
                'difficulty' => $challenge->difficulty,
            ];
        }

        return render($response, $twig, 'pages/unblock.html.twig', $payload);
    }

    return render($response, $twig, 'pages/unblock.html.twig', [
        'page_title' => 'Unblock SMTP',
    ]);
};

$unblockPost = function (Request $request, Response $response) {
    $twig = $this->get(Environment::class);
    /** @var UserService $userService */
    $userService = $this->get(UserService::class);
    /** @var ChallengeService $challengeService */
    $challengeService = $this->get(ChallengeService::class);

    $body = $request->getParsedBody() ?? [];
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $password = (string) ($body['password'] ?? '');

    if ($email === '' || $password === '') {
        return render($response, $twig, 'pages/unblock.html.twig', [
            'page_title' => 'Unblock SMTP',
            'errors' => ['Email and password are required.'],
        ]);
    }

    $user = $userService->authenticate($email, $password);
    if ($user === null) {
        return render($response, $twig, 'pages/unblock.html.twig', [
            'page_title' => 'Unblock SMTP',
            'errors' => ['Invalid email or password.'],
        ]);
    }

    if (!$userService->isSmtpBlocked($email)) {
        return render($response, $twig, 'pages/unblock.html.twig', [
            'page_title' => 'Unblock SMTP',
            'errors' => ['SMTP is already enabled for this account.'],
        ]);
    }

    try {
        $challenge = $challengeService->generateChallenge($email);
    } catch (RateLimitException $e) {
        return render($response, $twig, 'pages/unblock.html.twig', [
            'page_title' => 'Unblock SMTP',
            'errors' => [$e->getMessage()],
        ]);
    }

    return render($response, $twig, 'pages/unblock.html.twig', [
        'page_title' => 'Unblock SMTP',
        'email' => $email,
        'challenge' => [
            'seed' => $challenge->seed,
            'salt' => $challenge->salt,
            'difficulty' => $challenge->difficulty,
        ],
    ]);
};

$app->get('/unblock', $unblockGet);
$app->get('/unblock.php', $unblockGet);
$app->post('/unblock', $unblockPost);
$app->post('/unblock.php', $unblockPost);

// Static pages
$termsHandler = function (Request $request, Response $response) {
    $twig = $this->get(Environment::class);
    return render($response, $twig, 'pages/terms.html.twig', ['page_title' => 'Terms']);
};
$app->get('/terms', $termsHandler);
$app->get('/terms.php', $termsHandler);

$privacyHandler = function (Request $request, Response $response) {
    $twig = $this->get(Environment::class);
    return render($response, $twig, 'pages/privacy.html.twig', ['page_title' => 'Privacy']);
};
$app->get('/privacy', $privacyHandler);
$app->get('/privacy.php', $privacyHandler);

$contactHandler = function (Request $request, Response $response) {
    $twig = $this->get(Environment::class);
    return render($response, $twig, 'pages/contact.html.twig', ['page_title' => 'Contact']);
};
$app->get('/contact', $contactHandler);
$app->get('/contact.php', $contactHandler);

$canaryHandler = function (Request $request, Response $response) {
    $twig = $this->get(Environment::class);
    return render($response, $twig, 'pages/canary.html.twig', ['page_title' => 'Warrant Canary']);
};
$app->get('/canary', $canaryHandler);
$app->get('/canary.php', $canaryHandler);

// CAPTCHA image
$captchaHandler = function (Request $request, Response $response) {
    $captcha = $this->get(CaptchaGenerator::class);
    
    $params = $request->getQueryParams();
    $key = $params['k'] ?? '';
    $imageData = $captcha->render($key);
    
    if ($imageData === null) {
        return $response->withStatus(404);
    }
    
    $response->getBody()->write($imageData);
    return $response
        ->withHeader('Content-Type', 'image/png')
        ->withHeader('Cache-Control', 'no-store');
};
$app->get('/captcha', $captchaHandler);
$app->get('/captcha.php', $captchaHandler);
