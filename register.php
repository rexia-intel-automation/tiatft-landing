<?php
/**
 * TIATFT - Seller Registration Handler
 * Processa cadastros e envia para n8n via Railway
 */

// Carregar configurações
$config = require __DIR__ . '/config.php';

// Headers CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Validar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Rate limiting via sessão
session_start();

$max_attempts = $config['rate_limit']['max_attempts'];
$time_window = $config['rate_limit']['time_window'];

if (!isset($_SESSION['submit_count'])) {
    $_SESSION['submit_count'] = 0;
    $_SESSION['first_submit'] = time();
}

$time_elapsed = time() - $_SESSION['first_submit'];

if ($time_elapsed < $time_window) {
    if ($_SESSION['submit_count'] >= $max_attempts) {
        http_response_code(429);
        echo json_encode([
            'error' => 'Too many attempts. Try again in 1 minute.',
            'retry_after' => $time_window - $time_elapsed
        ]);
        exit;
    }
    $_SESSION['submit_count']++;
} else {
    $_SESSION['submit_count'] = 1;
    $_SESSION['first_submit'] = time();
}

// Pegar dados
$raw_data = file_get_contents('php://input');
$data = json_decode($raw_data, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON data']);
    exit;
}

// Sanitizar inputs
$name = isset($data['name']) ? trim($data['name']) : '';
$email = isset($data['email']) ? trim(strtolower($data['email'])) : '';
$platform = isset($data['platform']) ? trim($data['platform']) : '';

// Validações
$errors = [];

// Validar nome
$min_name = $config['validation']['min_name_length'];
if (empty($name) || strlen($name) < $min_name) {
    $errors[] = "Name must be at least {$min_name} characters";
}

// Validar email
if (empty($email)) {
    $errors[] = 'Email is required';
} else {
    // Validação nativa PHP
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }
    
    // Verificar estrutura
    if (substr_count($email, '@') !== 1) {
        $errors[] = 'Email must contain exactly one @ symbol';
    }
    
    if (strpos($email, '..') !== false) {
        $errors[] = 'Email cannot contain consecutive dots';
    }
    
    if (strpos($email, '.') === 0 || substr($email, -1) === '.') {
        $errors[] = 'Email cannot start or end with a dot';
    }
    
    // Verificar domínio temporário
    $email_parts = explode('@', $email);
    if (count($email_parts) === 2) {
        $email_domain = $email_parts[1];
        $temp_domains = $config['validation']['temp_email_domains'];
        
        if (in_array($email_domain, $temp_domains)) {
            $errors[] = 'Temporary email addresses are not allowed';
        }
        
        // Verificar TLD
        $domain_parts = explode('.', $email_domain);
        $tld = end($domain_parts);
        if (strlen($tld) < 2) {
            $errors[] = 'Invalid email domain';
        }
    }
}

// Validar plataforma
$min_platform = $config['validation']['min_platform_length'];
if (empty($platform) || strlen($platform) < $min_platform) {
    $errors[] = "Platform must be at least {$min_platform} characters";
}

// Se houver erros, retornar
if (!empty($errors)) {
    http_response_code(400);
    echo json_encode([
        'error' => implode(', ', $errors),
        'errors' => $errors
    ]);
    exit;
}

// Preparar dados para webhook
$webhook_data = [
    'name' => $name,
    'email' => $email,
    'platform' => $platform,
    'timestamp' => date('Y-m-d H:i:s'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
];

// Enviar para n8n
$webhook_url = $config['webhook_url'];

$ch = curl_init($webhook_url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($webhook_data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

// Verificar resposta
if ($http_code >= 200 && $http_code < 300) {
    echo json_encode([
        'success' => true,
        'message' => 'Registration received successfully'
    ]);
} else {
    http_response_code(500);
    
    $error_response = [
        'error' => 'Failed to process registration. Please try again.'
    ];
    
    // Adicionar debug info se habilitado
    if ($config['debug_mode']) {
        $error_response['debug'] = [
            'http_code' => $http_code,
            'curl_error' => $curl_error,
            'webhook_url' => $webhook_url
        ];
    }
    
    echo json_encode($error_response);
}
