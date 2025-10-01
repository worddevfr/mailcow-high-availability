<?php
/**
 * English:
 * Mailcow ISP - Auto Registration Page
 * 
 * IMPORTANT SECURITY NOTICE:
 * 
 * This auto-registration page requires HUMAN EXPERTISE to guarantee:
 * - That the Mailcow server is not configured as an open-relay
 * - That the registration page is properly secured against abuse
 * - That all security measures are correctly implemented
 * - That system performance and reliability are optimized
 * 
 * WE ARE CURRENTLY REWRITING/IMPROVING THIS CODE TO IMPLEMENT
 * THE STRICTEST SECURITY STANDARDS AND PREVENT ANY POTENTIAL VULNERABILITY.
 * 
 * SUCCESSFUL DEPLOYMENT REQUIRES HUMAN EXPERTISE to guarantee
 * optimal performance, security and reliability.
 * 
 * If you need this solution deployed quickly, I offer a complete
 * deployment service - turnkey solution for a small contribution. For this, please contact me by email
 */

/**
 * Français :
 * Mailcow ISP - Page d'Inscription Automatique
 * 
 * AVIS DE SÉCURITÉ IMPORTANT :
 * 
 * Cette page d'inscription automatique nécessite une EXPERTISE HUMAINE pour garantir :
 * - Que le serveur Mailcow n'est pas configuré en open-relay
 * - Que la page d'inscription est correctement sécurisée contre les abus
 * - Que toutes les mesures de sécurité sont correctement implémentées
 * - Que les performances et la fiabilité du système sont optimisées
 * 
 * NOUS SOMMES EN TRAIN DE RÉÉCRIRE/AMÉLIORER CE CODE POUR METTRE EN ŒUVRE
 * LES STANDARDS DE SÉCURITÉ LES PLUS STRICTS ET PRÉVENIR TOUTE VULNÉRABILITÉ POTENTIELLE.
 * 
 * UN DÉPLOIEMENT RÉUSSI NÉCESSITE UNE EXPERTISE HUMAINE pour garantir
 * des performances, une sécurité et une fiabilité optimales.
 * 
 * Si vous avez besoin de cette solution déployée rapidement, je propose un service
 * de déploiement complet - solution clé en main moyennant une petite contribution. Pour cela, merci de me contacter par mail
 */








# ==============================================================
# CONFIGURATION
# ==============================================================
 // Disables the display of PHP errors to the end user
error_reporting(0);
session_start();

$webroot_path = $_SERVER['DOCUMENT_ROOT'];
require_once $webroot_path . '/inc/prerequisites.inc.php';
if (!file_exists($webroot_path . '/inc/prerequisites.inc.php')) {
    die("ERROR: This script must be placed in the mailcow web root directory.");
}
require_once $webroot_path . '/inc/vars.inc.php';


// Redirect logged in users
if (isset($_SESSION['mailcow_cc_role'])) {
    header("Location: /" . ($_SESSION['mailcow_cc_role'] == 'user' ? 'user' : 'admin'));
    exit();
}

// Available languages
// https://www.iso.org/obp/ui/#search
// https://en.wikipedia.org/wiki/IETF_language_tag
$AVAILABLE_LANGUAGES = array(
  'de-de' => 'Deutsch',
  'en-gb' => 'English',
  'it-it' => 'Italiano',
  'fr-fr' => 'Français',
  'es-es' => 'Español',
);


// Edit this list to define the domains authorized for registration.
// These domains must be previously created and activated
// $list_domains = ['mydomain.tld', 'my-other-domain.tld'];
$list_domains = '';


// mailcow API Read-Write Access (Goto System->Configuration :: +API)
$api_key = "";


// Google reCAPCHA, see https://www.google.com/recaptcha/admin/create
$recaptcha_secret_key = "";
$recaptcha_site_key = "";


// Quota in Mebibytes (MiB, souvent notés Mo en français)
$quota   = 3072; // Here 3 GiB. Nb: 1 GiB = 1024 MiB ==> 3 GiB × 1024 = 3072 MiB.


// Permissions (ACL) for mailboxes
// Remove or comment out the option you wish to prohibit
$acl = [
    'spam_alias',
    'tls_policy',
    'spam_score',
    'spam_policy',
    'delimiter_action',
    'syncjobs',
    'eas_reset',
    'sogo_profile_reset',
    'pushover',
    'quarantine',
    'quarantine_attachments',
    'quarantine_notification',
    'quarantine_category',
    'app_passwds',
    'pw_reset'
];



// max mail (value) per period (period).
// Value must be >= 2 :: default 100 
// period (s, m, h, d) :: second, minute, hour, day :: default d
$ratelimit = ["value" => 100, "period" => "d"]; // This account can send a maximum of 100 emails per day










# ==============================================================
# Please do not change anything from here unless you know what you are doing.
# ==============================================================



# ==============================================================
# FONCTIONS
# ==============================================================


/**
 * Get the current full URL (scheme + host + URI)
 *
 * @return string The current URL
 */
function get_current_url() {
    // Detect protocol (HTTP or HTTPS)
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";

    // Get hostname (domain or IP + optional port)
    $host = $_SERVER['HTTP_HOST'];

    // Build and return full URL
    return $scheme . "://" . $host . '/';
}


/**
 * Truncate a string to a specific length
 *
 * @param string $value   The input string
 * @param int    $length  The maximum length (default 25)
 * @return string         The truncated string
 */
function truncateString($value, $length = 50) {
    // Ensure length is a positive integer
    if ($length <= 0) {
        return '';
    }

    // Use substr() to return only the first $length characters
    return substr($value, 0, $length);
}


/**
 * Validates and corrects quota values in Mebibytes (MiB).
 * Accepts only integers or strings representing integers (e.g., '2000' or 2000).
 * @param int|string|null $quota Quota value in MiB.
 * @return int Corrected quota value in MiB.
 */
function validate_quota($quota): int {
    $defaultQuota = 3072; // 3 GiB

    // If null or empty string, return default
    if ($quota === null || $quota === '') {
        return $defaultQuota;
    }

    // If already an integer, validate it
    if (is_int($quota)) {
        return ($quota > 0) ? $quota : $defaultQuota;
    }

    // If string, check if it's a valid integer
    if (is_string($quota) && ctype_digit($quota)) {
        $quota = (int)$quota;
        return ($quota > 0) ? $quota : $defaultQuota;
    }

    // Invalid case: return default
    return $defaultQuota;
}





/**
 * Load translations with strict checks:
 * - Each candidate language is valid only if BOTH:
 *     $webroot_path . '/lang/lang.{code}.json' AND
 *     $webroot_path . '/signup-lang/lang.{code}.json'
 *   exist and decode to valid JSON.
 * - Order of detection:
 *     1. $_GET['lang']
 *     2. $_SESSION['mailcow_locale']
 *     3. $_SERVER['HTTP_ACCEPT_LANGUAGE']
 *     4. $default_lang_code or throws on error
 *
 * @param string $webroot_path Absolute path to webroot (no trailing slash required).
 * @param string $default_lang_code Default fallback (e.g. 'en-gb').
 * @return array Associative array with merged translations.
 * @throws Exception When default language files are missing or invalid.
 */
function loadTranslations($webroot_path, $default_lang_code = 'en-gb') {
// TODO
}

// Example usage:
try {
    $lang = loadTranslations($webroot_path);
    // $lang now contains the merged translations
} catch (Exception $e) {
    // On default failure we let the caller handle it (display error / fallback)
    echo 'Translation error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
    exit(1);
}



/**
 * Get the real IP address of the client
 *
 * @return string Client IP address
 */
function getUserIP() {
    // Check if the client is using a shared internet connection (e.g., HTTP_CLIENT_IP)
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    }
    // Check if the client is behind a proxy and the IP is passed in HTTP_X_FORWARDED_FOR
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // This header can contain multiple IPs separated by commas, take the first one
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    // Fallback to REMOTE_ADDR if no other headers are set
    else {
        return $_SERVER['REMOTE_ADDR'];
    }
}


/**
 * Verify Google reCAPTCHA v3 token
 *
 * @param float $threshold Minimum score to consider human (0.0 - 1.0)
 * @return bool True if captcha is valid, false otherwise
 */
function verify_recaptcha_v3(float $threshold = 0.5): bool {
global $recaptcha_secret_key;

    $token = $_POST['g-recaptcha-response'] ?? '';

        // No token/secret provided
    if (empty($token) || empty($recaptcha_secret_key)) {

        return false;
    }

    // Send verification request to Google
    $response = file_get_contents(
        "https://www.google.com/recaptcha/api/siteverify?secret={$recaptcha_secret_key}&response={$token}&remoteip=" . getUserIP()
    );

    if (!$response) {
        // Failed to get response from Google
        return false;
    }

    $data = json_decode($response);

    // Check if success and score meets threshold
    if (isset($data->success) && $data->success === true && isset($data->score) && $data->score >= $threshold) {
        return true;
    }

    return false;
}


function translate(array $contents): string {
    global $lang;
    $default = $lang['danger']['unknown'] ?? 'Unknown error';

    $key = $contents[0]['type'] ?? 'danger';
    $msg = $contents[0]['msg'] ?? '';

    if (is_array($msg)) {
        $txt = $lang[$key][$msg[0]] ?? $default;
        $value = $msg[1] ?? '';
        return sprintf($txt, $value);
    } else {
        return $lang[$key][$msg] ?? $default;
    }
}


/**
 * Validates the "local part" of an email address (the part before the @).
 *
 * Validation rules:
 * 1. Must be between 5 and 50 characters long.
 * 2. Only letters, digits, dots (.), dashes (-), and underscores (_) are allowed.
 * 3. Must start with a letter or a digit.
 * 4. Cannot consist only of digits.
 * 5. No more than 3 identical characters in a row.
 * 6. No consecutive special characters (., -, or _).
 * 7. Must end with a letter or a digit.
 *
 * @param string $local_part The email local part entered by the user.
 * @return true|array Returns true if valid, or an array of error details.
 */
function validate_local_part(string $local_part): true|array {
    // Remove leading/trailing spaces
    // $local_part = trim($local_part);

    // Rule 1: Length between 5 and 50 characters
    if (strlen($local_part) < 5 || strlen($local_part) > 50) {
        return [['type' => 'signup', 'msg' => 'mail_length_between']];
    }

    // Rule 2: Allowed characters
    if (!preg_match('/^[a-zA-Z0-9.\-_]+$/', $local_part)) {
        return [['type' => 'signup', 'msg' => 'allowed_chars']];
    }

    // Rule 3: Must start with a letter or digit
    if (!preg_match('/^[a-zA-Z0-9]/', $local_part)) {
        return [['type' => 'signup', 'msg' => 'must_start_with']];
    }

    // Rule 4: Cannot consist only of digits
    if (preg_match('/^\d+$/', $local_part)) {
        return [['type' => 'signup', 'msg' => 'not_only_digits']];
    }

    // Rule 5: No more than 3 identical characters in a row
    if (preg_match('/(.)\1{3,}/', $local_part)) {
        return [['type' => 'signup', 'msg' => 'no_repetition']];
    }

    // Rule 6: No consecutive special characters
    if (preg_match('/[.\-_]{2,}/', $local_part)) {
        return [['type' => 'signup', 'msg' => 'no_consecutive_specials']];
    }
    // Rule 7: Must end with a letter or digit
    if (!preg_match('/[a-zA-Z0-9]$/', $local_part)) {
        return [['type' => 'signup', 'msg' => 'must_end_with']];
    }
    return true;
}


/**
 * Validates a full name (can include multiple first names and last name).
 *
 * Validation rules:
 * 1. Length must be between 5 and 50 characters.
 * 2. Only letters (Unicode, supports accents), digits, spaces, and hyphens are allowed.
 * 3. Must start with a letter (no digits at the beginning).
 * 4. Cannot consist only of digits.
 * 5. No more than 3 identical characters in a row.
 * 6. No consecutive spaces or hyphens (e.g., "Jean--Pierre" or "Dupont  Alain").
 * 7. Must end with a letter or a digit.
 *
 * @param string $full_name The full name entered by the user.
 * @return true|array Returns true if valid, or an array of error details.
 */
function validate_full_name(string $full_name): true|array {
    // Remove leading/trailing spaces
    // $full_name = trim($full_name);

    // Rule 1: Length check
    if (strlen($full_name) < 5 || strlen($full_name) > 50) {
        return [['type' => 'signup', 'msg' => 'name_length_between']];
    }

    // Rule 2: Allowed characters (letters, digits, spaces, hyphens)
    if (!preg_match('/^[\p{L}0-9\- ]+$/u', $full_name)) {
        return [['type' => 'signup', 'msg' => 'name_allowed_chars']];
    }

    // Rule 3: Must start with a letter
    if (!preg_match('/^\p{L}/u', $full_name)) {
        return [['type' => 'signup', 'msg' => 'must_start_with_letter']];
    }

    // Rule 4: Cannot be only digits
    if (preg_match('/^\d+$/', $full_name)) {
        return [['type' => 'signup', 'msg' => 'full_name_not_only_digits']];
    }

    // Rule 5: No more than 3 identical characters in a row
    if (preg_match('/(.)\1{3,}/u', $full_name)) {
        return [['type' => 'signup', 'msg' => 'no_repetition']];
    }

    // Rule 6: No consecutive spaces or hyphens
    if (preg_match('/[\- ]{2,}/', $full_name)) {
        return [['type' => 'signup', 'msg' => 'name_no_consecutive_specials']];
    }

    // Rule 7: Must end with a letter or digit
    if (!preg_match('/[a-zA-Z0-9]$/', $full_name)) {
        return [['type' => 'signup', 'msg' => 'must_end_with_letter']];
    }
    // Passed all checks
    return true;
}


/**
 * Calls the Mailcow API with the specified method, endpoint, and optional data.
 * Includes error handling for cURL and JSON decoding.
 *
 * @param string $method   HTTP method (e.g., 'GET', 'POST', 'PUT', 'DELETE').
 * @param string $endpoint API endpoint (e.g., '/api/v1/get/mailbox').
 * @param array|null $data  Optional associative array of data to send as JSON in the request body.
 * @return array|null      Decoded API response as an associative array, or null if an error occurs.
 */
function call_mailcow_api(string $method, string $endpoint, ?array $data = null): ?array {

// TODO

}



/**
* Validates and corrects ratelimit values.
* @param array $ratelimit Associative array with the keys 'value' and 'period'.
* @return array Corrected array.
*/
function validate_ratelimit(array $ratelimit): array {
    // Valeur par défaut pour 'value' si < 2
    $ratelimit['value'] = (int)($ratelimit['value'] ?? 0);
    if ($ratelimit['value'] < 2) {
        $ratelimit['value'] = 100;
    }

    // Période autorisée : s, m, h ou d
    $period = strtolower($ratelimit['period'] ?? '');
    if (!in_array($period, ['s', 'm', 'd'])) {
        $ratelimit['period'] = 'd';
    }

    return $ratelimit;
}



/**
 * Check if an email username is allowed for a new mailbox registration.
 *
 * Forbids usernames that are too short (< 5 characters) or
 * matching common administrative, technical, generic,
 * or system-reserved terms with minimum length 5.
 *
 * @param string $username (the part before @)
 * @return bool True if allowed, False if reserved
 *
 * Usage example:
 * ```php
 * isEmailAllowed("john.doe");   // true
 * isEmailAllowed("admin");      // false
 * isEmailAllowed("it");         // false (too short)
 * ```
 */

function isEmailAllowed($username) {

    // **NEW RULE: Forbid all usernames strictly less than 5 characters.**
    // This is the first check, making short usernames like 'it', 'dev', 'ceo', 'user' invalid.
    if (strlen($username) < 5) {
        return false; // Username is too short!
    }

    // Consolidated professional regex patterns for reserved usernames.
    // Each pattern is designed to match a term of at least 5 characters.
    // ^ and $ anchors ensure the entire username matches the pattern.
    // \b ensures word boundaries for partial matches if not using ^$.
    // (?:...) for non-capturing groups.
    $patterns = [
        // 1. Administration & Management (min length 5)
        '^(?:admin(?:istrat(?:or|eur)?)?|sysadmin|manager|director|chief|executive|president|staff|control|office|corporate|government|legal)$',

        // 2. Support, Contact & Communication (min length 5)
        '^(?:support|service(?:client)?|contact(?:e)?|help(?:desk)?|assistance|info(?:rmation)?|queries|feedback|sales|marketing|commercial|publicity|promo(?:tion)?|offer(?:s)?|newsletter|client(?:s)?|partner(?:s)?|media|press|communication|message(?:s)?)$',

        // 3. Finance & Accounting (min length 5)
        '^(?:finance|billing|accounts|invoice(?:s)?|payment(?:s)?|audit|treasury|budget)$',

        // 4. Human Resources (min length 5)
        '^(?:humanresources|career(?:s)?|jobs|recruitment)$',

        // 5. Technical & Development (min length 5)
        '^(?:develop(?:ment)?|engineer(?:ing)?|tech(?:nology)?|operations|system|server|database|network|test(?:ing)?|demo(?:nstration)?|program(?:mer)?|software|hardware|security)$',

        // 6. System & Generic Terms (min length 5)
        '^(?:noreply|no-?reply|mail(?:er|box)?|inbox|email|root|superuser|guest|member|user|account|private|public|robot|bot|daemon|spam|abuse|data|null|void|nobody|example|welcome|update|notification|alert|group|team|central|global|local|national|official|archive|backup|restore|monitor|status|report|event|calendar|question|query|main|default|primary|temp)$',

        // 7. SQL / Programming Keywords (min length 5)
        '^(?:select|insert|update|delete|from|where|public|private|static|class(?:es)?|function(?:s)?|object(?:s)?|array(?:s)?|false|true)$',

        // --- Professional Regex Optimizations for Generic Patterns (ensuring min length 5) ---

        // 8. Master variations: Matches any word ending with 'master' if the total length is 5 or more.
        '^\w{2,}master$', // Matches XXXXXmaster (where XXXXX is >= 2 chars)

        // 9. Smart variations: Matches "smart" followed by anything, ensuring total length is 5 or more.
        '^(?:smart)\w*$', // Matches "smart" or "smartX", "smartXX", etc.

        // 10. Pro variations: Matches "pro" followed by at least 2 characters, ensuring total length is 5 or more.
        '^(?:pro)\w{2,}$', // Matches "proXX..." (total length 5+)

        // 11. Common Prefixes: Matches common prefixes followed by at least 3 characters, ensuring total length is 5 or more.
        '^(?:admin|support|service|contact|sales|marketing|finance|tech|system|security)\w{3,}$',

        // 12. Common Suffixes: Matches common suffixes preceded by at least 3 characters, ensuring total length is 5 or more.
        '^\w{3,}(?:admin|support|service|corporate|enterprise|premium|expert|pro|team|group)$',

        // 13. Numeric Usernames (5 digits or more)
        '^\d{5,}$',

        // --- Important Note on Formerly Excluded Short Keywords ---
        // With the new rule `strlen($username) < 5` returning false, these short terms
        // are now implicitly forbidden if they are the full username.
        // E.g., 'it', 'dev', 'ceo', 'hr', 'ops', 'mgr' are all < 5 chars, so they will be blocked.
    ];

    // Combine all patterns into a single regex for efficient checking.
    $finalPattern = '/(?:' . implode(')|(?:', $patterns) . ')/i';

    // Apply the regex to the username
    if (preg_match($finalPattern, $username)) {
        return false; // Contains a reserved word or pattern
    }

    return true; // Username allowed
}

/**
 * Check if a password has been compromised using the "Have I Been Pwned" API.
 * It uses the k-Anonymity model to protect the full password hash from being exposed.
 *
 * @param string $password The password to check.
 * @return bool True if the password was found in data breaches, false otherwise.
 */
function isPasswordPwned(string $password): bool {
    // 1. Hash the password using SHA-1 (required by the HIBP API).
    //    The API does not accept plaintext passwords, only SHA-1 hashes.
    $sha1Password = strtoupper(sha1($password));

    // 2. Extract the first 5 characters of the hash (the prefix).
    //    This will be sent to the API to request a partial list of matches.
    $prefix = substr($sha1Password, 0, 5);
    
    // 3. Extract the remaining characters of the hash (the suffix).
    //    We'll compare this suffix against the API response.
    $suffix = substr($sha1Password, 5);

    // 4. Build the API URL using the prefix.
    //    The API will return a list of suffixes that share this prefix.
    $url = 'https://api.pwnedpasswords.com/range/' . $prefix;
    
    // Use cURL for a more reliable HTTP request.
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8); // Max time to connect (8s)
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);        // Max total time (8s)
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // If the API does not respond with 200 (OK), assume password is safe by default.
    // (You might also want to log this error for monitoring purposes.)
    if ($httpCode !== 200) {
        return false;
    }

    // 5. Parse the API response.
    //    The response contains lines in the format "HASH_SUFFIX:COUNT".
    //    Example: "003C0A8E7F6A1E8C60A1F6:2"
    $hashes = explode("\n", $response);
    foreach ($hashes as $hashLine) {
        list($pwnedSuffix, $count) = explode(':', $hashLine);
       // Compare our suffix against the returned suffixes.
        if ($pwnedSuffix === $suffix) {
            // If found, the password has been compromised.
            return true;
        }
    }

    // If the suffix was not found, the password has not been reported in breaches.
    return false;
}



# ==============================================================
# LOGIQUE FORMULAIRE
# ==============================================================

$current_url = get_current_url();
$api_url = $current_url.'api/v1/';
$error_message = null;
$success_message = null;
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name       = htmlspecialchars(trim($_POST['name'] ?? ''));
    $local_part = htmlspecialchars(trim(strtolower($_POST['local_part'] ?? '')));
    $domain     = htmlspecialchars(trim(strtolower($_POST['domain'] ?? '')));
    $password1  = htmlspecialchars($_POST['password1'] ?? '');
    $password2  = htmlspecialchars($_POST['password2'] ?? '');
    $mailbox = $local_part . '@' . $domain;

    $form_data = compact('name','local_part','domain');
    
    // Used by password_check()
    $_SESSION['return'] = [];

      if (/* Detect error here */) {
        $error_message = translate([["type" => "signup", "msg" => 'recaptcha']]);
    
    }  else {
        $success_message = true;
    }

} else {
        $error_message = translate([["type" => "signup", "msg" => 'AccessDenied']]);
        // Access Denied
        exit;
}


// Create the mailbox
$api_response = call_mailcow_api("POST", "/add/mailbox", $create_payload);

    // Error !
if ($api_response === null) {
        $error_message = translate([["type" => "signup", "msg" => 'apiErr']]);

} else {

    // OK continue
$success_message = true;
}

# ==============================================================
# TEMPLATE
# ==============================================================

require_once $webroot_path  . '/inc/header.inc.php';

$custom_login = customize('get', 'custom_login');
$template     = 'signup.twig';
$template_data = [
    'list_domains'        => $list_domains,
    'error_message'       => $error_message,
    'success_message'     => $success_message,
    'form_data'           => $form_data,
    'password_policy_html'=> password_complexity('html'),
    'custom_login'        => $custom_login,
    'recaptcha_site_key'  => $recaptcha_site_key,
    'local_part_policy'  => nl2br(translate([["type" => "signup", "msg" => "local_part_policy"]]))                  
];

//$js_minifier->add('/web/js/site/pwgen.js');

require_once $webroot_path . '/inc/footer.inc.php';

