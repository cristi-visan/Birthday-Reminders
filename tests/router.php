<?php
// Local dev server that mimics .htaccess routing:
//   BIRTHDAYS_CONFIG=/path/to/config.php php -S localhost:8080 tests/router.php
$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#^/(src|cron|data|tests)(/|$)|^/config(\.example)?\.php$|/\.(?!well-known/)#', $path)) {
    http_response_code(403);
    return true;
}
$routes = [
    '#^/\.well-known/oauth-protected-resource(/.*)?$#' => ['oauth.php', ['r' => 'resource-metadata']],
    '#^/\.well-known/oauth-authorization-server(/.*)?$#' => ['oauth.php', ['r' => 'server-metadata']],
    '#^/oauth/(register|authorize|token)$#' => ['oauth.php', ['r' => '$1']],
    '#^/mcp/?$#' => ['mcp.php', []],
    '#^/api/(.*)$#' => ['api.php', ['route' => '$1']],
];
foreach ($routes as $re => [$file, $params]) {
    if (preg_match($re, $path, $m)) {
        foreach ($params as $k => $v) {
            $_GET[$k] = $v === '$1' ? $m[1] : $v;
        }
        chdir($root);
        require $root . '/' . $file;
        return true;
    }
}
if ($path === '/') {
    readfile($root . '/index.html');
    return true;
}
return false;
