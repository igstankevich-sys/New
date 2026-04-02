<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Метод запроса не поддерживается.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

const STORAGE_DIR = __DIR__ . '/storage';
const RATE_LIMIT_WINDOW = 900;
const RATE_LIMIT_MAX = 4;

loadLocalConfig();

try {
    assertAllowedOrigin();

    $payload = readPayload();
    validateTrapFields($payload);

    $name = sanitizeName((string) ($payload['name'] ?? ''));
    $phone = normalizePhone((string) ($payload['phone'] ?? ''));
    $source = sanitizeSource((string) ($payload['source'] ?? 'main-form'));
    $page = sanitizePage((string) ($payload['page'] ?? ''));
    $ip = clientIp();

    enforceRateLimit($ip);

    $quizAnswers = normalizeQuizAnswers($payload['quiz_answers'] ?? null);

    $lead = [
        'name' => $name,
        'phone' => $phone,
        'source' => $source,
        'page' => $page,
        'ip' => $ip,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'created_at' => date('c'),
        'quiz_answers' => $quizAnswers,
    ];

    $emailSent = deliverEmail($lead);
    $amoResult = deliverAmoLead($lead);

    if (!$emailSent && !$amoResult['success']) {
        throw new RuntimeException('Не удалось передать обращение по защищенным каналам.');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Заявка отправлена. Мы свяжемся с вами после первичной проверки обращения.'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    logFailure($exception);

    $code = $exception instanceof InvalidArgumentException ? 422 : 400;
    if ($exception instanceof RuntimeException) {
        $code = 503;
    }

    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => publicErrorMessage($code)
    ], JSON_UNESCAPED_UNICODE);
}

function loadLocalConfig(): void
{
    $configFile = __DIR__ . '/config.local.php';

    if (is_file($configFile)) {
        require_once $configFile;
    }
}

function readPayload(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false) {
        throw new RuntimeException('Пустой поток данных.');
    }

    if ($raw !== '') {
        $json = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }
    }

    if (!empty($_POST)) {
        return $_POST;
    }

    throw new InvalidArgumentException('Не удалось прочитать данные формы.');
}

function assertAllowedOrigin(): void
{
    $allowedHosts = array_filter([
        envValue('SITE_HOST', 'protect-online.ru'),
        'www.' . envValue('SITE_HOST', 'protect-online.ru'),
        $_SERVER['HTTP_HOST'] ?? '',
    ]);

    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $serverKey) {
        if (empty($_SERVER[$serverKey])) {
            continue;
        }

        $parsedHost = (string) parse_url($_SERVER[$serverKey], PHP_URL_HOST);
        if ($parsedHost === '') {
            continue;
        }

        if (!in_array($parsedHost, $allowedHosts, true)) {
            throw new InvalidArgumentException('Источник запроса не разрешен.');
        }
    }
}

function validateTrapFields(array $payload): void
{
    $honeypot = trim((string) ($payload['company'] ?? ''));
    if ($honeypot !== '') {
        throw new InvalidArgumentException('Автоматическая отправка отклонена.');
    }

    $consent = (string) ($payload['consent'] ?? 'no');
    if ($consent !== 'yes' && $consent !== 'on' && $consent !== '1') {
        throw new InvalidArgumentException('Не подтверждено согласие на обработку данных.');
    }

    $sentAt = (string) ($payload['sent_at'] ?? '');
    if ($sentAt === '' || !ctype_digit($sentAt)) {
        throw new InvalidArgumentException('Некорректная метка времени формы.');
    }

    $elapsed = (int) floor((microtime(true) * 1000) - (int) $sentAt);
    if ($elapsed < 2500 || $elapsed > 86400000) {
        throw new InvalidArgumentException('Форма отправлена некорректно.');
    }
}

function sanitizeName(string $value): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

    if ($value === '') {
        return 'Без имени';
    }

    return mb_substr($value, 0, 80);
}

function normalizePhone(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';

    if ($digits === '') {
        throw new InvalidArgumentException('Телефон не указан.');
    }

    if ($digits[0] === '8') {
        $digits = '7' . substr($digits, 1);
    }

    if (strlen($digits) === 10) {
        $digits = '7' . $digits;
    }

    if (strlen($digits) !== 11 || $digits[0] !== '7') {
        throw new InvalidArgumentException('Принимаются только российские номера телефонов.');
    }

    if ($digits[1] !== '9') {
        throw new InvalidArgumentException('Принимаются только мобильные номера телефонов.');
    }

    return '+7 (' . substr($digits, 1, 3) . ') ' . substr($digits, 4, 3) . '-' . substr($digits, 7, 2) . '-' . substr($digits, 9, 2);
}

function sanitizeSource(string $value): string
{
    $value = trim($value);
    return mb_substr($value !== '' ? $value : 'main-form', 0, 80);
}

function sanitizePage(string $value): string
{
    $value = trim($value);
    return mb_substr($value, 0, 200);
}

/**
 * @param mixed $raw
 * @return array<string, mixed>
 */
function normalizeQuizAnswers($raw): array
{
    if ($raw === null) {
        return [];
    }

    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }

    if (!is_array($raw)) {
        return [];
    }

    $out = [];

    foreach (['debts', 'amount', 'property'] as $key) {
        if (empty($raw[$key]) || !is_array($raw[$key])) {
            continue;
        }

        $vals = [];
        foreach ($raw[$key] as $item) {
            $vals[] = mb_substr(trim((string) $item), 0, 120);
        }

        $vals = array_values(array_filter($vals, static fn ($v) => $v !== ''));
        if ($vals !== []) {
            $out[$key] = array_slice($vals, 0, 15);
        }
    }

    foreach (['late', 'official_income', 'city'] as $key) {
        if (!isset($raw[$key]) || $raw[$key] === '') {
            continue;
        }

        $out[$key] = mb_substr(trim((string) $raw[$key]), 0, 300);
    }

    if (isset($raw['contact_method'])) {
        $cm = (string) $raw['contact_method'];
        $allowed = ['phone', 'telegram', 'whatsapp', 'max'];
        $out['contact_method'] = in_array($cm, $allowed, true) ? $cm : 'phone';
    }

    return $out;
}

/**
 * @param array<string, mixed> $quiz
 */
function formatQuizAnswersForEmail(array $quiz): string
{
    if ($quiz === []) {
        return '';
    }

    $contactLabels = [
        'phone' => 'Телефон',
        'telegram' => 'Telegram',
        'whatsapp' => 'WhatsApp',
        'max' => 'MAX',
    ];

    $lines = [
        '',
        '--- Ответы квиза ---',
    ];

    if (!empty($quiz['debts']) && is_array($quiz['debts'])) {
        $lines[] = 'Перед кем долги: ' . implode(', ', $quiz['debts']);
    }

    if (!empty($quiz['amount']) && is_array($quiz['amount'])) {
        $lines[] = 'Примерная сумма долгов: ' . implode(', ', $quiz['amount']);
    }

    if (!empty($quiz['late'])) {
        $lines[] = 'Просрочки: ' . (string) $quiz['late'];
    }

    if (!empty($quiz['property']) && is_array($quiz['property'])) {
        $lines[] = 'Имущество: ' . implode(', ', $quiz['property']);
    }

    if (!empty($quiz['official_income'])) {
        $lines[] = 'Официальный доход: ' . (string) $quiz['official_income'];
    }

    if (!empty($quiz['city'])) {
        $lines[] = 'Город: ' . (string) $quiz['city'];
    }

    if (!empty($quiz['contact_method'])) {
        $cm = (string) $quiz['contact_method'];
        $label = $contactLabels[$cm] ?? $cm;
        $lines[] = 'Удобный способ связи: ' . $label;
    }

    return implode("\n", $lines);
}

/**
 * @param array<string, mixed> $quiz
 */
function formatQuizAnswersForAmo(array $quiz): string
{
    if ($quiz === []) {
        return '';
    }

    $block = formatQuizAnswersForEmail($quiz);

    return trim($block);
}

function clientIp(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
    }

    return 'unknown';
}

function enforceRateLimit(string $ip): void
{
    $dir = ensureStorageDirectory() . '/rate-limit';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось подготовить каталог ограничений.');
    }

    $file = $dir . '/' . hash('sha256', $ip) . '.json';
    $now = time();
    $entries = [];

    if (is_file($file)) {
        $stored = json_decode((string) file_get_contents($file), true);
        if (is_array($stored)) {
            $entries = array_values(array_filter($stored, static fn ($item) => is_int($item) && ($now - $item) <= RATE_LIMIT_WINDOW));
        }
    }

    if (count($entries) >= RATE_LIMIT_MAX) {
        throw new InvalidArgumentException('Слишком много запросов. Попробуйте позже.');
    }

    $entries[] = $now;
    file_put_contents($file, json_encode($entries), LOCK_EX);
}

function getGeoInfo(string $ip): array
{
    $default = ['country' => 'Не определено', 'city' => 'Не определено', 'region' => 'Не определено'];

    if ($ip === 'unknown' || $ip === '127.0.0.1' || $ip === '::1') {
        return $default;
    }

    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return $default;
    }

    $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=country,regionName,city&lang=ru';
    $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $ctx);

    if ($response === false) {
        return $default;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return $default;
    }

    return [
        'country' => $data['country'] ?? 'Не определено',
        'city' => $data['city'] ?? 'Не определено',
        'region' => $data['regionName'] ?? 'Не определено',
    ];
}

function parseBrowserInfo(string $ua): array
{
    $browser = 'Неизвестно';
    $os = 'Неизвестно';
    $device = 'ПК';

    if (preg_match('/YaBrowser/i', $ua)) {
        $browser = 'Яндекс.Браузер';
    } elseif (preg_match('/Edg\//i', $ua)) {
        $browser = 'Edge';
    } elseif (preg_match('/OPR\//i', $ua)) {
        $browser = 'Opera';
    } elseif (preg_match('/Chrome\//i', $ua) && !preg_match('/Edg|OPR/i', $ua)) {
        $browser = 'Chrome';
    } elseif (preg_match('/Firefox\//i', $ua)) {
        $browser = 'Firefox';
    } elseif (preg_match('/Safari\//i', $ua) && !preg_match('/Chrome/i', $ua)) {
        $browser = 'Safari';
    }

    if (preg_match('/Windows/i', $ua)) {
        $os = 'Windows';
    } elseif (preg_match('/Macintosh|Mac OS/i', $ua)) {
        $os = 'macOS';
    } elseif (preg_match('/Android/i', $ua)) {
        $os = 'Android';
    } elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) {
        $os = 'iOS';
    } elseif (preg_match('/Linux/i', $ua)) {
        $os = 'Linux';
    }

    if (preg_match('/Mobile|Android.*Mobile|iPhone/i', $ua)) {
        $device = 'Мобильный';
    } elseif (preg_match('/Tablet|iPad/i', $ua)) {
        $device = 'Планшет';
    }

    return ['browser' => $browser, 'os' => $os, 'device' => $device];
}

function deliverEmail(array $lead): bool
{
    $to = envValue('LEAD_EMAIL_TO', 'i@lk-protect.ru');
    if ($to === '') {
        return false;
    }

    $geo = getGeoInfo($lead['ip']);
    $browser = parseBrowserInfo($lead['user_agent']);

    $subject = '=?UTF-8?B?' . base64_encode('Новая заявка с protect-online.ru') . '?=';
    $quizBlock = formatQuizAnswersForEmail($lead['quiz_answers'] ?? []);

    $message = implode("\n", [
        'Новое обращение с сайта protect-online.ru',
        '',
        'Имя: ' . $lead['name'],
        'Телефон: ' . $lead['phone'],
        'Источник формы: ' . $lead['source'],
        'Страница: ' . ($lead['page'] !== '' ? $lead['page'] : 'не указана'),
        'Время: ' . date('d.m.Y H:i:s'),
        $quizBlock,
        '',
        '--- Техническая информация ---',
        'IP: ' . $lead['ip'],
        'Местоположение: ' . $geo['city'] . ', ' . $geo['region'] . ', ' . $geo['country'],
        'Браузер: ' . $browser['browser'],
        'ОС: ' . $browser['os'],
        'Устройство: ' . $browser['device'],
        'User-Agent: ' . $lead['user_agent'],
    ]);

    $from = envValue('LEAD_EMAIL_FROM', 'robot@protect-online.ru');
    $headers = [
        'From: ' . $from,
        'Reply-To: ' . $from,
        'Content-Type: text/plain; charset=utf-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: PHP/' . PHP_VERSION,
    ];

    return @mail($to, $subject, $message, implode("\r\n", $headers));
}

function deliverAmoLead(array $lead): array
{
    $domain = envValue('AMOCRM_DOMAIN', '');
    $token = envValue('AMOCRM_ACCESS_TOKEN', '');

    if ($domain === '' || $token === '' || !function_exists('curl_init')) {
        return ['success' => false];
    }

    $amoNote = "Имя: {$lead['name']}\nИсточник формы: {$lead['source']}\nСтраница: {$lead['page']}";
    $amoQuiz = formatQuizAnswersForAmo($lead['quiz_answers'] ?? []);

    if ($amoQuiz !== '') {
        $amoNote .= "\n\n" . $amoQuiz;
    }

    $payload = [[
        'name' => 'Обращение с сайта PROTECT ' . date('d.m.Y H:i'),
        'price' => 0,
        'custom_fields_values' => [[
            'field_code' => 'PHONE',
            'values' => [[
                'value' => $lead['phone'],
                'enum_code' => 'WORK',
            ]],
        ]],
        '_embedded' => [
            'notes' => [[
                'note_type' => 'common',
                'params' => [
                    'text' => $amoNote,
                ],
            ]],
        ],
    ]];

    $ch = curl_init('https://' . $domain . '/api/v4/leads/complex');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'User-Agent: protect-online.ru lead handler',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true];
    }

    storeSecureLog([
        'type' => 'amocrm_error',
        'time' => date('c'),
        'http_code' => $httpCode,
        'response_excerpt' => mb_substr((string) $response, 0, 300),
    ]);

    return ['success' => false];
}

function ensureStorageDirectory(): string
{
    if (!is_dir(STORAGE_DIR) && !mkdir(STORAGE_DIR, 0750, true) && !is_dir(STORAGE_DIR)) {
        throw new RuntimeException('Не удалось подготовить служебный каталог.');
    }

    return STORAGE_DIR;
}

function storeSecureLog(array $record): void
{
    $dir = ensureStorageDirectory() . '/logs';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        return;
    }

    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return;
    }

    file_put_contents($dir . '/lead-handler.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function logFailure(Throwable $exception): void
{
    storeSecureLog([
        'type' => 'request_error',
        'time' => date('c'),
        'message' => $exception->getMessage(),
        'code' => $exception->getCode(),
        'ip' => clientIp(),
    ]);
}

function publicErrorMessage(int $code): string
{
    if ($code === 422) {
        return 'Проверьте корректность данных формы и повторите отправку.';
    }

    if ($code === 503) {
        return 'Сервис временно недоступен. Попробуйте позже или напишите в Telegram.';
    }

    return 'Не удалось обработать обращение. Попробуйте еще раз чуть позже.';
}

function envValue(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value === false || $value === null) {
        return defined($key) ? (string) constant($key) : $default;
    }

    return trim((string) $value);
}
